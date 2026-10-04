<?php

namespace FD\PrismPayment\Prism;

use FD\PrismPayment\Config\ConfigResolver;
use FD\PrismUcp\Payment\PaymentHandlerInterface;
use FD\PrismUcp\Payment\VersionedPaymentHandlerInterface;
use FD\PrismUcp\Ucp\RequestContext;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class PrismHandler implements PaymentHandlerInterface, VersionedPaymentHandlerInterface
{
    public const NS = 'xyz.fd.prism_payment';

    public const NAME = 'Prism (x402 Stablecoin)';

    public const DESCRIPTION = 'Pay with stablecoins via an AI agent wallet (x402). Settled on-chain by Prism.';

    public const CACHE_PREFIX = 'FDPSPRISM_DISC_';

    public const CACHE_TTL = 300;

    private const LEGACY_ID = 'x402';

    private const INSTRUMENT_TYPE = 'x402';

    private const LEGACY_INSTRUMENT_TYPES = ['tokenized', 'default'];

    public function __construct(private \PaymentModule $module, private ?PrismClient $client = null)
    {
    }

    public static function cacheKey(string $gateway, string $ucpVersion): string
    {
        return self::CACHE_PREFIX . substr(md5($gateway . '|' . $ucpVersion), 0, 16);
    }

    public function id(): string
    {
        return self::NS;
    }

    public function name(): string
    {
        return self::NAME;
    }

    private function client(): PrismClient
    {
        return $this->client ?? new PrismClient(ConfigResolver::apiUrl(), ConfigResolver::apiKey());
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    public function getUcpDiscoveryHandlers(): array
    {
        if (!ConfigResolver::isConfigured()) {
            return [];
        }

        return $this->getUcpDiscoveryHandlersForVersion(RequestContext::current()->version());
    }

    public function getUcpDiscoveryHandlersForVersion(string $ucpVersion): array
    {
        if (!ConfigResolver::isConfigured()) {
            return [];
        }

        $key = self::cacheKey(ConfigResolver::apiUrl(), $ucpVersion);
        $cached = json_decode((string) \Configuration::get($key), true);
        if (is_array($cached) && (int) ($cached['expires'] ?? 0) > time()) {
            $canonical = $this->canonicalHandlers($cached['handlers'] ?? null);
            if ($canonical !== null) {
                return $canonical;
            }
        }

        $fetched = $this->client()->fetchUcpHandlers($ucpVersion);
        $canonical = $this->canonicalHandlers($fetched);
        if ($canonical === null) {
            \PrestaShopLogger::addLog('[FD Prism] UCP handlers for ' . $ucpVersion . ' unavailable or invalid; Prism handler omitted from discovery', 3);

            return [];
        }

        \Configuration::updateValue($key, (string) json_encode(['expires' => time() + self::CACHE_TTL, 'handlers' => $fetched]));

        return $canonical;
    }

    private function canonicalHandlers(mixed $handlers): ?array
    {
        $entries = is_array($handlers) ? ($handlers[self::NS] ?? null) : null;
        if (!is_array($entries) || $entries === [] || !array_is_list($entries)) {
            return null;
        }

        $canonical = [];
        foreach ($entries as $entry) {
            $normalised = is_array($entry) ? $this->canonicalEntry($entry) : null;
            if ($normalised === null) {
                return null;
            }
            $canonical[] = $normalised;
        }

        return [self::NS => $canonical];
    }

    private function canonicalEntry(array $entry): ?array
    {
        if (!in_array($entry['id'] ?? null, [self::NS, self::LEGACY_ID], true)) {
            return null;
        }
        foreach (['version', 'spec'] as $field) {
            if (!is_string($entry[$field] ?? null) || $entry[$field] === '') {
                return null;
            }
        }
        $schema = $entry['schema'] ?? $entry['config_schema'] ?? null;
        if (!is_string($schema) || $schema === '') {
            return null;
        }

        $canonical = ['id' => self::NS, 'name' => self::NAME];
        foreach ($entry as $field => $value) {
            if ($field === 'id' || $field === 'name') {
                continue;
            }
            if ($field === 'config_schema' && !isset($entry['schema'])) {
                $canonical['schema'] = $value;
                continue;
            }
            $canonical[$field] = $value;
        }
        $canonical['config'] = array_merge(
            is_array($entry['config'] ?? null) ? $entry['config'] : [],
            ['tokenization' => false, 'description' => self::DESCRIPTION]
        );

        return $canonical;
    }

    /**
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
        $existing = $input['checkout_meta'][$this->id()] ?? $input['checkout_meta'][self::LEGACY_ID] ?? null;

        $resourceUrl = "$baseUrl/checkout-sessions/$sessionId";

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
            "Order checkout at $storeName",
            RequestContext::current()->version()
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
     * @param array{session:array,cart:\Cart,handler_id:string,instrument_type:string,credential:mixed,checkout_meta:?array} $input
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
        if (!$this->hasX402Types($input['instrument_type'] ?? null, $input['credential'], $authorization)) {
            return ['success' => false, 'error' => 'Prism instrument and credential type must be "x402"'];
        }

        $summary = PrismValidator::extractSignedSummary($authorization);
        if ($summary === null) {
            return ['success' => false, 'error' => 'Could not extract payment summary from credential'];
        }
        $accepts = PrismValidator::readStoredAccepts($input['checkout_meta'][$this->id()] ?? $input['checkout_meta'][self::LEGACY_ID] ?? null);
        if ($accepts === null) {
            return ['success' => false, 'error' => 'No stored payment requirements to validate against'];
        }
        $check = PrismValidator::validate($summary, $accepts);
        if ($check !== true) {
            return ['success' => false, 'error' => $check];
        }

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

        return $this->placeOrder($input['cart'], (string) $txRef, $network);
    }

    /**
     * @param array<string,mixed>|null $paymentMeta
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function getUcpCheckoutHandlers(?array $paymentMeta = null): array
    {
        $node = $paymentMeta[$this->id()] ?? $paymentMeta[self::LEGACY_ID] ?? null;
        $ucp = (is_array($node) && isset($node['ucp']) && is_array($node['ucp'])) ? $node['ucp'] : null;
        if ($ucp === null) {
            return [];
        }

        return $ucp;
    }

    /**
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

    private function hasX402Types(mixed $instrumentType, mixed $credential, array $decoded): bool
    {
        $credentialType = is_array($credential) ? ($credential['type'] ?? null) : ($decoded['type'] ?? null);
        $instrumentAccepted = $instrumentType === self::INSTRUMENT_TYPE
            || $instrumentType === null
            || $instrumentType === ''
            || in_array($instrumentType, self::LEGACY_INSTRUMENT_TYPES, true);

        return $instrumentAccepted && ($credentialType === null || $credentialType === self::INSTRUMENT_TYPE);
    }

    /**
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
