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

    public function getOrderTotal($withTaxes = true, $type = self::BOTH)
    {
        return 46.95;
    }
}
