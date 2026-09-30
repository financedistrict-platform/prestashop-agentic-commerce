<?php

declare(strict_types=1);

use FD\PrismPayment\Config\ConfigResolver;
use FD\PrismPayment\Prism\PrismHandler;
use PHPUnit\Framework\TestCase;

final class PrismHandlerTest extends TestCase
{
    private const GATEWAY = 'https://prism-gw.example';

    protected function setUp(): void
    {
        Configuration::$values = [
            ConfigResolver::KEY_URL => self::GATEWAY,
            ConfigResolver::KEY_API => 'key',
        ];
    }

    private function handler(): PrismHandler
    {
        return new PrismHandler(new PaymentModule());
    }

    private function x402Credential(): array
    {
        return ['type' => 'x402', 'x402Version' => 2, 'paymentPayload' => [], 'paymentRequirements' => []];
    }

    public function test_id_is_the_handler_namespace(): void
    {
        $this->assertSame('xyz.fd.prism_payment', $this->handler()->id());
    }

    public function test_discovery_entry_matches_the_prism_contract(): void
    {
        $handlers = $this->handler()->getUcpDiscoveryHandlers();

        $this->assertSame(['xyz.fd.prism_payment'], array_keys($handlers));
        $entry = $handlers['xyz.fd.prism_payment'][0];
        $this->assertSame(['id', 'version', 'spec', 'schema', 'available_instruments', 'config'], array_keys($entry));
        $this->assertSame('xyz.fd.prism_payment', $entry['id']);
        $this->assertSame('2026-10-07', $entry['version']);
        $this->assertSame(self::GATEWAY . '/ucp/prism.md', $entry['spec']);
        $this->assertSame(self::GATEWAY . '/ucp/schema.json', $entry['schema']);
        $this->assertSame([['type' => 'x402']], $entry['available_instruments']);
        $this->assertSame('{}', json_encode($entry['config']));
    }

    public function test_discovery_is_empty_when_not_configured(): void
    {
        Configuration::$values = [];

        $this->assertSame([], $this->handler()->getUcpDiscoveryHandlers());
    }

    public function test_settle_rejects_non_x402_instrument_type(): void
    {
        $result = $this->handler()->settlePayment([
            'instrument_type' => 'tokenized',
            'credential' => $this->x402Credential(),
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('"x402"', $result['error']);
    }

    public function test_settle_rejects_credential_without_x402_type(): void
    {
        $credential = $this->x402Credential();
        unset($credential['type']);

        $result = $this->handler()->settlePayment([
            'instrument_type' => 'x402',
            'credential' => $credential,
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('"x402"', $result['error']);
    }

    public function test_settle_reads_type_from_an_encoded_credential(): void
    {
        $credential = $this->x402Credential();
        $credential['type'] = 'card';

        $result = $this->handler()->settlePayment([
            'instrument_type' => 'x402',
            'credential' => base64_encode((string) json_encode($credential)),
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('"x402"', $result['error']);
    }

    public function test_settle_passes_the_type_check_for_x402(): void
    {
        $result = $this->handler()->settlePayment([
            'instrument_type' => 'x402',
            'credential' => $this->x402Credential(),
            'checkout_meta' => null,
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringNotContainsString('"x402"', $result['error']);
    }
}
