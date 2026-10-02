<?php

class Configuration
{
    public static array $values = [];

    public static function get($key)
    {
        return self::$values[$key] ?? false;
    }

    public static function updateValue($key, $value, $html = false): bool
    {
        self::$values[$key] = $value;

        return true;
    }

    public static function deleteByName($key): bool
    {
        unset(self::$values[$key]);

        return true;
    }
}

class Hook
{
    public static array $calls = [];

    public static function exec($name, $params = [])
    {
        self::$calls[] = [$name, $params];

        return '';
    }
}

class PrestaShopLogger
{
    public static array $logs = [];

    public static function addLog($message, $severity = 1)
    {
        self::$logs[] = ['message' => $message, 'severity' => $severity];

        return true;
    }
}

class Module
{
    public static array $hooks = [];

    public function install(): bool
    {
        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }

    public function registerHook($hook): bool
    {
        self::$hooks[] = $hook;

        return true;
    }
}

class Db
{
    public static string $installedVersion = '';
    public static array $statements = [];

    public static function getInstance(): self
    {
        return new self();
    }

    public function execute($sql): bool
    {
        self::$statements[] = $sql;

        return true;
    }

    public function executeS($sql): array
    {
        return [['Field' => 'ucp_version']];
    }

    public function getValue($sql)
    {
        return self::$installedVersion === '' ? false : self::$installedVersion;
    }
}

function bqSQL($value): string
{
    return (string) $value;
}

class PaymentModule extends Module
{
}

class Order
{
    public $id;
    public $reference = 'XKBKNABJK';

    public function __construct($id = 1001)
    {
        $this->id = (int) $id;
    }
}

class Currency
{
    public $iso_code = 'EUR';
}

class Shop
{
    public $id = 1;
}

class Context
{
    public $currency;
    public $shop;

    public function __construct()
    {
        $this->currency = new Currency();
        $this->shop = new Shop();
    }
}

class Cart
{
}

final class FdTestStubs
{
    public static function reset(): void
    {
        Configuration::$values = [];
        Hook::$calls = [];
        Module::$hooks = [];
        Db::$installedVersion = '';
        Db::$statements = [];
        PrestaShopLogger::$logs = [];
        \FD\PrismUcp\Ucp\RequestContext::set(null);
        \FD\PrismUcp\Ucp\AgentProfileFetcher::resetCache();
    }
}
