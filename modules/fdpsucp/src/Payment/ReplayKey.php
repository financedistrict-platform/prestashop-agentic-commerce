<?php

namespace FD\PrismUcp\Payment;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class ReplayKey
{
    public static function authorization(string $network, string $asset, string $payer, string $nonce): string
    {
        return hash('sha256', implode('|', array_map('strtolower', [$network, $asset, $payer, $nonce])));
    }

    public static function transaction(string $reference): string
    {
        return strtolower(trim($reference));
    }
}
