<?php

namespace FD\PrismUcp\Ucp;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Ownership check for the per-resource capability secret (carts, checkout
 * sessions, and the orders they produce). Pure logic — no PrestaShop or HTTP —
 * so the missing / wrong / legacy paths are unit-testable in isolation; the
 * services turn the verdict into the right Response.
 *
 * The secret is minted at create, returned once, and hashed (sha256) at rest.
 * Resources created before 0.5.0 have no stored hash and stay accessible.
 */
final class CapabilitySecret
{
    /** Caller is authorized (legacy resource, or the secret matches). */
    public const OK = 'ok';
    /** No secret was supplied — the common integration mistake. */
    public const MISSING = 'missing';
    /** A secret was supplied but does not match — a real ownership violation. */
    public const MISMATCH = 'mismatch';

    /**
     * @return self::OK|self::MISSING|self::MISMATCH
     */
    public static function classify(string $storedHash, string $providedSecret): string
    {
        if ($storedHash === '') {
            return self::OK; // legacy resource, no secret required
        }
        if ($providedSecret === '') {
            return self::MISSING;
        }

        return hash_equals($storedHash, hash('sha256', $providedSecret)) ? self::OK : self::MISMATCH;
    }

    /** Convenience boolean: is the caller allowed to proceed? */
    public static function authorizes(string $storedHash, string $providedSecret): bool
    {
        return self::classify($storedHash, $providedSecret) === self::OK;
    }
}
