<?php

namespace FD\PrismUcp\Cart;

use FD\PrismUcp\Checkout\CheckoutService;
use FD\PrismUcp\Checkout\LineItems;
use FD\PrismUcp\Http\Response;
use FD\PrismUcp\Payment\PaymentRegistry;
use FD\PrismUcp\Ucp\CapabilitySecret;
use FD\PrismUcp\Ucp\RequestContext;
use FD\PrismUcp\Ucp\UcpError;
use FD\PrismUcp\Ucp\VersionPin;

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
        private string $agentFingerprint,
        private string $sessionSecret = '',
        private ?VersionPin $pin = null
    ) {
        $this->carts = new CartRepository();
    }

    private function idShop(): int
    {
        return (int) $this->context->shop->id;
    }

    private function ownedCart(string $uid): array|Response
    {
        if ($this->sessionSecret === '') {
            return UcpError::response('session_secret_required', 'Send the UCP-Session-Secret header returned when this cart was created', 401);
        }
        $cart = $this->carts->findByUid($uid, $this->idShop());
        if (!$cart || !CapabilitySecret::holds((string) ($cart['cart_secret_hash'] ?? ''), $this->sessionSecret)) {
            return UcpError::response('cart_not_found', 'Cart not found', 404);
        }

        return $cart;
    }

    // ---------------------------------------------------------------- create

    /** @param array<string,mixed> $body */
    public function create(array $body, ?string $idempotencyKey): Response
    {
        $lineItems = $body['line_items'] ?? null;
        if (!is_array($lineItems) || $lineItems === []) {
            return UcpError::response('missing_line_items', 'line_items array is required', 400);
        }

        $formatted = LineItems::format($lineItems, (int) $this->context->language->id);
        if ($formatted instanceof Response) {
            return $formatted;
        }

        $uid = self::uuid();
        $issued = CapabilitySecret::issue();
        $now = date('Y-m-d H:i:s');
        $this->carts->insert([
            'cart_uid' => $uid,
            'id_shop' => $this->idShop(),
            'line_items' => json_encode($formatted),
            'agent_fingerprint' => $this->agentFingerprint,
            'cart_secret_hash' => $issued['hash'],
            'ucp_version' => RequestContext::current()->sessionPin(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $cart = $this->carts->findByUid($uid, $this->idShop());

        $out = $this->formatCart($cart);

        return Response::json(201, $out)->withHeader(CapabilitySecret::HEADER, $issued['secret']);
    }

    // ------------------------------------------------------------------- get

    public function get(string $uid): Response
    {
        $cart = $this->ownedCart($uid);
        if ($cart instanceof Response) {
            return $cart;
        }
        if ($pinError = $this->pin?->check($cart['ucp_version'] ?? null)) {
            return $pinError;
        }

        return Response::json(200, $this->formatCart($cart));
    }

    // ---------------------------------------------------------------- update

    /** @param array<string,mixed> $body */
    public function update(string $uid, array $body): Response
    {
        $cart = $this->ownedCart($uid);
        if ($cart instanceof Response) {
            return $cart;
        }
        if ($pinError = $this->pin?->check($cart['ucp_version'] ?? null)) {
            return $pinError;
        }

        $lineItems = $body['line_items'] ?? null;
        if (!is_array($lineItems) || $lineItems === []) {
            return UcpError::response('missing_line_items', 'line_items array is required', 400);
        }

        $formatted = LineItems::format($lineItems, (int) $this->context->language->id);
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
        $cart = $this->ownedCart($uid);
        if ($cart instanceof Response) {
            return $cart;
        }
        if ($pinError = $this->pin?->check($cart['ucp_version'] ?? null)) {
            return $pinError;
        }

        $this->carts->delete($uid, $this->idShop());

        return Response::json(200, [
            'ucp' => RequestContext::current()->wire()->envelope(['cart']),
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
        $cart = $this->ownedCart($uid);
        if ($cart instanceof Response) {
            return $cart;
        }
        if ($pinError = $this->pin?->check($cart['ucp_version'] ?? null)) {
            return $pinError;
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
            $this->agentFingerprint,
            '',
            $this->pin
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
            'ucp' => RequestContext::current()->wire()->envelope(['cart']),
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
