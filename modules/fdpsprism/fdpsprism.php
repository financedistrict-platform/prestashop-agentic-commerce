<?php
/**
 * Finance District — Prism UCP payment handler.
 *
 * Settles agent (x402) payments on-chain via the Prism gateway and places the
 * paid PrestaShop order. Registers with the UCP core (fdpsucp) via the
 * actionUcpCollectPaymentHandlers hook. Gateway URL + API key are configured
 * in this module's own BO screen, per shop (woo model — see ConfigResolver).
 *
 * Ported from woocommerce-prism-payment.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/src/autoload.php';

use FD\PrismPayment\Config\ConfigResolver;

class FdPsPrism extends PaymentModule
{
    public function __construct()
    {
        $this->name = 'fdpsprism';
        $this->tab = 'payments_gateways';
        $this->version = '0.1.1';
        $this->author = 'Finance District';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        parent::__construct();

        $this->displayName = $this->trans('FD Prism Payment', [], 'Modules.Fdpsprism.Admin');
        $this->description = $this->trans(
            'Prism stablecoin payment handler for the UCP checkout flow. Settles AI-agent payments on-chain via the Prism gateway.',
            [],
            'Modules.Fdpsprism.Admin'
        );
        $this->confirmUninstall = $this->trans('Are you sure you want to uninstall the Prism UCP payment handler?', [], 'Modules.Fdpsprism.Admin');
    }

    public function install(): bool
    {
        return parent::install() && $this->registerHook('actionUcpCollectPaymentHandlers');
    }

    /**
     * Contribute the Prism handler to the UCP payment registry.
     *
     * @param array{registry: \FD\PrismUcp\Payment\PaymentRegistry} $params
     */
    public function hookActionUcpCollectPaymentHandlers(array $params): void
    {
        if (empty($params['registry'])) {
            return;
        }
        require_once __DIR__ . '/src/Prism/PrismHandler.php';
        $params['registry']->register(new \FD\PrismPayment\Prism\PrismHandler($this));
    }

    // ----------------------------------------------------------- BO settings

    public function getContent(): string
    {
        $output = '';

        if (Tools::isSubmit('submitFdpsprism')) {
            $apiUrl = trim((string) Tools::getValue(ConfigResolver::KEY_URL));
            $apiKey = trim((string) Tools::getValue(ConfigResolver::KEY_API));

            if ($apiUrl !== '' && !Validate::isUrl($apiUrl)) {
                $output .= $this->displayError($this->trans('The gateway URL is not a valid URL.', [], 'Modules.Fdpsprism.Admin'));
            } else {
                Configuration::updateValue(ConfigResolver::KEY_URL, $apiUrl);
                Configuration::updateValue(ConfigResolver::KEY_API, $apiKey);
                $output .= $this->displayConfirmation($this->trans('Settings updated.', [], 'Modules.Fdpsprism.Admin'));
            }
        }

        return $output . $this->renderForm();
    }

    private function renderForm(): string
    {
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Prism gateway', [], 'Modules.Fdpsprism.Admin'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('Prism gateway URL', [], 'Modules.Fdpsprism.Admin'),
                        'name' => ConfigResolver::KEY_URL,
                        'desc' => $this->trans('Prism gateway API base URL. Leave blank to use the production gateway. For testnet, enter your staging gateway URL.', [], 'Modules.Fdpsprism.Admin'),
                        'placeholder' => ConfigResolver::DEFAULT_URL,
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->trans('Prism API key', [], 'Modules.Fdpsprism.Admin'),
                        'name' => ConfigResolver::KEY_API,
                        'desc' => $this->trans('Your API key from the Prism Console for this store. Stored per shop.', [], 'Modules.Fdpsprism.Admin'),
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Admin.Actions'),
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitFdpsprism';
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->fields_value = [
            ConfigResolver::KEY_URL => Configuration::get(ConfigResolver::KEY_URL),
            ConfigResolver::KEY_API => Configuration::get(ConfigResolver::KEY_API),
        ];

        return $helper->generateForm([$fields_form]);
    }
}
