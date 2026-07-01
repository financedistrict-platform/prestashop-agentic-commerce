<?php
/**
 * Minimal PSR-4 autoloader for the FD\PrismPayment\ namespace.
 *
 * Mirrors fdpsucp's autoloader so the module installs by being dropped into
 * modules/ (no Composer step). FD\PrismPayment\Prism\PrismClient -> src/Prism/PrismClient.php
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

spl_autoload_register(function (string $class): void {
    $prefix = 'FD\\PrismPayment\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
