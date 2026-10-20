<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_7_11($module)
{
    $db = Db::getInstance();
    $table = _DB_PREFIX_ . 'prism_session';
    $exists = $db->executeS("SHOW COLUMNS FROM `$table` LIKE 'idempotency_hash'");
    if (empty($exists)) {
        return (bool) $db->execute("ALTER TABLE `$table` ADD `idempotency_hash` CHAR(64) NULL AFTER `idempotency_key`");
    }

    return true;
}
