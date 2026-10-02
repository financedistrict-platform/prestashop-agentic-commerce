<?php

if (!class_exists('Configuration')) {
    class Configuration
    {
        public static function get($key)
        {
            return [
                'PS_SHOP_NAME' => 'Demo Store',
                'PS_OS_PAYMENT' => 2,
                'PS_OS_SHIPPING' => 4,
                'PS_OS_DELIVERED' => 5,
                'PS_OS_CANCELED' => 6,
                'PS_OS_PREPARATION' => 3,
                'FDPSPRISM_API_URL' => 'https://gw.example',
                'FDPSPRISM_API_KEY' => 'test-key',
            ][$key] ?? false;
        }
    }
}

if (!class_exists('Order')) {
    class Order
    {
        public $id = 1001;
        public $reference = 'XKBKNABJK';
    }
}

if (!class_exists('PaymentModule')) {
    class PaymentModule
    {
    }
}

if (!class_exists('PrestaShopLogger')) {
    class PrestaShopLogger
    {
        public static function addLog(...$args)
        {
            return true;
        }
    }
}

if (!class_exists('Currency')) {
    class Currency
    {
        public $iso_code = 'EUR';
    }
}

if (!class_exists('Context')) {
    class Context
    {
        public $currency;

        public function __construct()
        {
            $this->currency = new Currency();
        }
    }
}
