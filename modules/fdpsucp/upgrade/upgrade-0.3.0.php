<?php
/**
 * Upgrade to 0.3.0 — add the pre-checkout cart table (dev.ucp.shopping.cart).
 *
 * PrestaShop runs this once when an installed 0.2.x module is upgraded to
 * 0.3.0. Creating the table here keeps existing installs (which already have
 * ps_prism_session) in sync without a reinstall.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_3_0($module)
{
    $engine = _MYSQL_ENGINE_;
    $cartTable = _DB_PREFIX_ . 'prism_cart';

    $sql = "CREATE TABLE IF NOT EXISTS `$cartTable` (
        `id_prism_cart` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `cart_uid` VARCHAR(64) NOT NULL,
        `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
        `line_items` LONGTEXT NULL,
        `agent_fingerprint` VARCHAR(128) NULL,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NULL,
        PRIMARY KEY (`id_prism_cart`),
        UNIQUE KEY `cart_uid` (`cart_uid`),
        KEY `idx_shop` (`id_shop`)
    ) ENGINE=$engine DEFAULT CHARSET=utf8mb4;";

    return (bool) Db::getInstance()->execute($sql);
}
