<?php

declare(strict_types=1);

use FD\PrismPayment\Config\ConfigResolver;
use FD\PrismPayment\Prism\PrismHandler;
use FD\PrismUcp\Payment\PaymentRegistry;
use FD\PrismUcp\Payment\ReplayKey;
use FD\PrismUcp\Ucp\RequestContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrismPaymentTamperTest extends TestCase
{
    private const SETTLE = 'POST /api/v2/payment/settle';

    private FdTestPrismClient $client;
    private PaymentModule $module;
    private FdTestMemoryReplayLedger $ledger;
    private Cart $cart;

    protected function setUp(): void
    {
        Configuration::$values = [
            ConfigResolver::KEY_URL => 'https://prism-gw.example',
            ConfigResolver::KEY_API => 'key',
        ];
        PrestaShopLogger::$logs = [];
        RequestContext::set(null);
        $this->client = new FdTestPrismClient();
        $this->client->responses[self::SETTLE] = ['success' => true, 'transaction' => '0x' . str_repeat('cd', 32), 'network' => FdTestX402::NETWORK];
        $this->module = new PaymentModule();
        $this->ledger = new FdTestMemoryReplayLedger();
        $this->cart = new Cart();
    }

    private function settle(int $quotedTotal, ?int $preparedAmount, string $tokenAmount, ?string $expiresAt = null): array
    {
        $node = ['ucp' => [PrismHandler::NS => [['config' => FdTestX402::quote($tokenAmount)]]]];
        if ($preparedAmount !== null) {
            $node['prepared_amount'] = $preparedAmount;
        }

        return $this->settleThroughCore([
            'session' => ['session_uid' => FdTestX402::SESSION_UID, 'totals' => json_encode([['type' => 'total', 'amount' => $quotedTotal]]), 'fulfillment' => FdTestShipping::FULFILLMENT, 'expires_at' => $expiresAt ?? FdTestShipping::liveQuote()],
            'cart' => $this->cart,
            'instrument_type' => 'x402',
            'credential' => FdTestX402::credential($tokenAmount),
            'checkout_meta' => [PrismHandler::NS => $node],
        ]);
    }

    private function settleTampered(callable $tamper, ?callable $tamperQuote = null): array
    {
        $quote = FdTestX402::quote('4695');
        if ($tamperQuote !== null) {
            $quote = $tamperQuote($quote);
        }

        return $this->settleThroughCore([
            'session' => ['session_uid' => FdTestX402::SESSION_UID, 'totals' => json_encode([['type' => 'total', 'amount' => 4695]]), 'fulfillment' => FdTestShipping::FULFILLMENT, 'expires_at' => FdTestShipping::liveQuote()],
            'cart' => $this->cart,
            'instrument_type' => 'x402',
            'credential' => $tamper(FdTestX402::credential('4695')),
            'checkout_meta' => [PrismHandler::NS => ['prepared_amount' => 4695, 'ucp' => [PrismHandler::NS => [['config' => $quote]]]]],
        ]);
    }

    private function settleThroughCore(array $input): array
    {
        $registry = new PaymentRegistry();
        $registry->register(new PrismHandler($this->module, $this->client, $this->ledger));

        return $registry->settle(PrismHandler::NS, $input);
    }

    private function assertNoOrderPlaced(array $result): void
    {
        $this->assertFalse($result['success']);
        $this->assertSame([], $this->module->validated);
    }

    public function test_settlement_without_an_explicit_success_places_no_order(): void
    {
        $this->client->responses[self::SETTLE] = ['transaction' => '0x' . str_repeat('cd', 32), 'network' => 'eip155:84532'];

        $this->assertNoOrderPlaced($this->settle(4695, 4695, '4695'));
    }

    public function test_settlement_with_a_non_boolean_success_places_no_order(): void
    {
        $this->client->responses[self::SETTLE] = ['success' => 'false', 'transaction' => '0x' . str_repeat('cd', 32), 'network' => 'eip155:84532'];

        $this->assertNoOrderPlaced($this->settle(4695, 4695, '4695'));
    }

    public function test_settlement_without_a_transaction_places_no_order(): void
    {
        $this->client->responses[self::SETTLE] = ['success' => true, 'network' => 'eip155:84532'];

        $this->assertNoOrderPlaced($this->settle(4695, 4695, '4695'));
    }

    public function test_settlement_on_another_network_than_the_signed_one_places_no_order(): void
    {
        $this->client->responses[self::SETTLE] = ['success' => true, 'transaction' => '0x' . str_repeat('cd', 32), 'network' => 'eip155:8453'];

        $this->assertNoOrderPlaced($this->settle(4695, 4695, '4695'));
    }

    public function test_handler_called_without_a_verified_paid_amount_places_no_order(): void
    {
        $result = (new PrismHandler($this->module, $this->client, $this->ledger))->settlePayment([
            'session' => ['session_uid' => FdTestX402::SESSION_UID, 'totals' => json_encode([['type' => 'total', 'amount' => 4695]]), 'fulfillment' => FdTestShipping::FULFILLMENT, 'expires_at' => FdTestShipping::liveQuote()],
            'cart' => $this->cart,
            'instrument_type' => 'x402',
            'credential' => FdTestX402::credential('4695'),
            'checkout_meta' => [PrismHandler::NS => ['prepared_amount' => 4695, 'ucp' => [PrismHandler::NS => [['config' => FdTestX402::quote('4695')]]]]],
        ]);

        $this->assertNothingCharged($result);
    }

    private function assertNothingCharged(array $result): void
    {
        $this->assertFalse($result['success']);
        $this->assertNotContains(self::SETTLE, $this->client->paths);
        $this->assertSame([], $this->module->validated);
    }

    public function test_cart_that_lost_the_selected_carrier_is_never_settled(): void
    {
        $this->cart->deliveryOption = [9 => '3,'];

        $this->assertNothingCharged($this->settle(4695, 4695, '4695'));
    }

    public function test_expired_quote_is_never_settled(): void
    {
        $this->assertNothingCharged($this->settle(4695, 4695, '4695', date('Y-m-d H:i:s', time() - 1)));
    }

    public function test_quote_below_the_order_total_is_never_settled(): void
    {
        $this->assertNothingCharged($this->settle(1500, 1500, '1500'));
    }

    public function test_prepared_amount_that_differs_from_the_quote_is_never_settled(): void
    {
        $this->assertNothingCharged($this->settle(4695, 1500, '1500'));
    }

    public function test_missing_prepared_amount_is_never_settled(): void
    {
        $this->assertNothingCharged($this->settle(4695, null, '4695'));
    }

    public function test_missing_quote_is_never_settled(): void
    {
        $this->cart->total = 0.0;

        $result = $this->settleThroughCore([
            'session' => ['session_uid' => FdTestX402::SESSION_UID, 'totals' => null, 'fulfillment' => FdTestShipping::FULFILLMENT],
            'cart' => $this->cart,
            'instrument_type' => 'x402',
            'credential' => FdTestX402::credential('0'),
            'checkout_meta' => [PrismHandler::NS => ['prepared_amount' => 0, 'ucp' => [PrismHandler::NS => [['config' => FdTestX402::quote('0')]]]]],
        ]);

        $this->assertNothingCharged($result);
    }

    public function test_untampered_credential_settles_against_the_stored_requirements(): void
    {
        $result = $this->settleTampered(static fn (array $c): array => $c);

        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));
        $body = $this->client->bodies[self::SETTLE];
        $this->assertSame(FdTestX402::accept('4695'), $body['paymentRequirements']);
        $this->assertSame(FdTestX402::accept('4695'), $body['paymentPayload']['accepted']);
        $this->assertSame(FdTestX402::quote('4695')['resource'], $body['paymentPayload']['resource']);
        $this->assertSame(2, $body['paymentPayload']['x402Version']);
        $this->assertSame(FdTestX402::credential('4695')['paymentPayload']['payload']['signature'], $body['paymentPayload']['payload']['signature']);
    }

    public function test_requirements_written_by_the_buyer_are_never_forwarded(): void
    {
        $result = $this->settleTampered(static function (array $c): array {
            $c['paymentRequirements'] = ['network' => 'eip155:1', 'asset' => FdTestX402::OTHER_ASSET, 'amount' => '1', 'payTo' => FdTestX402::PAYER];

            return $c;
        });

        $this->assertNothingCharged($result);
    }

    public function test_only_the_signed_fields_of_the_payload_are_forwarded(): void
    {
        $result = $this->settleTampered(static function (array $c): array {
            $c['paymentRequirements'] = FdTestX402::accept('4695');
            $c['paymentPayload']['extensions'] = ['anything' => 'goes'];
            $c['paymentPayload']['payload']['extra'] = 'ignored';

            return $c;
        });

        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));
        $body = $this->client->bodies[self::SETTLE];
        $this->assertSame(FdTestX402::accept('4695'), $body['paymentRequirements']);
        $this->assertSame(['x402Version', 'resource', 'accepted', 'payload'], array_keys($body['paymentPayload']));
        $this->assertSame(['signature', 'authorization'], array_keys($body['paymentPayload']['payload']));
        $this->assertSame(['from', 'to', 'value', 'validAfter', 'validBefore', 'nonce'], array_keys($body['paymentPayload']['payload']['authorization']));
    }

    public function test_settle_route_follows_the_stored_quote_not_the_credential_version(): void
    {
        $result = $this->settleTampered(static function (array $c): array {
            $c['x402Version'] = 1;

            return $c;
        });

        $this->assertNothingCharged($result);
        $this->assertNotContains('POST /api/v1/payment/settle', $this->client->paths);
    }

    public function test_requirement_matching_the_signed_accept_is_the_one_forwarded(): void
    {
        $second = ['network' => 'eip155:8453', 'asset' => FdTestX402::OTHER_ASSET] + FdTestX402::accept('4695');
        $quote = FdTestX402::quote('4695');
        $quote['accepts'][] = $second;
        $credential = FdTestX402::credential('4695');
        $credential['paymentPayload']['accepted'] = $second;
        $this->client->responses[self::SETTLE]['network'] = 'eip155:8453';

        $result = $this->settleThroughCore([
            'session' => ['session_uid' => FdTestX402::SESSION_UID, 'totals' => json_encode([['type' => 'total', 'amount' => 4695]]), 'fulfillment' => FdTestShipping::FULFILLMENT, 'expires_at' => FdTestShipping::liveQuote()],
            'cart' => $this->cart,
            'instrument_type' => 'x402',
            'credential' => $credential,
            'checkout_meta' => [PrismHandler::NS => ['prepared_amount' => 4695, 'ucp' => [PrismHandler::NS => [['config' => $quote]]]]],
        ]);

        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));
        $this->assertSame($second, $this->client->bodies[self::SETTLE]['paymentRequirements']);
        $this->assertSame('eip155:8453', $result['network']);
    }

    public function test_settlement_on_a_network_other_than_the_matched_requirement_places_no_order(): void
    {
        $this->client->responses[self::SETTLE]['network'] = 'eip155:56';

        $this->assertNoOrderPlaced($this->settleTampered(static fn (array $c): array => $c));
    }

    public static function tamperedCredentials(): array
    {
        $edit = static fn (string $path, mixed $value = null, bool $drop = false): callable => static function (array $c) use ($path, $value, $drop): array {
            $node = &$c;
            $keys = explode('.', $path);
            $last = array_pop($keys);
            foreach ($keys as $key) {
                $node = &$node[$key];
            }
            if ($drop) {
                unset($node[$last]);
            } else {
                $node[$last] = $value;
            }

            return $c;
        };
        $drop = static fn (string $path): callable => $edit($path, null, true);
        $set = static fn (string $path, mixed $value): callable => $edit($path, $value);

        return [
            'payload network matches quote, accepted network differs' => [static function (array $c): array {
                $c['paymentPayload']['network'] = FdTestX402::NETWORK;
                $c['paymentPayload']['accepted']['network'] = 'eip155:1';

                return $c;
            }],
            'accepted network differs from the quote' => [$set('paymentPayload.accepted.network', 'eip155:1')],
            'payload network differs from accepted network' => [$set('paymentPayload.network', 'eip155:1')],
            'accepted without network' => [$drop('paymentPayload.accepted.network')],
            'accepted asset differs, forwarded requirements copy the quote' => [static function (array $c): array {
                $c['paymentPayload']['accepted']['asset'] = FdTestX402::OTHER_ASSET;
                $c['paymentRequirements'] = FdTestX402::accept('4695');

                return $c;
            }],
            'accepted without asset' => [$drop('paymentPayload.accepted.asset')],
            'accepted payTo differs from the quote' => [$set('paymentPayload.accepted.payTo', FdTestX402::PAYER)],
            'accepted amount below the quote' => [$set('paymentPayload.accepted.amount', '1')],
            'accepted amount above the quote' => [$set('paymentPayload.accepted.amount', '9999')],
            'accepted scheme differs from the quote' => [$set('paymentPayload.accepted.scheme', 'upto')],
            'accepted without scheme' => [$drop('paymentPayload.accepted.scheme')],
            'payload without accepted' => [$drop('paymentPayload.accepted')],
            'authorization pays another recipient' => [$set('paymentPayload.payload.authorization.to', FdTestX402::PAYER)],
            'signed value below the quote' => [$set('paymentPayload.payload.authorization.value', '1')],
            'signed value above the quote' => [$set('paymentPayload.payload.authorization.value', '999999')],
            'signed value that is not an integer string' => [$set('paymentPayload.payload.authorization.value', '46.95')],
            'signed value as a number' => [$set('paymentPayload.payload.authorization.value', 4695)],
            'authorization already expired' => [$set('paymentPayload.payload.authorization.validBefore', (string) (time() - 5))],
            'authorization not yet valid' => [$set('paymentPayload.payload.authorization.validAfter', (string) (time() + 3600))],
            'authorization without from' => [$drop('paymentPayload.payload.authorization.from')],
            'authorization without nonce' => [$drop('paymentPayload.payload.authorization.nonce')],
            'authorization with a malformed nonce' => [$set('paymentPayload.payload.authorization.nonce', '0x01')],
            'authorization without validBefore' => [$drop('paymentPayload.payload.authorization.validBefore')],
            'authorization without validAfter' => [$drop('paymentPayload.payload.authorization.validAfter')],
            'authorization without value' => [$drop('paymentPayload.payload.authorization.value')],
            'payload without signature' => [$drop('paymentPayload.payload.signature')],
            'payload without authorization' => [$drop('paymentPayload.payload.authorization')],
            'resource url names another session' => [$set('paymentPayload.resource.url', 'https://shop.example/checkout-sessions/other')],
            'forwarded requirements name another network' => [$set('paymentRequirements', ['network' => 'eip155:1'] + FdTestX402::accept('4695'))],
            'forwarded requirements name another asset' => [$set('paymentRequirements', ['asset' => FdTestX402::OTHER_ASSET] + FdTestX402::accept('4695'))],
            'forwarded requirements name a cheaper amount' => [$set('paymentRequirements', ['amount' => '1'] + FdTestX402::accept('4695'))],
            'forwarded requirements name another recipient' => [$set('paymentRequirements', ['payTo' => FdTestX402::PAYER] + FdTestX402::accept('4695'))],
            'payload version differs from the quote' => [$set('paymentPayload.x402Version', 1)],
            'credential carrying both authorization and paymentPayload' => [static function (array $c): array {
                $c['authorization'] = base64_encode((string) json_encode(['paymentPayload' => FdTestX402::credential('1')['paymentPayload']]));

                return $c;
            }],
            'flat credential shape' => [static fn (array $c): array => ['type' => 'x402', 'network' => FdTestX402::NETWORK, 'asset' => FdTestX402::ASSET, 'value' => '4695', 'to' => FdTestX402::PAY_TO]],
            'credential without a payload' => [static fn (array $c): array => ['type' => 'x402', 'paymentPayload' => []]],
        ];
    }

    #[DataProvider('tamperedCredentials')]
    public function test_tampered_credential_is_rejected_before_settlement(callable $tamper): void
    {
        $this->assertNothingCharged($this->settleTampered($tamper));
    }

    public static function brokenQuotes(): array
    {
        $edit = static fn (callable $change): array => [static function (array $q) use ($change): array {
            $change($q);

            return $q;
        }];

        return [
            'without resource url' => $edit(static function (array &$q): void {
                unset($q['resource']['url']);
            }),
            'without resource' => $edit(static function (array &$q): void {
                unset($q['resource']);
            }),
            'without x402 version' => $edit(static function (array &$q): void {
                unset($q['x402Version']);
            }),
            'with another x402 version' => $edit(static function (array &$q): void {
                $q['x402Version'] = 1;
            }),
            'with a numeric amount' => $edit(static function (array &$q): void {
                $q['accepts'][0]['amount'] = 4695;
            }),
            'with a non-integer amount' => $edit(static function (array &$q): void {
                $q['accepts'][0]['amount'] = '46.95';
            }),
            'without a scheme' => $edit(static function (array &$q): void {
                unset($q['accepts'][0]['scheme']);
            }),
            'without accepts' => $edit(static function (array &$q): void {
                $q['accepts'] = [];
            }),
        ];
    }

    #[DataProvider('brokenQuotes')]
    public function test_stored_quote_that_cannot_bind_a_credential_is_never_settled(callable $tamperQuote): void
    {
        $this->assertNothingCharged($this->settleTampered(static fn (array $c): array => $c, $tamperQuote));
    }

    public function test_paid_amount_reported_to_prestashop_is_the_settled_quote(): void
    {
        $result = $this->settle(4695, 4695, '4695');

        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));
        $this->assertContains(self::SETTLE, $this->client->paths);
        $this->assertSame(46.95, $this->module->validated[0][2]);
    }

    private function settleOnSession(string $sessionUid, array $credential, string $amount = '4695'): array
    {
        return $this->settleThroughCore([
            'session' => ['session_uid' => $sessionUid, 'totals' => json_encode([['type' => 'total', 'amount' => (int) $amount]]), 'fulfillment' => FdTestShipping::FULFILLMENT, 'expires_at' => FdTestShipping::liveQuote()],
            'cart' => $this->cart,
            'instrument_type' => 'x402',
            'credential' => $credential,
            'checkout_meta' => [PrismHandler::NS => FdTestX402::checkoutMeta($amount)],
        ]);
    }

    private function settleRequests(): int
    {
        return count(array_keys($this->client->paths, self::SETTLE, true));
    }

    public function test_authorization_that_already_paid_one_session_is_never_settled_for_another(): void
    {
        $credential = FdTestX402::credential('4695');

        $first = $this->settleOnSession(FdTestX402::SESSION_UID, $credential);
        $second = $this->settleOnSession(FdTestX402::OTHER_SESSION_UID, $credential);

        $this->assertTrue($first['success'], (string) ($first['error'] ?? ''));
        $this->assertFalse($second['success']);
        $this->assertSame(1, $this->settleRequests());
        $this->assertCount(1, $this->module->validated);
    }

    public function test_authorization_claimed_by_another_session_is_never_sent_to_the_gateway(): void
    {
        $credential = FdTestX402::credential('4695');
        $authorization = $credential['paymentPayload']['payload']['authorization'];
        $key = ReplayKey::authorization(FdTestX402::NETWORK, FdTestX402::ASSET, $authorization['from'], $authorization['nonce']);
        $this->ledger->claims[$key] = FdTestX402::OTHER_SESSION_UID;

        $this->assertNothingCharged($this->settleOnSession(FdTestX402::SESSION_UID, $credential));
    }

    public function test_replayed_authorization_is_matched_regardless_of_address_and_nonce_case(): void
    {
        $this->settleOnSession(FdTestX402::SESSION_UID, FdTestX402::credential('4695'));
        $shouted = FdTestX402::credential('4695', '0x' . strtoupper(str_repeat('ab', 32)), '0x' . strtoupper(substr(FdTestX402::PAYER, 2)));

        $result = $this->settleOnSession(FdTestX402::OTHER_SESSION_UID, $shouted);

        $this->assertFalse($result['success']);
        $this->assertSame(1, $this->settleRequests());
    }

    public function test_the_same_nonce_from_another_payer_is_a_different_authorization(): void
    {
        $first = $this->settleOnSession(FdTestX402::SESSION_UID, FdTestX402::credential('4695'));
        $this->client->responses[self::SETTLE]['transaction'] = '0x' . str_repeat('ee', 32);
        $second = $this->settleOnSession(FdTestX402::OTHER_SESSION_UID, FdTestX402::credential('4695', null, '0x3333333333333333333333333333333333333333'));

        $this->assertTrue($first['success'], (string) ($first['error'] ?? ''));
        $this->assertTrue($second['success'], (string) ($second['error'] ?? ''));
        $this->assertCount(2, $this->module->validated);
    }

    public function test_transaction_already_used_by_another_session_places_no_order(): void
    {
        $first = $this->settleOnSession(FdTestX402::SESSION_UID, FdTestX402::credential('4695'));
        $second = $this->settleOnSession(FdTestX402::OTHER_SESSION_UID, FdTestX402::credential('4695', '0x' . str_repeat('ef', 32)));

        $this->assertTrue($first['success'], (string) ($first['error'] ?? ''));
        $this->assertFalse($second['success']);
        $this->assertCount(1, $this->module->validated);
    }

    public function test_transaction_that_cannot_be_recorded_places_no_order(): void
    {
        $this->ledger->refuseTransactions = true;

        $result = $this->settleOnSession(FdTestX402::SESSION_UID, FdTestX402::credential('4695'));

        $this->assertFalse($result['success']);
        $this->assertSame([], $this->module->validated);
    }

    public function test_unavailable_replay_ledger_never_settles(): void
    {
        $this->ledger->unavailable = true;

        $this->assertNothingCharged($this->settleOnSession(FdTestX402::SESSION_UID, FdTestX402::credential('4695')));
    }

    public function test_session_without_an_identifier_is_never_settled(): void
    {
        $this->assertNothingCharged($this->settleOnSession('', FdTestX402::credential('4695')));
    }

    public function test_same_session_can_settle_again_when_the_order_could_not_be_placed(): void
    {
        $module = new FdTestFlakyPaymentModule();
        $this->module = $module;
        $credential = FdTestX402::credential('4695');

        $first = $this->settleOnSession(FdTestX402::SESSION_UID, $credential);
        $module->failing = false;
        $second = $this->settleOnSession(FdTestX402::SESSION_UID, $credential);

        $this->assertFalse($first['success']);
        $this->assertTrue($second['success'], (string) ($second['error'] ?? ''));
        $this->assertCount(1, $module->validated);
    }

    public function test_failed_settlement_keeps_the_authorization_bound_to_its_session(): void
    {
        $this->client->responses[self::SETTLE] = ['success' => false, 'error' => 'insufficient_funds'];
        $credential = FdTestX402::credential('4695');

        $failed = $this->settleOnSession(FdTestX402::SESSION_UID, $credential);
        $this->client->responses[self::SETTLE] = ['success' => true, 'transaction' => '0x' . str_repeat('cd', 32), 'network' => FdTestX402::NETWORK];
        $elsewhere = $this->settleOnSession(FdTestX402::OTHER_SESSION_UID, $credential);
        $again = $this->settleOnSession(FdTestX402::SESSION_UID, $credential);

        $this->assertFalse($failed['success']);
        $this->assertFalse($elsewhere['success']);
        $this->assertTrue($again['success'], (string) ($again['error'] ?? ''));
    }
}

final class FdTestFlakyPaymentModule extends PaymentModule
{
    public bool $failing = true;

    public function validateOrder(...$args)
    {
        if ($this->failing) {
            throw new RuntimeException('order could not be placed');
        }

        return parent::validateOrder(...$args);
    }
}
