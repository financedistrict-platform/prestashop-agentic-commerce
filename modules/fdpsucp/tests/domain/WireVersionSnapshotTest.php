<?php

declare(strict_types=1);

use FD\PrismUcp\Payment\PaymentRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WireVersionSnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        FdTestStubs::reset();
    }

    public static function snapshots(): array
    {
        $cases = [];
        foreach (['2026-08-25', '2026-01-23'] as $version) {
            foreach (FdTestGoldenRenderer::names($version) as $name) {
                $cases["$version/$name"] = [$version, $name];
            }
        }

        return $cases;
    }

    #[DataProvider('snapshots')]
    public function test_version_output_matches_snapshot(string $version, string $name): void
    {
        $path = FdTestGoldenRenderer::fixtures() . '/ucp/' . $version . '/' . $name . '.json';
        $rendered = FdTestGoldenRenderer::render($version, new PaymentRegistry())[$name];

        if (getenv('FD_UPDATE_SNAPSHOTS')) {
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $rendered);
        }

        $this->assertFileExists($path);
        $this->assertSame(file_get_contents($path), $rendered);
    }

    public function test_2026_01_23_has_no_cart_or_catalog(): void
    {
        $profile = json_decode(FdTestGoldenRenderer::render('2026-01-23', new PaymentRegistry())['profile'], true);

        foreach (array_keys($profile['ucp']['capabilities']) as $name) {
            $this->assertStringNotContainsString('cart', $name);
            $this->assertStringNotContainsString('catalog', $name);
        }
        $this->assertArrayNotHasKey('supported_versions', $profile['ucp']);
        $this->assertArrayHasKey('signing_keys', $profile);
    }

    public function test_2026_01_23_drops_available_instruments_from_handler_entries(): void
    {
        $profile = json_decode(FdTestGoldenRenderer::render('2026-01-23', FdTestGoldenRenderer::prismRegistry('current-handlers-2026-04-08.json'), ['2026-04-08'])['profile'], true);

        $entry = $profile['ucp']['payment_handlers']['xyz.fd.prism_payment'][0];
        $this->assertArrayNotHasKey('available_instruments', $entry);
        $this->assertArrayNotHasKey('supported_versions', $profile['ucp']);
    }

    public function test_2026_08_25_keeps_available_instruments_and_plugin_authored_fields(): void
    {
        $profile = json_decode(FdTestGoldenRenderer::render('2026-08-25', FdTestGoldenRenderer::prismRegistry('current-handlers-2026-04-08.json'))['profile'], true);

        $entry = $profile['ucp']['payment_handlers']['xyz.fd.prism_payment'][0];
        $this->assertSame([['type' => 'x402']], $entry['available_instruments']);
        $this->assertSame('Prism (x402 Stablecoin)', $entry['name']);
        $this->assertFalse($entry['config']['tokenization']);
        $this->assertArrayNotHasKey('signing_keys', $profile);
    }

    public function test_empty_handler_config_is_an_object_after_2026_04_08(): void
    {
        $registry = new PaymentRegistry();
        $registry->register(new FdTestEmptyConfigHandler());

        $this->assertStringContainsString('"config": []', FdTestGoldenRenderer::render('2026-04-08', $registry)['profile']);
        $this->assertStringContainsString('"config": {}', FdTestGoldenRenderer::render('2026-08-25', $registry)['profile']);
        $this->assertStringContainsString('"config": {}', FdTestGoldenRenderer::render('2026-01-23', $registry)['profile']);
    }
}

final class FdTestEmptyConfigHandler implements \FD\PrismUcp\Payment\PaymentHandlerInterface
{
    public function id(): string
    {
        return 'test';
    }

    public function name(): string
    {
        return 'Test';
    }

    public function getUcpDiscoveryHandlers(): array
    {
        return ['dev.test' => [['id' => 'test', 'version' => '1', 'config' => []]]];
    }

    public function prepareCheckoutPayment(array $input): ?array
    {
        return null;
    }

    public function settlePayment(array $input): array
    {
        return ['success' => false];
    }

    public function getUcpCheckoutHandlers(?array $paymentMeta = null): array
    {
        return [];
    }
}
