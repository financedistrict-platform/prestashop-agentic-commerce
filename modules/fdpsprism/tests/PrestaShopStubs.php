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

final class FdTestX402
{
    public const PAY_TO = '0x1111111111111111111111111111111111111111';
    public const PAYER = '0x2222222222222222222222222222222222222222';
    public const ASSET = '0x036CbD53842c5426634e7929541eC2318f3dCF7e';
    public const OTHER_ASSET = '0x9999999999999999999999999999999999999999';
    public const NETWORK = 'eip155:84532';
    public const RESOURCE = 'https://shop.example/checkout-sessions/s1';

    public static function accept(string $amount): array
    {
        return [
            'scheme' => 'exact',
            'network' => self::NETWORK,
            'asset' => self::ASSET,
            'amount' => $amount,
            'payTo' => self::PAY_TO,
            'maxTimeoutSeconds' => 300,
            'extra' => ['name' => 'USDC', 'version' => '2'],
        ];
    }

    public static function quote(string $amount): array
    {
        return [
            'x402Version' => 2,
            'resource' => ['url' => self::RESOURCE, 'description' => 'Order checkout at Shop'],
            'accepts' => [self::accept($amount)],
        ];
    }

    public static function credential(string $amount): array
    {
        return [
            'type' => 'x402',
            'paymentPayload' => [
                'x402Version' => 2,
                'resource' => ['url' => self::RESOURCE],
                'accepted' => self::accept($amount),
                'payload' => [
                    'signature' => '0x' . str_repeat('cd', 65),
                    'authorization' => [
                        'from' => self::PAYER,
                        'to' => self::PAY_TO,
                        'value' => $amount,
                        'validAfter' => '0',
                        'validBefore' => (string) (time() + 300),
                        'nonce' => '0x' . str_repeat('ab', 32),
                    ],
                ],
            ],
        ];
    }

    public static function checkoutMeta(string $amount, ?int $prepared = null): array
    {
        return [
            'prepared_amount' => $prepared ?? (int) $amount,
            'ucp' => ['xyz.fd.prism_payment' => [['id' => 'xyz.fd.prism_payment', 'version' => '2026-10-07', 'config' => self::quote($amount)]]],
        ];
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
