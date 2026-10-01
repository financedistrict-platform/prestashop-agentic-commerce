<?php
/**
 * UCP discovery endpoint — the merchant's UCP profile.
 *
 * Reachable at:
 *   /index.php?fc=module&module=fdpsucp&controller=discovery   (always)
 *   /module/fdpsucp/discovery                                  (when friendly URLs are on)
 *   /.well-known/ucp                                           (via web-server rewrite → this controller)
 *
 * The advertised `endpoint` (the shopping service) is /module/fdpsucp/api, via a
 * web-server rewrite (works with Friendly URLs off, which the Back Office needs).
 *
 * Serves a per-shop profile (multistore): the shop is resolved by PrestaShop's
 * native domain dispatch, and the advertised payment_handlers reflect whatever
 * handler modules have registered via actionUcpCollectPaymentHandlers.
 */

use FD\PrismUcp\Payment\PaymentRegistry;
use FD\PrismUcp\Ucp\Formatter;
use FD\PrismUcp\Ucp\VersionRegistry;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'fdpsucp/src/autoload.php';

class FdPsUcpDiscoveryModuleFrontController extends ModuleFrontController
{
    /** No customer login required — agents are anonymous. */
    public $auth = false;
    public $ssl = true;

    public function initContent()
    {
        // The shopping service is served at /module/fdpsucp/api (via a web-server
        // rewrite to the api front controller). This works with Friendly URLs OFF,
        // which the PrestaShop admin requires to stay navigable in the official
        // image. (A clean /ucp/v1 route via hookModuleRoutes is available when
        // Friendly URLs are on, but that breaks Back-Office navigation here.)
        $storeBase = rtrim($this->context->link->getBaseLink(), '/');
        $endpoint = $storeBase . '/module/fdpsucp/api';
        $storeName = Configuration::get('PS_SHOP_NAME') ?: 'PrestaShop';
        $registry = PaymentRegistry::collect();

        $result = Formatter::discovery(
            VersionRegistry::fromConfiguration(),
            (string) Tools::getValue('ucp_version', ''),
            $registry,
            $storeBase,
            $endpoint,
            $storeName
        );

        http_response_code($result['status']);
        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=300');
        header('Access-Control-Allow-Origin: *');
        echo json_encode($result['body'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
}
