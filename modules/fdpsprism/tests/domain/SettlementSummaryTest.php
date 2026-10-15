<?php

declare(strict_types=1);

use FD\PrismPayment\Prism\PrismClient;
use FD\PrismPayment\Prism\PrismHandler;
use FD\PrismPayment\Prism\SettlementSummary;
use PHPUnit\Framework\TestCase;

final class SettlementSummaryTest extends TestCase
{
    private const CONFIRMED = '{"transaction":"0xabc","network":"eip155:84532","asset":"USDC","status":"confirmed","gross":"100.00","fee":"1.50","cashback":"5.00","net":"93.50"}';

    public function test_confirmed_summary_shows_net_and_refund_limit(): void
    {
        $summary = SettlementSummary::load('0xabc', 'https://prism-gw.example', 'key', new FdTestSummaryClient([200, self::CONFIRMED]));

        $this->assertSame('confirmed', $summary['state']);
        $this->assertSame('93.50', $summary['net']);
        $html = SettlementSummary::render($summary);
        $this->assertStringContainsString('Refund up to', $html);
        $this->assertStringContainsString('93.50 USDC', $html);
        $this->assertStringContainsString('Buyer cashback', $html);
        $this->assertStringContainsString('5.00 USDC', $html);
    }

    public function test_broadcasted_is_pending(): void
    {
        $summary = SettlementSummary::load('0xabc', 'https://prism-gw.example', 'key', new FdTestSummaryClient([200, '{"status":"broadcasted"}']));

        $this->assertSame(['state' => 'pending'], $summary);
        $this->assertStringContainsString('Pending', SettlementSummary::render($summary));
    }

    public function test_prism_error_is_unavailable_and_visible(): void
    {
        $summary = SettlementSummary::load('0xabc', 'https://prism-gw.example', 'key', new FdTestSummaryClient([500, '']));

        $this->assertSame(['state' => 'unavailable'], $summary);
        $html = SettlementSummary::render($summary);
        $this->assertStringContainsString(SettlementSummary::UNAVAILABLE_TEXT, $html);
        $this->assertStringNotContainsString('100.00', $html);
    }

    public function test_empty_shop_key_is_unavailable_without_a_call(): void
    {
        $client = new FdTestSummaryClient([200, self::CONFIRMED]);

        $summary = SettlementSummary::load('0xabc', 'https://prism-gw.example', '', $client);

        $this->assertSame(['state' => 'unavailable'], $summary);
        $this->assertSame([], $client->timeouts);
    }

    public function test_missing_transaction_is_unavailable(): void
    {
        $client = new FdTestSummaryClient([200, self::CONFIRMED]);

        $this->assertSame(['state' => 'unavailable'], SettlementSummary::load('', 'https://prism-gw.example', 'key', $client));
        $this->assertSame([], $client->timeouts);
    }

    public function test_client_uses_the_settlement_path_and_short_timeout(): void
    {
        $client = new FdTestSummaryClient([200, self::CONFIRMED]);

        SettlementSummary::load('0xabc', 'https://prism-gw.example', 'key', $client);

        $this->assertSame(['GET /api/v2/merchant/payments/0xabc/settlement' => 5], $client->timeouts);
    }

    public function test_render_escapes_values(): void
    {
        $html = SettlementSummary::render(['state' => 'confirmed', 'net' => '<b>', 'cashback' => '1', 'fee' => '1', 'asset' => 'USDC']);

        $this->assertStringContainsString('&lt;b&gt;', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    public function test_transaction_hash_comes_from_the_prism_payment_row_whatever_the_order(): void
    {
        $payments = [
            (object) ['payment_method' => 'Bank wire', 'transaction_id' => '0xother'],
            (object) ['payment_method' => PrismHandler::NAME, 'transaction_id' => '0xabc'],
        ];

        $this->assertSame('0xabc', SettlementSummary::transactionHash($payments, PrismHandler::NAME));
        $this->assertSame('0xabc', SettlementSummary::transactionHash(array_reverse($payments), PrismHandler::NAME));
    }

    public function test_transaction_hash_is_empty_without_a_prism_payment_row(): void
    {
        $payments = [
            (object) ['payment_method' => 'Bank wire', 'transaction_id' => '0xother'],
            (object) ['payment_method' => PrismHandler::NAME, 'transaction_id' => ''],
        ];

        $this->assertSame('', SettlementSummary::transactionHash($payments, PrismHandler::NAME));
    }

    public function test_upgrade_registers_the_order_hook(): void
    {
        require_once dirname(__DIR__, 2) . '/upgrade/upgrade-0.7.11.php';
        $fake = new class {
            public array $hooks = [];

            public function registerHook($hook)
            {
                $this->hooks[] = $hook;

                return true;
            }
        };

        $this->assertTrue(upgrade_module_0_7_11($fake));
        $this->assertSame(['displayAdminOrderMainBottom'], $fake->hooks);
    }

    public function test_install_registers_the_order_hook(): void
    {
        $this->assertStringContainsString("registerHook('displayAdminOrderMainBottom')", file_get_contents(dirname(__DIR__, 2) . '/fdpsprism.php'));
    }
}

final class FdTestSummaryClient extends PrismClient
{
    public array $timeouts = [];

    public function __construct(private ?array $response)
    {
        parent::__construct('https://prism-gw.example', 'key');
    }

    protected function transport(string $method, string $path, ?array $body, int $timeout): ?array
    {
        $this->timeouts["$method $path"] = $timeout;

        return $this->response;
    }
}
