<?php

namespace FD\PrismUcp\Ucp;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class CapabilitySecret
{
    public const HEADER = 'UCP-Session-Secret';

    /** @return array{secret:string,hash:string} */
    public static function issue(): array
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return ['secret' => $secret, 'hash' => self::digest($secret)];
    }

    public static function digest(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public static function holds(string $storedHash, string $providedSecret): bool
    {
        return $providedSecret !== ''
            && preg_match('/^[0-9a-f]{64}$/', $storedHash) === 1
            && hash_equals($storedHash, self::digest($providedSecret));
    }
}
