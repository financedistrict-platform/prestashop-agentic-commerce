<?php

namespace FD\PrismUcp;

use FD\PrismUcp\Cart\CartService;
use FD\PrismUcp\Catalog\CatalogService;
use FD\PrismUcp\Checkout\CheckoutService;
use FD\PrismUcp\Http\Response;
use FD\PrismUcp\Orders\OrderService;
use FD\PrismUcp\Payment\PaymentRegistry;
use FD\PrismUcp\Ucp\UcpError;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Dispatches a UCP REST request (method + path under the shopping endpoint)
 * to the right service. The single PrestaShop front controller delegates here.
 */
final class Router
{
    public function __construct(
        private \Context $context,
        private PaymentRegistry $registry,
        private string $endpointBase,
        private string $agentFingerprint
    ) {
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $headers lowercased header name => value
     */
    public function dispatch(string $method, string $path, array $body, array $headers): Response
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn ($s) => $s !== ''));
        $method = strtoupper($method);
        $idempotencyKey = $headers['idempotency-key'] ?? null;

        // Per-resource capability secret: minted at create, returned once, and
        // required on every later read/mutation. This — not the spoofable
        // UCP-Agent header — is what makes a session/cart private to its creator.
        //
        // Accept either header name: `UCP-Session-Secret` (checkout sessions,
        // returned as `session_secret`) or `UCP-Cart-Secret` (carts, returned as
        // `cart_secret`). Same value space; the hash check validates it. Reading
        // both means the header name matches the field name the caller was given,
        // instead of forcing a cart's `cart_secret` into a `*-Session-*` header.
        $sessionSecret = (string) ($headers['ucp-session-secret'] ?? $headers['ucp-cart-secret'] ?? '');

        // /catalog/...
        if (($segments[0] ?? '') === 'catalog') {
            $catalog = new CatalogService($this->context);
            if ($method === 'POST' && ($segments[1] ?? '') === 'search') {
                return $catalog->search($body);
            }
            if ($method === 'POST' && ($segments[1] ?? '') === 'lookup') {
                return $catalog->lookup($body);
            }
            return UcpError::response('not_found', 'Unknown catalog route', 404);
        }

        // /carts[/{id}[/checkout]]
        if (($segments[0] ?? '') === 'carts') {
            $cart = new CartService($this->context, $this->registry, $this->endpointBase, $this->agentFingerprint, $sessionSecret);
            $id = $segments[1] ?? null;
            $action = $segments[2] ?? null;

            if ($id === null) {
                if ($method === 'POST') {
                    return $cart->create($body, $idempotencyKey);
                }
                return UcpError::response('method_not_allowed', 'Use POST to create a cart', 405);
            }
            if ($action === 'checkout' && $method === 'POST') {
                return $cart->checkout($id, $idempotencyKey);
            }
            if ($action === null && $method === 'GET') {
                return $cart->get($id);
            }
            if ($action === null && in_array($method, ['PUT', 'PATCH'], true)) {
                return $cart->update($id, $body);
            }
            if ($action === null && $method === 'DELETE') {
                return $cart->delete($id);
            }
            return UcpError::response('not_found', 'Unknown cart route', 404);
        }

        // /checkout-sessions[/{id}[/complete|cancel]]
        if (($segments[0] ?? '') === 'checkout-sessions') {
            $checkout = new CheckoutService($this->context, $this->registry, $this->endpointBase, $this->agentFingerprint, $sessionSecret);
            $id = $segments[1] ?? null;
            $action = $segments[2] ?? null;

            if ($id === null) {
                if ($method === 'POST') {
                    return $checkout->create($body, $idempotencyKey);
                }
                return UcpError::response('method_not_allowed', 'Use POST to create a session', 405);
            }
            if ($action === 'complete' && $method === 'POST') {
                return $checkout->complete($id, $body, $idempotencyKey);
            }
            if ($action === 'cancel' && $method === 'POST') {
                return $checkout->cancel($id);
            }
            if ($action === null && $method === 'GET') {
                return $checkout->get($id);
            }
            if ($action === null && in_array($method, ['PUT', 'PATCH'], true)) {
                return $checkout->update($id, $body);
            }
            return UcpError::response('not_found', 'Unknown checkout route', 404);
        }

        // /orders/{id}
        if (($segments[0] ?? '') === 'orders') {
            $order = new OrderService($this->context, $sessionSecret);
            $id = $segments[1] ?? null;
            if ($id !== null && $method === 'GET') {
                return $order->get((int) $id);
            }
            return UcpError::response('not_found', 'Unknown orders route', 404);
        }

        return UcpError::response('not_found', 'Unknown UCP route: ' . $path, 404);
    }
}
