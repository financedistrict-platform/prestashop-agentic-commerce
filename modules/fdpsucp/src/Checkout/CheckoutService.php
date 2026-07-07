<?php

namespace FD\PrismUcp\Checkout;

use FD\PrismUcp\Http\Response;
use FD\PrismUcp\Payment\PaymentRegistry;
use FD\PrismUcp\Ucp\Formatter;
use FD\PrismUcp\Ucp\SessionRepository;
use FD\PrismUcp\Ucp\UcpError;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * UCP checkout-session lifecycle. Ported from FD_UCP_Checkout_Controller,
 * adapted to PrestaShop: canonical state in ps_prism_session, a transient
 * Cart for pricing/shipping, order creation delegated to the payment handler.
 */
final class CheckoutService
{
    private SessionRepository $sessions;
    private CartBuilder $cartBuilder;

    public function __construct(
        private \Context $context,
        private PaymentRegistry $registry,
        private string $endpointBase,
        private string $agentFingerprint,
        private string $sessionSecret = ''
    ) {
        $this->sessions = new SessionRepository();
        $this->cartBuilder = new CartBuilder();
    }

    private function idShop(): int
    {
        return (int) $this->context->shop->id;
    }

    // ---------------------------------------------------------------- create

    /** @param array<string,mixed> $body */
    public function create(array $body, ?string $idempotencyKey): Response
    {
        $lineItems = $body['line_items'] ?? null;
        if (!is_array($lineItems) || $lineItems === []) {
            return UcpError::response('missing_line_items', 'line_items array is required', 400);
        }

        $idLang = (int) $this->context->language->id;
        $currency = $this->context->currency->iso_code;

        $formatted = [];
        foreach ($lineItems as $item) {
            $idProduct = (int) ($item['item']['id'] ?? 0);
            $idAttr = (int) ($item['item']['variant_id'] ?? 0);
            $qty = (int) ($item['quantity'] ?? 1);
            if ($qty < 1) {
                return UcpError::response('invalid_quantity', "Quantity for product $idProduct must be a positive integer", 422);
            }

            $product = new \Product($idProduct, false, $idLang);
            if (!\Validate::isLoadedObject($product) || !$product->active) {
                return UcpError::response('invalid_product', "Product $idProduct not found or not purchasable", 422);
            }
            if ($stockError = $this->checkStock($idProduct, $idAttr, $qty, $product)) {
                return $stockError;
            }

            $price = Formatter::toMinor((float) \Product::getPriceStatic($idProduct, true, $idAttr ?: null));
            $itemTotal = $price * $qty;
            $name = is_array($product->name) ? ($product->name[$idLang] ?? reset($product->name)) : $product->name;

            $entry = [
                'id' => 'li_' . (count($formatted) + 1),
                'item' => ['id' => (string) $idProduct, 'title' => (string) $name, 'price' => $price],
                'quantity' => $qty,
                'totals' => [
                    ['type' => 'subtotal', 'amount' => $itemTotal],
                    ['type' => 'total', 'amount' => $itemTotal],
                ],
            ];
            if ($idAttr > 0) {
                $entry['item']['variant_id'] = (string) $idAttr;
            }
            $formatted[] = $entry;
        }

        $buyer = $this->extractBuyer($body['buyer'] ?? null);
        $inputFulfillment = (!empty($body['fulfillment']) && is_array($body['fulfillment'])) ? $body['fulfillment'] : null;

        $provisional = [
            'id_shop' => $this->idShop(),
            'currency' => $currency,
            'line_items' => $formatted,
            'buyer' => $buyer,
            'fulfillment' => $inputFulfillment,
        ];

        [$totals, $fulfillment] = $this->priceAndFulfill($provisional, $formatted, $inputFulfillment);

        $paymentMeta = $this->registry->prepareAll($this->prepareInput(self::uuid(), $totals, $currency));

        $uid = self::uuid();
        $secret = bin2hex(random_bytes(32));
        $now = date('Y-m-d H:i:s');
        $this->sessions->insert([
            'session_uid' => $uid,
            'id_shop' => $this->idShop(),
            'status' => 'incomplete',
            'currency' => $currency,
            'line_items' => json_encode($formatted),
            'totals' => json_encode($totals),
            'buyer' => $buyer ? json_encode($buyer) : null,
            'fulfillment' => $fulfillment ? json_encode($fulfillment) : null,
            'payment_meta' => json_encode($paymentMeta),
            'agent_fingerprint' => $this->agentFingerprint,
            'session_secret_hash' => hash('sha256', $secret),
            'idempotency_key' => $idempotencyKey,
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => date('Y-m-d H:i:s', time() + 6 * 3600),
        ]);

        $session = $this->sessions->findByUid($uid, $this->idShop());
        $out = Formatter::checkoutSession($session, $this->registry);
        // The capability secret is returned exactly once, at creation. The agent
        // must store it and send it as `UCP-Session-Secret` on every later call.
        $out['session_secret'] = $secret;
        return Response::json(201, $out);
    }

    // ------------------------------------------------------------------- get

    public function get(string $uid): Response
    {
        $session = $this->sessions->findByUid($uid, $this->idShop());
        if (!$session) {
            return UcpError::response('checkout_not_found', 'Checkout session not found', 404);
        }
        if (!$this->ownsSession($session)) {
            return UcpError::response('session_ownership', 'Session belongs to a different agent', 403);
        }
        return Response::json(200, Formatter::checkoutSession($session, $this->registry));
    }

    // ---------------------------------------------------------------- update

    /** @param array<string,mixed> $body */
    public function update(string $uid, array $body): Response
    {
        $session = $this->sessions->findByUid($uid, $this->idShop());
        if (!$session) {
            return UcpError::response('checkout_not_found', 'Checkout session not found', 404);
        }
        if (!$this->ownsSession($session)) {
            return UcpError::response('session_ownership', 'Session belongs to a different agent', 403);
        }
        if (in_array($session['status'], ['canceled', 'completed', 'complete_in_progress'], true)) {
            return UcpError::response('session_' . $session['status'], 'Session is ' . $session['status'], 409);
        }

        $idLang = (int) $this->context->language->id;
        $formatted = json_decode($session['line_items'] ?? '[]', true) ?: [];
        $buyer = json_decode($session['buyer'] ?? 'null', true);
        $fulfillmentInput = json_decode($session['fulfillment'] ?? 'null', true);

        // Buyer
        if (!empty($body['buyer']) && is_array($body['buyer'])) {
            $buyer = array_merge(is_array($buyer) ? $buyer : [], $this->extractBuyer($body['buyer']) ?? []);
        }

        // Line items (full replace)
        if (!empty($body['line_items']) && is_array($body['line_items'])) {
            $formatted = [];
            foreach ($body['line_items'] as $item) {
                $idProduct = (int) ($item['item']['id'] ?? 0);
                $idAttr = (int) ($item['item']['variant_id'] ?? 0);
                $qty = (int) ($item['quantity'] ?? 1);
                if ($qty < 1) {
                    return UcpError::response('invalid_quantity', "Quantity for product $idProduct must be a positive integer", 422);
                }
                $product = new \Product($idProduct, false, $idLang);
                if (!\Validate::isLoadedObject($product) || !$product->active) {
                    return UcpError::response('invalid_product', "Product $idProduct not found", 422);
                }
                if ($stockError = $this->checkStock($idProduct, $idAttr, $qty, $product)) {
                    return $stockError;
                }
                $price = Formatter::toMinor((float) \Product::getPriceStatic($idProduct, true, $idAttr ?: null));
                $itemTotal = $price * $qty;
                $name = is_array($product->name) ? ($product->name[$idLang] ?? reset($product->name)) : $product->name;
                $entry = [
                    'id' => $item['id'] ?? ('li_' . (count($formatted) + 1)),
                    'item' => ['id' => (string) $idProduct, 'title' => (string) $name, 'price' => $price],
                    'quantity' => $qty,
                    'totals' => [
                        ['type' => 'subtotal', 'amount' => $itemTotal],
                        ['type' => 'total', 'amount' => $itemTotal],
                    ],
                ];
                if ($idAttr > 0) {
                    $entry['item']['variant_id'] = (string) $idAttr;
                }
                $formatted[] = $entry;
            }
        }

        // Fulfillment (destination and/or selected option)
        if (!empty($body['fulfillment']) && is_array($body['fulfillment'])) {
            $fulfillmentInput = $body['fulfillment'];
        }

        $provisional = [
            'id_shop' => $this->idShop(),
            'currency' => $session['currency'],
            'line_items' => $formatted,
            'buyer' => $buyer,
            'fulfillment' => $fulfillmentInput,
        ];
        [$totals, $fulfillment] = $this->priceAndFulfill($provisional, $formatted, $fulfillmentInput);

        $updates = [
            'line_items' => json_encode($formatted),
            'totals' => json_encode($totals),
            'buyer' => $buyer ? json_encode($buyer) : null,
            'fulfillment' => $fulfillment ? json_encode($fulfillment) : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        // Re-prepare payment if the total changed.
        $oldTotal = $this->totalOf(json_decode($session['totals'] ?? '[]', true) ?: []);
        $newTotal = $this->totalOf($totals);
        if ($oldTotal !== $newTotal || empty($session['payment_meta']) || $session['payment_meta'] === 'null') {
            $paymentMeta = $this->registry->prepareAll(
                $this->prepareInput($uid, $totals, (string) $session['currency'])
            );
            $updates['payment_meta'] = json_encode($paymentMeta);
        }

        $this->sessions->update($uid, $this->idShop(), $updates);
        $session = $this->sessions->findByUid($uid, $this->idShop());
        return Response::json(200, Formatter::checkoutSession($session, $this->registry));
    }

    // ---------------------------------------------------------------- cancel

    public function cancel(string $uid): Response
    {
        $session = $this->sessions->findByUid($uid, $this->idShop());
        if (!$session) {
            return UcpError::response('checkout_not_found', 'Checkout session not found', 404);
        }
        if (!$this->ownsSession($session)) {
            return UcpError::response('session_ownership', 'Session belongs to a different agent', 403);
        }
        if ($session['status'] === 'completed') {
            return UcpError::response('session_completed', 'Cannot cancel a completed session', 409);
        }

        $this->sessions->update($uid, $this->idShop(), [
            'status' => 'canceled',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $session['status'] = 'canceled';
        return Response::json(200, Formatter::checkoutSession($session, $this->registry));
    }

    // -------------------------------------------------------------- complete

    /** @param array<string,mixed> $body */
    public function complete(string $uid, array $body, ?string $idempotencyKey): Response
    {
        // Idempotency: replay a prior completion (NFR-3). Only the owner of the
        // prior session (capability-secret holder) may replay it.
        if ($idempotencyKey) {
            $prior = $this->sessions->findByIdempotencyKey($idempotencyKey, $this->idShop());
            if ($prior && $this->ownsSession($prior) && $prior['status'] === 'completed' && !empty($prior['id_order'])) {
                $order = new \Order((int) $prior['id_order']);
                if (\Validate::isLoadedObject($order)) {
                    return Response::json(200, Formatter::completeResponse($prior, $order, $this->registry));
                }
            }
        }

        $session = $this->sessions->findByUid($uid, $this->idShop());
        if (!$session) {
            return UcpError::response('checkout_not_found', 'Checkout session not found', 404);
        }
        if (!$this->ownsSession($session)) {
            return UcpError::response('session_ownership', 'Session belongs to a different agent', 403);
        }
        if (in_array($session['status'], ['canceled', 'completed'], true)) {
            return UcpError::response('session_' . $session['status'], 'Session is ' . $session['status'], 409);
        }

        $payment = $body['payment'] ?? null;
        $instrument = $payment['instruments'][0] ?? null;
        if (!is_array($instrument) || empty($instrument['handler_id']) || !isset($instrument['credential'])) {
            return UcpError::response('invalid_instrument', 'payment.instruments[0].handler_id and credential are required', 400);
        }
        $handlerId = (string) $instrument['handler_id'];
        if (!$this->registry->get($handlerId)) {
            return UcpError::response('unknown_handler', "Unknown payment handler: $handlerId", 422);
        }

        // Atomic, once-only claim (NFR-2).
        if (!$this->sessions->claimForCompletion($uid, $this->idShop())) {
            return UcpError::response('session_in_progress', 'Session is already being completed', 409);
        }

        try {
            $cart = $this->cartBuilder->build($session, $this->context);
        } catch (\Throwable $e) {
            $this->sessions->update($uid, $this->idShop(), ['status' => 'incomplete']);
            \PrestaShopLogger::addLog('[FD UCP] Cart build failed: ' . $e->getMessage(), 3);
            return UcpError::response('cart_build_failed', 'Could not build the cart for this session', 422);
        }

        $result = $this->registry->settle($handlerId, [
            'session' => $session,
            'cart' => $cart,
            'handler_id' => $handlerId,
            'credential' => $instrument['credential'],
            'checkout_meta' => json_decode($session['payment_meta'] ?? 'null', true),
        ]);

        if (empty($result['success']) || empty($result['id_order'])) {
            $this->sessions->update($uid, $this->idShop(), ['status' => 'incomplete']);
            return UcpError::response('payment_failed', $result['error'] ?? 'Payment settlement failed', 422);
        }

        $paymentMeta = json_decode($session['payment_meta'] ?? '{}', true) ?: [];
        if (!empty($result['transaction_reference'])) {
            $paymentMeta['transaction_reference'] = $result['transaction_reference'];
        }
        if (!empty($result['network'])) {
            $paymentMeta['network'] = $result['network'];
        }

        $completionUpdate = [
            'status' => 'completed',
            'id_order' => (int) $result['id_order'],
            'payment_meta' => json_encode($paymentMeta),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        // Bind the idempotency key to the session so a replay returns this
        // same order instead of erroring (NFR-3).
        if ($idempotencyKey) {
            $completionUpdate['idempotency_key'] = $idempotencyKey;
        }
        $this->sessions->update($uid, $this->idShop(), $completionUpdate);

        $session = $this->sessions->findByUid($uid, $this->idShop());
        $order = new \Order((int) $result['id_order']);
        return Response::json(200, Formatter::completeResponse($session, $order, $this->registry));
    }

    // --------------------------------------------------------------- helpers

    /**
     * Build a transient Cart to price the session and (if an address is set)
     * produce the UCP fulfillment block.
     *
     * @param array<string,mixed> $provisional
     * @param array<int,array<string,mixed>> $formatted
     * @param array<string,mixed>|null $inputFulfillment
     * @return array{0:array<int,array<string,mixed>>,1:array<string,mixed>|null}
     */
    private function priceAndFulfill(array $provisional, array $formatted, ?array $inputFulfillment): array
    {
        $cart = $this->cartBuilder->build($provisional, $this->context);

        // Subtotal is the sum of the line items (catalog prices), so it always
        // matches what the agent sees per item. The Cart is used only to price
        // shipping (and later to create the order).
        $subtotal = 0;
        foreach ($formatted as $li) {
            $subtotal += $this->totalOf($li['totals'] ?? []);
        }

        $dest = $inputFulfillment['methods'][0]['destinations'][0] ?? null;
        $fulfillment = null;
        $shipping = 0;
        if (is_array($dest) && !empty($dest['address_country']) && (int) $cart->id_address_delivery > 0) {
            $selected = Fulfillment::selectedCarrierId($inputFulfillment);
            if ($selected !== null && ctype_digit($selected)) {
                $this->cartBuilder->selectCarrier($cart, (int) $selected);
            }
            $shipping = $this->cartBuilder->totals($cart)['shipping'];
            $lineItemIds = array_column($formatted, 'id');
            $fulfillment = Fulfillment::fromCart($cart, $dest, $lineItemIds, $selected);
        }

        $totals = [['type' => 'subtotal', 'amount' => $subtotal]];
        if ($shipping > 0) {
            $totals[] = ['type' => 'fulfillment', 'amount' => $shipping];
        }
        $totals[] = ['type' => 'total', 'amount' => $subtotal + $shipping];

        return [$totals, $fulfillment];
    }

    /**
     * @param array<int,array<string,mixed>> $totals
     */
    private function totalOf(array $totals): int
    {
        foreach ($totals as $t) {
            if (($t['type'] ?? '') === 'total') {
                return (int) $t['amount'];
            }
        }
        return 0;
    }

    /**
     * @param array<int,array<string,mixed>> $totals
     * @return array<string,mixed>
     */
    private function prepareInput(string $uid, array $totals, string $currency): array
    {
        return [
            'checkout_id' => $uid,
            'total' => $this->totalOf($totals),
            'currency' => $currency,
            'checkout_base_url' => $this->endpointBase,
            'store_name' => \Configuration::get('PS_SHOP_NAME') ?: 'PrestaShop',
            'checkout_meta' => null,
        ];
    }

    /**
     * @param mixed $raw
     * @return array<string,string>|null
     */
    private function extractBuyer($raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $buyer = [];
        if (!empty($raw['email']) && \Validate::isEmail($raw['email'])) {
            $buyer['email'] = (string) $raw['email'];
        }
        if (!empty($raw['first_name'])) {
            $buyer['first_name'] = (string) $raw['first_name'];
        }
        if (!empty($raw['last_name'])) {
            $buyer['last_name'] = (string) $raw['last_name'];
        }
        return $buyer === [] ? null : $buyer;
    }

    /**
     * A session is owned by whoever presents the capability secret minted at
     * creation. The secret is never echoed back after create, so it can't be
     * lifted from a later response or a spoofable header (unlike the old
     * agent-fingerprint check). Sessions created before 0.5.0 have no stored
     * hash and remain accessible (legacy compatibility).
     *
     * @param array<string,mixed> $session
     */
    private function ownsSession(array $session): bool
    {
        $storedHash = (string) ($session['session_secret_hash'] ?? '');
        if ($storedHash === '') {
            return true;
        }
        if ($this->sessionSecret === '') {
            return false;
        }
        return hash_equals($storedHash, hash('sha256', $this->sessionSecret));
    }

    /**
     * Reject a line item whose quantity exceeds available stock, unless the
     * product allows ordering when out of stock (backorders). Returns a UcpError
     * Response on failure, or null when the quantity is orderable.
     */
    private function checkStock(int $idProduct, int $idAttr, int $qty, \Product $product): ?Response
    {
        if (\Product::isAvailableWhenOutOfStock((int) $product->out_of_stock)) {
            return null;
        }
        $available = (int) \StockAvailable::getQuantityAvailableByProduct($idProduct, $idAttr ?: null);
        if ($qty > $available) {
            return UcpError::response(
                'insufficient_stock',
                "Requested quantity ($qty) exceeds available stock ($available) for product $idProduct",
                422
            );
        }

        return null;
    }

    private static function uuid(): string
    {
        $d = sprintf(
            '%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff),
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );
        return $d;
    }
}
