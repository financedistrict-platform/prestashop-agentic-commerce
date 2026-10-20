<?php

declare(strict_types=1);

use FD\PrismPayment\Config\ConfigResolver;
use FD\PrismPayment\Prism\PrismHandler;
use FD\PrismUcp\Payment\PaymentRegistry;
use FD\PrismUcp\Ucp\RequestContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettleContractTest extends TestCase
{
    private PaymentModule $module;

    protected function setUp(): void
    {
        Configuration::$values = [
            ConfigResolver::KEY_URL => 'https://prism-gw.example',
            ConfigResolver::KEY_API => 'key',
        ];
        PrestaShopLogger::$logs = [];
        RequestContext::set(null);
        $this->module = new PaymentModule();
    }

    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/fdpsucp/tests/fixtures/prism/settle-replies.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function rows(): array
    {
        $rows = [];
        foreach (self::fixture()['rows'] as $row) {
            $rows[$row['name']] = [$row];
        }

        return $rows;
    }

    private static function transportReply(array $row): array
    {
        return $row['http'] === 200 ? [200, (string) json_encode($row['body'])] : [$row['http'], ''];
    }

    private function settle(array $row, bool $resumed): array
    {
        $registry = new PaymentRegistry();
        $registry->register(new PrismHandler($this->module, new FdTestTransportClient(self::transportReply($row)), new FdTestMemoryReplayLedger()));

        return $registry->settle(PrismHandler::NS, [
            'session' => ['session_uid' => FdTestX402::SESSION_UID, 'totals' => json_encode([['type' => 'total', 'amount' => 4695]]), 'fulfillment' => FdTestShipping::FULFILLMENT, 'expires_at' => FdTestShipping::liveQuote()],
            'cart' => new Cart(),
            'instrument_type' => 'x402',
            'credential' => FdTestX402::credential('4695'),
            'checkout_meta' => [PrismHandler::NS => FdTestX402::checkoutMeta('4695')],
        ] + ($resumed ? ['resumed' => true] : []));
    }

    #[DataProvider('rows')]
    public function test_first_attempt_classifies_each_gateway_reply(array $row): void
    {
        $result = $this->settle($row, false);

        $this->assertFalse($result['success']);
        $this->assertSame([], $this->module->validated);
        match ($row['first']) {
            'pending' => $this->assertTrue($result['pending'] ?? false, (string) json_encode($result)),
            'review' => $this->assertTrue($result['review'] ?? false, (string) json_encode($result)),
            'declined' => $this->assertSame([], array_intersect_key($result, ['pending' => 1, 'review' => 1]), (string) json_encode($result)),
        };
        if ($row['first'] === 'review' && isset($row['body']['transaction'])) {
            $this->assertSame($row['body']['transaction'], $result['transaction_reference'] ?? null);
        }
    }

    #[DataProvider('rows')]
    public function test_resumed_attempt_stays_pending_only_for_pending_rows(array $row): void
    {
        $result = $this->settle($row, true);

        $this->assertFalse($result['success']);
        $this->assertSame($row['resume'] === 'pending', $result['pending'] ?? false, (string) json_encode($result));
        if ($row['resume'] === 'review') {
            $this->assertArrayNotHasKey('pending', $result);
        }
    }

    public function test_reason_lists_equal_the_shared_fixture(): void
    {
        $fixture = self::fixture();
        $definite = array_merge(
            $fixture['declinedReasons'],
            array_map(static fn (string $prefix): string => rtrim($prefix, ':'), $fixture['declinedPrefixes'])
        );

        $this->assertEqualsCanonicalizing($fixture['pendingReasons'], PrismHandler::PENDING_REASONS);
        $this->assertEqualsCanonicalizing($fixture['reviewReasons'], PrismHandler::REVIEW_REASONS);
        $this->assertEqualsCanonicalizing($definite, PrismHandler::DEFINITE_REASONS);
        $this->assertSame(count(PrismHandler::DEFINITE_REASONS), count(array_unique(PrismHandler::DEFINITE_REASONS)));
    }
}
