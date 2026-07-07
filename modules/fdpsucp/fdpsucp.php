<?php
/**
 * Finance District — Universal Commerce Protocol (UCP) core module.
 *
 * Exposes a PrestaShop store as a UCP shopping surface (discovery, catalog,
 * checkout sessions, orders) so AI agents can browse and buy. Payment is
 * delegated to separate handler modules (fdpsdummy / fdpsprism) that register
 * via the `actionUcpCollectPaymentHandlers` hook.
 *
 * Ported from woocommerce-ucp (includes/ucp + includes/payment).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/src/autoload.php';

class FdPsUcp extends Module
{
    public const UCP_VERSION = '2026-04-08';

    public function __construct()
    {
        $this->name = 'fdpsucp';
        $this->tab = 'others';
        $this->version = '0.5.0';
        $this->author = 'Finance District';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Finance District UCP', [], 'Modules.Fdpsucp.Admin');
        $this->description = $this->trans(
            'Universal Commerce Protocol (UCP) endpoints — makes the store discoverable and purchasable by AI agents. Payment handlers are provided by separate modules.',
            [],
            'Modules.Fdpsucp.Admin'
        );
        $this->confirmUninstall = $this->trans('Are you sure you want to uninstall Finance District UCP?', [], 'Modules.Fdpsucp.Admin');
    }

    public function install(): bool
    {
        return parent::install()
            && $this->installDb()
            && $this->ensureAgentToken()
            && $this->registerHook('actionUcpCollectPaymentHandlers')
            && $this->registerHook('moduleRoutes');
    }

    /**
     * Closed-by-default posture (NFR-5): generate a high-entropy agent token on
     * install if none is set, so the write/PII endpoints are protected out of
     * the box. The merchant shares this token with the agent(s) they onboard;
     * discovery + catalog stay reachable, but everything else requires it.
     */
    private function ensureAgentToken(): bool
    {
        if ((string) Configuration::get('FDPSUCP_AGENT_TOKEN') === '') {
            Configuration::updateValue('FDPSUCP_AGENT_TOKEN', bin2hex(random_bytes(32)));
        }

        return true;
    }

    /**
     * Back-office screen: show the agent token so the merchant can hand it to
     * their agent, and let them regenerate it (which immediately invalidates
     * the old one).
     */
    public function getContent(): string
    {
        $output = '';

        if (Tools::isSubmit('submitFdpsucpRegenerate')) {
            Configuration::updateValue('FDPSUCP_AGENT_TOKEN', bin2hex(random_bytes(32)));
            $output .= $this->displayConfirmation(
                $this->trans('A new agent token was generated. Update your agent configuration — the old token no longer works.', [], 'Modules.Fdpsucp.Admin')
            );
        }

        $token = (string) Configuration::get('FDPSUCP_AGENT_TOKEN');
        $tokenHtml = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

        $intro = $this->trans('Agents must send this token on every request to the shopping endpoints (discovery and catalog aside):', [], 'Modules.Fdpsucp.Admin');
        $header = $this->trans('Authorization: Bearer <token>', [], 'Modules.Fdpsucp.Admin');
        $warn = $this->trans('Keep this secret. Anyone with it can create checkout sessions against your store.', [], 'Modules.Fdpsucp.Admin');
        $regen = $this->trans('Regenerate token', [], 'Modules.Fdpsucp.Admin');

        $action = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '', ENT_QUOTES, 'UTF-8');

        return $output . '
            <div class="panel">
                <h3><i class="icon icon-key"></i> ' . $this->trans('Agent authentication', [], 'Modules.Fdpsucp.Admin') . '</h3>
                <p>' . $intro . '</p>
                <pre>' . $header . '</pre>
                <div class="form-group">
                    <input type="text" class="form-control" readonly value="' . $tokenHtml . '" onclick="this.select();">
                </div>
                <p class="text-muted">' . $warn . '</p>
                <form method="post" action="' . $action . '">
                    <button type="submit" name="submitFdpsucpRegenerate" class="btn btn-default"
                            onclick="return confirm(\'' . $regen . '?\');">' . $regen . '</button>
                </form>
            </div>';
    }

    public function uninstall(): bool
    {
        return $this->uninstallDb() && parent::uninstall();
    }

    /**
     * Native PrestaShop routing for the UCP shopping service — the analog of
     * WordPress's register_rest_route (which is how the WooCommerce port exposes
     * `/wp-json/fd-ucp/v1`). Maps the clean, versioned namespace
     *   /ucp/v1/<ucp-path>   →   this module's `api` front controller
     * with the remainder captured into `ucp_path`, so no hand-written web-server
     * rewrite is needed for the API.
     *
     * Requires Friendly URLs (PS_REWRITING_SETTINGS); when off, discovery falls
     * back to advertising the front-controller URL. The spec-fixed `.well-known/ucp`
     * path is not a route namespace and stays a web-server rewrite (same as Woo).
     *
     * @param array<string,mixed> $params
     * @return array<string,array<string,mixed>>
     */
    public function hookModuleRoutes(array $params): array
    {
        return [
            'module-fdpsucp-api' => [
                'controller' => 'api',
                'rule' => 'ucp/v1/{ucp_path}',
                'keywords' => [
                    'ucp_path' => ['regexp' => '[/a-zA-Z0-9_\-]+', 'param' => 'ucp_path'],
                ],
                'params' => [
                    'fc' => 'module',
                    'module' => 'fdpsucp',
                    'controller' => 'api',
                ],
            ],
        ];
    }

    /**
     * Canonical session table. Holds the full UCP checkout-session state
     * (a PrestaShop Cart is used only transiently for pricing). `id_shop`
     * scopes every session to the shop it was created in (multistore, FR-15).
     */
    private function installDb(): bool
    {
        $engine = _MYSQL_ENGINE_;
        $sessionTable = _DB_PREFIX_ . 'prism_session';
        $cartTable = _DB_PREFIX_ . 'prism_cart';

        $sessionSql = "CREATE TABLE IF NOT EXISTS `$sessionTable` (
            `id_prism_session` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `session_uid` VARCHAR(64) NOT NULL,
            `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
            `status` VARCHAR(32) NOT NULL DEFAULT 'incomplete',
            `currency` VARCHAR(3) NOT NULL DEFAULT 'USD',
            `line_items` LONGTEXT NULL,
            `totals` LONGTEXT NULL,
            `buyer` LONGTEXT NULL,
            `fulfillment` LONGTEXT NULL,
            `payment_meta` LONGTEXT NULL,
            `id_order` INT UNSIGNED NULL,
            `agent_fingerprint` VARCHAR(128) NULL,
            `session_secret_hash` VARCHAR(64) NULL,
            `idempotency_key` VARCHAR(128) NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NULL,
            `expires_at` DATETIME NULL,
            PRIMARY KEY (`id_prism_session`),
            UNIQUE KEY `session_uid` (`session_uid`),
            KEY `idx_shop` (`id_shop`),
            KEY `idx_status` (`status`),
            KEY `idx_order` (`id_order`)
        ) ENGINE=$engine DEFAULT CHARSET=utf8mb4;";

        // Pre-checkout cart (dev.ucp.shopping.cart). Holds line items only; it
        // is converted into a ps_prism_session on checkout. Scoped per shop.
        $cartSql = "CREATE TABLE IF NOT EXISTS `$cartTable` (
            `id_prism_cart` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `cart_uid` VARCHAR(64) NOT NULL,
            `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
            `line_items` LONGTEXT NULL,
            `agent_fingerprint` VARCHAR(128) NULL,
            `cart_secret_hash` VARCHAR(64) NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`id_prism_cart`),
            UNIQUE KEY `cart_uid` (`cart_uid`),
            KEY `idx_shop` (`id_shop`)
        ) ENGINE=$engine DEFAULT CHARSET=utf8mb4;";

        // Durable per-IP rate-limit counters (NFR-5). Kept in the DB rather than
        // the optional PrestaShop cache so limiting works out of the box.
        $rateTable = _DB_PREFIX_ . 'prism_rate_limit';
        $rateSql = "CREATE TABLE IF NOT EXISTS `$rateTable` (
            `rl_key` VARCHAR(191) NOT NULL,
            `hits` INT UNSIGNED NOT NULL DEFAULT 0,
            `window_start` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`rl_key`),
            KEY `idx_window` (`window_start`)
        ) ENGINE=$engine DEFAULT CHARSET=utf8mb4;";

        return (bool) Db::getInstance()->execute($sessionSql)
            && (bool) Db::getInstance()->execute($cartSql)
            && (bool) Db::getInstance()->execute($rateSql);
    }

    private function uninstallDb(): bool
    {
        // Keep order data; only drop our own tables.
        return (bool) Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'prism_session`')
            && (bool) Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'prism_cart`')
            && (bool) Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'prism_rate_limit`');
    }
}
