<?php

declare(strict_types=1);

use FD\PrismUcp\Ucp\RequestContext;
use FD\PrismUcp\Ucp\VersionRegistry;
use FD\PrismUcp\Ucp\VersionResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VersionResolverTest extends TestCase
{
    public const PROFILE = 'https://agent.example/.well-known/ucp';

    protected function setUp(): void
    {
        FdTestStubs::reset();
    }

    public static function resolveWith(
        ?string $body,
        string $negotiation = 'lenient',
        array $supported = FdTestLegacyVersionStore::SUPPORTED,
        ?string $pinned = null,
        ?string $header = null
    ): RequestContext {
        $versions = new VersionRegistry(FdTestLegacyVersionStore::CURRENT, $supported, $negotiation);
        $fetcher = new FdTestFixtureProfileFetcher($body === null ? [] : [self::PROFILE => $body]);

        return (new VersionResolver($versions, $fetcher))->resolve($header ?? 'profile="' . self::PROFILE . '"', $pinned);
    }

    public static function table(): array
    {
        $declares = static fn (?string $v): string => FdTestFixtureProfileFetcher::declaring($v);

        return [
            'unreachable lenient' => [null, 'lenient', null, 'unreachable', '2026-04-08', null],
            'unreachable strict' => [null, 'strict', 424, 'unreachable', null, 'profile_unreachable'],
            'undeclared lenient' => [$declares(null), 'lenient', null, 'undeclared', '2026-04-08', null],
            'undeclared strict' => [$declares(null), 'strict', 422, 'undeclared', null, 'profile_malformed'],
            'malformed lenient' => [$declares('April 2026'), 'lenient', null, 'undeclared', '2026-04-08', null],
            'not json lenient' => ['<html>', 'lenient', null, 'unreachable', '2026-04-08', null],
            'unknown lenient' => [$declares('2026-01-11'), 'lenient', 422, 'unknown', null, 'version_unsupported'],
            'unknown strict' => [$declares('2026-01-11'), 'strict', 422, 'unknown', null, 'version_unsupported'],
            'matched lenient' => [$declares('2026-08-25'), 'lenient', null, 'matched', '2026-08-25', null],
            'matched strict' => [$declares('2026-01-23'), 'strict', null, 'matched', '2026-01-23', null],
            'matched current' => [$declares('2026-04-08'), 'lenient', null, 'matched', '2026-04-08', null],
        ];
    }

    #[DataProvider('table')]
    public function test_resolution_table(?string $body, string $negotiation, ?int $status, string $outcome, ?string $served, ?string $code): void
    {
        $context = self::resolveWith($body, $negotiation);

        $this->assertSame($outcome, $context->outcome());
        if ($status === null) {
            $this->assertNull($context->rejection());
            $this->assertSame($served, $context->version());

            return;
        }
        $this->assertSame($status, $context->rejection()['status']);
        $this->assertSame($code, $context->rejection()['code']);
    }

    public function test_missing_header_keeps_todays_behaviour(): void
    {
        $fetcher = new FdTestFixtureProfileFetcher([]);
        $context = (new VersionResolver(FdTestLegacyVersionStore::registry(), $fetcher))->resolve(null);

        $this->assertSame('none', $context->outcome());
        $this->assertSame('2026-04-08', $context->version());
        $this->assertSame([], $fetcher->requested);
    }

    public function test_header_without_profile_keeps_todays_behaviour(): void
    {
        $context = self::resolveWith(null, 'strict', FdTestLegacyVersionStore::SUPPORTED, null, 'agent-a/1.0');

        $this->assertSame('none', $context->outcome());
        $this->assertNull($context->rejection());
    }

    public function test_disabled_version_is_rejected_in_both_modes(): void
    {
        foreach (['lenient', 'strict'] as $mode) {
            FdTestStubs::reset();
            $context = self::resolveWith(FdTestFixtureProfileFetcher::declaring('2026-08-25'), $mode, []);

            $this->assertSame('disabled', $context->outcome(), $mode);
            $this->assertSame(422, $context->rejection()['status'], $mode);
            $this->assertSame('version_unsupported', $context->rejection()['code'], $mode);
        }
    }

    public function test_rejection_body_lists_enabled_versions_in_current_wire_shape(): void
    {
        $response = self::resolveWith(FdTestFixtureProfileFetcher::declaring('2026-08-25'), 'lenient', ['2026-01-23'])->rejectionResponse();

        $this->assertSame(422, $response->status);
        $this->assertSame('2026-04-08', $response->body['ucp']['version']);
        $this->assertSame(
            'Version 2026-08-25 is not supported. This business implements versions 2026-04-08, 2026-01-23.',
            $response->body['messages'][0]['content']
        );
    }

    public function test_fallback_logs_a_warning_with_the_outcome_label(): void
    {
        self::resolveWith(null);

        $this->assertSame(2, PrestaShopLogger::$logs[0]['severity']);
        $this->assertStringContainsString('ucp_profile_resolution=unreachable', PrestaShopLogger::$logs[0]['message']);
    }

    public function test_resolution_fires_the_profile_resolution_hook(): void
    {
        self::resolveWith(FdTestFixtureProfileFetcher::declaring('2026-08-25'));

        $this->assertContains(
            ['actionFdUcpProfileResolution', ['outcome' => 'matched', 'version' => '2026-08-25', 'host' => 'agent.example']],
            Hook::$calls
        );
    }
}
