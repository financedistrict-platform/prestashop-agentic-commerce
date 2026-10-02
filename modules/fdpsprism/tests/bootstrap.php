<?php
if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '9.0.0');
}

require_once __DIR__ . '/PrestaShopStubs.php';
require_once dirname(__DIR__, 2) . '/fdpsucp/src/autoload.php';
require_once dirname(__DIR__, 2) . '/fdpsucp/src/Payment/PaymentHandlerInterface.php';
require_once dirname(__DIR__) . '/src/Config/ConfigResolver.php';
require_once dirname(__DIR__) . '/src/Prism/PrismValidator.php';
require_once dirname(__DIR__) . '/src/Prism/PrismClient.php';
require_once dirname(__DIR__) . '/src/Prism/PrismHandler.php';
require_once dirname(__DIR__) . '/fdpsprism.php';
