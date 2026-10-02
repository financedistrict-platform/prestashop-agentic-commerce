<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_7_0($module)
{
    $db = Db::getInstance();
    foreach (['prism_session', 'prism_cart'] as $table) {
        $name = _DB_PREFIX_ . $table;
        $rows = $db->executeS('SHOW COLUMNS FROM `' . bqSQL($name) . '` LIKE "ucp_version"');
        if (empty($rows) && !$db->execute("ALTER TABLE `$name` ADD COLUMN `ucp_version` VARCHAR(10) NULL")) {
            return false;
        }
    }

    $installed = (string) $db->getValue('SELECT `version` FROM `' . _DB_PREFIX_ . 'module` WHERE `name` = \'fdpsucp\'');
    \FD\PrismUcp\Ucp\VersionRegistry::seedOnUpgrade($installed);

    return $module->installHtaccessRules();
}
