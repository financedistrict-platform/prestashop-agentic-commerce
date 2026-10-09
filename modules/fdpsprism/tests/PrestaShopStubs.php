<?php

class Configuration
{
    public static array $values = [];

    public static function get(string $key): mixed
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

class Validate
{
    public static function isLoadedObject($object): bool
    {
        return is_object($object) && !empty($object->id);
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

class Cart
{
    public const BOTH = 3;

    public $id = 77;
    public $id_customer = 5;
    public $id_currency = 1;
    public $id_address_delivery = 9;
    public float $total = 46.95;
    public array $deliveryOption = [9 => '7,'];

    public function getOrderTotal($withTaxes = true, $type = self::BOTH)
    {
        return $this->total;
    }

    public function getDeliveryOptionList()
    {
        return [9 => ['7,' => []]];
    }

    public function isVirtualCart()
    {
        return false;
    }

    public function getDeliveryOption($defaultCountry = null, $dontAutoSelectOptions = false, $useCache = true)
    {
        return $this->deliveryOption;
    }
}

final class FdTestShipping
{
    public const FULFILLMENT = '{"methods":[{"groups":[{"selected_option_id":"7"}]}]}';

    public static function liveQuote(): string
    {
        return date('Y-m-d H:i:s', time() + 600);
    }
}
