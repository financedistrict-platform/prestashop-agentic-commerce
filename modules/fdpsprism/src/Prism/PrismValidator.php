<?php

namespace FD\PrismPayment\Prism;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Binding guard (NFR-1). Ported from FD_Prism_Validator. Confirms the agent's
 * signed x402 credential matches the payment requirements we quoted — same
 * network/asset/recipient and an amount that is not short — before we ask Prism
 * to settle. Returns true on success or a human-readable error string.
 */
final class PrismValidator
{
    /**
     * @param array<string,mixed> $summary {network, asset, value, to}
     * @param array<int,array<string,mixed>> $accepts stored accepts[] from the quote
     * @return true|string
     */
    public static function validate(array $summary, array $accepts)
    {
        // Fail closed on an incomplete credential: the signed summary MUST carry
        // every field we guard on, independent of which accept it matches. These
        // are checked up front so the per-accept loop below can `continue` past a
        // non-matching accept (e.g. a different asset) without ever waving through
        // a credential that simply omitted a field (money-movement guard, NFR-1).
        if (empty($summary['asset'])) {
            return 'Signed payment asset is missing';
        }
        if (empty($summary['to'])) {
            return 'Signed payment recipient is missing';
        }
        $signedAmount = (string) ($summary['value'] ?? '');
        if (!self::isNonNegativeInteger($signedAmount)) {
            return 'Signed payment amount is missing or not a valid integer';
        }

        foreach ($accepts as $accept) {
            // Network must match this accept.
            if (($accept['network'] ?? null) !== ($summary['network'] ?? null)) {
                continue;
            }

            // Asset must match — but a mismatch just means THIS accept is not the
            // one; try the next. This is essential for multi-asset networks: e.g.
            // if EURC is listed before USDC on the same network, a USDC payment
            // must still match the USDC accept rather than be rejected at EURC.
            if (empty($accept['asset'])
                || strcasecmp((string) $accept['asset'], (string) $summary['asset']) !== 0
            ) {
                continue;
            }

            // The accept now matches network + asset. Recipient and amount MUST
            // hold — a mismatch here signals tampering, not a different accept.
            if (empty($accept['payTo'])
                || strcasecmp((string) $accept['payTo'], (string) $summary['to']) !== 0
            ) {
                return 'Signed payment recipient does not match the expected payTo address';
            }

            $storedAmount = (string) ($accept['amount'] ?? '');
            if (!self::isNonNegativeInteger($storedAmount)) {
                return 'Stored payment requirement has an invalid amount';
            }
            if (self::compareBigInt($signedAmount, $storedAmount) < 0) {
                return "Signed amount ($signedAmount) is less than required ($storedAmount)";
            }

            return true;
        }

        return 'No stored payment requirement matches network=' . ($summary['network'] ?? '');
    }

    /**
     * Extract {network, asset, value, to} from an x402 credential. Handles
     * base64 string, nested paymentPayload, and flat-object wire formats.
     *
     * @param mixed $credential
     * @return array<string,string>|null
     */
    public static function extractSignedSummary($credential): ?array
    {
        $decoded = self::decodeToArray($credential);
        if ($decoded === null) {
            return null;
        }

        // Format: { paymentPayload: { network, payload: { authorization: { to, value } } } }
        $payload = $decoded['paymentPayload'] ?? null;
        if (is_array($payload)) {
            $auth = $payload['payload']['authorization'] ?? null;
            if (is_array($auth)) {
                $accepted = $payload['accepted'] ?? [];

                return [
                    'network' => (string) ($payload['network'] ?? $accepted['network'] ?? ''),
                    'asset' => (string) ($decoded['paymentRequirements']['asset']
                        ?? $accepted['asset'] ?? $payload['payload']['asset'] ?? ''),
                    'value' => (string) ($auth['value'] ?? '0'),
                    'to' => (string) ($auth['to'] ?? ''),
                ];
            }
        }

        // Format: flat { network, asset, value, to }
        if (isset($decoded['network'], $decoded['value'])) {
            return [
                'network' => (string) $decoded['network'],
                'asset' => (string) ($decoded['asset'] ?? ''),
                'value' => (string) $decoded['value'],
                'to' => (string) ($decoded['to'] ?? ''),
            ];
        }

        return null;
    }

    /**
     * Pull the stored accepts[] entries out of this handler's payment-meta node
     * (the `ucp` block returned by Prism's prepare).
     *
     * @param array<string,mixed>|null $meta the handler's own payment_meta node
     * @return array<int,array<string,mixed>>|null
     */
    public static function readStoredAccepts(?array $meta): ?array
    {
        $ucp = $meta['ucp'] ?? null;
        if (!is_array($ucp)) {
            return null;
        }
        foreach ($ucp as $entries) {
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if (isset($entry['config']['accepts']) && is_array($entry['config']['accepts'])) {
                    return $entry['config']['accepts'];
                }
            }
        }

        return null;
    }

    /** A base-10 integer string with no sign, decimal point, or exponent. */
    private static function isNonNegativeInteger(string $value): bool
    {
        return $value !== '' && ctype_digit($value);
    }

    /** BigInt-safe compare: -1 / 0 / 1. Uses bcmath/gmp when available. */
    private static function compareBigInt(string $a, string $b): int
    {
        if (function_exists('bccomp')) {
            return bccomp($a, $b, 0);
        }
        if (function_exists('gmp_cmp')) {
            return (int) gmp_cmp($a, $b);
        }
        $len = max(strlen($a), strlen($b));

        return strcmp(
            str_pad($a, $len, '0', STR_PAD_LEFT),
            str_pad($b, $len, '0', STR_PAD_LEFT)
        );
    }

    /**
     * @param mixed $input
     * @return array<string,mixed>|null
     */
    private static function decodeToArray($input): ?array
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
