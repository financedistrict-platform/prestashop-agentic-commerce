<?php

namespace FD\PrismUcp\Payment;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class DbReplayLedger implements ReplayLedger
{
    public const TABLE = 'prism_payment_replay';

    private string $table;

    public function __construct()
    {
        $this->table = _DB_PREFIX_ . self::TABLE;
    }

    public static function createSql(string $engine): string
    {
        return 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . "` (
            `id_prism_payment_replay` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `authorization_key` CHAR(64) NOT NULL,
            `session_uid` VARCHAR(64) NOT NULL,
            `transaction_key` VARCHAR(191) NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_prism_payment_replay`),
            UNIQUE KEY `authorization_key` (`authorization_key`),
            UNIQUE KEY `transaction_key` (`transaction_key`),
            KEY `idx_session` (`session_uid`)
        ) ENGINE=$engine DEFAULT CHARSET=utf8mb4;";
    }

    public function claim(string $sessionUid, string $authorizationKey): bool
    {
        $db = \Db::getInstance();
        $inserted = $db->execute(
            'INSERT IGNORE INTO `' . $this->table . '` (`authorization_key`, `session_uid`, `created_at`)
             VALUES ("' . pSQL($authorizationKey) . '", "' . pSQL($sessionUid) . '", "' . pSQL(date('Y-m-d H:i:s')) . '")'
        );
        if (!$inserted) {
            throw new \RuntimeException('The payment replay ledger could not be written');
        }
        if ($db->Affected_Rows() === 1) {
            return true;
        }

        return $this->owner('authorization_key', $authorizationKey, 'session_uid') === $sessionUid;
    }

    public function recordTransaction(string $authorizationKey, string $transactionKey): bool
    {
        if ($transactionKey === '') {
            return false;
        }
        $owner = $this->owner('transaction_key', $transactionKey, 'authorization_key');
        if ($owner === null) {
            \Db::getInstance()->execute(
                'UPDATE IGNORE `' . $this->table . '` SET `transaction_key` = "' . pSQL($transactionKey) . '"
                 WHERE `authorization_key` = "' . pSQL($authorizationKey) . '" AND `transaction_key` IS NULL'
            );
            $owner = $this->owner('transaction_key', $transactionKey, 'authorization_key');
        }

        return $owner === $authorizationKey;
    }

    private function owner(string $keyColumn, string $key, string $ownerColumn): ?string
    {
        $value = \Db::getInstance()->getValue(
            'SELECT `' . bqSQL($ownerColumn) . '` FROM `' . $this->table . '` WHERE `' . bqSQL($keyColumn) . '` = "' . pSQL($key) . '"'
        );

        return is_string($value) && $value !== '' ? $value : null;
    }
}
