<?php

declare(strict_types=1);

use FD\PrismUcp\Ucp\RequestContext;
use FD\PrismUcp\Ucp\VersionPin;
use FD\PrismUcp\Ucp\VersionResolver;
use PHPUnit\Framework\TestCase;

final class SessionPinTest extends TestCase
{
    protected function setUp(): void
    {
        FdTestStubs::reset();
    }

    public function test_pinned_session_with_unreachable_profile_keeps_its_version(): void
    {
        $context = VersionResolverTest::resolveWith(null, 'lenient', FdTestLegacyVersionStore::SUPPORTED, '2026-08-25');

        $this->assertNull($context->rejection());
        $this->assertSame('2026-08-25', $context->version());
        $this->assertSame('unreachable', $context->outcome());
    }

    public function test_pinned_session_with_undeclared_or_unknown_profile_keeps_its_version(): void
    {
        foreach ([null, '2026-01-11'] as $declared) {
            FdTestStubs::reset();
            $context = VersionResolverTest::resolveWith(FdTestFixtureProfileFetcher::declaring($declared), 'lenient', FdTestLegacyVersionStore::SUPPORTED, '2026-08-25');

            $this->assertNull($context->rejection());
            $this->assertSame('2026-08-25', $context->version());
        }
    }

    public function test_pinned_session_with_matched_different_version_is_rejected(): void
    {
        $context = VersionResolverTest::resolveWith(FdTestFixtureProfileFetcher::declaring('2026-04-08'), 'lenient', FdTestLegacyVersionStore::SUPPORTED, '2026-08-25');

        $this->assertSame(422, $context->rejection()['status']);
        $this->assertSame('version_unsupported', $context->rejection()['code']);
    }

    public function test_pinned_session_with_matched_same_version_is_served(): void
    {
        $context = VersionResolverTest::resolveWith(FdTestFixtureProfileFetcher::declaring('2026-08-25'), 'lenient', FdTestLegacyVersionStore::SUPPORTED, '2026-08-25');

        $this->assertNull($context->rejection());
        $this->assertSame('2026-08-25', $context->version());
    }

    public function test_pinned_session_in_strict_mode_follows_the_strict_rows(): void
    {
        $context = VersionResolverTest::resolveWith(null, 'strict', FdTestLegacyVersionStore::SUPPORTED, '2026-08-25');

        $this->assertSame(424, $context->rejection()['status']);
    }

    public function test_only_a_matched_resolution_pins_a_new_session(): void
    {
        $this->assertSame('2026-08-25', VersionResolverTest::resolveWith(FdTestFixtureProfileFetcher::declaring('2026-08-25'))->sessionPin());
        FdTestStubs::reset();
        $this->assertNull(VersionResolverTest::resolveWith(null)->sessionPin());
        FdTestStubs::reset();
        $this->assertNull(VersionResolverTest::resolveWith(FdTestFixtureProfileFetcher::declaring('2026-01-11'))->sessionPin());
        $this->assertNull((new VersionResolver(FdTestLegacyVersionStore::registry(), new FdTestFixtureProfileFetcher([])))->resolve(null)->sessionPin());
    }

    public function test_pinned_session_without_agent_header_keeps_its_version(): void
    {
        $context = (new VersionResolver(FdTestLegacyVersionStore::registry(), new FdTestFixtureProfileFetcher([])))->resolve(null, '2026-01-23');

        $this->assertSame('2026-01-23', $context->version());
    }

    public function test_session_rows_without_a_version_serve_current_or_the_matched_version(): void
    {
        $resolver = new VersionResolver(
            FdTestLegacyVersionStore::registry(),
            new FdTestFixtureProfileFetcher(['https://agent.example/p' => FdTestFixtureProfileFetcher::declaring('2026-08-25')])
        );

        $this->assertNull((new VersionPin($resolver, null))->check(null));
        $this->assertSame('2026-04-08', RequestContext::current()->version());

        $this->assertNull((new VersionPin($resolver, 'profile="https://agent.example/p"'))->check(null));
        $this->assertSame('2026-08-25', RequestContext::current()->version());
    }

    public function test_pin_rejection_answers_in_the_current_wire_shape(): void
    {
        $resolver = new VersionResolver(
            FdTestLegacyVersionStore::registry(),
            new FdTestFixtureProfileFetcher(['https://agent.example/p' => FdTestFixtureProfileFetcher::declaring('2026-04-08')])
        );

        $response = (new VersionPin($resolver, 'profile="https://agent.example/p"'))->check('2026-08-25');

        $this->assertSame(422, $response->status);
        $this->assertSame('version_unsupported', $response->body['messages'][0]['code']);
        $this->assertSame('2026-04-08', $response->body['ucp']['version']);
    }
}
