<?php

namespace FD\PrismPayment\Prism;

use FD\PrismPayment\Config\ConfigResolver;
use FD\PrismUcp\Payment\DbReplayLedger;
use FD\PrismUcp\Payment\PaymentHandlerInterface;
use FD\PrismUcp\Payment\ReplayKey;
use FD\PrismUcp\Payment\ReplayLedger;
use FD\PrismUcp\Payment\VersionedPaymentHandlerInterface;
use FD\PrismUcp\Ucp\Formatter;
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

    public const PENDING_REASONS = ['settlement_pending', 'unexpected_settle_error', 'checkout_settlement_in_progress'];

    public const REVIEW_REASONS = ['authorization_consumed', 'settlement_unresolved', 'checkout_already_settled'];

    public const DEFINITE_REASONS = [
        'authorization_mismatch',
        'unsupported_network',
        'unsupported_token',
        'point_of_service_not_found',
        'seller_wallet_not_configured',
        'affiliate_residual_overflow',
        'affiliate_settlement_unavailable',
        'cashback_outside_campaign_bounds',
        'sanctions_hit',
        'risk_threshold_exceeded',
        'network_not_found',
        'unknown_network',
        'unknown_token',
        'missing_variant_config',
        'invalid_authorization_recipient',
        'invalid_authorization_format',
        'invalid_signature',
        'invalid_payment_accepted_mismatch',
        'invalid_exact_evm_payload_recipient_mismatch',
        'invalid_exact_evm_payload_authorization_missing_signature',
        'invalid_exact_evm_payload_authorization_signature',
        'invalid_exact_evm_payload_authorization_not_yet_valid',
        'invalid_exact_evm_payload_authorization_expired',
        'invalid_amount_format',
        'invalid_amount_zero_or_negative',
        'invalid_amount_below_required',
        'invalid_amount_exceeds_maximum',
        'invalid_timestamp_format',
        'invalid_timestamp_order',
        'insufficient_funds',
        'balance_check_failed',
        'balance_verification_failed',
        'unauthorized',
        'quote_not_found',
        'quote_mismatch',
        'credential_already_used',
        'invalid_exact_evm_payload_authorization_value',
        'invalid_exact_evm_payload_authorization_time_bounds',
    ];

    private const LEGACY_ID = 'x402';

    private const INSTRUMENT_TYPE = 'x402';

    private const LEGACY_INSTRUMENT_TYPES = ['tokenized', 'default'];

    public function __construct(private \PaymentModule $module, private ?PrismClient $client = null, private ?ReplayLedger $ledger = null)
    {
    }

    private function ledger(): ReplayLedger
    {
        return $this->ledger ??= new DbReplayLedger();
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

        $resourceUrl = "$baseUrl/checkout-sessions/$sessionId";

        $ucpVersion = RequestContext::current()->version();
        $declaration = $this->checkoutDeclaration($ucpVersion);
        if ($declaration === null) {
            \PrestaShopLogger::addLog('[FD Prism] UCP handler declaration for ' . $ucpVersion . ' unavailable; Prism entry omitted from checkout', 3);

            return null;
        }

        $requirements = $this->client()->preparePaymentRequirements(
            PrismClient::minorToMajorString($total),
            $currency,
            $resourceUrl,
            "Order checkout at $storeName"
        );

        $quote = PrismValidator::parseQuote($requirements);
        if ($quote === null) {
            \PrestaShopLogger::addLog('[FD Prism] Payment requirements response invalid; Prism entry omitted from checkout', 3);

            return null;
        }

        return [
            'ucp' => [self::NS => [[
                'id' => $declaration['id'],
                'version' => $declaration['version'],
                'config' => $quote,
            ]]],
            'prepared_amount' => $total,
            'prepared_resource_url' => $resourceUrl,
        ];
    }

    private function checkoutDeclaration(string $ucpVersion): ?array
    {
        return $this->getUcpDiscoveryHandlersForVersion($ucpVersion)[self::NS][0] ?? null;
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

        $paidAmount = $input['paid_amount'] ?? null;
        if (!is_int($paidAmount)) {
            return ['success' => false, 'error' => 'Payment amount was not verified'];
        }

        $credential = PrismValidator::decode($input['credential'] ?? null);
        if ($credential === null) {
            return ['success' => false, 'error' => 'Invalid x402 credential format'];
        }
        if (!$this->hasX402Types($input['instrument_type'] ?? null, $input['credential'], $credential)) {
            return ['success' => false, 'error' => 'Prism instrument and credential type must be "x402"'];
        }

        $quote = PrismValidator::parseQuote($this->storedQuote($input['checkout_meta'] ?? null));
        if ($quote === null) {
            return ['success' => false, 'error' => 'No stored payment requirements to validate against'];
        }
        $payment = PrismValidator::verify($credential, $quote, ($input['resumed'] ?? false) === true);
        if (is_string($payment)) {
            return ['success' => false, 'error' => $payment];
        }
        $authorizationKey = ReplayKey::authorization(
            $payment->network,
            (string) $payment->requirements['asset'],
            (string) $payment->paymentPayload['payload']['authorization']['from'],
            (string) $payment->paymentPayload['payload']['authorization']['nonce']
        );
        $claimError = $this->claimError($input['session'] ?? null, $authorizationKey);
        if ($claimError !== null) {
            return ['success' => false, 'error' => $claimError];
        }
        $result = $this->client()->settle($payment->version, $payment->paymentPayload, $payment->requirements);
        if (!$result) {
            return self::pending('no_gateway_answer');
        }

        $txRef = $result['transaction'] ?? $result['transactionHash']
            ?? $result['facilitatorTransactionId'] ?? $result['txHash'] ?? '';
        if (($result['success'] ?? null) === true && (!is_string($txRef) || $txRef === '')) {
            \PrestaShopLogger::addLog('[FD Prism] Settlement reported success without a transaction; outcome unknown', 3);

            return self::pending('success_without_transaction');
        }
        if (($result['success'] ?? null) !== true) {
            \PrestaShopLogger::addLog('[FD Prism] Settlement not confirmed; no order placed. Response: ' . json_encode($result), 3);

            return self::unsettled($result, ($input['resumed'] ?? false) === true);
        }

        return $this->afterSettled($result, $txRef, $payment->network, $authorizationKey, $input['cart'], $paidAmount);
    }

    private function afterSettled(array $result, string $txRef, string $network, string $authorizationKey, \Cart $cart, int $paidAmount): array
    {
        if (isset($result['network']) && $result['network'] !== $network) {
            \PrestaShopLogger::addLog('[FD Prism] Settlement network ' . json_encode($result['network']) . ' differs from the signed network ' . $network . '; no order placed for transaction ' . $txRef, 3);

            return self::reviewAfterSettle($txRef, 'Settlement network does not match the signed payment');
        }

        if (!$this->recorded($authorizationKey, ReplayKey::transaction($txRef))) {
            \PrestaShopLogger::addLog('[FD Prism] Settlement transaction ' . $txRef . ' could not be bound to this payment; no order placed', 3);

            return self::reviewAfterSettle($txRef, 'Settlement transaction was already used by another payment');
        }

        $placed = $this->placeOrder($cart, $txRef, $network, Formatter::toMajor($paidAmount));
        if (($placed['success'] ?? false) !== true) {
            return self::reviewAfterSettle($txRef, (string) ($placed['error'] ?? 'Order could not be placed'));
        }

        return $placed;
    }

    private static function reviewAfterSettle(string $txRef, string $error): array
    {
        return ['success' => false, 'review' => true, 'transaction_reference' => $txRef, 'error' => $error];
    }

    private static function pending(string $reason = 'settlement_outcome_unknown'): array
    {
        return ['success' => false, 'pending' => true, 'reason' => $reason, 'error' => 'Prism settlement outcome is unknown'];
    }

    private static function unsettled(array $result, bool $resumed): array
    {
        $reason = $result['errorReason'] ?? null;
        $key = self::reasonKey($reason);
        if (in_array($key, self::PENDING_REASONS, true)) {
            return self::pending($key);
        }
        $txRef = $result['transaction'] ?? null;
        $withTx = is_string($txRef) && $txRef !== '' ? ['transaction_reference' => $txRef] : [];
        if (in_array($key, self::REVIEW_REASONS, true)) {
            return ['success' => false, 'review' => true, 'error' => $reason] + $withTx;
        }
        if (preg_match('/^http_4(?!08$|09$|29$)\d\d$/', $key) === 1 || in_array($key, self::DEFINITE_REASONS, true)) {
            return ['success' => false, 'error' => $result['error'] ?? $reason];
        }
        if ($resumed) {
            return ['success' => false, 'review' => true, 'error' => $key !== '' ? $key : 'settlement_unresolved'] + $withTx;
        }

        return self::pending($key !== '' ? $key : 'unlisted_reason');
    }

    private static function reasonKey(mixed $reason): string
    {
        return is_string($reason) ? strtolower(trim(explode(':', $reason, 2)[0])) : '';
    }

    private function claimError(mixed $session, string $authorizationKey): ?string
    {
        $sessionUid = is_array($session) ? ($session['session_uid'] ?? null) : null;
        if (!is_string($sessionUid) || $sessionUid === '') {
            return 'Checkout session is missing';
        }
        try {
            if ($this->ledger()->claim($sessionUid, $authorizationKey)) {
                return null;
            }
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('[FD Prism] Payment replay ledger unavailable: ' . $e->getMessage(), 3);

            return 'Payment replay protection is unavailable';
        }

        return 'Payment authorization was already used';
    }

    private function recorded(string $authorizationKey, string $transactionKey): bool
    {
        try {
            return $this->ledger()->recordTransaction($authorizationKey, $transactionKey);
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('[FD Prism] Payment replay ledger unavailable: ' . $e->getMessage(), 3);

            return false;
        }
    }

    private function storedQuote(?array $checkoutMeta): mixed
    {
        $ucp = $this->storedNode($checkoutMeta)['ucp'] ?? null;
        foreach (is_array($ucp) ? $ucp : [] as $entries) {
            foreach (is_array($entries) ? $entries : [] as $entry) {
                if (is_array($entry) && is_array($entry['config'] ?? null)) {
                    return $entry['config'];
                }
            }
        }

        return null;
    }

    public function preparedAmount(?array $checkoutMeta): ?int
    {
        $amount = $this->storedNode($checkoutMeta)['prepared_amount'] ?? null;

        return is_int($amount) ? $amount : null;
    }

    private function storedNode(?array $checkoutMeta): mixed
    {
        return $checkoutMeta[$this->id()] ?? $checkoutMeta[self::LEGACY_ID] ?? null;
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
    private function placeOrder(\Cart $cart, string $txRef, string $network, float $paidAmount): array
    {
        if (!\Validate::isLoadedObject($cart)) {
            return ['success' => false, 'error' => 'Invalid cart'];
        }
        $customer = new \Customer((int) $cart->id_customer);
        if (!\Validate::isLoadedObject($customer)) {
            return ['success' => false, 'error' => 'Invalid customer'];
        }

        try {
            $this->module->validateOrder(
                (int) $cart->id,
                (int) \Configuration::get('PS_OS_PAYMENT'),
                $paidAmount,
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
}
