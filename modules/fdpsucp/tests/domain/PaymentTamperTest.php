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
        $this->cart->id_address_delivery = 9;
        $this->cart->deliveryOptionList = [9 => ['7,' => ['total_price_with_tax' => 4.95]]];
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

    private function offerCarriers(array $shippingByCarrier): void
    {
        $this->cart->deliveryOptionList = [9 => []];
        foreach ($shippingByCarrier as $idCarrier => $shipping) {
            $this->cart->deliveryOptionList[9][$idCarrier . ','] = ['total_price_with_tax' => $shipping];
            $this->cart->carrierTotals[$idCarrier] = [Cart::ONLY_PRODUCTS => 42.00, Cart::ONLY_SHIPPING => $shipping, Cart::BOTH => 42.00 + $shipping];
        }
    }

    private function selectStoredCarrier(string $optionId): void
    {
        $fulfillment = json_decode($this->row()['fulfillment'], true);
        $fulfillment['methods'][0]['groups'][0]['selected_option_id'] = $optionId;
        $this->sessions->rows[self::SESSION_ID]['fulfillment'] = json_encode($fulfillment);
    }

    private function dropStoredCarrierGroups(): void
    {
        $fulfillment = json_decode($this->row()['fulfillment'], true);
        unset($fulfillment['methods'][0]['groups']);
        $this->sessions->rows[self::SESSION_ID]['fulfillment'] = json_encode($fulfillment);
    }

    private function assertCarrierRejected(Response $response): void
    {
        $this->assertSame(422, $response->status, (string) json_encode($response->body));
        $this->assertSame('carrier_unavailable', $response->body['messages'][0]['code']);
        $this->assertSame('incomplete', $this->row()['status']);
        $this->assertSame([], $this->handler->settled);
    }

    public function test_completion_is_rejected_when_the_selected_carrier_is_no_longer_offered(): void
    {
        $this->offerCarriers([3 => 4.95]);

        $this->assertCarrierRejected($this->complete());
    }

    public function test_completion_is_rejected_when_the_session_has_no_selected_carrier(): void
    {
        $this->offerCarriers([7 => 4.95]);
        $this->dropStoredCarrierGroups();

        $this->assertCarrierRejected($this->complete());
    }

    public function test_completion_is_rejected_when_the_delivery_address_is_not_on_the_order_cart(): void
    {
        $this->offerCarriers([7 => 4.95]);
        $this->cart->id_address_delivery = 0;

        $this->assertCarrierRejected($this->complete());
    }

    public function test_completion_is_rejected_when_no_carrier_serves_the_address_any_more(): void
    {
        $this->cart->deliveryOptionList = [9 => []];

        $this->assertCarrierRejected($this->complete());
    }

    public function test_completion_is_rejected_when_the_carrier_cannot_be_saved_on_the_cart(): void
    {
        $this->cart->saves = false;

        $response = $this->complete();

        $this->assertSame(422, $response->status, (string) json_encode($response->body));
        $this->assertSame('cart_build_failed', $response->body['messages'][0]['code']);
        $this->assertSame([], $this->handler->settled);
    }

    public function test_completion_settles_a_multi_package_carrier_selection(): void
    {
        $this->cart->deliveryOptionList = [9 => ['2,2,' => [], '2,3,' => []]];
        $this->selectStoredCarrier('2,3');

        $response = $this->complete();

        $this->assertSame(200, $response->status, (string) json_encode($response->body));
        $this->assertCount(1, $this->handler->settled);
        $this->assertSame([9 => '2,3,'], $this->cart->deliveryOption);
    }

    public function test_settlement_gate_rejects_a_cart_without_the_selected_carrier(): void
    {
        $this->offerCarriers([3 => 4.95]);
        $this->cart->deliveryOption = [9 => '3,'];
        $registry = new PaymentRegistry();
        $registry->register($this->handler);

        $result = $registry->settle('xyz.fd.prism_payment', [
            'session' => $this->row(),
            'cart' => $this->cart,
            'checkout_meta' => json_decode($this->row()['payment_meta'], true),
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('The selected carrier is not available for this delivery address', $result['error']);
        $this->assertSame([], $this->handler->settled);
    }

    public function test_session_update_with_a_carrier_that_is_not_offered_quotes_the_carrier_it_shows(): void
    {
        $this->offerCarriers([3 => 4.95, 5 => 8.95]);
        $this->cart->orderTotals = $this->cart->carrierTotals[5];
        $fulfillment = json_decode($this->row()['fulfillment'], true);
        $fulfillment['methods'][0]['groups'][0]['selected_option_id'] = '99';

        $response = $this->service()->update(self::SESSION_ID, ['fulfillment' => $fulfillment]);

        $this->assertSame(200, $response->status, (string) json_encode($response->body));
        $stored = json_decode($this->row()['fulfillment'], true);
        $this->assertSame('3', $stored['methods'][0]['groups'][0]['selected_option_id']);
        $this->assertSame(4695, PaymentIntegrity::quotedTotal($this->row()));
        $this->assertSame([9 => '3,'], $this->cart->deliveryOption);
    }

    public function test_completion_prices_the_carrier_selected_on_the_session(): void
    {
        $this->offerCarriers([3 => 2.00, 7 => 10.00]);
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

    public function test_update_after_a_requote_keeps_the_order_cart_quote(): void
    {
        $this->cart->id_address_delivery = 9;
        $this->cart->products[0]['price_wt'] = 18.75;
        $this->cart->orderTotals = [Cart::ONLY_PRODUCTS => 43.50, Cart::ONLY_SHIPPING => 4.95, Cart::BOTH => 48.45];
        $this->assertSame(409, $this->complete()->status);

        $update = $this->service()->update(self::SESSION_ID, ['buyer' => ['first_name' => 'Anne']]);

        $this->assertSame(200, $update->status, (string) json_encode($update->body));
        $this->assertSame(4845, PaymentIntegrity::quotedTotal($this->row()));
        $response = $this->complete();
        $this->assertSame(200, $response->status, (string) json_encode($response->body));
        $this->assertSame(4845, $this->handler->settled[0]['paid_amount'] ?? null);
    }

    private function updateUnderRequestCurrency(string $requestIso, array $body): Response
    {
        Product::$prices = [101 => ['EUR' => 18.00, 'KWD' => 6.00]];
        $this->cart->id_address_delivery = 0;
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

    public function test_session_currency_that_no_longer_loads_is_rejected(): void
    {
        Currency::$deleted = [1];
        $before = $this->row();

        $response = $this->updateUnderRequestCurrency('KWD', ['line_items' => [['item' => ['id' => '101'], 'quantity' => 2]]]);

        $this->assertSame(422, $response->status, (string) json_encode($response->body));
        $this->assertSame('invalid_currency', $response->body['messages'][0]['code']);
        $this->assertSame($before, $this->row());
    }

    public function test_inactive_session_currency_is_rejected(): void
    {
        Currency::$inactive = [1];
        $before = $this->row();

        $response = $this->updateUnderRequestCurrency('KWD', ['line_items' => [['item' => ['id' => '101'], 'quantity' => 2]]]);

        $this->assertSame(422, $response->status, (string) json_encode($response->body));
        $this->assertSame('invalid_currency', $response->body['messages'][0]['code']);
        $this->assertSame($before, $this->row());
    }

    public function test_completion_prices_the_order_in_the_session_currency_not_the_request_currency(): void
    {
        Context::getContext()->currency = new Currency(Currency::getIdByIsoCode('KWD'));

        $response = $this->complete();

        $this->assertSame(200, $response->status, (string) json_encode($response->body));
        $this->assertCount(1, $this->handler->settled);
        $this->assertSame('EUR', Context::getContext()->currency->iso_code);
    }

    public function test_completion_with_an_unknown_session_currency_is_rejected_and_released(): void
    {
        $this->sessions->rows[self::SESSION_ID]['currency'] = 'XYZ';

        $response = $this->complete();

        $this->assertSame(422, $response->status, (string) json_encode($response->body));
        $this->assertSame('invalid_currency', $response->body['messages'][0]['code']);
        $this->assertSame('incomplete', $this->row()['status']);
        $this->assertSame([], $this->handler->settled);
    }

    private function createUnderRequestCurrency(Currency $currency): Response
    {
        Product::$prices = [101 => ['EUR' => 18.00, 'KWD' => 6.00]];
        Context::getContext()->currency = $currency;
        $registry = new PaymentRegistry();
        $registry->register($this->handler);
        $service = new CheckoutService(Context::getContext(), $registry, FdTestGoldenRenderer::ENDPOINT, hash('sha256', ''), self::SECRET);
        (new ReflectionProperty(CheckoutService::class, 'sessions'))->setValue($service, $this->sessions);
        (new ReflectionProperty(CheckoutService::class, 'cartBuilder'))->setValue($service, new FdTestFixedCartBuilder($this->cart));

        return $service->create(['line_items' => [['item' => ['id' => '101'], 'quantity' => 2]]], null);
    }

    public function test_session_create_prices_and_stores_the_requested_currency(): void
    {
        $response = $this->createUnderRequestCurrency(new Currency(Currency::getIdByIsoCode('KWD')));

        $this->assertSame(201, $response->status, (string) json_encode($response->body));
        $row = $this->sessions->rows[$response->body['id']];
        $this->assertSame('KWD', $row['currency']);
        $this->assertSame(600, json_decode($row['line_items'], true)[0]['item']['price']);
        $this->assertSame('KWD', Context::getContext()->currency->iso_code);
    }

    public function test_session_create_with_an_unknown_request_currency_is_rejected(): void
    {
        $currency = new Currency();
        $currency->iso_code = 'XYZ';
        $rows = $this->sessions->rows;

        $response = $this->createUnderRequestCurrency($currency);

        $this->assertSame(422, $response->status, (string) json_encode($response->body));
        $this->assertSame('invalid_currency', $response->body['messages'][0]['code']);
        $this->assertSame($rows, $this->sessions->rows);
    }

    private const SWEDEN = ['methods' => [['destinations' => [[
        'street_address' => 'Drottninggatan 1',
        'address_locality' => 'Stockholm',
        'postal_code' => '11122',
        'address_country' => 'SE',
    ]]]]];

    private function shipToHigherVatCountry(): CheckoutService
    {
        Product::$prices = [101 => 17.85];
        $this->cart->id_address_delivery = 9;
        $this->cart->products = [['id_product' => 101, 'id_product_attribute' => 0, 'cart_quantity' => 2, 'price_wt' => 18.75, 'total_wt' => 37.50]];
        $this->cart->orderTotals = [Cart::ONLY_PRODUCTS => 37.50, Cart::ONLY_SHIPPING => 4.95, Cart::BOTH => 42.45];
        $registry = new PaymentRegistry();
        $registry->register($this->handler);
        $service = new CheckoutService(Context::getContext(), $registry, FdTestGoldenRenderer::ENDPOINT, hash('sha256', ''), self::SECRET);
        (new ReflectionProperty(CheckoutService::class, 'sessions'))->setValue($service, $this->sessions);
        (new ReflectionProperty(CheckoutService::class, 'cartBuilder'))->setValue($service, new FdTestFixedCartBuilder($this->cart));

        return $service;
    }

    private function assertQuotedAtDestinationTax(array $row): void
    {
        $lines = json_decode($row['line_items'], true);
        $this->assertSame(1875, $lines[0]['item']['price']);
        $this->assertSame([['type' => 'subtotal', 'amount' => 3750], ['type' => 'total', 'amount' => 3750]], $lines[0]['totals']);
        $this->assertSame(
            [['type' => 'subtotal', 'amount' => 3750], ['type' => 'fulfillment', 'amount' => 495], ['type' => 'total', 'amount' => 4245]],
            json_decode($row['totals'], true)
        );
        $this->assertSame(4245, end($this->handler->prepared)['total']);
    }

    public function test_session_update_quotes_the_tax_of_the_delivery_country(): void
    {
        $response = $this->shipToHigherVatCountry()->update(self::SESSION_ID, [
            'line_items' => [['item' => ['id' => '101'], 'quantity' => 2]],
            'fulfillment' => self::SWEDEN,
        ]);

        $this->assertSame(200, $response->status, (string) json_encode($response->body));
        $this->assertQuotedAtDestinationTax($this->row());
    }

    public function test_session_create_quotes_the_tax_of_the_delivery_country(): void
    {
        $response = $this->shipToHigherVatCountry()->create([
            'line_items' => [['item' => ['id' => '101'], 'quantity' => 2]],
            'fulfillment' => self::SWEDEN,
        ], null);

        $this->assertSame(201, $response->status, (string) json_encode($response->body));
        $this->assertQuotedAtDestinationTax($this->sessions->rows[$response->body['id']]);
    }

    public function test_session_update_is_rejected_when_the_delivery_cart_misses_a_line_item(): void
    {
        $service = $this->shipToHigherVatCountry();
        $this->cart->products = [];
        $before = $this->row();

        $response = $service->update(self::SESSION_ID, [
            'line_items' => [['item' => ['id' => '101'], 'quantity' => 2]],
            'fulfillment' => self::SWEDEN,
        ]);

        $this->assertSame(422, $response->status, (string) json_encode($response->body));
        $this->assertSame('cart_mismatch', $response->body['messages'][0]['code']);
        $this->assertSame($before, $this->row());
        $this->assertSame([], $this->handler->prepared);
    }

    public function test_completion_is_rejected_when_the_order_cart_misses_a_line_item(): void
    {
        $this->cart->products = [$this->cart->products[0]];
        $this->cart->orderTotals = [Cart::ONLY_PRODUCTS => 36.00, Cart::ONLY_SHIPPING => 4.95, Cart::BOTH => 40.95];
        $quoted = $this->row()['totals'];

        $response = $this->complete();

        $this->assertSame(422, $response->status, (string) json_encode($response->body));
        $this->assertSame('cart_mismatch', $response->body['messages'][0]['code']);
        $this->assertSame([], $this->handler->settled);
        $this->assertSame('incomplete', $this->row()['status']);
        $this->assertSame($quoted, $this->row()['totals']);
    }

    public function test_requote_stores_the_order_cart_line_prices(): void
    {
        $this->cart->products[0]['price_wt'] = 18.75;
        $this->cart->orderTotals = [Cart::ONLY_PRODUCTS => 43.50, Cart::ONLY_SHIPPING => 4.95, Cart::BOTH => 48.45];

        $response = $this->complete();

        $this->assertSame(409, $response->status, (string) json_encode($response->body));
        $lines = json_decode($this->row()['line_items'], true);
        $this->assertSame(1875, $lines[0]['item']['price']);
        $this->assertSame([['type' => 'subtotal', 'amount' => 3750], ['type' => 'total', 'amount' => 3750]], $lines[0]['totals']);
    }

    public function test_cart_line_without_a_tax_inclusive_price_is_rejected(): void
    {
        unset($this->cart->products[1]['price_wt']);

        $response = $this->complete();

        $this->assertSame(422, $response->status, (string) json_encode($response->body));
        $this->assertSame('cart_mismatch', $response->body['messages'][0]['code']);
        $this->assertSame([], $this->handler->settled);
    }

    private function dummySettle(?array $meta): array
    {
        $this->cart->deliveryOption = [9 => '7,'];
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
        $this->applySessionCarrier($this->cart, $session);

        return $this->cart;
    }
}
