<?php

declare(strict_types=1);

use FD\PrismUcp\Ucp\CapabilitySecret;
use PHPUnit\Framework\TestCase;

/**
 * The per-resource capability-secret ownership check: legacy (no stored hash),
 * missing secret (the common integration mistake), wrong secret, and match.
 */
final class CapabilitySecretTest extends TestCase
{
    private const SECRET = 'a-real-capability-secret';

    private function hashOf(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public function test_legacy_resource_without_stored_hash_is_ok(): void
    {
        $this->assertSame(CapabilitySecret::OK, CapabilitySecret::classify('', ''));
        $this->assertSame(CapabilitySecret::OK, CapabilitySecret::classify('', 'anything'));
        $this->assertTrue(CapabilitySecret::authorizes('', ''));
    }

    public function test_missing_secret_is_distinguished_from_mismatch(): void
    {
        $stored = $this->hashOf(self::SECRET);

        $this->assertSame(CapabilitySecret::MISSING, CapabilitySecret::classify($stored, ''));
        $this->assertSame(CapabilitySecret::MISMATCH, CapabilitySecret::classify($stored, 'wrong-secret'));
    }

    public function test_correct_secret_authorizes(): void
    {
        $stored = $this->hashOf(self::SECRET);

        $this->assertSame(CapabilitySecret::OK, CapabilitySecret::classify($stored, self::SECRET));
        $this->assertTrue(CapabilitySecret::authorizes($stored, self::SECRET));
    }

    public function test_authorizes_is_false_for_missing_and_wrong(): void
    {
        $stored = $this->hashOf(self::SECRET);

        $this->assertFalse(CapabilitySecret::authorizes($stored, ''));
        $this->assertFalse(CapabilitySecret::authorizes($stored, 'nope'));
    }
}
