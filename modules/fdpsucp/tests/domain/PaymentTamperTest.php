<?php

declare(strict_types=1);

use FD\PrismDummy\DummyHandler;
use FD\PrismUcp\Checkout\CartBuilder;
use FD\PrismUcp\Checkout\CheckoutService;
use FD\PrismUcp\Http\Response;
use FD\PrismUcp\Payment\PaymentIntegrity;
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
        $this->sessions->rows[self::SESSION_ID]['payment_meta'] = json_encode(['xyz.fd.prism_payment' => ['prepared_amount' => 4695]]);
    }

    private function quote(int $total, ?int $prepared = null): void
    {
        $this->sessions->rows[self::SESSION_ID]['totals'] = json_encode([['type' => 'total', 'amount' => $total]]);
        $this->sessions->rows[self::SESSION_ID]['payment_meta'] = json_encode(['xyz.fd.prism_payment' => ['prepared_amount' => $prepared ?? $total]]);
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

    private function complete(): Response
    {
        return $this->service()->complete(self::SESSION_ID, ['payment' => ['instruments' => [self::INSTRUMENT]]], null);
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
        $this->assertSame(['xyz.fd.prism_payment' => ['prepared_amount' => 5495]], json_decode($this->row()['payment_meta'], true));
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

    public function test_prepared_amount_that_differs_from_the_quote_is_never_handed_to_the_handler(): void
    {
        $this->quote(4695, 1500);

        $response = $this->complete();

        $this->assertSame(422, $response->status, (string) json_encode($response->body));
        $this->assertSame('payment_failed', $response->body['messages'][0]['code']);
        $this->assertSame([], $this->handler->settled);
        $this->assertSame('incomplete', $this->row()['status']);
    }

    public function test_missing_prepared_amount_is_never_handed_to_the_handler(): void
    {
        $this->sessions->rows[self::SESSION_ID]['payment_meta'] = json_encode(['xyz.fd.prism_payment' => null]);

        $response = $this->complete();

        $this->assertSame(422, $response->status, (string) json_encode($response->body));
        $this->assertSame([], $this->handler->settled);
    }

    public function test_handler_is_given_the_verified_paid_amount(): void
    {
        $response = $this->complete();

        $this->assertSame(200, $response->status, (string) json_encode($response->body));
        $this->assertSame(4695, $this->handler->settled[0]['paid_amount'] ?? null);
    }

    public function test_completion_prices_the_carrier_selected_on_the_session(): void
    {
        $this->cart->id_address_delivery = 9;
        $this->cart->carrierTotals = [7 => [Cart::ONLY_PRODUCTS => 42.00, Cart::ONLY_SHIPPING => 10.00, Cart::BOTH => 52.00]];
        $this->quote(5200);

        $response = $this->complete();

        $this->assertSame(200, $response->status, (string) json_encode($response->body));
        $this->assertCount(1, $this->handler->settled);
        $this->assertSame([9 => '7,'], $this->cart->deliveryOption);
    }

    public function test_requote_failure_releases_the_session(): void
    {
        $this->handler = new FdTestRecordingPrismHandler(false, true);
        $this->cart->orderTotals = [Cart::ONLY_PRODUCTS => 50.00, Cart::ONLY_SHIPPING => 4.95, Cart::BOTH => 54.95];

        $response = $this->complete();

        $this->assertSame(422, $response->status, (string) json_encode($response->body));
        $this->assertSame('requote_failed', $response->body['messages'][0]['code']);
        $this->assertSame('incomplete', $this->row()['status']);
        $this->assertSame([], $this->handler->settled);
    }

    private function updateUnderRequestCurrency(string $requestIso, array $body): Response
    {
        Product::$prices = [101 => ['EUR' => 18.00, 'KWD' => 6.00]];
        $context = Context::getContext();
        $context->currency = new Currency(Currency::getIdByIsoCode($requestIso));
        $registry = new PaymentRegistry();
        $registry->register($this->handler);
        $service = new CheckoutService($context, $registry, FdTestGoldenRenderer::ENDPOINT, hash('sha256', ''), self::SECRET);
        (new ReflectionProperty(CheckoutService::class, 'sessions'))->setValue($service, $this->sessions);
        (new ReflectionProperty(CheckoutService::class, 'cartBuilder'))->setValue($service, new FdTestFixedCartBuilder($this->cart));

        return $service->update(self::SESSION_ID, $body);
    }

    public function test_session_update_prices_line_items_in_the_session_currency_not_the_request_currency(): void
    {
        $response = $this->updateUnderRequestCurrency('KWD', ['line_items' => [['item' => ['id' => '101'], 'quantity' => 2]]]);

        $this->assertSame(200, $response->status, (string) json_encode($response->body));
        $this->assertSame('EUR', $this->row()['currency']);
        $this->assertSame(1800, json_decode($this->row()['line_items'], true)[0]['item']['price']);
        $this->assertSame([['type' => 'subtotal', 'amount' => 3600], ['type' => 'total', 'amount' => 3600]], json_decode($this->row()['totals'], true));
        $this->assertSame('EUR', Context::getContext()->currency->iso_code);
    }

    public function test_session_update_with_an_unknown_session_currency_is_rejected(): void
    {
        $this->sessions->rows[self::SESSION_ID]['currency'] = 'XYZ';
        $before = $this->row();

        $response = $this->updateUnderRequestCurrency('EUR', ['line_items' => [['item' => ['id' => '101'], 'quantity' => 2]]]);

        $this->assertSame(422, $response->status, (string) json_encode($response->body));
        $this->assertSame('invalid_currency', $response->body['messages'][0]['code']);
        $this->assertSame($before, $this->row());
    }

    private function dummySettle(?array $meta): array
    {
        $module = new PaymentModule();
        $registry = new PaymentRegistry();
        $registry->register(new DummyHandler($module));
        $result = $registry->settle('dummy', [
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
