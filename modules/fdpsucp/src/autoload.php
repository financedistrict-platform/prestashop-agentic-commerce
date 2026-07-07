<?php
/**
 * Minimal PSR-4 autoloader for the FD\PrismUcp\ namespace.
 *
 * We avoid a Composer dependency so the module installs by simply being
 * dropped into modules/ (no `composer install` step in the container).
 * FD\PrismUcp\Ucp\Formatter  ->  src/Ucp/Formatter.php
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

spl_autoload_register(function (string $class): void {
    $prefix = 'FD\\PrismUcp\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
