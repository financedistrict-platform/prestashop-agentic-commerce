<?php

declare(strict_types=1);

use FD\PrismUcp\Payment\DbReplayLedger;
use FD\PrismUcp\Payment\ReplayKey;
use PHPUnit\Framework\TestCase;

final class DbReplayLedgerTest extends TestCase
{
    private const SESSION = '5f0c2a8e-3b1d-4c6e-9a7f-2d4b8e1c0a91';
    private const OTHER_SESSION = '0a9c5e21-7d34-4f58-b1a6-93c8d2e47f10';

    protected function setUp(): void
    {
        FdTestStubs::reset();
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required to exercise the ledger queries');
        }
        Db::$pdo = new PDO('sqlite::memory:');
        Db::$pdo->exec(
            'CREATE TABLE ps_prism_payment_replay (
                id_prism_payment_replay INTEGER PRIMARY KEY AUTOINCREMENT,
                authorization_key TEXT NOT NULL UNIQUE,
                session_uid TEXT NOT NULL,
                transaction_key TEXT NULL UNIQUE,
                created_at TEXT NOT NULL
            )'
        );
    }

    private static function key(string $nonce = 'ab'): string
    {
        return ReplayKey::authorization('eip155:84532', '0xAsset', '0xPayer', '0x' . str_repeat($nonce, 32));
    }

    public function test_first_claim_wins_and_a_second_session_is_refused(): void
    {
        $ledger = new DbReplayLedger();

        $this->assertTrue($ledger->claim(self::SESSION, self::key()));
        $this->assertFalse($ledger->claim(self::OTHER_SESSION, self::key()));
    }

    public function test_owning_session_may_claim_again(): void
    {
        $ledger = new DbReplayLedger();

        $this->assertTrue($ledger->claim(self::SESSION, self::key()));
        $this->assertTrue($ledger->claim(self::SESSION, self::key()));
    }

    public function test_different_nonces_are_independent_claims(): void
    {
        $ledger = new DbReplayLedger();

        $this->assertTrue($ledger->claim(self::SESSION, self::key('ab')));
        $this->assertTrue($ledger->claim(self::OTHER_SESSION, self::key('cd')));
    }

    public function test_transaction_belongs_to_the_first_authorization_that_records_it(): void
    {
        $ledger = new DbReplayLedger();
        $ledger->claim(self::SESSION, self::key('ab'));
        $ledger->claim(self::OTHER_SESSION, self::key('cd'));
        $tx = ReplayKey::transaction('0xABCDEF');

        $this->assertTrue($ledger->recordTransaction(self::key('ab'), $tx));
        $this->assertFalse($ledger->recordTransaction(self::key('cd'), ReplayKey::transaction('0xabcdef')));
    }

    public function test_owner_may_record_the_same_transaction_again(): void
    {
        $ledger = new DbReplayLedger();
        $ledger->claim(self::SESSION, self::key());

        $this->assertTrue($ledger->recordTransaction(self::key(), '0xabc'));
        $this->assertTrue($ledger->recordTransaction(self::key(), '0xabc'));
    }

    public function test_authorization_cannot_be_bound_to_a_second_transaction(): void
    {
        $ledger = new DbReplayLedger();
        $ledger->claim(self::SESSION, self::key());

        $this->assertTrue($ledger->recordTransaction(self::key(), '0xabc'));
        $this->assertFalse($ledger->recordTransaction(self::key(), '0xdef'));
    }

    public function test_transaction_cannot_be_recorded_for_an_unclaimed_authorization(): void
    {
        $this->assertFalse((new DbReplayLedger())->recordTransaction(self::key(), '0xabc'));
    }

    public function test_empty_transaction_is_never_recorded(): void
    {
        $ledger = new DbReplayLedger();
        $ledger->claim(self::SESSION, self::key());

        $this->assertFalse($ledger->recordTransaction(self::key(), ''));
    }

    public function test_claim_reports_a_ledger_that_cannot_be_written_instead_of_a_used_authorization(): void
    {
        Db::$failing = true;

        $this->expectException(\RuntimeException::class);
        (new DbReplayLedger())->claim(self::SESSION, self::key());
    }

    public function test_missing_table_refuses_the_claim(): void
    {
        Db::$pdo->exec('DROP TABLE ps_prism_payment_replay');

        $this->expectException(PDOException::class);
        (new DbReplayLedger())->claim(self::SESSION, self::key());
    }

    public function test_schema_enforces_uniqueness_on_authorization_and_transaction(): void
    {
        $sql = DbReplayLedger::createSql('InnoDB');

        $this->assertStringContainsString('UNIQUE KEY `authorization_key` (`authorization_key`)', $sql);
        $this->assertStringContainsString('UNIQUE KEY `transaction_key` (`transaction_key`)', $sql);
    }

    public function test_module_install_and_upgrade_create_the_replay_table(): void
    {
        if (!defined('_MYSQL_ENGINE_')) {
            define('_MYSQL_ENGINE_', 'InnoDB');
        }
        Db::$pdo = null;
        $module = (new ReflectionClass(FdPsUcp::class))->newInstanceWithoutConstructor();
        Db::$statements = [];

        $this->assertTrue($module->installReplayTable());
        $this->assertStringContainsString('ps_prism_payment_replay', Db::$statements[0]);

        require_once dirname(__DIR__, 2) . '/upgrade/upgrade-0.7.6.php';
        Db::$statements = [];
        $this->assertTrue(upgrade_module_0_7_6($module));
        $this->assertStringContainsString('ps_prism_payment_replay', Db::$statements[0]);
    }
}
