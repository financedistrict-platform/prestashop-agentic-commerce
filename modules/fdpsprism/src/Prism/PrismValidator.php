<?php

namespace FD\PrismPayment\Prism;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class PrismValidator
{
    public const X402_VERSION = 2;

    private const SCHEME = 'exact';

    private const START_LEEWAY = 30;

    private const AUTHORIZATION_FIELDS = ['from', 'to', 'value', 'validAfter', 'validBefore', 'nonce'];

    public static function parseQuote(mixed $config): ?array
    {
        if (!is_array($config) || ($config['x402Version'] ?? null) !== self::X402_VERSION) {
            return null;
        }
        $resource = $config['resource'] ?? null;
        if (!is_array($resource) || self::text($resource['url'] ?? null) === null) {
            return null;
        }
        $accepts = $config['accepts'] ?? null;
        if (!is_array($accepts) || !array_is_list($accepts)) {
            return null;
        }
        $usable = array_values(array_filter($accepts, self::isSettleable(...)));
        if ($usable === []) {
            return null;
        }

        return array_replace($config, ['accepts' => $usable]);
    }

    public static function decode(mixed $credential): ?array
    {
        $decoded = self::decodeValue($credential);
        if ($decoded === null || !isset($decoded['authorization'])) {
            return $decoded;
        }
        if (isset($decoded['paymentPayload'])) {
            return null;
        }

        return self::decodeValue($decoded['authorization']);
    }

    public static function verify(array $credential, array $quote): VerifiedPayment|string
    {
        $payload = $credential['paymentPayload'] ?? $credential;
        if (!is_array($payload)) {
            return 'Payment payload is missing';
        }
        foreach ([$credential['x402Version'] ?? null, $payload['x402Version'] ?? null] as $version) {
            if ($version !== null && $version !== $quote['x402Version']) {
                return 'Payment protocol version does not match the stored payment requirements';
            }
        }

        $accepted = $payload['accepted'] ?? null;
        if (!is_array($accepted)) {
            return 'Signed payment does not state which requirement it accepts';
        }
        $requirement = self::matchRequirement($accepted, $quote['accepts']);
        if ($requirement === null) {
            return 'Signed payment does not match any stored payment requirement';
        }
        if (isset($payload['network']) && $payload['network'] !== $requirement['network']) {
            return 'Signed payment network does not match the accepted requirement';
        }
        if (array_key_exists('paymentRequirements', $credential)
            && !(is_array($credential['paymentRequirements']) && self::sameRequirement($credential['paymentRequirements'], $requirement))
        ) {
            return 'Forwarded payment requirements do not match the stored payment requirement';
        }
        if (array_key_exists('resource', $payload)
            && !(is_array($payload['resource']) && ($payload['resource']['url'] ?? null) === $quote['resource']['url'])
        ) {
            return 'Signed payment names another resource';
        }

        $signed = $payload['payload'] ?? null;
        $signature = is_array($signed) ? ($signed['signature'] ?? null) : null;
        $authorization = is_array($signed) ? ($signed['authorization'] ?? null) : null;
        if (!is_string($signature) || preg_match('/^0x[0-9a-fA-F]+$/', $signature) !== 1) {
            return 'Payment signature is missing';
        }
        if (!is_array($authorization)) {
            return 'Payment authorization is missing';
        }
        $error = self::authorizationError($authorization, $requirement);
        if ($error !== null) {
            return $error;
        }

        return new VerifiedPayment(
            $quote['x402Version'],
            $requirement,
            [
                'x402Version' => $quote['x402Version'],
                'resource' => $quote['resource'],
                'accepted' => $requirement,
                'payload' => [
                    'signature' => $signature,
                    'authorization' => self::signedFields($authorization),
                ],
            ],
            $requirement['network'],
        );
    }

    private static function authorizationError(array $authorization, array $requirement): ?string
    {
        foreach (self::AUTHORIZATION_FIELDS as $field) {
            if (!array_key_exists($field, $authorization)) {
                return "Payment authorization $field is missing";
            }
            if (self::text($authorization[$field]) === null) {
                return "Payment authorization $field must be a non-empty string";
            }
        }
        if (preg_match('/^0x[0-9a-fA-F]{40}$/', $authorization['from']) !== 1) {
            return 'Payment authorization from is not an address';
        }
        if (strcasecmp($authorization['to'], $requirement['payTo']) !== 0) {
            return 'Signed payment recipient does not match the expected payTo address';
        }
        foreach (['value', 'validAfter', 'validBefore'] as $field) {
            if (!ctype_digit($authorization[$field])) {
                return "Payment authorization $field must be a decimal string";
            }
        }
        if ($authorization['value'] !== $requirement['amount']) {
            return 'Signed amount does not equal the required amount';
        }
        if (preg_match('/^0x[0-9a-fA-F]{64}$/', $authorization['nonce']) !== 1) {
            return 'Payment authorization nonce is malformed';
        }
        $now = time();
        if ((int) $authorization['validAfter'] > $now + self::START_LEEWAY) {
            return 'Payment authorization is not valid yet';
        }
        if ((int) $authorization['validBefore'] <= $now) {
            return 'Payment authorization has expired';
        }

        return null;
    }

    private static function signedFields(array $authorization): array
    {
        $fields = [];
        foreach (self::AUTHORIZATION_FIELDS as $field) {
            $fields[$field] = $authorization[$field];
        }

        return $fields;
    }

    private static function matchRequirement(array $accepted, array $requirements): ?array
    {
        foreach ($requirements as $requirement) {
            if (self::sameRequirement($accepted, $requirement)) {
                return $requirement;
            }
        }

        return null;
    }

    private static function sameRequirement(array $candidate, array $requirement): bool
    {
        foreach (['scheme', 'network', 'amount'] as $field) {
            if (($candidate[$field] ?? null) !== $requirement[$field]) {
                return false;
            }
        }
        foreach (['asset', 'payTo'] as $field) {
            $value = $candidate[$field] ?? null;
            if (!is_string($value) || strcasecmp($value, $requirement[$field]) !== 0) {
                return false;
            }
        }

        return true;
    }

    private static function isSettleable(mixed $accept): bool
    {
        if (!is_array($accept) || ($accept['scheme'] ?? null) !== self::SCHEME) {
            return false;
        }
        $network = self::text($accept['network'] ?? null);
        $amount = self::text($accept['amount'] ?? null);

        return $network !== null
            && str_starts_with($network, 'eip155:')
            && self::text($accept['asset'] ?? null) !== null
            && self::text($accept['payTo'] ?? null) !== null
            && $amount !== null
            && ctype_digit($amount);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function decodeValue(mixed $input): ?array
    {
        if (is_array($input)) {
            return $input;
        }
        if (!is_string($input)) {
            return null;
        }
        $b64 = base64_decode($input, true);
        if ($b64 !== false) {
            $parsed = json_decode($b64, true);
            if (is_array($parsed)) {
                return $parsed;
            }
        }
        $parsed = json_decode($input, true);

        return is_array($parsed) ? $parsed : null;
    }
}
