<?php

namespace FD\PrismUcp\Orders;

use FD\PrismUcp\Http\Response;
use FD\PrismUcp\Ucp\CapabilitySecret;
use FD\PrismUcp\Ucp\Formatter;
use FD\PrismUcp\Ucp\UcpError;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * UCP orders read endpoint. Ported from FD_UCP_Order_Controller. Only orders
 * placed through UCP (linked in ps_prism_session) are exposed, scoped to shop.
 */
final class OrderService
{
    public function __construct(private \Context $context, private string $sessionSecret = '')
    {
    }

    public function get(int $idOrder): Response
    {
        $idShop = (int) $this->context->shop->id;

        // Order must exist, belong to this shop, and have been placed via UCP.
        $row = \Db::getInstance()->getRow(
            'SELECT `id_order`, `session_secret_hash` FROM `' . _DB_PREFIX_ . 'prism_session`
             WHERE `id_order` = ' . (int) $idOrder . ' AND `id_shop` = ' . $idShop
        );
        if (!$row || (int) $row['id_order'] === 0) {
            return UcpError::response('order_not_found', 'Order not found', 404);
        }

        // Only the agent that owns the originating session (capability-secret
        // holder) may read the order. A generic 404 avoids confirming existence
        // to a non-owner — so both a missing and a wrong secret map to 404 here,
        // unlike the cart/session endpoints which distinguish the two.
        $storedHash = (string) ($row['session_secret_hash'] ?? '');
        if (!CapabilitySecret::authorizes($storedHash, $this->sessionSecret)) {
            return UcpError::response('order_not_found', 'Order not found', 404);
        }

        $order = new \Order($idOrder);
        if (!\Validate::isLoadedObject($order) || (int) $order->id_shop !== $idShop) {
            return UcpError::response('order_not_found', 'Order not found', 404);
        }

        return Response::json(200, Formatter::order($order, (int) $this->context->language->id));
    }
}
