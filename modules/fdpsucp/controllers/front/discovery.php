<?php
/**
 * UCP discovery endpoint — the merchant's UCP profile.
 *
 * Reachable at:
 *   /index.php?fc=module&module=fdpsucp&controller=discovery   (always)
 *   /module/fdpsucp/discovery                                  (when friendly URLs are on)
 *   /.well-known/ucp                                           (via web-server rewrite → this controller)
 *
 * The advertised `endpoint` (the shopping service) is /ucp/v1, served natively
 * via the module's hookModuleRoutes (requires Friendly URLs).
 *
 * Serves a per-shop profile (multistore): the shop is resolved by PrestaShop's
 * native domain dispatch, and the advertised payment_handlers reflect whatever
 * handler modules have registered via actionUcpCollectPaymentHandlers.
 */

use FD\PrismUcp\Payment\PaymentRegistry;
use FD\PrismUcp\Ucp\Formatter;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'fdpsucp/src/autoload.php';

class FdPsUcpDiscoveryModuleFrontController extends ModuleFrontController
{
    /** No customer login required — agents are anonymous. */
    public $auth = false;
    public $ssl = false;

    public function initContent()
    {
        // The shopping service is served at the clean /ucp/v1 namespace via the
        // module's hookModuleRoutes. Requires Friendly URLs to be enabled.
        $endpoint = rtrim($this->context->link->getBaseLink(), '/') . '/ucp/v1';
        $storeName = Configuration::get('PS_SHOP_NAME') ?: 'PrestaShop';
        $registry = PaymentRegistry::collect();

        $profile = Formatter::profile($endpoint, $storeName, $registry);

        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=300');
        header('Access-Control-Allow-Origin: *');
        echo json_encode($profile, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
}
