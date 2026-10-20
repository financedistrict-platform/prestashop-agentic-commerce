<?php

declare(strict_types=1);

use FD\PrismUcp\Ucp\CapabilitySecret;
use PHPUnit\Framework\TestCase;

final class CapabilitySecretTest extends TestCase
{
    private const SECRET = 'a-real-capability-secret';

    private function hashOf(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public function test_issue_returns_a_base64url_secret_and_its_sha256_hash(): void
    {
        $first = CapabilitySecret::issue();
        $second = CapabilitySecret::issue();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $first['secret']);
        $this->assertSame(hash('sha256', $first['secret']), $first['hash']);
        $this->assertNotSame($first['secret'], $second['secret']);
        $this->assertTrue(CapabilitySecret::holds($first['hash'], $first['secret']));
    }

    public function test_holds_requires_a_stored_hash_and_the_matching_secret(): void
    {
        $stored = $this->hashOf(self::SECRET);

        $this->assertTrue(CapabilitySecret::holds($stored, self::SECRET));
        $this->assertFalse(CapabilitySecret::holds($stored, ''));
        $this->assertFalse(CapabilitySecret::holds($stored, 'nope'));
        $this->assertFalse(CapabilitySecret::holds('', ''));
        $this->assertFalse(CapabilitySecret::holds('', self::SECRET));
    }

    public function test_holds_rejects_a_malformed_stored_hash(): void
    {
        $stored = $this->hashOf(self::SECRET);

        $this->assertFalse(CapabilitySecret::holds('garbage', self::SECRET));
        $this->assertFalse(CapabilitySecret::holds(strtoupper($stored), self::SECRET));
    }

    public function test_no_legacy_verdict_helpers_remain(): void
    {
        $this->assertFalse(method_exists(CapabilitySecret::class, 'classify'));
        $this->assertFalse(method_exists(CapabilitySecret::class, 'authorizes'));
    }
}
