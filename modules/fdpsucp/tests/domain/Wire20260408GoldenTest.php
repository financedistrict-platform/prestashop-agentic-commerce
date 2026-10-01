<?php

declare(strict_types=1);

use FD\PrismUcp\Payment\PaymentRegistry;
use FD\PrismUcp\Ucp\Formatter;
use FD\PrismUcp\Ucp\VersionRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Wire20260408GoldenTest extends TestCase
{
    private const VERSION = '2026-04-08';

    protected function setUp(): void
    {
        FdTestStubs::reset();
    }

    public static function documents(): array
    {
        $names = FdTestGoldenRenderer::names(self::VERSION);

        return array_combine($names, array_map(static fn (string $name): array => [$name], $names));
    }

    #[DataProvider('documents')]
    public function test_empty_registry_output_is_byte_equal_to_original_release(string $name): void
    {
        $rendered = FdTestGoldenRenderer::render(self::VERSION, new PaymentRegistry());

        $this->assertSame(
            file_get_contents(FdTestGoldenRenderer::fixtures() . '/ucp/' . self::VERSION . '/' . $name . '.json'),
            $rendered[$name]
        );
    }

    public function test_default_supported_versions_only_add_the_supported_versions_key(): void
    {
        $original = json_decode(FdTestGoldenRenderer::render(self::VERSION, new PaymentRegistry())['profile'], true);
        $default = json_decode(FdTestGoldenRenderer::render(self::VERSION, new PaymentRegistry(), VersionRegistry::DEFAULT_SUPPORTED)['profile'], true);

        $this->assertSame(
            [
                '2026-08-25' => 'https://store.test/.well-known/ucp/2026-08-25',
                '2026-01-23' => 'https://store.test/.well-known/ucp/2026-01-23',
            ],
            $default['ucp']['supported_versions']
        );
        unset($default['ucp']['supported_versions']);
        $this->assertSame(json_encode($original), json_encode($default));
    }

    public function test_default_supported_versions_leave_other_documents_unchanged(): void
    {
        $original = FdTestGoldenRenderer::render(self::VERSION, new PaymentRegistry());
        $default = FdTestGoldenRenderer::render(self::VERSION, new PaymentRegistry(), VersionRegistry::DEFAULT_SUPPORTED);

        unset($original['profile'], $default['profile']);
        $this->assertSame($original, $default);
    }

    public function test_leaf_profile_never_contains_supported_versions(): void
    {
        foreach (VersionRegistry::known() as $version) {
            $result = $this->discovery(new VersionRegistry(), $version);

            $this->assertSame(200, $result['status'], $version);
            $this->assertArrayNotHasKey('supported_versions', $result['body']['ucp'], $version);
            $this->assertSame($version, $result['body']['ucp']['version']);
        }
    }

    public function test_root_profile_lists_enabled_versions_with_leaf_urls(): void
    {
        $result = $this->discovery(new VersionRegistry(), '');

        $this->assertSame(self::VERSION, $result['body']['ucp']['version']);
        $this->assertSame(['2026-08-25', '2026-01-23'], array_keys($result['body']['ucp']['supported_versions']));
    }

    public function test_leaf_profile_of_disabled_version_is_not_found(): void
    {
        $result = $this->discovery(new VersionRegistry(self::VERSION, []), '2026-08-25');

        $this->assertSame(404, $result['status']);
        $this->assertSame('version_unsupported', $result['body']['messages'][0]['code']);
    }

    public function test_invalid_configuration_makes_discovery_answer_configuration_invalid(): void
    {
        $result = $this->discovery(new VersionRegistry(self::VERSION, [], 'relaxed'), '');

        $this->assertSame(500, $result['status']);
        $this->assertSame('configuration_invalid', $result['body']['messages'][0]['code']);
    }

    private function discovery(VersionRegistry $versions, string $leaf): array
    {
        return Formatter::discovery($versions, $leaf, new PaymentRegistry(), FdTestGoldenRenderer::STORE_BASE, FdTestGoldenRenderer::ENDPOINT, 'Demo Store');
    }
}
