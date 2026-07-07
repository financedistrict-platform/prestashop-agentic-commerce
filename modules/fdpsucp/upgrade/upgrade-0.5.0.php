<?php
/**
 * Upgrade to 0.5.0 — authorization hardening.
 *
 *  - Add per-resource capability-secret columns so a session/cart is private to
 *    whoever created it (replaces the spoofable agent-fingerprint check). The
 *    secret is minted at create time, returned once, and required (hashed) on
 *    every later read/mutation/complete and on the linked order read.
 *  - Generate a random agent token if none is set, so the write/PII endpoints
 *    are closed by default (a fresh install auto-generates one; existing
 *    installs that never set a token get one here).
 *
 * Clear the PrestaShop cache after upgrading (run bin/console as www-data).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_5_0($module)
{
    $db = Db::getInstance();
    $sessionTable = _DB_PREFIX_ . 'prism_session';
    $cartTable = _DB_PREFIX_ . 'prism_cart';

    if (!columnExists0_5_0($sessionTable, 'session_secret_hash')) {
        $db->execute("ALTER TABLE `$sessionTable` ADD `session_secret_hash` VARCHAR(64) NULL AFTER `agent_fingerprint`");
    }
    if (!columnExists0_5_0($cartTable, 'cart_secret_hash')) {
        $db->execute("ALTER TABLE `$cartTable` ADD `cart_secret_hash` VARCHAR(64) NULL AFTER `agent_fingerprint`");
    }

    // Durable rate-limit table (NFR-5) — the old cache-backed limiter never
    // tripped when the shop cache was disabled.
    $rateTable = _DB_PREFIX_ . 'prism_rate_limit';
    $db->execute("CREATE TABLE IF NOT EXISTS `$rateTable` (
        `rl_key` VARCHAR(191) NOT NULL,
        `hits` INT UNSIGNED NOT NULL DEFAULT 0,
        `window_start` INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (`rl_key`),
        KEY `idx_window` (`window_start`)
    ) ENGINE=" . _MYSQL_ENGINE_ . " DEFAULT CHARSET=utf8mb4;");

    if ((string) Configuration::get('FDPSUCP_AGENT_TOKEN') === '') {
        Configuration::updateValue('FDPSUCP_AGENT_TOKEN', bin2hex(random_bytes(32)));
    }

    return true;
}

function columnExists0_5_0(string $table, string $column): bool
{
    $rows = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . bqSQL($table) . '` LIKE "' . pSQL($column) . '"');

    return !empty($rows);
}
