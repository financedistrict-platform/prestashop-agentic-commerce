<?php
/**
 * Domain test bootstrap — PrismValidator is pure PHP (no gateway, no PrestaShop
 * kernel), guarded only by `_PS_VERSION_`. Define it and load the class.
 */

if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '9.0.0');
}

require_once dirname(__DIR__) . '/src/Prism/PrismValidator.php';
