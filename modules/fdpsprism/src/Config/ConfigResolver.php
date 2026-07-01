<?php

namespace FD\PrismPayment\Config;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Resolves the Prism gateway URL + API key for the current shop.
 *
 * Follows the WooCommerce model (the port-first template): both the gateway
 * URL and the key are plain, editable BO settings — there is no env switch and
 * no hardcoded-prod constant beyond the default the merchant sees. `Configuration`
 * reads/writes are shop-aware (current shop context, global fallback — FR-16).
 */
final class ConfigResolver
{
    public const DEFAULT_URL = 'https://prism-gw.fd.xyz';
    public const KEY_URL = 'FDPSPRISM_API_URL';
    public const KEY_API = 'FDPSPRISM_API_KEY';

    /** Gateway base URL for the current shop (defaults to prod if unset). */
    public static function apiUrl(): string
    {
        $url = trim((string) \Configuration::get(self::KEY_URL));

        return $url !== '' ? rtrim($url, '/') : self::DEFAULT_URL;
    }

    /** Prism API key for the current shop ('' if not configured). */
    public static function apiKey(): string
    {
        return trim((string) \Configuration::get(self::KEY_API));
    }

    /** True when both URL and key are present, i.e. the handler can call the gateway. */
    public static function isConfigured(): bool
    {
        return self::apiUrl() !== '' && self::apiKey() !== '';
    }
}
