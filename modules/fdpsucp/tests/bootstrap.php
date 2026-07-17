<?php
/**
 * Domain test bootstrap — the pure-logic UCP classes are guarded by
 * `_PS_VERSION_` but have no real PrestaShop dependency, so we define the
 * constant and require them directly. No PrestaShop kernel / DB / HTTP needed;
 * these tests exercise mapping and status logic in isolation (plan §4.1).
 */

if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '9.0.0');
}

$src = dirname(__DIR__) . '/src';

require_once $src . '/Http/Response.php';
require_once $src . '/Ucp/UcpAddress.php';
require_once $src . '/Ucp/UcpStatus.php';
require_once $src . '/Ucp/UcpError.php';
require_once $src . '/Ucp/CapabilitySecret.php';
require_once $src . '/Support/HtaccessRules.php';
