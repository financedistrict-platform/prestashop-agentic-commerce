<?php

declare(strict_types=1);

use FD\PrismPayment\Config\ConfigResolver;
use FD\PrismPayment\Prism\PrismClient;
use FD\PrismPayment\Prism\PrismHandler;
use FD\PrismUcp\Payment\PaymentRegistry;
use FD\PrismUcp\Payment\ReplayLedger;
use FD\PrismUcp\Ucp\RequestContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrismHandlerTest extends TestCase
{
    private const GATEWAY = 'https://prism-gw.example';
    private const PAY_TO = '0x1111111111111111111111111111111111111111';
    private const ASSET = '0x036CbD53842c5426634e7929541eC2318f3dCF7e';

    private FdTestPrismClient $client;
    private PaymentModule $module;
    private FdTestMemoryReplayLedger $ledger;

    protected function setUp(): void
    {
        Configuration::$values = [
            ConfigResolver::KEY_URL => self::GATEWAY,
            ConfigResolver::KEY_API => 'key',
        ];
        PrestaShopLogger::$logs = [];
        RequestContext::set(null);
        $this->client = new FdTestPrismClient();
        $this->module = new PaymentModule();
        $this->ledger = new FdTestMemoryReplayLedger();
    }

    private function handler(): PrismHandler
    {
        return new PrismHandler($this->module, $this->client, $this->ledger);
    }

    private function settleThroughCore(array $input): array
    {
        $registry = new PaymentRegistry();
        $registry->register($this->handler());

        return $registry->settle(PrismHandler::NS, $input);
    }

    private static function recorded(string $name): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/fdpsucp/tests/fixtures/prism/' . $name), true);
    }

    private function x402Credential(string $amount = '4695'): array
    {
        return FdTestX402::credential($amount);
    }

    private function checkoutMeta(): array
    {
        return [PrismHandler::NS => FdTestX402::checkoutMeta('4695')];
    }

    private function session(int $total = 4695): array
    {
        return ['session_uid' => FdTestX402::SESSION_UID, 'totals' => json_encode([['type' => 'total', 'amount' => $total]]), 'fulfillment' => FdTestShipping::FULFILLMENT, 'expires_at' => FdTestShipping::liveQuote()];
    }

    private function settle(mixed $instrumentType, mixed $credential): array
    {
        $this->client->responses['POST /api/v2/payment/settle'] = ['success' => true, 'transaction' => '0x' . str_repeat('cd', 32), 'network' => 'eip155:84532'];

        return $this->settleThroughCore([
            'session' => $this->session(),
            'cart' => new Cart(),
            'instrument_type' => $instrumentType,
            'credential' => $credential,
            'checkout_meta' => $this->checkoutMeta(),
        ]);
    }

    public function test_id_is_the_handler_namespace(): void
    {
        $this->assertSame('xyz.fd.prism_payment', $this->handler()->id());
    }

    public function test_every_client_method_uses_its_gateway_path(): void
    {
        $client = new FdTestPrismClient();
        $client->fetchUcpHandlers('2026-08-25');
        $client->preparePaymentRequirements('15.00', 'USD', 'https://shop.example/checkout-sessions/1', 'Order');
        $client->settle(2, [], []);

        $this->assertSame([
            'GET /ucp/2026-08-25/handlers',
            'POST /api/v2/merchant/payment-requirements',
            'POST /api/v2/payment/settle',
        ], $client->paths);
    }

    private function rawRequirements(): array
    {
        return [
            'x402Version' => 2,
            'resource' => ['url' => 'https://shop.example/checkout-sessions/s1', 'description' => 'Order checkout at Shop'],
            'accepts' => [['scheme' => 'exact', 'network' => 'eip155:84532', 'asset' => self::ASSET, 'amount' => '1500', 'payTo' => self::PAY_TO]],
        ];
    }

    private function prepare(): ?array
    {
        return $this->handler()->prepareCheckoutPayment([
            'checkout_id' => 's1',
            'total' => 1500,
            'currency' => 'USD',
            'checkout_base_url' => 'https://shop.example',
            'store_name' => 'Shop',
            'checkout_meta' => null,
        ]);
    }

    private function stubPrepare(string $fixture = 'current-handlers-2026-04-08.json'): void
    {
        RequestContext::set(RequestContext::forVersion('2026-08-25'));
        $this->client->responses['GET /ucp/2026-08-25/handlers'] = self::recorded($fixture);
        $this->client->responses['POST /api/v2/merchant/payment-requirements'] = $this->rawRequirements();
    }

    public function test_prepare_posts_to_the_protocol_free_path(): void
    {
        $this->stubPrepare();

        $this->prepare();

        $this->assertSame(['GET /ucp/2026-08-25/handlers', 'POST /api/v2/merchant/payment-requirements'], $this->client->paths);
        $this->assertSame([
            'amount' => '15.00',
            'currency' => 'USD',
            'resource' => ['url' => 'https://shop.example/checkout-sessions/s1', 'description' => 'Order checkout at Shop'],
        ], $this->client->bodies['POST /api/v2/merchant/payment-requirements']);
    }

    public function test_checkout_entry_matches_the_cached_discovery_declaration(): void
    {
        $this->stubPrepare();

        $result = $this->prepare();
        $declaration = $this->handler()->getUcpDiscoveryHandlersForVersion('2026-08-25')[PrismHandler::NS][0];
        $entry = $this->handler()->getUcpCheckoutHandlers([PrismHandler::NS => $result])[PrismHandler::NS][0];

        $this->assertSame(['id' => $declaration['id'], 'version' => $declaration['version']], ['id' => $entry['id'], 'version' => $entry['version']]);
        $this->assertSame('2026-10-07', $entry['version']);
        $this->assertSame($this->rawRequirements(), $entry['config']);
        $this->assertSame(['id', 'version', 'config'], array_keys($entry));
        $this->assertCount(2, $this->client->paths);
    }

    public function test_checkout_entry_uses_the_canonical_id_for_a_legacy_declaration(): void
    {
        $this->stubPrepare('legacy-handlers.json');

        $entry = $this->handler()->getUcpCheckoutHandlers([PrismHandler::NS => $this->prepare()])[PrismHandler::NS][0];

        $this->assertSame('xyz.fd.prism_payment', $entry['id']);
        $this->assertSame('2026-01-15', $entry['version']);
    }

    public function test_no_declaration_omits_the_prism_entry_and_logs(): void
    {
        RequestContext::set(RequestContext::forVersion('2026-08-25'));
        $this->client->responses['POST /api/v2/merchant/payment-requirements'] = $this->rawRequirements();

        $this->assertNull($this->prepare());
        $this->assertSame(['GET /ucp/2026-08-25/handlers'], $this->client->paths);
        $this->assertSame([], $this->handler()->getUcpCheckoutHandlers([PrismHandler::NS => null]));
        $logged = array_filter(
            PrestaShopLogger::$logs,
            static fn (array $log): bool => str_contains($log['message'], 'Prism entry omitted from checkout') && $log['severity'] === 3
        );
        $this->assertNotEmpty($logged);
    }

    public function test_only_accepts_that_can_settle_are_offered_to_the_agent(): void
    {
        $this->stubPrepare();
        $requirements = $this->rawRequirements();
        $solana = ['network' => 'solana:mainnet'] + $requirements['accepts'][0];
        $upto = ['scheme' => 'upto'] + $requirements['accepts'][0];
        $requirements['accepts'] = [$solana, $requirements['accepts'][0], $upto];
        $this->client->responses['POST /api/v2/merchant/payment-requirements'] = $requirements;

        $entry = $this->handler()->getUcpCheckoutHandlers([PrismHandler::NS => $this->prepare()])[PrismHandler::NS][0];

        $this->assertSame($this->rawRequirements()['accepts'], $entry['config']['accepts']);
    }

    public function test_requirements_without_accepts_are_rejected(): void
    {
        $this->stubPrepare();
        $this->client->responses['POST /api/v2/merchant/payment-requirements'] = ['x402Version' => 2, 'resource' => [], 'accepts' => []];

        $this->assertNull($this->prepare());
    }

    public function test_failed_reprepare_drops_the_stale_quote(): void
    {
        $this->stubPrepare();
        $stale = $this->prepare();
        $this->client->responses['POST /api/v2/merchant/payment-requirements'] = null;

        $this->assertNull($this->handler()->prepareCheckoutPayment([
            'checkout_id' => 's1',
            'total' => 2500,
            'currency' => 'USD',
            'checkout_base_url' => 'https://shop.example',
            'store_name' => 'Shop',
            'checkout_meta' => [PrismHandler::NS => $stale],
        ]));
    }

    public function test_prepare_always_fetches_fresh_requirements_for_the_same_amount(): void
    {
        $this->stubPrepare();
        $stale = $this->prepare();
        $fresh = $this->rawRequirements();
        $fresh['accepts'][0]['amount'] = '1490';
        $this->client->responses['POST /api/v2/merchant/payment-requirements'] = $fresh;

        $prepared = $this->handler()->prepareCheckoutPayment([
            'checkout_id' => 's1',
            'total' => 1500,
            'currency' => 'USD',
            'checkout_base_url' => 'https://shop.example',
            'store_name' => 'Shop',
            'checkout_meta' => [PrismHandler::NS => $stale],
        ]);

        $this->assertSame('1490', $prepared['ucp'][PrismHandler::NS][0]['config']['accepts'][0]['amount'] ?? null);
    }

    public function test_prepared_offer_still_binds_the_credential(): void
    {
        $this->stubPrepare();
        $meta = [PrismHandler::NS => $this->prepare()];
        $this->client->responses['POST /api/v2/payment/settle'] = ['success' => true, 'transaction' => '0x' . str_repeat('cd', 32), 'network' => 'eip155:84532'];
        $credential = $this->x402Credential('1500');
        $cart = new Cart();
        $cart->total = 15.00;

        $result = $this->settleThroughCore([
            'session' => $this->session(1500),
            'cart' => $cart,
            'instrument_type' => 'x402',
            'credential' => $credential,
            'checkout_meta' => $meta,
        ]);

        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));
    }

    public function test_discovery_is_fetched_per_version(): void
    {
        $this->client->responses['GET /ucp/2026-08-25/handlers'] = self::recorded('current-handlers-2026-04-08.json');

        $this->handler()->getUcpDiscoveryHandlersForVersion('2026-08-25');

        $this->assertSame(['GET /ucp/2026-08-25/handlers'], $this->client->paths);
    }

    public function test_current_entry_keeps_prism_fields_and_overlays_plugin_authored_fields(): void
    {
        $this->client->responses['GET /ucp/2026-04-08/handlers'] = self::recorded('current-handlers-2026-04-08.json');

        $entry = $this->handler()->getUcpDiscoveryHandlersForVersion('2026-04-08')[PrismHandler::NS][0];

        $this->assertSame(['id', 'name', 'version', 'spec', 'schema', 'available_instruments', 'config', 'config_schema', 'instrument_schemas'], array_keys($entry));
        $this->assertSame('xyz.fd.prism_payment', $entry['id']);
        $this->assertSame('Prism (x402 Stablecoin)', $entry['name']);
        $this->assertSame([['type' => 'x402']], $entry['available_instruments']);
        $this->assertSame(['tokenization' => false, 'description' => PrismHandler::DESCRIPTION], $entry['config']);
    }

    public function test_legacy_entry_yields_one_canonical_entry(): void
    {
        $this->client->responses['GET /ucp/2026-04-08/handlers'] = self::recorded('legacy-handlers.json');

        $handlers = $this->handler()->getUcpDiscoveryHandlersForVersion('2026-04-08');

        $this->assertSame([PrismHandler::NS], array_keys($handlers));
        $this->assertCount(1, $handlers[PrismHandler::NS]);
        $entry = $handlers[PrismHandler::NS][0];
        $this->assertSame('xyz.fd.prism_payment', $entry['id']);
        $this->assertSame('https://gw.example/ucp/schema.json', $entry['schema']);
        $this->assertArrayNotHasKey('config_schema', $entry);
        $this->assertSame('Prism (x402 Stablecoin)', $entry['name']);
    }

    public function test_discovery_uses_the_request_version_by_default(): void
    {
        RequestContext::set(RequestContext::forVersion('2026-01-23'));
        $this->client->responses['GET /ucp/2026-01-23/handlers'] = self::recorded('legacy-handlers.json');

        $this->assertNotSame([], $this->handler()->getUcpDiscoveryHandlers());
        $this->assertSame(['GET /ucp/2026-01-23/handlers'], $this->client->paths);
    }

    public function test_discovery_is_cached_per_gateway_and_version(): void
    {
        $this->client->responses['GET /ucp/2026-04-08/handlers'] = self::recorded('current-handlers-2026-04-08.json');
        $this->client->responses['GET /ucp/2026-08-25/handlers'] = self::recorded('current-handlers-2026-04-08.json');

        $this->handler()->getUcpDiscoveryHandlersForVersion('2026-04-08');
        $this->handler()->getUcpDiscoveryHandlersForVersion('2026-04-08');
        $this->handler()->getUcpDiscoveryHandlersForVersion('2026-08-25');

        $this->assertCount(2, $this->client->paths);
        $this->assertNotSame(PrismHandler::cacheKey(self::GATEWAY, '2026-04-08'), PrismHandler::cacheKey(self::GATEWAY, '2026-08-25'));
        $this->assertNotSame(PrismHandler::cacheKey(self::GATEWAY, '2026-04-08'), PrismHandler::cacheKey('https://other.example', '2026-04-08'));
        $this->assertMatchesRegularExpression('/^FDPSPRISM_DISC_[0-9a-f]{16}$/', PrismHandler::cacheKey(self::GATEWAY, '2026-04-08'));
        $this->assertArrayHasKey(PrismHandler::cacheKey(self::GATEWAY, '2026-04-08'), Configuration::$values);
    }

    public function test_expired_cache_is_refetched(): void
    {
        Configuration::$values[PrismHandler::cacheKey(self::GATEWAY, '2026-04-08')] = json_encode(['expires' => time() - 1, 'handlers' => self::recorded('legacy-handlers.json')]);
        $this->client->responses['GET /ucp/2026-04-08/handlers'] = self::recorded('current-handlers-2026-04-08.json');

        $entry = $this->handler()->getUcpDiscoveryHandlersForVersion('2026-04-08')[PrismHandler::NS][0];

        $this->assertSame('2026-10-07', $entry['version']);
        $this->assertCount(1, $this->client->paths);
    }

    public function test_unreachable_or_invalid_prism_omits_the_handler_and_logs_an_error(): void
    {
        $this->assertSame([], $this->handler()->getUcpDiscoveryHandlersForVersion('2026-04-08'));

        $this->client->responses['GET /ucp/2026-08-25/handlers'] = [PrismHandler::NS => [['id' => 'other', 'version' => 'v', 'spec' => 's', 'schema' => 's']]];
        $this->assertSame([], $this->handler()->getUcpDiscoveryHandlersForVersion('2026-08-25'));

        $this->assertCount(2, PrestaShopLogger::$logs);
        $this->assertSame(3, PrestaShopLogger::$logs[0]['severity']);
    }

    public function test_discovery_is_empty_when_not_configured(): void
    {
        Configuration::$values = [];

        $this->assertSame([], $this->handler()->getUcpDiscoveryHandlersForVersion('2026-04-08'));
        $this->assertSame([], $this->client->paths);
    }

    public function test_settle_rejects_a_foreign_instrument_type(): void
    {
        $result = $this->settle('card', $this->x402Credential());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('"x402"', $result['error']);
    }

    public function test_settle_rejects_a_foreign_credential_type(): void
    {
        $credential = $this->x402Credential();
        $credential['type'] = 'card';

        $result = $this->settle('x402', base64_encode((string) json_encode($credential)));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('"x402"', $result['error']);
    }

    public function test_x402_credential_still_passes_the_type_check_and_settles(): void
    {
        $result = $this->settle('x402', $this->x402Credential());

        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));
        $this->assertSame(1001, $result['id_order']);
        $this->assertSame('0x' . str_repeat('cd', 32), $result['transaction_reference']);
        $this->assertSame('Prism (x402 Stablecoin)', $this->module->validated[0][3]);
    }

    public function test_session_prepared_by_the_original_release_still_settles(): void
    {
        $this->client->responses['POST /api/v2/payment/settle'] = ['success' => true, 'transaction' => '0x' . str_repeat('cd', 32)];
        $meta = ['x402' => $this->checkoutMeta()[PrismHandler::NS]];

        $result = $this->settleThroughCore([
            'session' => $this->session(),
            'cart' => new Cart(),
            'instrument_type' => null,
            'credential' => $this->x402Credential(),
            'checkout_meta' => $meta,
        ]);

        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));
        $this->assertSame($meta['x402']['ucp'], $this->handler()->getUcpCheckoutHandlers($meta));
    }

    public function test_original_era_instruments_settle(): void
    {
        $credential = $this->x402Credential();
        unset($credential['type']);

        foreach ([null, '', 'tokenized', 'default'] as $type) {
            $this->module->validated = [];
            $result = $this->settle($type, $credential);

            $this->assertTrue($result['success'], var_export($type, true) . ' ' . ($result['error'] ?? ''));
        }
    }

    private function settleWithGateway(?array $gatewayResponse): array
    {
        if ($gatewayResponse !== null) {
            $this->client->responses['POST /api/v2/payment/settle'] = $gatewayResponse;
        }

        return $this->settleThroughCore([
            'session' => $this->session(),
            'cart' => new Cart(),
            'instrument_type' => 'x402',
            'credential' => $this->x402Credential(),
            'checkout_meta' => $this->checkoutMeta(),
        ]);
    }

    public function test_no_gateway_answer_is_an_unknown_outcome_not_a_rejection(): void
    {
        $result = $this->settleWithGateway(null);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['pending'] ?? false);
        $this->assertArrayNotHasKey('review', $result);
        $this->assertSame([], $this->module->validated);
    }

    public function test_gateway_settlement_pending_is_an_unknown_outcome(): void
    {
        $result = $this->settleWithGateway(['success' => false, 'transaction' => '', 'errorReason' => 'settlement_pending']);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['pending'] ?? false);
        $this->assertSame([], $this->module->validated);
    }

    public function test_success_without_a_transaction_is_an_unknown_outcome(): void
    {
        $result = $this->settleWithGateway(['success' => true, 'transaction' => '']);

        $this->assertTrue($result['pending'] ?? false);
        $this->assertSame([], $this->module->validated);
    }

    #[DataProvider('reviewReasons')]
    public function test_consumed_or_unresolved_authorization_needs_review(string $reason): void
    {
        $result = $this->settleWithGateway(['success' => false, 'transaction' => '', 'errorReason' => $reason]);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['review'] ?? false);
        $this->assertSame($reason, $result['error']);
        $this->assertArrayNotHasKey('pending', $result);
        $this->assertSame([], $this->module->validated);
    }

    public static function reviewReasons(): array
    {
        return [['authorization_consumed'], ['settlement_unresolved']];
    }

    #[DataProvider('definiteReasons')]
    public function test_a_reason_known_to_precede_any_broadcast_is_a_definite_rejection(string $reason): void
    {
        $result = $this->settleWithGateway(['success' => false, 'transaction' => '', 'errorReason' => $reason]);

        $this->assertFalse($result['success']);
        $this->assertSame($reason, $result['error']);
        $this->assertArrayNotHasKey('pending', $result);
        $this->assertArrayNotHasKey('review', $result);
    }

    public static function definiteReasons(): array
    {
        return array_map(static fn (string $reason): array => [$reason], [
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
            'invalid_signature',
            'invalid_payment_accepted_mismatch',
            'invalid_exact_evm_payload_recipient_mismatch',
            'invalid_amount_below_required',
            'invalid_exact_evm_payload_authorization_expired',
            'invalid_exact_evm_payload_authorization_not_yet_valid',
            'insufficient_funds',
            'balance_check_failed',
            'unknown_token: 0x036CbD53842c5426634e7929541eC2318f3dCF7e on chain 84532',
            'invalid_authorization_recipient',
            'authorization_mismatch',
            'http_400',
            'http_403',
        ]);
    }

    #[DataProvider('unknownOutcomeReasons')]
    public function test_an_unknown_outcome_reason_holds_the_session_instead_of_declining(mixed $reason): void
    {
        $result = $this->settleWithGateway(['success' => false, 'transaction' => '', 'errorReason' => $reason]);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['pending'] ?? false, var_export($reason, true));
        $this->assertArrayNotHasKey('review', $result);
        $this->assertSame([], $this->module->validated);
    }

    public static function unknownOutcomeReasons(): array
    {
        return array_map(static fn (mixed $reason): array => [$reason], [
            'unexpected_settle_error',
            'receipt_timeout',
            'settlement_pending',
            'execution_failed: Transaction would revert: execution reverted: FiatTokenV2: authorization is used or canceled',
            'execution_cancelled',
            'settlement_failed',
            'transaction_reverted',
            'facilitator_settlement_failed',
            'some_reason_added_by_a_future_gateway',
            '',
            null,
            ['not', 'a', 'string'],
        ]);
    }

    public function test_a_failure_without_any_reason_holds_the_session(): void
    {
        $result = $this->settleWithGateway(['success' => false, 'transaction' => '']);

        $this->assertTrue($result['pending'] ?? false);
    }

    #[DataProvider('landedNonceReasons')]
    public function test_a_resumed_retry_with_an_unlisted_reason_goes_to_review(string $reason, string $expectedError): void
    {
        $result = $this->settleResumed(['success' => false, 'transaction' => '', 'errorReason' => $reason]);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['review'] ?? false);
        $this->assertSame($expectedError, $result['error']);
        $this->assertArrayNotHasKey('pending', $result);
    }

    public static function landedNonceReasons(): array
    {
        return [
            ['execution_failed: Transaction would revert: execution reverted: FiatTokenV2: authorization is used or canceled', 'execution_failed'],
            ['settlement_failed', 'settlement_failed'],
            ['transaction_reverted', 'transaction_reverted'],
            ['receipt_timeout', 'receipt_timeout'],
        ];
    }

    public function test_a_resumed_retry_with_an_unlisted_reason_keeps_the_gateway_transaction_for_review(): void
    {
        $tx = '0x' . str_repeat('dd', 32);

        $result = $this->settleResumed(['success' => false, 'transaction' => $tx, 'errorReason' => 'receipt_timeout']);

        $this->assertTrue($result['review'] ?? false);
        $this->assertSame($tx, $result['transaction_reference'] ?? null);
    }

    public function test_a_resumed_retry_without_a_reason_goes_to_review(): void
    {
        $result = $this->settleResumed(['success' => false, 'transaction' => '']);

        $this->assertTrue($result['review'] ?? false);
        $this->assertSame('settlement_unresolved', $result['error']);
    }

    #[DataProvider('transientReasons')]
    public function test_a_resumed_retry_that_is_still_unanswered_stays_pending(string $reason): void
    {
        $result = $this->settleResumed(['success' => false, 'transaction' => '', 'errorReason' => $reason]);

        $this->assertTrue($result['pending'] ?? false);
        $this->assertArrayNotHasKey('review', $result);
    }

    public static function transientReasons(): array
    {
        return [['unexpected_settle_error'], ['settlement_pending'], ['checkout_settlement_in_progress']];
    }

    public function test_a_resumed_retry_with_no_gateway_answer_stays_pending(): void
    {
        $result = $this->settleResumed(null);

        $this->assertTrue($result['pending'] ?? false);
    }

    public function test_a_resumed_retry_with_a_definite_reason_is_left_to_the_checkout_to_escalate(): void
    {
        $result = $this->settleResumed(['success' => false, 'transaction' => '', 'errorReason' => 'insufficient_funds']);

        $this->assertFalse($result['success']);
        $this->assertArrayNotHasKey('pending', $result);
        $this->assertArrayNotHasKey('review', $result);
    }

    private function settleResumed(?array $gatewayResponse): array
    {
        if ($gatewayResponse !== null) {
            $this->client->responses['POST /api/v2/payment/settle'] = $gatewayResponse;
        }

        return $this->settleThroughCore([
            'session' => $this->session(),
            'cart' => new Cart(),
            'instrument_type' => 'x402',
            'credential' => $this->x402Credential(),
            'checkout_meta' => $this->checkoutMeta(),
            'resumed' => true,
        ]);
    }

    private function expiredCredential(): array
    {
        $credential = $this->x402Credential();
        $credential['paymentPayload']['payload']['authorization']['validBefore'] = (string) (time() - 3600);

        return $credential;
    }

    public function test_resumed_settlement_of_an_expired_authorization_still_reaches_the_gateway(): void
    {
        $this->client->responses['POST /api/v2/payment/settle'] = ['success' => false, 'transaction' => '', 'errorReason' => 'settlement_unresolved'];

        $result = $this->settleThroughCore([
            'session' => $this->session(),
            'cart' => new Cart(),
            'instrument_type' => 'x402',
            'credential' => $this->expiredCredential(),
            'checkout_meta' => $this->checkoutMeta(),
            'resumed' => true,
        ]);

        $this->assertSame(['POST /api/v2/payment/settle'], $this->client->paths);
        $this->assertTrue($result['review'] ?? false);
    }

    public function test_first_settlement_of_an_expired_authorization_never_reaches_the_gateway(): void
    {
        $result = $this->settleThroughCore([
            'session' => $this->session(),
            'cart' => new Cart(),
            'instrument_type' => 'x402',
            'credential' => $this->expiredCredential(),
            'checkout_meta' => $this->checkoutMeta(),
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame([], $this->client->paths);
    }

    #[DataProvider('failuresAfterSettlement')]
    public function test_every_failure_after_a_settled_payment_needs_review_with_the_transaction(string $failure): void
    {
        $tx = '0x' . str_repeat('cd', 32);
        $cart = new Cart();
        $this->client->responses['POST /api/v2/payment/settle'] = ['success' => true, 'transaction' => $tx, 'network' => $failure === 'network mismatch' ? 'eip155:1' : 'eip155:84532'];
        match ($failure) {
            'ledger bind fails' => $this->ledger->refuseTransactions = true,
            'invalid cart' => $cart->id = 0,
            'invalid customer' => $cart->id_customer = 0,
            'order placement throws' => $this->module = new FdTestFlakyPaymentModule(),
            'order id missing' => $this->module = new FdTestOrderlessPaymentModule(),
            default => null,
        };

        $result = $this->settleThroughCore([
            'session' => $this->session(),
            'cart' => $cart,
            'instrument_type' => 'x402',
            'credential' => $this->x402Credential(),
            'checkout_meta' => $this->checkoutMeta(),
        ]);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['review'] ?? false, (string) json_encode($result));
        $this->assertSame($tx, $result['transaction_reference'] ?? null);
        $this->assertArrayNotHasKey('pending', $result);
    }

    public static function failuresAfterSettlement(): array
    {
        return array_map(static fn (string $failure): array => [$failure], [
            'network mismatch' => 'network mismatch',
            'ledger bind fails' => 'ledger bind fails',
            'invalid cart' => 'invalid cart',
            'invalid customer' => 'invalid customer',
            'order placement throws' => 'order placement throws',
            'order id missing' => 'order id missing',
        ]);
    }

    public function test_retry_of_a_settled_payment_places_the_order_once(): void
    {
        $this->assertTrue($this->settleWithGateway(null)['pending']);

        $result = $this->settleWithGateway(['success' => true, 'transaction' => '0x' . str_repeat('cd', 32), 'network' => 'eip155:84532']);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $this->module->validated);
    }
}

final class FdTestPrismClient extends PrismClient
{
    public array $responses = [];
    public array $paths = [];
    public array $bodies = [];

    public function __construct()
    {
        parent::__construct('https://prism-gw.example', 'key');
    }

    protected function request(string $method, string $path, ?array $body, int $timeout, bool $refusalIsAnswer = false): ?array
    {
        $this->paths[] = "$method $path";
        $this->bodies["$method $path"] = $body;

        return $this->responses["$method $path"] ?? null;
    }
}

final class FdTestOrderlessPaymentModule extends PaymentModule
{
    public function validateOrder(...$args)
    {
        $this->validated[] = $args;

        return true;
    }
}

final class FdTestMemoryReplayLedger implements ReplayLedger
{
    public array $claims = [];
    public array $transactions = [];
    public bool $unavailable = false;
    public bool $refuseTransactions = false;

    public function claim(string $sessionUid, string $authorizationKey): bool
    {
        $this->guard();
        if (isset($this->claims[$authorizationKey])) {
            return $this->claims[$authorizationKey] === $sessionUid;
        }
        $this->claims[$authorizationKey] = $sessionUid;

        return true;
    }

    public function recordTransaction(string $authorizationKey, string $transactionKey): bool
    {
        $this->guard();
        if ($this->refuseTransactions || !isset($this->claims[$authorizationKey])) {
            return false;
        }
        if (isset($this->transactions[$transactionKey])) {
            return $this->transactions[$transactionKey] === $authorizationKey;
        }
        if (in_array($authorizationKey, $this->transactions, true)) {
            return false;
        }
        $this->transactions[$transactionKey] = $authorizationKey;

        return true;
    }

    private function guard(): void
    {
        if ($this->unavailable) {
            throw new RuntimeException('replay ledger unavailable');
        }
    }
}
