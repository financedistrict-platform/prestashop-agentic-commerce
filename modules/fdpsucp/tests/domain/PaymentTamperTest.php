<?php

declare(strict_types=1);

use FD\PrismDummy\DummyGate;
use FD\PrismDummy\DummyHandler;
use FD\PrismUcp\Cart\CartRepository;
use FD\PrismUcp\Cart\CartService;
use FD\PrismUcp\Checkout\CartBuilder;
use FD\PrismUcp\Checkout\CheckoutService;
use FD\PrismUcp\Http\Response;
use FD\PrismUcp\Payment\PaymentIntegrity;
use FD\PrismUcp\Payment\PaymentRegistry;
use FD\PrismUcp\Ucp\VersionRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $session['expires_at'] = date('Y-m-d H:i:s', time() + 600);
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

    private function service(string $secret = self::SECRET): CheckoutService
    {
        $registry = new PaymentRegistry();
        $registry->register($this->handler);
        $service = new CheckoutService(Context::getContext(), $registry, FdTestGoldenRenderer::ENDPOINT, hash('sha256', ''), $secret);
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
        $this->sessions->rows[self::SESSION_ID]['fulfillment'] = null;

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

    private function expireQuote(?string $expiresAt): void
    {
        $this->sessions->rows[self::SESSION_ID]['expires_at'] = $expiresAt;
    }

    private function assertRequotedWithFreshExpiry(Response $response): void
    {
        $this->assertSame(409, $response->status, (string) json_encode($response->body));
        $this->assertSame('quote_expired', $response->body['messages'][0]['code']);
        $this->assertSame([], $this->handler->settled);
        $this->assertSame('incomplete', $this->row()['status']);
        $this->assertCount(1, $this->handler->prepared);
        $this->assertGreaterThan(time(), strtotime($this->row()['expires_at']));
    }

    public function test_expired_quote_is_requoted_and_never_settled(): void
    {
        $this->expireQuote(date('Y-m-d H:i:s', time() - 1));

        $this->assertRequotedWithFreshExpiry($this->complete());
    }

    public function test_quote_without_an_expiry_is_requoted_and_never_settled(): void
    {
        $this->expireQuote(null);

        $this->assertRequotedWithFreshExpiry($this->complete());
    }

    public function test_quote_with_an_unreadable_expiry_is_requoted_and_never_settled(): void
    {
        $this->expireQuote('soon');

        $this->assertRequotedWithFreshExpiry($this->complete());
    }

    public function test_settlement_gate_rejects_an_expired_quote(): void
    {
        $this->expireQuote(date('Y-m-d H:i:s', time() - 1));
        $this->cart->deliveryOption = [9 => '7,'];
        $registry = new PaymentRegistry();
        $registry->register($this->handler);

        $result = $registry->settle('xyz.fd.prism_payment', [
            'session' => $this->row(),
            'cart' => $this->cart,
            'checkout_meta' => json_decode($this->row()['payment_meta'], true),
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Checkout quote has expired', $result['error']);
        $this->assertSame([], $this->handler->settled);
    }

    public function test_session_update_reprepares_an_expired_quote_with_the_same_total(): void
    {
        $this->expireQuote(date('Y-m-d H:i:s', time() - 1));
        $this->quote(4200);
        $this->sessions->rows[self::SESSION_ID]['fulfillment'] = null;

        $response = $this->service()->update(self::SESSION_ID, ['buyer' => ['first_name' => 'Anne']]);

        $this->assertSame(200, $response->status, (string) json_encode($response->body));
        $this->assertCount(1, $this->handler->prepared);
        $this->assertGreaterThan(time(), strtotime($this->row()['expires_at']));
    }

    public function test_session_update_keeps_a_live_quote_with_the_same_total(): void
    {
        $expiresAt = $this->row()['expires_at'];
        $this->quote(4200);
        $this->sessions->rows[self::SESSION_ID]['fulfillment'] = null;

        $response = $this->service()->update(self::SESSION_ID, ['buyer' => ['first_name' => 'Anne']]);

        $this->assertSame(200, $response->status, (string) json_encode($response->body));
        $this->assertSame([], $this->handler->prepared);
        $this->assertSame($expiresAt, $this->row()['expires_at']);
    }

    public function test_session_create_quotes_with_a_short_expiry(): void
    {
        $response = $this->createUnderRequestCurrency(new Currency(Currency::getIdByIsoCode('EUR')));

        $this->assertSame(201, $response->status, (string) json_encode($response->body));
        $expiresAt = strtotime($this->sessions->rows[$response->body['id']]['expires_at']);
        $this->assertGreaterThan(time(), $expiresAt);
        $this->assertLessThanOrEqual(time() + PaymentIntegrity::QUOTE_TTL, $expiresAt);
    }

    public function test_requote_refreshes_the_quote_expiry(): void
    {
        $this->expireQuote(date('Y-m-d H:i:s', time() + 5));
        $this->cart->orderTotals = [Cart::ONLY_PRODUCTS => 50.00, Cart::ONLY_SHIPPING => 4.95, Cart::BOTH => 54.95];

        $this->assertSame(409, $this->complete()->status);

        $this->assertGreaterThan(time() + 5, strtotime($this->row()['expires_at']));
    }

    private function shipToSweden(): Response
    {
        $fulfillment = json_decode($this->row()['fulfillment'], true);
        $fulfillment['methods'][0]['destinations'] = self::SWEDEN['methods'][0]['destinations'];

        return $this->service()->update(self::SESSION_ID, ['fulfillment' => $fulfillment]);
    }

    public function test_completion_is_rejected_when_no_carrier_serves_the_address_and_the_session_holds_a_carrierless_option(): void
    {
        $this->cart->deliveryOptionList = [9 => []];
        $this->selectStoredCarrier('free_shipping');

        $this->assertCarrierRejected($this->complete());
    }

    public function test_completion_is_rejected_when_no_carrier_serves_the_address_and_no_option_is_selected(): void
    {
        $this->cart->deliveryOptionList = [];
        $this->dropStoredCarrierGroups();

        $this->assertCarrierRejected($this->complete());
    }

    public function test_settlement_gate_rejects_a_cart_that_no_carrier_serves(): void
    {
        $this->cart->deliveryOptionList = [9 => []];
        $this->selectStoredCarrier('free_shipping');
        $registry = new PaymentRegistry();
        $registry->register($this->handler);

        $result = $registry->settle('xyz.fd.prism_payment', [
            'session' => $this->row(),
            'cart' => $this->cart,
            'checkout_meta' => json_decode($this->row()['payment_meta'], true),
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame([], $this->handler->settled);
    }

    public function test_session_update_to_an_address_no_carrier_serves_is_rejected_without_a_free_option(): void
    {
        $this->cart->deliveryOptionList = [9 => []];
        $before = $this->row();

        $response = $this->shipToSweden();

        $this->assertSame(422, $response->status, (string) json_encode($response->body));
        $this->assertSame('carrier_unavailable', $response->body['messages'][0]['code']);
        $this->assertSame($before['fulfillment'], $this->row()['fulfillment']);
        $this->assertSame($before['totals'], $this->row()['totals']);
    }

    public function test_session_update_to_an_address_the_shop_does_not_serve_is_rejected(): void
    {
        $this->cart->id_address_delivery = 0;
        $before = $this->row();

        $response = $this->shipToSweden();

        $this->assertSame(422, $response->status, (string) json_encode($response->body));
        $this->assertSame('carrier_unavailable', $response->body['messages'][0]['code']);
        $this->assertSame($before['totals'], $this->row()['totals']);
    }

    public function test_virtual_cart_completes_without_a_carrier(): void
    {
        $this->cart->virtual = true;
        $this->cart->deliveryOptionList = [9 => []];
        $this->cart->orderTotals = [Cart::ONLY_PRODUCTS => 46.95, Cart::ONLY_SHIPPING => 0.0, Cart::BOTH => 46.95];
        $this->dropStoredCarrierGroups();

        $response = $this->complete();

        $this->assertSame(200, $response->status, (string) json_encode($response->body));
        $this->assertCount(1, $this->handler->settled);
    }

    public function test_delivery_country_must_be_active(): void
    {
        Country::$inactive = [113];

        $this->assertSame(0, (new CartBuilder())->deliveryCountryId('KP', 1));
        $this->assertSame(18, (new CartBuilder())->deliveryCountryId('se', 1));
        $this->assertSame(0, (new CartBuilder())->deliveryCountryId('ZZ', 1));
    }

    public function test_delivery_country_must_be_enabled_for_the_shop(): void
    {
        Country::$unassociated = [[18, 2]];

        $this->assertSame(0, (new CartBuilder())->deliveryCountryId('SE', 2));
        $this->assertSame(18, (new CartBuilder())->deliveryCountryId('SE', 1));
    }

    public function test_malformed_delivery_country_is_unresolved_instead_of_failing(): void
    {
        $this->assertSame(0, (new CartBuilder())->deliveryCountryId('S;E', 1));
        $this->assertSame(0, (new CartBuilder())->deliveryCountryId('', 1));
    }

    public function test_delivery_state_of_another_country_cannot_move_the_shipping_zone(): void
    {
        $this->assertSame(0, (new CartBuilder())->deliveryStateId('California', 8));
        $this->assertSame(0, (new CartBuilder())->deliveryStateId('CA', 8));
        $this->assertSame(5, (new CartBuilder())->deliveryStateId('California', 21));
        $this->assertSame(5, (new CartBuilder())->deliveryStateId('CA', 21));
        $this->assertSame(60, (new CartBuilder())->deliveryStateId('Stockholm', 18));
    }

    private function buildCartFor(string $email, bool $withAddress = false): Cart
    {
        $session = $this->row();
        $session['buyer'] = json_encode(['email' => $email, 'first_name' => 'Mallory', 'last_name' => 'Buyer']);
        $fulfillment = json_decode((string) $session['fulfillment'], true);
        $fulfillment['methods'][0]['destinations'][0]['address_country'] = $withAddress ? 'SE' : '';
        $session['fulfillment'] = json_encode($fulfillment);
        Context::getContext()->currency = new Currency(Currency::getIdByIsoCode('EUR'));

        return (new CartBuilder())->build($session, Context::getContext());
    }

    public function test_buyer_email_of_a_registered_customer_never_attaches_the_cart_to_that_account(): void
    {
        Customer::$registered = [42 => 'victim@example.com'];

        $cart = $this->buildCartFor('victim@example.com');

        $this->assertNotSame(42, (int) $cart->id_customer);
        $this->assertCount(1, Customer::$created);
        $this->assertSame(1, (int) Customer::$created[0]->is_guest);
        $this->assertSame((int) Customer::$created[0]->id, (int) $cart->id_customer);
    }

    public function test_delivery_address_is_never_added_to_a_registered_customers_address_book(): void
    {
        Customer::$registered = [42 => 'victim@example.com'];

        $cart = $this->buildCartFor('victim@example.com', true);

        $this->assertNotEmpty(Address::$created);
        foreach (Address::$created as $address) {
            $this->assertNotSame(42, (int) $address->id_customer);
            $this->assertSame((int) $cart->id_customer, (int) $address->id_customer);
        }
    }

    public function test_buyer_email_is_matched_case_insensitively_without_attaching_to_a_registered_customer(): void
    {
        Customer::$registered = [42 => 'victim@example.com'];

        $cart = $this->buildCartFor('VICTIM@example.com');

        $this->assertNotSame(42, (int) $cart->id_customer);
    }

    public function test_every_cart_gets_its_own_guest_customer(): void
    {
        $first = $this->buildCartFor('buyer@example.com');
        $second = $this->buildCartFor('buyer@example.com');

        $this->assertNotSame((int) $first->id_customer, (int) $second->id_customer);
        $this->assertCount(2, Customer::$created);
    }

    public function test_cart_is_never_built_when_the_guest_customer_cannot_be_created(): void
    {
        Customer::$failAdd = true;

        $this->expectException(\RuntimeException::class);
        $this->buildCartFor('buyer@example.com');
    }

    private function serviceWithRealCartBuilder(): CheckoutService
    {
        $service = $this->service();
        (new ReflectionProperty(CheckoutService::class, 'cartBuilder'))->setValue($service, new CartBuilder());
        Product::$prices = [101 => ['EUR' => 18.00]];
        Context::getContext()->currency = new Currency(Currency::getIdByIsoCode('EUR'));

        return $service;
    }

    public function test_session_create_is_rejected_cleanly_when_the_guest_customer_cannot_be_created(): void
    {
        Customer::$failAdd = true;

        $response = $this->serviceWithRealCartBuilder()->create(['line_items' => [['item' => ['id' => '101'], 'quantity' => 2]]], null);

        $this->assertSame(422, $response->status);
        $this->assertSame('cart_build_failed', $response->body['messages'][0]['code']);
    }

    public function test_session_update_is_rejected_cleanly_when_the_guest_customer_cannot_be_created(): void
    {
        Customer::$failAdd = true;
        $before = $this->row();

        $response = $this->serviceWithRealCartBuilder()->update(self::SESSION_ID, ['line_items' => [['item' => ['id' => '101'], 'quantity' => 2]]]);

        $this->assertSame(422, $response->status);
        $this->assertSame('cart_build_failed', $response->body['messages'][0]['code']);
        $this->assertSame($before['totals'], $this->row()['totals']);
    }

    public static function unusableQuantities(): array
    {
        return [
            'above the cap' => [1001],
            'above the cap as text' => ['1001'],
            'fractional' => [2.5],
            'fractional text' => ['2.5'],
            'exponent text' => ['1e3'],
            'list' => [[2]],
            'boolean' => [true],
            'negative' => [-3],
            'zero' => [0],
            'int max' => [PHP_INT_MAX],
            'huge text' => ['99999999999999999999'],
        ];
    }

    #[DataProvider('unusableQuantities')]
    public function test_session_create_rejects_an_unusable_line_quantity(mixed $quantity): void
    {
        $response = $this->serviceWithRealCartBuilder()->create(['line_items' => [['item' => ['id' => '101'], 'quantity' => $quantity]]], null);

        $this->assertSame(422, $response->status, (string) json_encode($response->body));
        $this->assertSame('invalid_quantity', $response->body['messages'][0]['code']);
        $this->assertCount(1, $this->sessions->rows);
    }

    #[DataProvider('unusableQuantities')]
    public function test_session_update_rejects_an_unusable_line_quantity(mixed $quantity): void
    {
        $before = $this->row();

        $response = $this->serviceWithRealCartBuilder()->update(self::SESSION_ID, ['line_items' => [['item' => ['id' => '101'], 'quantity' => $quantity]]]);

        $this->assertSame(422, $response->status, (string) json_encode($response->body));
        $this->assertSame('invalid_quantity', $response->body['messages'][0]['code']);
        $this->assertSame($before['totals'], $this->row()['totals']);
        $this->assertSame($before['line_items'], $this->row()['line_items']);
    }

    #[DataProvider('unusableQuantities')]
    public function test_cart_create_rejects_an_unusable_line_quantity(mixed $quantity): void
    {
        $carts = new FdTestMemoryCarts();
        $response = $this->cartService($carts)->create(['line_items' => [['item' => ['id' => '101'], 'quantity' => $quantity]]], null);

        $this->assertSame(422, $response->status, (string) json_encode($response->body));
        $this->assertSame('invalid_quantity', $response->body['messages'][0]['code']);
        $this->assertSame([], $carts->rows);
    }

    public function test_line_quantity_at_the_cap_is_accepted(): void
    {
        $response = $this->serviceWithRealCartBuilder()->create(['line_items' => [['item' => ['id' => '101'], 'quantity' => 1000]]], null);

        $this->assertSame(201, $response->status, (string) json_encode($response->body));
    }

    public function test_line_quantity_given_as_whole_text_is_accepted(): void
    {
        $response = $this->serviceWithRealCartBuilder()->create(['line_items' => [['item' => ['id' => '101'], 'quantity' => '2']]], null);

        $this->assertSame(201, $response->status, (string) json_encode($response->body));
        $this->assertSame(2, $response->body['line_items'][0]['quantity']);
    }

    public function test_cart_build_never_drops_a_line_with_an_unusable_quantity(): void
    {
        $session = $this->row();
        $session['line_items'] = json_encode([['item' => ['id' => '101'], 'quantity' => 5000]]);
        Context::getContext()->currency = new Currency(Currency::getIdByIsoCode('EUR'));

        $this->expectException(\RuntimeException::class);
        (new CartBuilder())->build($session, Context::getContext());
    }

    private function cartService(FdTestMemoryCarts $carts): CartService
    {
        $registry = new PaymentRegistry();
        $registry->register($this->handler);
        $service = new CartService(Context::getContext(), $registry, FdTestGoldenRenderer::ENDPOINT, hash('sha256', ''), self::SECRET);
        (new ReflectionProperty(CartService::class, 'carts'))->setValue($service, $carts);
        Product::$prices = [101 => ['EUR' => 18.00], 205 => ['EUR' => 6.00]];
        Context::getContext()->currency = new Currency(Currency::getIdByIsoCode('EUR'));

        return $service;
    }

    public static function foreignVariants(): array
    {
        return [
            'variant of another product' => ['101', '12'],
            'variant that does not exist' => ['101', '999'],
            'variant that is not a number' => ['101', 'abc'],
            'variant with trailing text' => ['101', '11abc'],
            'negative variant' => ['101', '-11'],
            'list variant' => ['101', ['11']],
        ];
    }

    #[DataProvider('foreignVariants')]
    public function test_session_create_rejects_a_variant_that_is_not_a_combination_of_the_product(string $product, mixed $variant): void
    {
        $response = $this->serviceWithRealCartBuilder()->create(['line_items' => [['item' => ['id' => $product, 'variant_id' => $variant], 'quantity' => 1]]], null);

        $this->assertSame(422, $response->status, (string) json_encode($response->body));
        $this->assertSame('invalid_variant', $response->body['messages'][0]['code']);
        $this->assertCount(1, $this->sessions->rows);
    }

    #[DataProvider('foreignVariants')]
    public function test_session_update_rejects_a_variant_that_is_not_a_combination_of_the_product(string $product, mixed $variant): void
    {
        $before = $this->row();

        $response = $this->serviceWithRealCartBuilder()->update(self::SESSION_ID, ['line_items' => [['item' => ['id' => $product, 'variant_id' => $variant], 'quantity' => 1]]]);

        $this->assertSame(422, $response->status, (string) json_encode($response->body));
        $this->assertSame('invalid_variant', $response->body['messages'][0]['code']);
        $this->assertSame($before['line_items'], $this->row()['line_items']);
    }

    #[DataProvider('foreignVariants')]
    public function test_cart_create_rejects_a_variant_that_is_not_a_combination_of_the_product(string $product, mixed $variant): void
    {
        $carts = new FdTestMemoryCarts();
        $response = $this->cartService($carts)->create(['line_items' => [['item' => ['id' => $product, 'variant_id' => $variant], 'quantity' => 1]]], null);

        $this->assertSame(422, $response->status, (string) json_encode($response->body));
        $this->assertSame('invalid_variant', $response->body['messages'][0]['code']);
        $this->assertSame([], $carts->rows);
    }

    public function test_variant_of_the_product_is_accepted_and_kept_on_the_line(): void
    {
        $response = $this->serviceWithRealCartBuilder()->create(['line_items' => [['item' => ['id' => '101', 'variant_id' => '11'], 'quantity' => 1]]], null);

        $this->assertSame(201, $response->status, (string) json_encode($response->body));
        $this->assertSame('11', $response->body['line_items'][0]['item']['variant_id']);
    }

    public static function malformedProducts(): array
    {
        return [
            'product that is not a number' => ['abc'],
            'product with trailing text' => ['101abc'],
            'product zero' => ['0'],
            'list product' => [['101']],
        ];
    }

    #[DataProvider('malformedProducts')]
    public function test_session_create_rejects_a_malformed_product_id(mixed $product): void
    {
        $response = $this->serviceWithRealCartBuilder()->create(['line_items' => [['item' => ['id' => $product], 'quantity' => 1]]], null);

        $this->assertSame(422, $response->status, (string) json_encode($response->body));
        $this->assertSame('invalid_product', $response->body['messages'][0]['code']);
    }

    public function test_session_create_is_rejected_when_the_cart_refuses_a_line(): void
    {
        Cart::$rejectQuantity = true;

        $response = $this->serviceWithRealCartBuilder()->create(['line_items' => [['item' => ['id' => '101'], 'quantity' => 2]]], null);

        $this->assertSame(422, $response->status, (string) json_encode($response->body));
        $this->assertSame('cart_build_failed', $response->body['messages'][0]['code']);
        $this->assertCount(1, $this->sessions->rows);
    }

    public function test_session_update_is_rejected_when_the_cart_refuses_a_line(): void
    {
        Cart::$rejectQuantity = true;
        $before = $this->row();

        $response = $this->serviceWithRealCartBuilder()->update(self::SESSION_ID, ['line_items' => [['item' => ['id' => '101'], 'quantity' => 2]]]);

        $this->assertSame(422, $response->status, (string) json_encode($response->body));
        $this->assertSame('cart_build_failed', $response->body['messages'][0]['code']);
        $this->assertSame($before['totals'], $this->row()['totals']);
    }

    public function test_completion_is_rejected_and_released_when_the_cart_refuses_a_line(): void
    {
        Cart::$rejectQuantity = true;

        $response = $this->serviceWithRealCartBuilder()->complete(self::SESSION_ID, ['payment' => ['instruments' => [self::INSTRUMENT]]], null);

        $this->assertSame(422, $response->status, (string) json_encode($response->body));
        $this->assertSame('cart_build_failed', $response->body['messages'][0]['code']);
        $this->assertSame('incomplete', $this->row()['status']);
        $this->assertSame([], $this->handler->settled);
    }

    public function test_cart_build_never_drops_a_line_without_a_product(): void
    {
        $session = $this->row();
        $session['line_items'] = json_encode([['item' => ['id' => '0'], 'quantity' => 1]]);
        Context::getContext()->currency = new Currency(Currency::getIdByIsoCode('EUR'));

        $this->expectException(\RuntimeException::class);
        (new CartBuilder())->build($session, Context::getContext());
    }

    public function test_cart_build_fails_when_the_delivery_address_cannot_be_saved(): void
    {
        Address::$failAdd = true;
        $session = $this->row();
        Context::getContext()->currency = new Currency(Currency::getIdByIsoCode('EUR'));
        $fulfillment = json_decode((string) $session['fulfillment'], true);
        $fulfillment['methods'][0]['destinations'][0]['address_country'] = 'SE';
        $session['fulfillment'] = json_encode($fulfillment);

        $this->expectException(\RuntimeException::class);
        (new CartBuilder())->build($session, Context::getContext());
    }

    private const OTHER_SESSION_ID ='0a9c5e21-7d34-4f58-b1a6-93c8d2e47f10';
    private const KEY = 'idem-key-1';

    private function claimKey(string $status = 'incomplete', ?string $secretHash = null): void
    {
        $row = &$this->sessions->rows[self::SESSION_ID];
        $row['idempotency_key'] = self::KEY;
        $row['status'] = $status;
        $row['id_order'] = $status === 'completed' ? 1001 : null;
        if ($secretHash !== null) {
            $row['session_secret_hash'] = $secretHash;
        }
    }

    private function createWithKey(string $secret, ?string $key = self::KEY): Response
    {
        Product::$prices = [101 => ['EUR' => 18.00]];
        Context::getContext()->currency = new Currency(Currency::getIdByIsoCode('EUR'));

        return $this->service($secret)->create(['line_items' => [['item' => ['id' => '101'], 'quantity' => 2]]], $key);
    }

    private function assertSessionUntouched(string $secretHash): void
    {
        $this->assertSame($secretHash, $this->sessions->rows[self::SESSION_ID]['session_secret_hash']);
    }

    public static function claimedSessionStatuses(): array
    {
        return ['open session' => ['incomplete'], 'completed session' => ['completed']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('claimedSessionStatuses')]
    public function test_create_replay_without_the_session_secret_does_not_return_or_rotate_the_session(string $status): void
    {
        $this->claimKey($status);
        $original = $this->row()['session_secret_hash'];

        $response = $this->createWithKey('');

        $this->assertSame(201, $response->status, (string) json_encode($response->body));
        $this->assertNotSame(self::SESSION_ID, $response->body['id']);
        $this->assertSessionUntouched($original);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('claimedSessionStatuses')]
    public function test_create_replay_with_another_session_secret_does_not_return_or_rotate_the_session(string $status): void
    {
        $this->claimKey($status);
        $original = $this->row()['session_secret_hash'];

        $response = $this->createWithKey('someone-elses-secret');

        $this->assertSame(201, $response->status, (string) json_encode($response->body));
        $this->assertNotSame(self::SESSION_ID, $response->body['id']);
        $this->assertSessionUntouched($original);
    }

    public function test_create_replay_by_the_secret_holder_returns_the_session_without_minting_a_secret(): void
    {
        $this->claimKey();
        $original = $this->row()['session_secret_hash'];

        $response = $this->createWithKey(self::SECRET);

        $this->assertSame(200, $response->status, (string) json_encode($response->body));
        $this->assertSame(self::SESSION_ID, $response->body['id']);
        $this->assertArrayNotHasKey('session_secret', $response->body);
        $this->assertSessionUntouched($original);
    }

    public function test_create_replay_never_matches_a_session_without_a_stored_secret(): void
    {
        $this->claimKey('incomplete', '');

        $response = $this->createWithKey('');

        $this->assertSame(201, $response->status, (string) json_encode($response->body));
        $this->assertNotSame(self::SESSION_ID, $response->body['id']);
        $this->assertSame('', $this->sessions->rows[self::SESSION_ID]['session_secret_hash']);
    }

    public function test_create_without_a_key_always_mints_a_fresh_session(): void
    {
        $this->claimKey();

        $response = $this->createWithKey(self::SECRET, null);

        $this->assertSame(201, $response->status, (string) json_encode($response->body));
        $this->assertNotSame(self::SESSION_ID, $response->body['id']);
        $this->assertArrayHasKey('session_secret', $response->body);
    }

    private function completeWithKey(string $sessionId, string $secret = self::SECRET): Response
    {
        return $this->service($secret)->complete($sessionId, ['payment' => ['instruments' => [self::INSTRUMENT]]], self::KEY);
    }

    private function addCompletedSession(string $secretHash): void
    {
        $row = $this->row();
        $row['session_uid'] = self::OTHER_SESSION_ID;
        $row['status'] = 'completed';
        $row['id_order'] = 2002;
        $row['idempotency_key'] = self::KEY;
        $row['session_secret_hash'] = $secretHash;
        $this->sessions->rows[self::OTHER_SESSION_ID] = $row;
    }

    public function test_create_replay_picks_the_callers_own_session_among_sessions_sharing_the_key(): void
    {
        $this->addCompletedSession(hash('sha256', 'someone-elses-secret'));
        $this->claimKey();

        $response = $this->createWithKey(self::SECRET);

        $this->assertSame(200, $response->status, (string) json_encode($response->body));
        $this->assertSame(self::SESSION_ID, $response->body['id']);
        $this->assertArrayNotHasKey('session_secret', $response->body);
    }

    public function test_complete_replay_by_the_secret_holder_returns_the_same_order(): void
    {
        $this->claimKey('completed');

        $response = $this->completeWithKey(self::SESSION_ID);

        $this->assertSame(200, $response->status, (string) json_encode($response->body));
        $this->assertSame([], $this->handler->settled);
    }

    public function test_complete_replay_guard_never_returns_the_order_of_a_different_session(): void
    {
        $this->addCompletedSession(hash('sha256', self::SECRET));

        $response = $this->completeWithKey(self::SESSION_ID);

        $this->assertSame(409, $response->status, (string) json_encode($response->body));
        $this->assertSame('incomplete', $this->row()['status']);
        $this->assertSame([], $this->handler->settled);
    }

    public function test_complete_with_a_key_claimed_by_another_secret_holder_settles_only_its_own_session(): void
    {
        $this->addCompletedSession(hash('sha256', 'someone-elses-secret'));

        $response = $this->completeWithKey(self::SESSION_ID);

        $this->assertSame(200, $response->status, (string) json_encode($response->body));
        $this->assertCount(1, $this->handler->settled);
        $this->assertSame(2002, (int) $this->sessions->rows[self::OTHER_SESSION_ID]['id_order']);
    }

    public function test_complete_replay_never_matches_a_session_without_a_stored_secret(): void
    {
        $this->claimKey('completed', '');

        $response = $this->completeWithKey(self::SESSION_ID, '');

        $this->assertNotSame(200, $response->status);
        $this->assertSame([], $this->handler->settled);
    }

    private function dummySettle(?array $meta): array
    {
        $this->cart->deliveryOption = [9 => '7,'];
        $module = new PaymentModule();
        $registry = new PaymentRegistry();
        $registry->register(new DummyHandler($module, new DummyGate(true, true, false)));
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

final class FdTestMemoryCarts extends CartRepository
{
    public array $rows = [];

    public function __construct()
    {
    }

    public function insert(array $data): bool
    {
        $this->rows[$data['cart_uid']] = $data;

        return true;
    }

    public function findByUid(string $uid, int $idShop): ?array
    {
        return $this->rows[$uid] ?? null;
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
