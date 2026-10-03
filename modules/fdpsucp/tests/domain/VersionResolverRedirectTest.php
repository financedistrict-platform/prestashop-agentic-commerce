<?php

declare(strict_types=1);

use FD\PrismUcp\Ucp\RequestContext;
use FD\PrismUcp\Ucp\VersionRegistry;
use FD\PrismUcp\Ucp\VersionResolver;
use PHPUnit\Framework\TestCase;

final class VersionResolverRedirectTest extends TestCase
{
    private const PROFILE = 'https://agent.example/.well-known/ucp';
    private const FALLBACK_OUTCOMES = ['unreachable', 'undeclared'];

    protected function setUp(): void
    {
        FdTestStubs::reset();
    }

    private function resolve(array|string|null $response, string $negotiation = 'lenient', array $supported = FdTestLegacyVersionStore::SUPPORTED, bool $withHeader = true): RequestContext
    {
        $versions = new VersionRegistry(FdTestLegacyVersionStore::CURRENT, $supported, $negotiation);
        $fetcher = new FdTestFixtureProfileFetcher($response === null ? [] : [self::PROFILE => $response]);

        return (new VersionResolver($versions, $fetcher))->resolve($withHeader ? 'profile="' . self::PROFILE . '"' : null);
    }

    private function redirect(int $code, ?string $location): array
    {
        return ['code' => $code, 'body' => '', 'location' => (string) $location];
    }

    public function test_same_origin_redirect_serves_the_declared_version(): void
    {
        foreach ([301, 308] as $code) {
            FdTestStubs::reset();
            $versions = new VersionRegistry(FdTestLegacyVersionStore::CURRENT, FdTestLegacyVersionStore::SUPPORTED, 'lenient');
            $fetcher = new FdTestFixtureProfileFetcher([
                self::PROFILE => $this->redirect($code, self::PROFILE . '/'),
                self::PROFILE . '/' => FdTestFixtureProfileFetcher::declaring('2026-01-23'),
            ]);

            $context = (new VersionResolver($versions, $fetcher))->resolve('profile="' . self::PROFILE . '"');

            $this->assertSame('matched', $context->outcome(), (string) $code);
            $this->assertSame('2026-01-23', $context->version(), (string) $code);
            $this->assertNull($context->rejection(), (string) $code);
        }
    }

    public function test_cross_origin_redirect_is_rejected_and_logged_in_both_modes(): void
    {
        foreach (['lenient', 'strict'] as $mode) {
            FdTestStubs::reset();
            $context = $this->resolve($this->redirect(301, 'https://other.example/ucp'), $mode);

            $this->assertSame('redirected', $context->outcome(), $mode);
            $this->assertSame([
                'status' => 424,
                'code' => 'profile_redirected',
                'message' => 'Agent profile URL redirects to https://other.example/ucp; use the final URL.',
            ], $context->rejection(), $mode);
            $this->assertSame(
                '[FD UCP] ucp_profile_resolution=redirected host=agent.example rejected=profile_redirected location=https://other.example/ucp',
                PrestaShopLogger::$logs[0]['message'],
                $mode
            );
        }
    }

    public function test_userinfo_never_reaches_the_message_or_the_log(): void
    {
        $context = $this->resolve($this->redirect(301, 'https://user:secret@other.example/p'));

        $this->assertSame('Agent profile URL redirects to https://other.example/p; use the final URL.', $context->rejection()['message']);
        $this->assertStringContainsString('location=https://other.example/p', PrestaShopLogger::$logs[0]['message']);
        foreach ([$context->rejection()['message'], PrestaShopLogger::$logs[0]['message']] as $text) {
            $this->assertStringNotContainsString('secret', $text);
            $this->assertStringNotContainsString('user@', $text);
        }
    }

    public function test_missing_location_uses_the_short_message(): void
    {
        $context = $this->resolve($this->redirect(302, null));

        $this->assertSame('Agent profile URL redirects; use the final URL.', $context->rejection()['message']);
        $this->assertSame(
            '[FD UCP] ucp_profile_resolution=redirected host=agent.example rejected=profile_redirected location=',
            PrestaShopLogger::$logs[0]['message']
        );
    }

    public function test_two_hops_are_rejected(): void
    {
        $versions = new VersionRegistry(FdTestLegacyVersionStore::CURRENT, FdTestLegacyVersionStore::SUPPORTED, 'lenient');
        $fetcher = new FdTestFixtureProfileFetcher([
            self::PROFILE => $this->redirect(301, self::PROFILE . '/'),
            self::PROFILE . '/' => $this->redirect(301, self::PROFILE . '//'),
        ]);

        $context = (new VersionResolver($versions, $fetcher))->resolve('profile="' . self::PROFILE . '"');

        $this->assertSame('redirected', $context->outcome());
        $this->assertSame(424, $context->rejection()['status']);
    }

    public function test_downgrade_is_rejected(): void
    {
        $context = $this->resolve($this->redirect(301, 'http://agent.example/.well-known/ucp'));

        $this->assertSame('profile_redirected', $context->rejection()['code']);
    }

    public function test_cached_redirect_logs_again(): void
    {
        $this->resolve($this->redirect(301, 'https://other.example/ucp'));
        $this->resolve($this->redirect(301, 'https://other.example/ucp'));

        $this->assertCount(2, PrestaShopLogger::$logs);
    }

    public function test_other_rejections_keep_their_log_line(): void
    {
        $this->resolve(FdTestFixtureProfileFetcher::declaring('2026-01-11'));

        $this->assertSame(
            '[FD UCP] ucp_profile_resolution=unknown host=agent.example rejected=version_unsupported',
            PrestaShopLogger::$logs[0]['message']
        );
    }

    public static function outcomeScenarios(): array
    {
        $redirect = ['code' => 301, 'body' => '', 'location' => 'https://other.example/ucp'];

        return [
            RequestContext::OUTCOME_NONE => [null, FdTestLegacyVersionStore::SUPPORTED, false],
            RequestContext::OUTCOME_MATCHED => [FdTestFixtureProfileFetcher::declaring('2026-08-25'), FdTestLegacyVersionStore::SUPPORTED, true],
            RequestContext::OUTCOME_UNREACHABLE => [null, FdTestLegacyVersionStore::SUPPORTED, true],
            RequestContext::OUTCOME_UNDECLARED => [FdTestFixtureProfileFetcher::declaring(null), FdTestLegacyVersionStore::SUPPORTED, true],
            RequestContext::OUTCOME_UNKNOWN => [FdTestFixtureProfileFetcher::declaring('2026-01-11'), FdTestLegacyVersionStore::SUPPORTED, true],
            RequestContext::OUTCOME_DISABLED => [FdTestFixtureProfileFetcher::declaring('2026-08-25'), [], true],
            RequestContext::OUTCOME_REDIRECTED => [$redirect, FdTestLegacyVersionStore::SUPPORTED, true],
        ];
    }

    public function test_every_outcome_is_served_as_a_known_fallback_or_rejected_in_lenient_mode(): void
    {
        $scenarios = self::outcomeScenarios();
        $constants = (new ReflectionClass(RequestContext::class))->getConstants();
        $outcomes = array_values(array_filter($constants, static fn ($value, $name): bool => str_starts_with($name, 'OUTCOME_'), ARRAY_FILTER_USE_BOTH));
        $this->assertNotEmpty($outcomes);

        foreach ($outcomes as $outcome) {
            $this->assertArrayHasKey($outcome, $scenarios, "outcome '$outcome' has no resolver scenario");
            [$response, $supported, $withHeader] = $scenarios[$outcome];
            FdTestStubs::reset();

            $context = $this->resolve($response, 'lenient', $supported, $withHeader);

            $this->assertSame($outcome, $context->outcome(), $outcome);
            if (in_array($outcome, [RequestContext::OUTCOME_NONE, RequestContext::OUTCOME_MATCHED], true) || in_array($outcome, self::FALLBACK_OUTCOMES, true)) {
                $this->assertNull($context->rejection(), $outcome);

                continue;
            }
            $this->assertNotNull($context->rejection(), "outcome '$outcome' is silently served in lenient mode");
        }
    }
}
