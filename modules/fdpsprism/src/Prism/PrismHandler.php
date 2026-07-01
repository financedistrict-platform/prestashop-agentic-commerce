<?php

namespace FD\PrismPayment\Prism;

use FD\PrismPayment\Config\ConfigResolver;
use FD\PrismUcp\Payment\PaymentHandlerInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Prism x402 stablecoin payment handler. Ported from FD_Prism_Handler
 * (woocommerce-prism-payment), adapted to the PrestaShop UCP core:
 *  - prepare → ask Prism for x402 payment requirements for the session total
 *  - settle  → guard the signed credential (NFR-1), settle on-chain via Prism,
 *              then place the paid PrestaShop order via PaymentModule::validateOrder.
 */
final class PrismHandler implements PaymentHandlerInterface
{
    public const NS = 'xyz.fd.prism_payment';

    /**
     * Prism's own x402 handler version — independent of the UCP protocol version
     * advertised in the profile. Tracks Prism's handler, bumped per release.
     */
    private const HANDLER_VERSION = '2026-01-15';

    public function __construct(private \PaymentModule $module)
    {
    }

    // Must match the entry id Prism returns in the checkout config (and thus the
    // handler_id the agent submits at /complete) so CheckoutService can resolve it.
    public function id(): string
    {
        return 'x402';
    }

    public function name(): string
    {
        return 'Prism (x402 Stablecoin)';
    }

    private function client(): PrismClient
    {
        return new PrismClient(ConfigResolver::apiUrl(), ConfigResolver::apiKey());
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    public function getUcpDiscoveryHandlers(): array
    {
        // The Prism handler is an external component with its own version and docs:
        // spec/config_schema are served by the resolved gateway, not ucp.dev.
        $gateway = ConfigResolver::apiUrl();

        return [
            self::NS => [[
                'id' => $this->id(),
                'name' => $this->name(),
                'version' => self::HANDLER_VERSION,
                'spec' => $gateway . '/ucp/prism.md',
                'config_schema' => $gateway . '/ucp/schema.json',
                'instrument_schemas' => [],
                'config' => [
                    'tokenization' => false,
                    'description' => 'Pay with stablecoins via an AI agent wallet (x402). Settled on-chain by Prism.',
                ],
            ]],
        ];
    }

    /**
     * Ask Prism for x402 payment requirements for this session's total. Skips
     * the round-trip when the resource URL and amount are unchanged (idempotent).
     *
     * @param array{checkout_id:string,total:int,currency:string,checkout_base_url:string,store_name:string,checkout_meta:?array} $input
     * @return array<string,mixed>|null
     */
    public function prepareCheckoutPayment(array $input): ?array
    {
        if (!ConfigResolver::isConfigured()) {
            return null;
        }

        $total = (int) ($input['total'] ?? 0);
        $currency = (string) ($input['currency'] ?? 'USD');
        $sessionId = (string) ($input['checkout_id'] ?? '');
        $baseUrl = rtrim((string) ($input['checkout_base_url'] ?? ''), '/');
        $storeName = (string) ($input['store_name'] ?? 'PrestaShop');
        $existing = $input['checkout_meta'][$this->id()] ?? null;

        $resourceUrl = "$baseUrl/checkout-sessions/$sessionId";

        // Idempotency: reuse the prior quote if resource URL + amount are unchanged.
        if (is_array($existing)
            && ($existing['prepared_resource_url'] ?? '') === $resourceUrl
            && (int) ($existing['prepared_amount'] ?? -1) === $total
        ) {
            return $existing;
        }

        $result = $this->client()->prepareUcpPayment(
            PrismClient::minorToMajorString($total),
            $currency,
            $resourceUrl,
            "Order checkout at $storeName"
        );

        if (!$result) {
            return is_array($existing) ? $existing : null;
        }

        return [
            'ucp' => $result,
            'prepared_amount' => $total,
            'prepared_resource_url' => $resourceUrl,
        ];
    }

    /**
     * Guard the credential, settle on-chain, then place the paid order.
     *
     * @param array{session:array,cart:\Cart,handler_id:string,credential:mixed,checkout_meta:?array} $input
     * @return array<string,mixed>
     */
    public function settlePayment(array $input): array
    {
        if (!ConfigResolver::isConfigured()) {
            return ['success' => false, 'error' => 'Prism gateway is not configured'];
        }

        $authorization = $this->decodeCredential($input['credential'] ?? null);
        if ($authorization === null) {
            return ['success' => false, 'error' => 'Invalid x402 credential format'];
        }

        // Binding guard (NFR-1): the signed credential must match our quote.
        $summary = PrismValidator::extractSignedSummary($authorization);
        if ($summary === null) {
            return ['success' => false, 'error' => 'Could not extract payment summary from credential'];
        }
        $accepts = PrismValidator::readStoredAccepts($input['checkout_meta'][$this->id()] ?? null);
        if ($accepts === null) {
            return ['success' => false, 'error' => 'No stored payment requirements to validate against'];
        }
        $check = PrismValidator::validate($summary, $accepts);
        if ($check !== true) {
            return ['success' => false, 'error' => $check];
        }

        // Settle on-chain via Prism.
        $result = $this->client()->settle($authorization);
        if (!$result) {
            return ['success' => false, 'error' => 'Prism settlement request failed'];
        }

        $txRef = $result['transaction'] ?? $result['transactionHash']
            ?? $result['facilitatorTransactionId'] ?? $result['txHash'] ?? '';
        $settled = $result['success'] ?? ($txRef !== '');
        if (!$settled) {
            return ['success' => false, 'error' => $result['error'] ?? $result['errorReason'] ?? $result['reason'] ?? 'Settlement failed'];
        }

        $network = (string) ($result['network']
            ?? $authorization['paymentPayload']['accepted']['network']
            ?? $authorization['paymentPayload']['network'] ?? '');

        // Place the paid PrestaShop order from the session's transient cart.
        return $this->placeOrder($input['cart'], (string) $txRef, $network);
    }

    /**
     * @param array<string,mixed>|null $paymentMeta
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function getUcpCheckoutHandlers(?array $paymentMeta = null): array
    {
        $node = $paymentMeta[$this->id()] ?? null;
        $ucp = (is_array($node) && isset($node['ucp']) && is_array($node['ucp'])) ? $node['ucp'] : null;
        if ($ucp === null) {
            return [];
        }

        // Prism's prepare response is already in the payment_handlers shape.
        return $ucp;
    }

    /**
     * Turn the transient cart into a paid order carrying the on-chain tx ref.
     *
     * @return array<string,mixed>
     */
    private function placeOrder(\Cart $cart, string $txRef, string $network): array
    {
        if (!\Validate::isLoadedObject($cart)) {
            return ['success' => false, 'error' => 'Invalid cart'];
        }
        $customer = new \Customer((int) $cart->id_customer);
        if (!\Validate::isLoadedObject($customer)) {
            return ['success' => false, 'error' => 'Invalid customer'];
        }

        $total = (float) $cart->getOrderTotal(true, \Cart::BOTH);

        try {
            $this->module->validateOrder(
                (int) $cart->id,
                (int) \Configuration::get('PS_OS_PAYMENT'),
                $total,
                $this->name(),
                null,
                ['transaction_id' => $txRef],
                (int) $cart->id_currency,
                false,
                $customer->secure_key
            );
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('[FD Prism] validateOrder failed: ' . $e->getMessage(), 3);
            return ['success' => false, 'error' => 'Order could not be placed'];
        }

        $idOrder = (int) $this->module->currentOrder;
        if ($idOrder <= 0) {
            return ['success' => false, 'error' => 'Order was not created'];
        }

        return [
            'success' => true,
            'id_order' => $idOrder,
            'transaction_reference' => $txRef,
            'network' => $network,
        ];
    }

    /**
     * Decode an x402 credential from base64-JSON, raw JSON, or a structured object.
     *
     * @param mixed $credential
     * @return array<string,mixed>|null
     */
    private function decodeCredential($credential): ?array
    {
        if (is_string($credential)) {
            $b64 = base64_decode($credential, true);
            if ($b64 !== false) {
                $parsed = json_decode($b64, true);
                if (is_array($parsed)) {
                    return $parsed;
                }
            }
            $parsed = json_decode($credential, true);

            return is_array($parsed) ? $parsed : null;
        }
        if (is_array($credential)) {
            if (isset($credential['paymentPayload'])) {
                return $credential;
            }
            if (isset($credential['authorization'])) {
                return $this->decodeCredential($credential['authorization']);
            }

            return $credential;
        }

        return null;
    }
}
