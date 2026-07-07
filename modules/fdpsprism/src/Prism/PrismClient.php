<?php

namespace FD\PrismPayment\Prism;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Thin HTTP client for the Prism gateway. Ported from FD_Prism_Client
 * (woocommerce-prism-payment), using cURL instead of wp_remote_*. Every call
 * carries the X-API-Key header; non-2xx / invalid-JSON responses return null
 * (the caller decides how to degrade).
 */
final class PrismClient
{
    private string $apiUrl;
    private string $apiKey;

    public function __construct(string $apiUrl, string $apiKey)
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiKey = $apiKey;
    }

    /**
     * GET /api/v2/merchant/ucp/handlers — UCP discovery entries.
     *
     * @return array<string,mixed>|null
     */
    public function fetchUcpHandlers(): ?array
    {
        return $this->request('GET', '/api/v2/merchant/ucp/handlers', null, 15);
    }

    /**
     * POST /api/v2/merchant/ucp/payment-requirements — UCP checkout prepare.
     * Amount is in major fiat units (e.g. "15.00").
     *
     * @return array<string,mixed>|null
     */
    public function prepareUcpPayment(string $amount, string $currency, string $resourceUrl, string $description): ?array
    {
        return $this->request('POST', '/api/v2/merchant/ucp/payment-requirements', [
            'amount' => $amount,
            'currency' => $currency,
            'resource' => [
                'url' => $resourceUrl,
                'description' => $description,
            ],
        ], 30);
    }

    /**
     * POST /api/v{version}/payment/settle — settle on-chain.
     *
     * @param array<string,mixed> $x402Authorization
     * @return array<string,mixed>|null
     */
    public function settle(array $x402Authorization): ?array
    {
        $version = (int) ($x402Authorization['x402Version']
            ?? $x402Authorization['paymentPayload']['x402Version'] ?? 2);
        $body = [
            'paymentPayload' => $x402Authorization['paymentPayload'] ?? $x402Authorization,
            'paymentRequirements' => $x402Authorization['paymentRequirements'] ?? null,
        ];

        return $this->request('POST', "/api/v{$version}/payment/settle", $body, 30);
    }

    /**
     * POST /api/v{version}/payment/verify — verify x402 authorization.
     *
     * @param array<string,mixed> $x402Authorization
     * @return array<string,mixed>|null
     */
    public function verify(array $x402Authorization): ?array
    {
        $version = (int) ($x402Authorization['x402Version'] ?? 2);

        return $this->request('POST', "/api/v{$version}/payment/verify", $x402Authorization, 30);
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>|null
     */
    private function request(string $method, string $path, ?array $body, int $timeout): ?array
    {
        if (!function_exists('curl_init')) {
            \PrestaShopLogger::addLog("[FD Prism] cURL unavailable for $method $path", 3);

            return null;
        }

        $ch = curl_init($this->apiUrl . $path);
        $headers = [
            'X-API-Key: ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            // Explicit TLS verification — the API key travels on this connection.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            \PrestaShopLogger::addLog("[FD Prism] $method $path failed: $err", 3);

            return null;
        }
        if ($code < 200 || $code >= 300) {
            \PrestaShopLogger::addLog("[FD Prism] $method $path returned HTTP $code: " . substr((string) $raw, 0, 500), 3);

            return null;
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            \PrestaShopLogger::addLog("[FD Prism] $method $path returned invalid JSON: " . substr((string) $raw, 0, 500), 3);

            return null;
        }

        return $decoded;
    }

    /** Convert minor units (cents) to a major-unit decimal string. 4500 → "45.00". */
    public static function minorToMajorString(int $minorUnits): string
    {
        return number_format($minorUnits / 100, 2, '.', '');
    }
}
