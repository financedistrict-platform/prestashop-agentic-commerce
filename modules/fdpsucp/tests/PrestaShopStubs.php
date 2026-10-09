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
    public static ?PDO $pdo = null;
    public static int $affected = 0;
    public static bool $failing = false;

    public static function getInstance(): self
    {
        return new self();
    }

    public function execute($sql): bool
    {
        self::$statements[] = $sql;
        if (self::$failing) {
            return false;
        }
        if (self::$pdo === null) {
            return true;
        }
        $changed = self::$pdo->exec(preg_replace('/^(INSERT|UPDATE) IGNORE/', '$1 OR IGNORE', $sql));
        self::$affected = $changed === false ? 0 : $changed;

        return $changed !== false;
    }

    public function executeS($sql): array
    {
        return [['Field' => 'ucp_version']];
    }

    public function getValue($sql)
    {
        if (self::$pdo === null) {
            return false;
        }

        return self::$pdo->query($sql)->fetchColumn();
    }

    public function Affected_Rows(): int
    {
        return self::$affected;
    }
}

function bqSQL($value): string
{
    return (string) $value;
}

function pSQL($value, $htmlOk = false): string
{
    return str_replace("'", "''", (string) $value);
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
    public static array $registered = [];
    public static array $created = [];
    public static int $nextId = 900;
    public static bool $failAdd = false;

    public $id;
    public $secure_key = 'secure';
    public $is_guest = 0;
    public $id_shop;
    public $id_lang;
    public $email = '';
    public $firstname = '';
    public $lastname = '';
    public $passwd = '';

    public function __construct($id = null)
    {
        $this->id = $id;
        if ($id !== null && isset(self::$registered[(int) $id])) {
            $this->email = self::$registered[(int) $id];
        }
    }

    public static function customerExists($email, $returnId = false, $ignoreGuest = true)
    {
        foreach (self::$registered as $id => $registeredEmail) {
            if (strcasecmp($registeredEmail, (string) $email) === 0) {
                return (int) $id;
            }
        }

        return 0;
    }

    public function add(): bool
    {
        if (self::$failAdd) {
            return false;
        }
        $this->id = self::$nextId++;
        self::$created[] = $this;

        return true;
    }
}

class Address
{
    public static array $created = [];
    public static bool $failAdd = false;

    public $id;
    public $id_customer;
    public $id_country;
    public $id_state;
    public $alias;
    public $firstname;
    public $lastname;
    public $address1;
    public $address2;
    public $city;
    public $postcode;

    public function add(): bool
    {
        if (self::$failAdd) {
            return false;
        }
        $this->id = 700 + count(self::$created);
        self::$created[] = $this;

        return true;
    }
}

class Tools
{
    public static function hash($value): string
    {
        return hash('sha256', (string) $value);
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

class Country
{
    public static array $ids = ['FR' => 8, 'SE' => 18, 'KP' => 113];
    public static array $inactive = [];
    public static array $unassociated = [];

    public $id;

    public function __construct($id = null)
    {
        $this->id = $id;
    }

    public static function getByIso($isoCode, $active = false)
    {
        if (!preg_match('/^[a-zA-Z]{2,3}$/', (string) $isoCode)) {
            throw new RuntimeException('Given iso code is not valid.');
        }
        $id = self::$ids[strtoupper((string) $isoCode)] ?? 0;

        return $active && in_array($id, self::$inactive, true) ? 0 : $id;
    }

    public function isAssociatedToShop($idShop = null)
    {
        return !in_array([(int) $this->id, (int) $idShop], self::$unassociated, true);
    }
}

class State
{
    public static array $states = [
        5 => ['iso' => 'CA', 'name' => 'California', 'id_country' => 21],
        60 => ['iso' => 'AB', 'name' => 'Stockholm', 'id_country' => 18],
    ];

    public $id;
    public $id_country = 0;

    public function __construct($id = null)
    {
        $this->id = $id;
        $this->id_country = self::$states[(int) $id]['id_country'] ?? 0;
    }

    public static function getIdByIso($isoCode, $idCountry = null)
    {
        foreach (self::$states as $id => $state) {
            if ($state['iso'] === $isoCode && (!$idCountry || $state['id_country'] === (int) $idCountry)) {
                return $id;
            }
        }

        return 0;
    }

    public static function getIdByName($name)
    {
        foreach (self::$states as $id => $state) {
            if ($state['name'] === $name) {
                return $id;
            }
        }

        return false;
    }
}

class Shop
{
    public $id = 1;
    public $id_shop_group = 1;
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
    public $cookie;
    public $cart;

    public function __construct()
    {
        $this->cookie = new stdClass();
        $this->cookie->id_guest = 0;
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

class Combination
{
    public static array $products = [11 => 101, 12 => 205];

    public $id;
    public $id_product = 0;

    public function __construct($id = null)
    {
        if ($id !== null && isset(self::$products[(int) $id])) {
            $this->id = (int) $id;
            $this->id_product = self::$products[(int) $id];
        }
    }
}

class Validate
{
    public static function isLoadedObject($object): bool
    {
        return is_object($object) && !empty($object->id);
    }

    public static function isEmail($email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function isLanguageIsoCode($isoCode)
    {
        return preg_match('/^[a-zA-Z]{2,3}$/', (string) $isoCode);
    }
}

class Cart
{
    public const ONLY_PRODUCTS = 1;
    public const BOTH = 3;
    public const ONLY_SHIPPING = 5;

    public static array $added = [];
    public static bool $rejectQuantity = false;

    public $id = 77;
    public $id_shop;
    public $id_shop_group;
    public $id_lang;
    public $id_guest;
    public $recyclable;
    public $gift;
    public $id_customer = 5;
    public $id_address_invoice = 0;
    public $id_currency = 1;
    public $id_address_delivery = 0;
    public array $orderTotals = [self::ONLY_PRODUCTS => 42.00, self::ONLY_SHIPPING => 4.95, self::BOTH => 46.95];
    public array $products = [
        ['id_product' => 101, 'id_product_attribute' => 0, 'cart_quantity' => 2, 'price_wt' => 18.00, 'total_wt' => 36.00],
        ['id_product' => 205, 'id_product_attribute' => 0, 'cart_quantity' => 1, 'price_wt' => 6.00, 'total_wt' => 6.00],
    ];

    public ?array $deliveryOption = null;
    public array $deliveryOptionList = [];
    public bool $saves = true;
    public array $carrierTotals = [];
    public bool $virtual = false;

    public function add(): bool
    {
        self::$added[] = $this;

        return true;
    }

    public function updateQty($quantity, $idProduct, $idProductAttribute = null)
    {
        return !self::$rejectQuantity;
    }

    public function isVirtualCart()
    {
        return $this->virtual;
    }

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
        return $this->saves;
    }

    public function getProducts($refresh = false)
    {
        return $this->products;
    }

    public function getDeliveryOptionList()
    {
        return $this->deliveryOptionList;
    }

    public function getDeliveryOption($defaultCountry = null, $dontAutoSelectOptions = false, $useCache = true)
    {
        if ($this->deliveryOption !== null) {
            $valid = true;
            foreach ($this->deliveryOption as $idAddress => $key) {
                $valid = $valid && isset($this->deliveryOptionList[$idAddress][$key]);
            }
            if ($valid) {
                return $this->deliveryOption;
            }
        }
        if ($dontAutoSelectOptions) {
            return false;
        }

        return array_map(fn (array $options): string => (string) array_key_first($options), $this->deliveryOptionList);
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
        Db::$pdo = null;
        Db::$affected = 0;
        Db::$failing = false;
        Product::$prices = [];
        Currency::$deleted = [];
        Currency::$inactive = [];
        Country::$inactive = [];
        Country::$unassociated = [];
        Context::$instance = null;
        Customer::$registered = [];
        Customer::$created = [];
        Customer::$failAdd = false;
        Address::$created = [];
        Cart::$added = [];
        Cart::$rejectQuantity = false;
        Address::$failAdd = false;
        PrestaShopLogger::$logs = [];
        \FD\PrismUcp\Ucp\RequestContext::set(null);
        \FD\PrismUcp\Ucp\AgentProfileFetcher::resetCache();
    }
}
