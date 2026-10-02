<?php

declare(strict_types=1);

use FD\PrismUcp\Payment\PaymentRegistry;
use FD\PrismUcp\Router;
use FD\PrismUcp\Ucp\VersionRegistry;
use FD\PrismUcp\Ucp\VersionResolver;
use PHPUnit\Framework\TestCase;

final class RegistryConfigTest extends TestCase
{
    private const AGENT = 'profile="https://agent.example/p"';

    protected function setUp(): void
    {
        FdTestStubs::reset();
    }

    private function router(?VersionResolver $resolver = null): Router
    {
        return new Router(new Context(), new PaymentRegistry(), FdTestGoldenRenderer::ENDPOINT, hash('sha256', ''), $resolver);
    }

    private function resolverDeclaring(string $version, ?VersionRegistry $versions = null): VersionResolver
    {
        return new VersionResolver(
            $versions ?? VersionRegistry::fromConfiguration(),
            new FdTestFixtureProfileFetcher(['https://agent.example/p' => FdTestFixtureProfileFetcher::declaring($version)])
        );
    }

    public function test_defaults_serve_the_latest_version_and_enable_all_three(): void
    {
        $versions = VersionRegistry::fromConfiguration();

        $this->assertSame('2026-08-25', $versions->current());
        $this->assertSame(['2026-08-25', '2026-04-08', '2026-01-23'], $versions->enabled());
        $this->assertSame('lenient', $versions->negotiation());
        $this->assertNull($versions->assertValid());
    }

    public function test_stored_values_are_read_from_configuration(): void
    {
        Configuration::$values = [
            VersionRegistry::KEY_CURRENT => '2026-08-25',
            VersionRegistry::KEY_SUPPORTED => '["2026-01-23"]',
            VersionRegistry::KEY_NEGOTIATION => 'strict',
        ];

        $versions = VersionRegistry::fromConfiguration();

        $this->assertSame(['2026-08-25', '2026-01-23'], $versions->enabled());
        $this->assertTrue($versions->isStrict());
    }

    public function test_current_version_is_removed_from_the_supported_list(): void
    {
        $versions = new VersionRegistry('2026-08-25', ['2026-08-25', '2026-04-08']);

        $this->assertSame(['2026-04-08'], $versions->supported());
        $this->assertNull($versions->assertValid());
    }

    public function test_unknown_values_are_configuration_errors(): void
    {
        $this->assertNotNull((new VersionRegistry('2026-01-11'))->assertValid());
        $this->assertNotNull((new VersionRegistry('2026-04-08', ['2025-12-01']))->assertValid());
        $this->assertNotNull((new VersionRegistry('2026-04-08', [], 'relaxed'))->assertValid());

        Configuration::$values[VersionRegistry::KEY_SUPPORTED] = '{"a":1}';
        $this->assertNotNull(VersionRegistry::fromConfiguration()->assertValid());
    }

    public function test_unknown_stored_value_makes_ucp_routes_answer_configuration_invalid(): void
    {
        Configuration::$values[VersionRegistry::KEY_CURRENT] = '2026-01-11';

        $response = $this->router()->dispatch('POST', 'checkout-sessions', [], []);

        $this->assertSame(500, $response->status);
        $this->assertSame('configuration_invalid', $response->body['messages'][0]['code']);
        $this->assertSame('2026-08-25', $response->body['ucp']['version']);
    }

    public function test_disabled_version_request_is_rejected_by_the_router(): void
    {
        Configuration::$values[VersionRegistry::KEY_SUPPORTED] = '[]';

        $response = $this->router($this->resolverDeclaring('2026-04-08'))->dispatch('POST', 'checkout-sessions', [], ['ucp-agent' => self::AGENT]);

        $this->assertSame(422, $response->status);
        $this->assertSame('version_unsupported', $response->body['messages'][0]['code']);
    }

    public function test_cart_and_catalog_routes_are_not_available_in_2026_01_23(): void
    {
        foreach (['carts', 'catalog/search'] as $path) {
            FdTestStubs::reset();
            $response = $this->router($this->resolverDeclaring('2026-01-23'))->dispatch('POST', $path, [], ['ucp-agent' => self::AGENT]);

            $this->assertSame(404, $response->status, $path);
            $this->assertSame('capabilities_incompatible', $response->body['messages'][0]['code'], $path);
            $this->assertSame('2026-01-23', $response->body['ucp']['version'], $path);
        }
    }

    public function test_matched_version_shapes_router_errors(): void
    {
        $response = $this->router($this->resolverDeclaring('2026-08-25'))->dispatch('GET', 'unknown', [], ['ucp-agent' => self::AGENT]);

        $this->assertSame(404, $response->status);
        $this->assertSame('2026-08-25', $response->body['ucp']['version']);
        $this->assertSame('unrecoverable', $response->body['messages'][0]['severity']);
    }
}
