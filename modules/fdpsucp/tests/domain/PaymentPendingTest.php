<?php

declare(strict_types=1);

use FD\PrismUcp\Checkout\CheckoutService;
use FD\PrismUcp\Http\Response;
use FD\PrismUcp\Payment\PaymentHandlerInterface;
use FD\PrismUcp\Payment\PaymentRegistry;
use FD\PrismUcp\Ucp\VersionRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentPendingTest extends TestCase
{
    private const SESSION_ID = '5f0c2a8e-3b1d-4c6e-9a7f-2d4b8e1c0a91';
    private const SECRET = 'test';
    private const CREDENTIAL = ['type' => 'x402', 'authorization' => ['nonce' => '0xaa', 'from' => '0xbb']];
    private const PENDING = ['success' => false, 'pending' => true, 'error' => 'Prism settlement outcome is unknown'];
    private const SETTLED = ['success' => true, 'id_order' => 1001, 'transaction_reference' => '0xcd', 'network' => 'eip155:84532'];

    private FdTestMemorySessions $sessions;
    private FdTestScriptedPrismHandler $handler;
    private Cart $cart;

    protected function setUp(): void
    {
        FdTestStubs::reset();
        VersionRegistry::seedOnUpgrade();

        $session = FdTestGoldenRenderer::input('checkout-session.json');
        $session['ucp_version'] = null;
        $session['expires_at'] = date('Y-m-d H:i:s', time() + 600);
        $session['payment_meta'] = json_encode(['xyz.fd.prism_payment' => ['prepared_amount' => 4695]]);
        $this->sessions = new FdTestMemorySessions([self::SESSION_ID => $session]);
        $this->handler = new FdTestScriptedPrismHandler();
        $this->cart = new Cart();
        $this->cart->id_address_delivery = 9;
        $this->cart->deliveryOptionList = [9 => ['7,' => ['total_price_with_tax' => 4.95]]];
    }

    private function service(): CheckoutService
    {
        $registry = new PaymentRegistry();
        $registry->register($this->handler);
        $service = new CheckoutService(Context::getContext(), $registry, FdTestGoldenRenderer::ENDPOINT, hash('sha256', ''), self::SECRET);
        (new ReflectionProperty(CheckoutService::class, 'sessions'))->setValue($service, $this->sessions);
        (new ReflectionProperty(CheckoutService::class, 'cartBuilder'))->setValue($service, new FdTestFixedCartBuilder($this->cart));

        return $service;
    }

    private function complete(array $credential = self::CREDENTIAL): Response
    {
        $instrument = ['handler_id' => 'xyz.fd.prism_payment', 'type' => 'x402', 'credential' => $credential];

        return $this->service()->complete(self::SESSION_ID, ['payment' => ['instruments' => [$instrument]]], null);
    }

    private function row(): array
    {
        return $this->sessions->rows[self::SESSION_ID];
    }

    private function pendingSession(): void
    {
        $this->handler->script = [self::PENDING];
        $this->assertSame(202, $this->complete()->status);
    }

    public function test_unknown_settlement_outcome_holds_the_session_instead_of_failing_it(): void
    {
        $this->handler->script = [self::PENDING];

        $response = $this->complete();

        $this->assertSame(202, $response->status, (string) json_encode($response->body));
        $this->assertSame('payment_pending', $this->row()['status']);
        $this->assertSame('complete_in_progress', $response->body['status']);
        $this->assertArrayNotHasKey('order', $response->body);
        $this->assertEmpty($this->row()['id_order'] ?? null);
    }

    public function test_pending_session_remembers_only_a_digest_of_the_credential(): void
    {
        $this->pendingSession();

        $meta = json_decode($this->row()['payment_meta'], true);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $meta['pending_credential_sha256']);
        $this->assertStringNotContainsString('0xaa', $this->row()['payment_meta']);
    }

    public function test_handler_exception_holds_the_session_instead_of_failing_it(): void
    {
        $this->handler->script = [new RuntimeException('connection reset')];

        $response = $this->complete();

        $this->assertSame(202, $response->status, (string) json_encode($response->body));
        $this->assertSame('payment_pending', $this->row()['status']);
    }

    public function test_same_credential_retry_settles_and_places_the_order_once(): void
    {
        $this->pendingSession();
        $this->handler->script = [self::SETTLED];

        $response = $this->complete();

        $this->assertSame(200, $response->status, (string) json_encode($response->body));
        $this->assertSame('completed', $this->row()['status']);
        $this->assertSame(1001, (int) $this->row()['id_order']);
        $this->assertCount(2, $this->handler->settled);
        $this->assertArrayNotHasKey('pending_credential_sha256', json_decode($this->row()['payment_meta'], true));
        $this->assertSame(409, $this->complete()->status);
    }

    public function test_same_credential_with_reordered_keys_is_the_same_credential(): void
    {
        $this->pendingSession();
        $this->handler->script = [self::SETTLED];

        $response = $this->complete(['authorization' => ['from' => '0xbb', 'nonce' => '0xaa'], 'type' => 'x402']);

        $this->assertSame(200, $response->status, (string) json_encode($response->body));
    }

    public function test_retry_can_still_be_pending(): void
    {
        $this->pendingSession();
        $this->handler->script = [self::PENDING];

        $this->assertSame(202, $this->complete()->status);
        $this->assertSame('payment_pending', $this->row()['status']);
    }

    public function test_different_credential_is_refused_while_pending(): void
    {
        $this->pendingSession();
        $this->handler->script = [self::SETTLED];

        $response = $this->complete(['type' => 'x402', 'authorization' => ['nonce' => '0xdd', 'from' => '0xbb']]);

        $this->assertSame(409, $response->status, (string) json_encode($response->body));
        $this->assertSame('payment_pending_mismatch', $response->body['messages'][0]['code']);
        $this->assertCount(1, $this->handler->settled);
        $this->assertSame('payment_pending', $this->row()['status']);
    }

    public function test_update_and_cancel_are_refused_while_pending(): void
    {
        $this->pendingSession();

        $update = $this->service()->update(self::SESSION_ID, ['buyer' => ['first_name' => 'Anne']]);
        $cancel = $this->service()->cancel(self::SESSION_ID);

        $this->assertSame(409, $update->status);
        $this->assertSame(409, $cancel->status);
        $this->assertSame('payment_pending', $this->row()['status']);
    }

    public function test_retry_after_the_quote_expired_still_reaches_the_gateway(): void
    {
        $this->pendingSession();
        $this->sessions->rows[self::SESSION_ID]['expires_at'] = date('Y-m-d H:i:s', time() - 60);
        $this->handler->script = [self::SETTLED];

        $response = $this->complete();

        $this->assertSame(200, $response->status, (string) json_encode($response->body));
    }

    public function test_pending_stays_pending_when_the_cart_total_drifts_before_the_retry(): void
    {
        $this->pendingSession();
        $this->cart->orderTotals = [Cart::ONLY_PRODUCTS => 50.00, Cart::ONLY_SHIPPING => 4.95, Cart::BOTH => 54.95];

        $response = $this->complete();

        $this->assertSame(409, $response->status, (string) json_encode($response->body));
        $this->assertSame('payment_pending', $this->row()['status']);
        $this->assertCount(1, $this->handler->settled);
    }

    public function test_any_non_pending_failure_on_a_resumed_session_is_escalated_never_released(): void
    {
        $this->pendingSession();
        $this->handler->script = [['success' => false, 'error' => 'Payment authorization has expired']];

        $response = $this->complete();

        $this->assertSame(409, $response->status, (string) json_encode($response->body));
        $this->assertSame('payment_requires_review', $response->body['messages'][0]['code']);
        $this->assertSame('requires_escalation', $this->row()['status']);
        $this->assertSame(409, $this->complete(['type' => 'x402', 'authorization' => ['nonce' => '0xdd', 'from' => '0xbb']])->status);
    }

    public function test_only_the_resumed_attempt_tells_the_handler_to_skip_the_expiry_check(): void
    {
        $this->pendingSession();
        $this->handler->script = [self::SETTLED];

        $this->complete();

        $this->assertArrayNotHasKey('resumed', $this->handler->settled[0]);
        $this->assertTrue($this->handler->settled[1]['resumed'] ?? false);
    }

    public function test_credential_digest_is_stored_before_the_settle_call(): void
    {
        $seen = null;
        $this->handler->onSettle = function () use (&$seen): void {
            $seen = [$this->row()['status'], json_decode($this->row()['payment_meta'], true)['pending_credential_sha256'] ?? null];
        };
        $this->handler->script = [self::SETTLED];

        $this->complete();

        $this->assertSame('complete_in_progress', $seen[0]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $seen[1]);
    }

    private function abandonedClaim(int $ageSeconds, bool $withDigest = true): void
    {
        $this->handler->script = [self::SETTLED];
        $digest = hash('sha256', (string) json_encode(['authorization' => ['from' => '0xbb', 'nonce' => '0xaa'], 'type' => 'x402']));
        $meta = ['xyz.fd.prism_payment' => ['prepared_amount' => 4695]] + ($withDigest ? ['pending_credential_sha256' => $digest] : []);
        $this->sessions->rows[self::SESSION_ID]['status'] = 'complete_in_progress';
        $this->sessions->rows[self::SESSION_ID]['payment_meta'] = json_encode($meta);
        $this->sessions->rows[self::SESSION_ID]['updated_at'] = date('Y-m-d H:i:s', time() - $ageSeconds);
    }

    public function test_abandoned_claim_resumes_with_the_same_credential(): void
    {
        $this->abandonedClaim(120);

        $response = $this->complete();

        $this->assertSame(200, $response->status, (string) json_encode($response->body));
        $this->assertSame('completed', $this->row()['status']);
        $this->assertTrue($this->handler->settled[0]['resumed'] ?? false);
    }

    public function test_abandoned_claim_refuses_a_different_credential(): void
    {
        $this->abandonedClaim(120);

        $response = $this->complete(['type' => 'x402', 'authorization' => ['nonce' => '0xdd', 'from' => '0xbb']]);

        $this->assertSame(409, $response->status);
        $this->assertSame('payment_pending_mismatch', $response->body['messages'][0]['code']);
        $this->assertSame([], $this->handler->settled);
    }

    public function test_recent_claim_is_still_in_progress(): void
    {
        $this->abandonedClaim(5);

        $response = $this->complete();

        $this->assertSame(409, $response->status);
        $this->assertSame('session_in_progress', $response->body['messages'][0]['code']);
        $this->assertSame([], $this->handler->settled);
    }

    public function test_abandoned_claim_that_never_reached_settlement_is_a_fresh_attempt(): void
    {
        $this->abandonedClaim(120, false);

        $response = $this->complete(['type' => 'x402', 'authorization' => ['nonce' => '0xdd', 'from' => '0xbb']]);

        $this->assertSame(200, $response->status, (string) json_encode($response->body));
        $this->assertArrayNotHasKey('resumed', $this->handler->settled[0]);
    }

    public function test_definite_rejection_still_fails_the_session(): void
    {
        $this->handler->script = [['success' => false, 'error' => 'Settlement failed']];

        $response = $this->complete();

        $this->assertSame(422, $response->status);
        $this->assertSame('payment_failed', $response->body['messages'][0]['code']);
        $this->assertSame('incomplete', $this->row()['status']);
    }

    #[DataProvider('reviewReasons')]
    public function test_consumed_or_unresolved_authorization_is_escalated_without_an_order(string $reason): void
    {
        $this->handler->script = [['success' => false, 'review' => true, 'error' => $reason]];

        $response = $this->complete();

        $this->assertSame(409, $response->status, (string) json_encode($response->body));
        $this->assertSame('payment_requires_review', $response->body['messages'][0]['code']);
        $this->assertStringContainsString($reason, $response->body['messages'][0]['content']);
        $this->assertSame('requires_escalation', $this->row()['status']);
        $this->assertEmpty($this->row()['id_order'] ?? null);
        $this->assertStringContainsString($reason, $this->row()['payment_meta']);
    }

    public static function reviewReasons(): array
    {
        return [['authorization_consumed'], ['settlement_unresolved']];
    }

    public function test_escalated_session_cannot_be_completed_updated_or_canceled(): void
    {
        $this->handler->script = [['success' => false, 'review' => true, 'error' => 'authorization_consumed']];
        $this->complete();

        $this->assertSame(409, $this->complete()->status);
        $this->assertSame(409, $this->service()->update(self::SESSION_ID, ['buyer' => ['first_name' => 'Anne']])->status);
        $this->assertSame(409, $this->service()->cancel(self::SESSION_ID)->status);
        $this->assertCount(1, $this->handler->settled);
        $this->assertSame('requires_escalation', $this->row()['status']);
    }
}

final class FdTestScriptedPrismHandler implements PaymentHandlerInterface
{
    public array $script = [];
    public array $settled = [];
    public ?Closure $onSettle = null;

    public function id(): string
    {
        return 'xyz.fd.prism_payment';
    }

    public function name(): string
    {
        return 'Scripted Prism';
    }

    public function getUcpDiscoveryHandlers(): array
    {
        return [];
    }

    public function prepareCheckoutPayment(array $input): ?array
    {
        return ['prepared_amount' => $input['total']];
    }

    public function preparedAmount(?array $checkoutMeta): ?int
    {
        $amount = $checkoutMeta[$this->id()]['prepared_amount'] ?? null;

        return is_int($amount) ? $amount : null;
    }

    public function settlePayment(array $input): array
    {
        $this->settled[] = $input;
        if ($this->onSettle !== null) {
            ($this->onSettle)();
        }
        $next = array_shift($this->script);
        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next ?? throw new LogicException('no scripted settlement result');
    }

    public function getUcpCheckoutHandlers(?array $paymentMeta = null): array
    {
        return [];
    }
}
