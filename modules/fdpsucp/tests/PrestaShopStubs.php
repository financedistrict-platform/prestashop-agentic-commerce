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
        return false;
    }
}

function bqSQL($value): string
{
    return (string) $value;
}

class PaymentModule extends Module
{
    public $currentOrder = 0;
    public array $validated = [];

    public function validateOrder(...$args)
    {
        $this->validated[] = $args;
        $this->currentOrder = 1001;

        return true;
    }
}

class Customer
{
    public $id;
    public $secure_key = 'secure';

    public function __construct($id = null)
    {
        $this->id = $id;
    }
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

class Language
{
    public $id = 1;
}

class Context
{
    public $currency;
    public $shop;
    public $language;

    public function __construct()
    {
        $this->currency = new Currency();
        $this->shop = new Shop();
        $this->language = new Language();
    }
}

class Product
{
    public static array $prices = [];

    public $id;
    public $active = true;
    public $out_of_stock = 1;
    public $name;

    public function __construct($id = null, $full = false, $idLang = null)
    {
        $this->id = (int) $id;
        $this->name = 'Product ' . $this->id;
    }

    public static function getPriceStatic($idProduct, $usetax = true, $idProductAttribute = null)
    {
        return self::$prices[(int) $idProduct] ?? 0.0;
    }

    public static function isAvailableWhenOutOfStock($outOfStock): bool
    {
        return true;
    }
}

class Validate
{
    public static function isLoadedObject($object): bool
    {
        return is_object($object) && !empty($object->id);
    }
}

class Cart
{
    public const ONLY_PRODUCTS = 1;
    public const BOTH = 3;
    public const ONLY_SHIPPING = 5;

    public $id = 77;
    public $id_customer = 5;
    public $id_currency = 1;
    public array $orderTotals = [self::ONLY_PRODUCTS => 42.00, self::ONLY_SHIPPING => 4.95, self::BOTH => 46.95];

    public function getOrderTotal($withTaxes = true, $type = self::BOTH)
    {
        return $this->orderTotals[$type] ?? 0.0;
    }
}

final class FdTestStubs
{
    public static function reset(): void
    {
        Configuration::$values = [];
        Hook::$calls = [];
        Module::$hooks = [];
        Db::$statements = [];
        Product::$prices = [];
        PrestaShopLogger::$logs = [];
        \FD\PrismUcp\Ucp\RequestContext::set(null);
        \FD\PrismUcp\Ucp\AgentProfileFetcher::resetCache();
    }
}
