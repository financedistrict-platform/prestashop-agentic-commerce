<?php

declare(strict_types=1);

use FD\PrismDummy\DummyHandler;
use FD\PrismUcp\Checkout\CartBuilder;
use FD\PrismUcp\Checkout\CheckoutService;
use FD\PrismUcp\Http\Response;
use FD\PrismUcp\Payment\PaymentRegistry;
use FD\PrismUcp\Ucp\VersionRegistry;
use PHPUnit\Framework\TestCase;

if (!defined('_PS_MODE_DEV_')) {
    define('_PS_MODE_DEV_', true);
}

require_once dirname(__DIR__, 3) . '/fdpsdummy/src/DummyHandler.php';

final class PaymentTamperTest extends TestCase
{
    private const SESSION_ID = '5f0c2a8e-3b1d-4c6e-9a7f-2d4b8e1c0a91';
    private const SECRET = 'test';
    private const INSTRUMENT = ['handler_id' => 'xyz.fd.prism_payment', 'type' => 'x402', 'credential' => ['type' => 'x402']];

    private FdTestMemorySessions $sessions;
    private FdTestRecordingPrismHandler $handler;
    private Cart $cart;

    protected function setUp(): void
    {
        FdTestStubs::reset();
        VersionRegistry::seedOnUpgrade();

        $session = FdTestGoldenRenderer::input('checkout-session.json');
        $session['ucp_version'] = null;
        $this->sessions = new FdTestMemorySessions([self::SESSION_ID => $session]);
        $this->handler = new FdTestRecordingPrismHandler();
        $this->cart = new Cart();
    }

    private function complete(): Response
    {
        $registry = new PaymentRegistry();
        $registry->register($this->handler);
        $service = new CheckoutService(new Context(), $registry, FdTestGoldenRenderer::ENDPOINT, hash('sha256', ''), self::SECRET);
        (new ReflectionProperty(CheckoutService::class, 'sessions'))->setValue($service, $this->sessions);
        (new ReflectionProperty(CheckoutService::class, 'cartBuilder'))->setValue($service, new FdTestFixedCartBuilder($this->cart));

        return $service->complete(self::SESSION_ID, ['payment' => ['instruments' => [self::INSTRUMENT]]], null);
    }

    private function row(): array
    {
        return $this->sessions->rows[self::SESSION_ID];
    }

    public function test_order_total_above_the_quote_is_requoted_and_never_settled(): void
    {
        $this->cart->orderTotals = [Cart::ONLY_PRODUCTS => 50.00, Cart::ONLY_SHIPPING => 4.95, Cart::BOTH => 54.95];

        $response = $this->complete();

        $this->assertSame(409, $response->status, (string) json_encode($response->body));
        $this->assertSame('quote_changed', $response->body['messages'][0]['code']);
        $this->assertSame([], $this->handler->settled);
        $this->assertSame('incomplete', $this->row()['status']);
        $this->assertSame(
            [['type' => 'subtotal', 'amount' => 5000], ['type' => 'fulfillment', 'amount' => 495], ['type' => 'total', 'amount' => 5495]],
            json_decode($this->row()['totals'], true)
        );
        $this->assertSame(['xyz.fd.prism_payment' => null], json_decode($this->row()['payment_meta'], true));
    }

    public function test_order_total_below_the_quote_is_requoted_and_never_settled(): void
    {
        $this->cart->orderTotals = [Cart::ONLY_PRODUCTS => 30.00, Cart::ONLY_SHIPPING => 0.0, Cart::BOTH => 30.00];

        $response = $this->complete();

        $this->assertSame(409, $response->status);
        $this->assertSame([], $this->handler->settled);
        $this->assertSame([['type' => 'subtotal', 'amount' => 3000], ['type' => 'total', 'amount' => 3000]], json_decode($this->row()['totals'], true));
    }

    public function test_session_without_a_stored_total_is_never_settled(): void
    {
        $this->sessions->rows[self::SESSION_ID]['totals'] = json_encode([['type' => 'subtotal', 'amount' => 4695]]);

        $response = $this->complete();

        $this->assertSame(409, $response->status);
        $this->assertSame([], $this->handler->settled);
    }

    public function test_order_total_equal_to_the_quote_settles(): void
    {
        $response = $this->complete();

        $this->assertSame(200, $response->status, (string) json_encode($response->body));
        $this->assertCount(1, $this->handler->settled);
    }

    private function dummySettle(?array $meta): array
    {
        $module = new PaymentModule();
        $result = (new DummyHandler($module))->settlePayment([
            'session' => $this->row(),
            'cart' => $this->cart,
            'handler_id' => 'dummy',
            'credential' => [],
            'checkout_meta' => $meta,
        ]);

        return [$result, $module->validated];
    }

    public function test_dummy_reports_the_quoted_amount_as_paid(): void
    {
        [$result, $validated] = $this->dummySettle(['dummy' => ['handler' => 'dummy', 'amount' => 4695, 'currency' => 'EUR']]);

        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));
        $this->assertSame(46.95, $validated[0][2]);
    }

    public function test_dummy_rejects_a_quote_below_the_order_total(): void
    {
        $this->sessions->rows[self::SESSION_ID]['totals'] = json_encode([['type' => 'total', 'amount' => 100]]);

        [$result, $validated] = $this->dummySettle(['dummy' => ['handler' => 'dummy', 'amount' => 100, 'currency' => 'EUR']]);

        $this->assertFalse($result['success']);
        $this->assertSame([], $validated);
    }

    public function test_dummy_rejects_a_prepared_amount_that_differs_from_the_quote(): void
    {
        [$result, $validated] = $this->dummySettle(['dummy' => ['handler' => 'dummy', 'amount' => 100, 'currency' => 'EUR']]);

        $this->assertFalse($result['success']);
        $this->assertSame([], $validated);
    }

    public function test_dummy_rejects_a_missing_prepared_amount(): void
    {
        [$result, $validated] = $this->dummySettle(null);

        $this->assertFalse($result['success']);
        $this->assertSame([], $validated);
    }
}

final class FdTestFixedCartBuilder extends CartBuilder
{
    public function __construct(private \Cart $cart)
    {
    }

    public function build(array $session, \Context $context): \Cart
    {
        return $this->cart;
    }
}
