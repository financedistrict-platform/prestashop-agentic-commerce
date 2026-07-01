<?php
/**
 * Finance District — Dummy UCP payment handler.
 *
 * A no-gateway payment handler that always succeeds. Lets the full UCP
 * checkout flow (create -> ... -> complete -> paid order) be exercised locally
 * without Prism or any crypto. Registers itself with the UCP core (fdpsucp)
 * via the actionUcpCollectPaymentHandlers hook.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class FdPsDummy extends PaymentModule
{
    public function __construct()
    {
        $this->name = 'fdpsdummy';
        $this->tab = 'payments_gateways';
        $this->version = '0.1.0';
        $this->author = 'Finance District';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        parent::__construct();

        $this->displayName = $this->trans('Finance District UCP — Dummy Payment', [], 'Modules.Fdpsdummy.Admin');
        $this->description = $this->trans(
            'Test payment handler for the UCP checkout flow. Always succeeds; no real gateway. For local testing only.',
            [],
            'Modules.Fdpsdummy.Admin'
        );
        $this->confirmUninstall = $this->trans('Are you sure you want to uninstall the Dummy UCP payment handler?', [], 'Modules.Fdpsdummy.Admin');
    }

    public function install(): bool
    {
        return parent::install() && $this->registerHook('actionUcpCollectPaymentHandlers');
    }

    /**
     * Contribute the dummy handler to the UCP payment registry.
     *
     * @param array{registry: \FD\PrismUcp\Payment\PaymentRegistry} $params
     */
    public function hookActionUcpCollectPaymentHandlers(array $params): void
    {
        if (empty($params['registry'])) {
            return;
        }
        require_once __DIR__ . '/src/DummyHandler.php';
        $params['registry']->register(new \FD\PrismDummy\DummyHandler($this));
    }
}
