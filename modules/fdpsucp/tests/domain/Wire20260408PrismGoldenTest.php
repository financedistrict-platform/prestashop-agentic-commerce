<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Wire20260408PrismGoldenTest extends TestCase
{
    private const VERSION = '2026-04-08';
    private const GOLDEN = '2026-04-08-prism';
    private const NS = 'xyz.fd.prism_payment';
    private const PRISM_OWNED = ['id', 'version', 'spec', 'schema', 'config_schema', 'instrument_schemas', 'available_instruments'];

    protected function setUp(): void
    {
        FdTestStubs::reset();
    }

    public static function lanes(): array
    {
        $lanes = [];
        foreach (['current-handlers-2026-04-08.json', 'legacy-handlers.json'] as $recorded) {
            foreach (FdTestGoldenRenderer::names(self::VERSION) as $name) {
                $lanes["$recorded vs $name"] = [$recorded, $name];
            }
        }

        return $lanes;
    }

    #[DataProvider('lanes')]
    public function test_upgraded_plugin_matches_original_release_under_the_diff_policy(string $recorded, string $name): void
    {
        $rendered = FdTestGoldenRenderer::render(self::VERSION, FdTestGoldenRenderer::prismRegistry($recorded))[$name];
        $original = file_get_contents(FdTestGoldenRenderer::fixtures() . '/ucp/' . self::GOLDEN . '/' . $name . '.json');

        $this->assertSame($this->without($original, self::PRISM_OWNED), $this->without($rendered, self::PRISM_OWNED));
        $this->assertSame($this->pluginAuthored($original), $this->pluginAuthored($rendered));
        $this->assertSame($this->outsideEntries($original), $this->outsideEntries($rendered));
    }

    public function test_profile_keeps_the_prism_handler_for_both_recorded_shapes(): void
    {
        foreach (['current-handlers-2026-04-08.json', 'legacy-handlers.json'] as $recorded) {
            FdTestStubs::reset();
            $profile = json_decode(FdTestGoldenRenderer::render(self::VERSION, FdTestGoldenRenderer::prismRegistry($recorded))['profile'], true);
            $entries = $profile['ucp']['payment_handlers'][self::NS];

            $this->assertCount(1, $entries, $recorded);
            $this->assertSame(self::NS, $entries[0]['id'], $recorded);
            $this->assertIsString($entries[0]['schema'], $recorded);
            $this->assertSame('Prism (x402 Stablecoin)', $entries[0]['name'], $recorded);
        }
    }

    public function test_profile_omits_the_prism_handler_when_prism_is_unreachable(): void
    {
        $profile = json_decode(FdTestGoldenRenderer::render(self::VERSION, FdTestGoldenRenderer::prismRegistry(null))['profile'], true);

        $this->assertSame([], (array) $profile['ucp']['payment_handlers']);
        $this->assertSame(3, PrestaShopLogger::$logs[0]['severity']);
    }

    private function entries(object $document): array
    {
        return $document->ucp->payment_handlers->{self::NS} ?? [];
    }

    private function without(string $json, array $keys): string
    {
        $document = json_decode($json);
        foreach ($this->entries($document) as $entry) {
            foreach ($keys as $key) {
                unset($entry->$key);
            }
        }

        return json_encode($document, FdTestGoldenRenderer::FLAGS);
    }

    private function pluginAuthored(string $json): string
    {
        $kept = [];
        foreach ($this->entries(json_decode($json)) as $entry) {
            $kept[] = [
                'name' => $entry->name ?? null,
                'tokenization' => $entry->config->tokenization ?? null,
                'description' => $entry->config->description ?? null,
            ];
        }

        return json_encode($kept, FdTestGoldenRenderer::FLAGS);
    }

    private function outsideEntries(string $json): string
    {
        $document = json_decode($json);
        if (isset($document->ucp->payment_handlers->{self::NS})) {
            $document->ucp->payment_handlers->{self::NS} = null;
        }

        return json_encode($document, FdTestGoldenRenderer::FLAGS);
    }
}
