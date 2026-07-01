<?php

namespace FD\PrismUcp\Cart;

use FD\PrismUcp\Checkout\CheckoutService;
use FD\PrismUcp\Http\Response;
use FD\PrismUcp\Payment\PaymentRegistry;
use FD\PrismUcp\Ucp\Formatter;
use FD\PrismUcp\Ucp\UcpError;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * UCP cart lifecycle (dev.ucp.shopping.cart). Ported from
 * FD_UCP_Cart_Controller, adapted to PrestaShop: cart state lives in
 * ps_prism_cart and holds line items only (catalog prices, no shipping —
 * there is no address yet). On checkout the cart is converted into a
 * canonical ps_prism_session via CheckoutService and then discarded.
 */
final class CartService
{
    private CartRepository $carts;

    public function __construct(
        private \Context $context,
        private PaymentRegistry $registry,
        private string $endpointBase,
        private string $agentFingerprint
    ) {
        $this->carts = new CartRepository();
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

        $formatted = $this->formatLineItems($lineItems);
        if ($formatted instanceof Response) {
            return $formatted;
        }

        $uid = self::uuid();
        $now = date('Y-m-d H:i:s');
        $this->carts->insert([
            'cart_uid' => $uid,
            'id_shop' => $this->idShop(),
            'line_items' => json_encode($formatted),
            'agent_fingerprint' => $this->agentFingerprint,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $cart = $this->carts->findByUid($uid, $this->idShop());

        return Response::json(201, $this->formatCart($cart));
    }

    // ------------------------------------------------------------------- get

    public function get(string $uid): Response
    {
        $cart = $this->carts->findByUid($uid, $this->idShop());
        if (!$cart) {
            return UcpError::response('cart_not_found', 'Cart not found', 404);
        }
        if (!$this->ownsCart($cart)) {
            return UcpError::response('cart_ownership', 'Cart belongs to a different agent', 403);
        }

        return Response::json(200, $this->formatCart($cart));
    }

    // ---------------------------------------------------------------- update

    /** @param array<string,mixed> $body */
    public function update(string $uid, array $body): Response
    {
        $cart = $this->carts->findByUid($uid, $this->idShop());
        if (!$cart) {
            return UcpError::response('cart_not_found', 'Cart not found', 404);
        }
        if (!$this->ownsCart($cart)) {
            return UcpError::response('cart_ownership', 'Cart belongs to a different agent', 403);
        }

        $lineItems = $body['line_items'] ?? null;
        if (!is_array($lineItems) || $lineItems === []) {
            return UcpError::response('missing_line_items', 'line_items array is required', 400);
        }

        $formatted = $this->formatLineItems($lineItems);
        if ($formatted instanceof Response) {
            return $formatted;
        }

        $this->carts->update($uid, $this->idShop(), [
            'line_items' => json_encode($formatted),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $cart = $this->carts->findByUid($uid, $this->idShop());

        return Response::json(200, $this->formatCart($cart));
    }

    // ---------------------------------------------------------------- delete

    public function delete(string $uid): Response
    {
        $cart = $this->carts->findByUid($uid, $this->idShop());
        if (!$cart) {
            return UcpError::response('cart_not_found', 'Cart not found', 404);
        }
        if (!$this->ownsCart($cart)) {
            return UcpError::response('cart_ownership', 'Cart belongs to a different agent', 403);
        }

        $this->carts->delete($uid, $this->idShop());

        return Response::json(200, [
            'ucp' => [
                'version' => Formatter::UCP_VERSION,
                'status' => 'success',
                'capabilities' => [
                    'dev.ucp.shopping.cart' => [['version' => Formatter::UCP_VERSION]],
                ],
            ],
            'id' => $uid,
            'deleted' => true,
            'messages' => [],
        ]);
    }

    // -------------------------------------------------------------- checkout

    /**
     * Convert the cart into a checkout session. Delegates to CheckoutService so
     * pricing, fulfillment and payment-handler preparation follow the single
     * canonical session-creation path; the cart is discarded once the session
     * exists. The agent continues against /checkout-sessions/{id}.
     */
    public function checkout(string $uid, ?string $idempotencyKey): Response
    {
        $cart = $this->carts->findByUid($uid, $this->idShop());
        if (!$cart) {
            return UcpError::response('cart_not_found', 'Cart not found', 404);
        }
        if (!$this->ownsCart($cart)) {
            return UcpError::response('cart_ownership', 'Cart belongs to a different agent', 403);
        }

        $stored = json_decode($cart['line_items'] ?? '[]', true) ?: [];
        if ($stored === []) {
            return UcpError::response('empty_cart', 'Cart has no line items', 422);
        }

        // Rebuild the create body in agent (item/variant) shape from the cart.
        $sessionLineItems = [];
        foreach ($stored as $li) {
            $entry = [
                'item' => ['id' => $li['item']['id'] ?? ''],
                'quantity' => $li['quantity'] ?? 1,
            ];
            if (!empty($li['item']['variant_id'])) {
                $entry['item']['variant_id'] = $li['item']['variant_id'];
            }
            $sessionLineItems[] = $entry;
        }

        $checkout = new CheckoutService(
            $this->context,
            $this->registry,
            $this->endpointBase,
            $this->agentFingerprint
        );
        $response = $checkout->create(['line_items' => $sessionLineItems], $idempotencyKey);

        // Only discard the cart once the session was actually created.
        if ($response->status === 201) {
            $this->carts->delete($uid, $this->idShop());
        }

        return $response;
    }

    // --------------------------------------------------------------- helpers

    /**
     * Build formatted line items from agent input, or return a UcpError
     * Response if any product is invalid. Mirrors CheckoutService line pricing.
     *
     * @param array<int,mixed> $lineItems
     * @return array<int,array<string,mixed>>|Response
     */
    private function formatLineItems(array $lineItems)
    {
        $idLang = (int) $this->context->language->id;
        $formatted = [];

        foreach ($lineItems as $item) {
            $idProduct = (int) ($item['item']['id'] ?? 0);
            $idAttr = (int) ($item['item']['variant_id'] ?? 0);
            $qty = max(1, (int) ($item['quantity'] ?? 1));

            $product = new \Product($idProduct, false, $idLang);
            if (!\Validate::isLoadedObject($product) || !$product->active) {
                return UcpError::response('invalid_product', "Product $idProduct not found or not purchasable", 422);
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

        return $formatted;
    }

    /**
     * @param array<string,mixed> $cart
     * @return array<string,mixed>
     */
    private function formatCart(array $cart): array
    {
        $lineItems = json_decode($cart['line_items'] ?? '[]', true) ?: [];
        $subtotal = 0;
        foreach ($lineItems as $li) {
            foreach ($li['totals'] ?? [] as $t) {
                if (($t['type'] ?? '') === 'subtotal') {
                    $subtotal += (int) $t['amount'];
                }
            }
        }

        return [
            'ucp' => [
                'version' => Formatter::UCP_VERSION,
                'status' => 'success',
                'capabilities' => [
                    'dev.ucp.shopping.cart' => [['version' => Formatter::UCP_VERSION]],
                ],
            ],
            'id' => $cart['cart_uid'],
            'currency' => $this->context->currency->iso_code,
            'line_items' => $lineItems,
            'totals' => [
                ['type' => 'subtotal', 'amount' => $subtotal],
                ['type' => 'total', 'amount' => $subtotal],
            ],
            'messages' => [],
        ];
    }

    /** @param array<string,mixed> $cart */
    private function ownsCart(array $cart): bool
    {
        $stored = (string) ($cart['agent_fingerprint'] ?? '');
        if ($stored === '') {
            return true;
        }

        return hash_equals($stored, $this->agentFingerprint);
    }

    private static function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff),
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );
    }
}
