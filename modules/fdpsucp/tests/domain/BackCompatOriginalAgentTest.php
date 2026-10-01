<?php

declare(strict_types=1);

use FD\PrismUcp\Checkout\CartBuilder;
use FD\PrismUcp\Checkout\CheckoutService;
use FD\PrismUcp\Http\Response;
use FD\PrismUcp\Payment\PaymentHandlerInterface;
use FD\PrismUcp\Payment\PaymentRegistry;
use FD\PrismUcp\Ucp\SessionRepository;
use FD\PrismUcp\Ucp\VersionPin;
use FD\PrismUcp\Ucp\VersionRegistry;
use FD\PrismUcp\Ucp\VersionResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BackCompatOriginalAgentTest extends TestCase
{
    private const SESSION_ID = '5f0c2a8e-3b1d-4c6e-9a7f-2d4b8e1c0a91';
    private const SECRET = 'test';

    private FdTestMemorySessions $sessions;
    private FdTestRecordingPrismHandler $handler;

    protected function setUp(): void
    {
        FdTestStubs::reset();

        $session = FdTestGoldenRenderer::input('checkout-session.json');
        $session['ucp_version'] = null;
        $this->sessions = new FdTestMemorySessions([self::SESSION_ID => $session]);
        $this->handler = new FdTestRecordingPrismHandler();
    }

    private function service(?VersionPin $pin = null, ?PaymentHandlerInterface $handler = null): CheckoutService
    {
        $registry = new PaymentRegistry();
        $registry->register($handler ?? $this->handler);
        $service = new CheckoutService(new Context(), $registry, FdTestGoldenRenderer::ENDPOINT, hash('sha256', ''), self::SECRET, $pin);
        (new ReflectionProperty(CheckoutService::class, 'sessions'))->setValue($service, $this->sessions);
        (new ReflectionProperty(CheckoutService::class, 'cartBuilder'))->setValue($service, new FdTestCartBuilder());

        return $service;
    }

    private function complete(array $instrument, ?PaymentHandlerInterface $handler = null): Response
    {
        return $this->service(null, $handler)->complete(self::SESSION_ID, ['payment' => ['instruments' => [$instrument]]], null);
    }

    public static function originalInstruments(): array
    {
        $authorization = ['x402Version' => 2, 'paymentPayload' => ['network' => 'eip155:84532']];

        return [
            'original 0.5.3 body with x402 handler id and no type' => [[
                'handler_id' => 'x402',
                'credential' => $authorization,
            ]],
            'tokenized without id or credential type' => [[
                'handler_id' => 'xyz.fd.prism_payment',
                'type' => 'tokenized',
                'credential' => $authorization,
            ]],
            'default type with base64 credential' => [[
                'handler_id' => 'x402',
                'type' => 'default',
                'credential' => base64_encode((string) json_encode($authorization)),
            ]],
            'current x402 instrument' => [[
                'id' => 'inst_1',
                'handler_id' => 'xyz.fd.prism_payment',
                'type' => 'x402',
                'credential' => ['type' => 'x402'] + $authorization,
            ]],
        ];
    }

    #[DataProvider('originalInstruments')]
    public function test_original_era_instrument_completes_with_the_canonical_handler(array $instrument): void
    {
        $response = $this->complete($instrument);

        $this->assertSame(200, $response->status, (string) json_encode($response->body));
        $this->assertSame('completed', $response->body['status']);
        $this->assertSame('2026-04-08', $response->body['ucp']['version']);
        $this->assertCount(1, $this->handler->settled);
        $this->assertSame('xyz.fd.prism_payment', $this->handler->settled[0]['handler_id']);
        $this->assertSame($instrument['credential'], $this->handler->settled[0]['credential']);
        $this->assertSame($instrument['type'] ?? null, $this->handler->settled[0]['instrument_type']);
        $this->assertSame('completed', $this->sessions->rows[self::SESSION_ID]['status']);
        $this->assertSame(1001, $this->sessions->rows[self::SESSION_ID]['id_order']);
    }

    public function test_unknown_handler_is_rejected_before_settle(): void
    {
        $response = $this->complete(['handler_id' => 'other', 'credential' => ['x' => 1]]);

        $this->assertSame(422, $response->status);
        $this->assertSame('unknown_handler', $response->body['messages'][0]['code']);
        $this->assertSame([], $this->handler->settled);
    }

    public function test_missing_credential_keeps_the_original_error(): void
    {
        $response = $this->complete(['handler_id' => 'xyz.fd.prism_payment']);

        $this->assertSame(400, $response->status);
        $this->assertSame('payment.instruments[0].handler_id and credential are required', $response->body['messages'][0]['content']);
    }

    public function test_throwing_handler_releases_the_session(): void
    {
        $response = $this->complete(['handler_id' => 'x402', 'credential' => ['a' => 1]], new FdTestRecordingPrismHandler(true));

        $this->assertSame(422, $response->status);
        $this->assertSame('incomplete', $this->sessions->rows[self::SESSION_ID]['status']);
    }

    public function test_session_pinned_to_another_version_rejects_a_matched_different_agent(): void
    {
        $this->sessions->rows[self::SESSION_ID]['ucp_version'] = '2026-08-25';
        $pin = new VersionPin(
            new VersionResolver(new VersionRegistry(), new FdTestFixtureProfileFetcher(['https://agent.example/p' => FdTestFixtureProfileFetcher::declaring('2026-04-08')])),
            'profile="https://agent.example/p"'
        );

        $response = $this->service($pin)->get(self::SESSION_ID);

        $this->assertSame(422, $response->status);
        $this->assertSame('version_unsupported', $response->body['messages'][0]['code']);
    }

    public function test_pinned_session_is_served_in_its_version_on_a_fallback_outcome(): void
    {
        $this->sessions->rows[self::SESSION_ID]['ucp_version'] = '2026-08-25';
        $pin = new VersionPin(new VersionResolver(new VersionRegistry(), new FdTestFixtureProfileFetcher([])), 'profile="https://agent.example/p"');

        $response = $this->service($pin)->get(self::SESSION_ID);

        $this->assertSame(200, $response->status);
        $this->assertSame('2026-08-25', $response->body['ucp']['version']);
    }
}

final class FdTestRecordingPrismHandler implements PaymentHandlerInterface
{
    public array $settled = [];

    public function __construct(private bool $throws = false)
    {
    }

    public function id(): string
    {
        return 'xyz.fd.prism_payment';
    }

    public function name(): string
    {
        return 'Recording Prism';
    }

    public function getUcpDiscoveryHandlers(): array
    {
        return [];
    }

    public function prepareCheckoutPayment(array $input): ?array
    {
        return null;
    }

    public function settlePayment(array $input): array
    {
        if ($this->throws) {
            throw new RuntimeException('boom');
        }
        $this->settled[] = $input;

        return ['success' => true, 'id_order' => 1001, 'transaction_reference' => '0x' . str_repeat('cd', 32), 'network' => 'eip155:84532'];
    }

    public function getUcpCheckoutHandlers(?array $paymentMeta = null): array
    {
        return [];
    }
}

final class FdTestCartBuilder extends CartBuilder
{
    public function build(array $session, \Context $context): \Cart
    {
        return new Cart();
    }
}

final class FdTestMemorySessions extends SessionRepository
{
    public function __construct(public array $rows)
    {
    }

    public function insert(array $data): bool
    {
        $this->rows[$data['session_uid']] = $data;

        return true;
    }

    public function findByUid(string $uid, int $idShop): ?array
    {
        return $this->rows[$uid] ?? null;
    }

    public function findByIdempotencyKey(string $key, int $idShop): ?array
    {
        foreach ($this->rows as $row) {
            if (($row['idempotency_key'] ?? null) === $key) {
                return $row;
            }
        }

        return null;
    }

    public function update(string $uid, int $idShop, array $data): bool
    {
        $this->rows[$uid] = array_merge($this->rows[$uid], $data);

        return true;
    }

    public function claimForCompletion(string $uid, int $idShop): bool
    {
        if (($this->rows[$uid]['status'] ?? '') !== 'incomplete') {
            return false;
        }
        $this->rows[$uid]['status'] = 'complete_in_progress';

        return true;
    }
}
