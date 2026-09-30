<?php

class Configuration
{
    public static array $values = [];

    public static function get(string $key): mixed
    {
        return self::$values[$key] ?? false;
    }
}

class PaymentModule
{
}
