<?php

declare(strict_types=1);

use FD\PrismUcp\Checkout\CheckoutService;
use FD\PrismUcp\Http\Response;
use FD\PrismUcp\Payment\PaymentIntegrity;
use FD\PrismUcp\Payment\PaymentRegistry;
use FD\PrismUcp\Ucp\VersionRegistry;
use PHPUnit\Framework\TestCase;

final class QuoteTtlTest extends TestCase
{
    private const SESSION_ID = '5f0c2a8e-3b1d-4c6e-9a7f-2d4b8e1c0a91';
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
        $session['payment_meta'] = json_encode(['xyz.fd.prism_payment' => ['prepared_amount' => 4695]]);
        $this->sessions = new FdTestMemorySessions([self::SESSION_ID => $session]);
        $this->handler = new FdTestScriptedPrismHandler();
        $this->cart = new Cart();
        $this->cart->id_address_delivery = 9;
        $this->cart->deliveryOptionList = [9 => ['7,' => ['total_price_with_tax' => 4.95]]];
    }

    private function complete(): Response
    {
        $registry = new PaymentRegistry();
        $registry->register($this->handler);
        $service = new CheckoutService(Context::getContext(), $registry, FdTestGoldenRenderer::ENDPOINT, hash('sha256', ''), 'test');
        (new ReflectionProperty(CheckoutService::class, 'sessions'))->setValue($service, $this->sessions);
        (new ReflectionProperty(CheckoutService::class, 'cartBuilder'))->setValue($service, new FdTestFixedCartBuilder($this->cart));
        $instrument = ['handler_id' => 'xyz.fd.prism_payment', 'type' => 'x402', 'credential' => self::CREDENTIAL];

        return $service->complete(self::SESSION_ID, ['payment' => ['instruments' => [$instrument]]], null);
    }

    private function quotePreparedSecondsAgo(int $seconds): void
    {
        $this->sessions->rows[self::SESSION_ID]['expires_at'] = date('Y-m-d H:i:s', strtotime(PaymentIntegrity::quoteExpiry()) - $seconds);
    }

    private function row(): array
    {
        return $this->sessions->rows[self::SESSION_ID];
    }

    public function test_quote_prepared_901_seconds_ago_is_expired(): void
    {
        $this->quotePreparedSecondsAgo(901);
        $this->handler->script = [self::SETTLED];

        $response = $this->complete();

        $this->assertSame(409, $response->status, (string) json_encode($response->body));
        $this->assertSame('quote_expired', $response->body['messages'][0]['code']);
        $this->assertSame([], $this->handler->settled);
    }

    public function test_quote_prepared_899_seconds_ago_still_settles(): void
    {
        $this->quotePreparedSecondsAgo(899);
        $this->handler->script = [self::SETTLED];

        $response = $this->complete();

        $this->assertSame(200, $response->status, (string) json_encode($response->body));
        $this->assertCount(1, $this->handler->settled);
    }

    public function test_resume_with_the_pending_credential_ignores_the_quote_age_and_renews_the_expiry(): void
    {
        $this->quotePreparedSecondsAgo(0);
        $this->handler->script = [self::PENDING];
        $this->assertSame(202, $this->complete()->status);
        $this->quotePreparedSecondsAgo(901);
        $this->handler->script = [self::PENDING];

        $response = $this->complete();

        $this->assertSame(202, $response->status, (string) json_encode($response->body));
        $this->assertCount(2, $this->handler->settled);
        $this->assertTrue($this->handler->settled[1]['resumed'] ?? false);
        $this->assertEqualsWithDelta(time() + 900, strtotime($this->row()['expires_at']), 2);
    }
}
