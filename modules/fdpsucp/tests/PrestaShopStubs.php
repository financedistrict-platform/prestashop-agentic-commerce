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
    public static array $ids = ['EUR' => 1, 'KWD' => 2];
    public static array $deleted = [];
    public static array $inactive = [];

    public $id = 1;
    public $iso_code = 'EUR';
    public $active = true;

    public function __construct($id = null, $idLang = null, $idShop = null)
    {
        if ($id !== null) {
            $this->id = in_array((int) $id, self::$deleted, true) ? null : (int) $id;
            $this->iso_code = (string) array_search((int) $id, self::$ids, true);
            $this->active = !in_array((int) $id, self::$inactive, true);
        }
    }

    public static function getIdByIsoCode($isoCode, $idShop = 0)
    {
        return self::$ids[$isoCode] ?? 0;
    }
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
    public static ?Context $instance = null;

    public $currency;
    public $shop;
    public $language;

    public function __construct()
    {
        $this->currency = new Currency();
        $this->shop = new Shop();
        $this->language = new Language();
    }

    public static function getContext(): self
    {
        return self::$instance ??= new self();
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
        $price = self::$prices[(int) $idProduct] ?? 0.0;

        return is_array($price) ? ($price[Context::getContext()->currency->iso_code] ?? 0.0) : $price;
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
    public $id_address_delivery = 0;
    public array $orderTotals = [self::ONLY_PRODUCTS => 42.00, self::ONLY_SHIPPING => 4.95, self::BOTH => 46.95];
    public array $products = [
        ['id_product' => 101, 'id_product_attribute' => 0, 'cart_quantity' => 2, 'price_wt' => 18.00, 'total_wt' => 36.00],
        ['id_product' => 205, 'id_product_attribute' => 0, 'cart_quantity' => 1, 'price_wt' => 6.00, 'total_wt' => 6.00],
    ];

    public ?array $deliveryOption = null;
    public array $carrierTotals = [];

    public function getOrderTotal($withTaxes = true, $type = self::BOTH)
    {
        $carrier = $this->deliveryOption === null ? null : (int) reset($this->deliveryOption);

        return ($this->carrierTotals[$carrier] ?? $this->orderTotals)[$type] ?? 0.0;
    }

    public function setDeliveryOption($deliveryOption = null)
    {
        $this->deliveryOption = $deliveryOption;

        return true;
    }

    public function update($nullValues = false)
    {
        return true;
    }

    public function getProducts($refresh = false)
    {
        return $this->products;
    }

    public function getDeliveryOptionList()
    {
        return [];
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
        Currency::$deleted = [];
        Currency::$inactive = [];
        Context::$instance = null;
        PrestaShopLogger::$logs = [];
        \FD\PrismUcp\Ucp\RequestContext::set(null);
        \FD\PrismUcp\Ucp\AgentProfileFetcher::resetCache();
    }
}
