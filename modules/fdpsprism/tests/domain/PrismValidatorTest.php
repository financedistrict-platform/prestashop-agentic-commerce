<?php

declare(strict_types=1);

use FD\PrismPayment\Prism\PrismValidator;
use PHPUnit\Framework\TestCase;

/**
 * Ported from woocommerce-prism-payment PrismValidatorTest — the binding guard
 * (NFR-1) that confirms a signed x402 credential matches the quoted payment
 * requirements before settlement.
 *
 * The PrestaShop port splits woo's monolithic validate_credential() into three
 * pure methods (extractSignedSummary / readStoredAccepts / validate) and returns
 * `true|string` (human message) instead of `true|WP_Error`, so failures are
 * asserted by message content rather than error code.
 */
final class PrismValidatorTest extends TestCase
{
    /** @param array<int,array<string,mixed>> $accepts */
    private function makeMeta(array $accepts): array
    {
        // The handler's own payment_meta node: { ucp: [ [ { config: { accepts } } ] ] }
        return ['ucp' => [[['config' => ['accepts' => $accepts]]]]];
    }

    /** A single accept entry as returned in a Prism quote. */
    private function accept(string $network, string $asset, string $amount, string $payTo): array
    {
        return ['network' => $network, 'asset' => $asset, 'amount' => $amount, 'payTo' => $payTo];
    }

    // ── extractSignedSummary() ───────────────────────────────

    public function test_extract_flat_credential(): void
    {
        $summary = PrismValidator::extractSignedSummary([
            'network' => 'eip155:84532',
            'asset' => '0xUsdc',
            'value' => '1500000',
            'to' => '0xRecipient',
        ]);

        $this->assertSame('eip155:84532', $summary['network']);
        $this->assertSame('0xUsdc', $summary['asset']);
        $this->assertSame('1500000', $summary['value']);
        $this->assertSame('0xRecipient', $summary['to']);
    }

    public function test_extract_nested_payment_payload(): void
    {
        $summary = PrismValidator::extractSignedSummary([
            'paymentPayload' => [
                'network' => 'eip155:84532',
                'payload' => [
                    'asset' => '0xAsset',
                    'authorization' => ['value' => '2000000', 'to' => '0xPayTo'],
                ],
                'accepted' => ['asset' => '0xAcceptedAsset'],
            ],
            'paymentRequirements' => ['asset' => '0xReqAsset'],
        ]);

        $this->assertSame('eip155:84532', $summary['network']);
        $this->assertSame('0xReqAsset', $summary['asset']);
        $this->assertSame('2000000', $summary['value']);
        $this->assertSame('0xPayTo', $summary['to']);
    }

    public function test_extract_base64_credential(): void
    {
        $b64 = base64_encode((string) json_encode([
            'network' => 'eip155:1',
            'value' => '5000000',
            'to' => '0xAddr',
            'asset' => '0xToken',
        ]));

        $summary = PrismValidator::extractSignedSummary($b64);

        $this->assertSame('eip155:1', $summary['network']);
        $this->assertSame('5000000', $summary['value']);
    }

    public function test_extract_returns_null_for_garbage(): void
    {
        $this->assertNull(PrismValidator::extractSignedSummary('not-valid'));
        $this->assertNull(PrismValidator::extractSignedSummary(42));
        $this->assertNull(PrismValidator::extractSignedSummary(['foo' => 'bar']));
    }

    // ── readStoredAccepts() ──────────────────────────────────

    public function test_read_stored_accepts_finds_accepts(): void
    {
        $accepts = [$this->accept('eip155:84532', '0xUsdc', '1500000', '0xPayTo')];
        $this->assertSame($accepts, PrismValidator::readStoredAccepts($this->makeMeta($accepts)));
    }

    public function test_read_stored_accepts_null_when_absent(): void
    {
        $this->assertNull(PrismValidator::readStoredAccepts(null));
        $this->assertNull(PrismValidator::readStoredAccepts([]));
        $this->assertNull(PrismValidator::readStoredAccepts(['ucp' => [[['config' => []]]]]));
    }

    // ── validate() ───────────────────────────────────────────

    public function test_valid_credential_passes(): void
    {
        $summary = ['network' => 'eip155:84532', 'asset' => '0xUsdc', 'value' => '1500000', 'to' => '0xPayTo'];
        $accepts = [$this->accept('eip155:84532', '0xUsdc', '1500000', '0xPayTo')];
        $this->assertTrue(PrismValidator::validate($summary, $accepts));
    }

    public function test_overpayment_passes(): void
    {
        $summary = ['network' => 'eip155:84532', 'asset' => '0xUsdc', 'value' => '2000000', 'to' => '0xPayTo'];
        $accepts = [$this->accept('eip155:84532', '0xUsdc', '1500000', '0xPayTo')];
        $this->assertTrue(PrismValidator::validate($summary, $accepts));
    }

    public function test_underpayment_fails(): void
    {
        $summary = ['network' => 'eip155:84532', 'asset' => '0xUsdc', 'value' => '500000', 'to' => '0xPayTo'];
        $accepts = [$this->accept('eip155:84532', '0xUsdc', '1500000', '0xPayTo')];

        $result = PrismValidator::validate($summary, $accepts);
        $this->assertIsString($result);
        $this->assertStringContainsString('less than required', $result);
    }

    public function test_wrong_network_fails(): void
    {
        $summary = ['network' => 'eip155:1', 'asset' => '0xUsdc', 'value' => '1500000', 'to' => '0xPayTo'];
        $accepts = [$this->accept('eip155:84532', '0xUsdc', '1500000', '0xPayTo')];

        $result = PrismValidator::validate($summary, $accepts);
        $this->assertIsString($result);
        $this->assertStringContainsString('No stored payment requirement', $result);
    }

    public function test_recipient_mismatch_fails(): void
    {
        $summary = ['network' => 'eip155:84532', 'asset' => '0xUsdc', 'value' => '1500000', 'to' => '0xWrongAddr'];
        $accepts = [$this->accept('eip155:84532', '0xUsdc', '1500000', '0xPayTo')];

        $result = PrismValidator::validate($summary, $accepts);
        $this->assertIsString($result);
        $this->assertStringContainsString('recipient', $result);
    }

    public function test_case_insensitive_asset_matching(): void
    {
        $summary = ['network' => 'eip155:84532', 'asset' => '0xUSDC', 'value' => '1500000', 'to' => '0xPayTo'];
        $accepts = [$this->accept('eip155:84532', '0xusdc', '1500000', '0xPayTo')];
        $this->assertTrue(PrismValidator::validate($summary, $accepts));
    }

    public function test_large_amounts_compared_correctly(): void
    {
        $summary = ['network' => 'eip155:84532', 'asset' => '0xUsdc', 'value' => '999999999999999999', 'to' => '0xPayTo'];
        $accepts = [$this->accept('eip155:84532', '0xUsdc', '1000000000000000000', '0xPayTo')];

        $result = PrismValidator::validate($summary, $accepts);
        $this->assertIsString($result);
        $this->assertStringContainsString('less than required', $result);
    }

    public function test_multi_network_accepts_matches_correct_one(): void
    {
        $summary = ['network' => 'eip155:1', 'asset' => '0xUsdc', 'value' => '2000000', 'to' => '0xPayTo'];
        $accepts = [
            $this->accept('eip155:84532', '0xUsdc', '1500000', '0xPayTo'),
            $this->accept('eip155:1', '0xUsdc', '1500000', '0xPayTo'),
        ];
        $this->assertTrue(PrismValidator::validate($summary, $accepts));
    }

    // ── fail-closed guards (0.5.0) ───────────────────────────

    public function test_missing_signed_recipient_fails(): void
    {
        $summary = ['network' => 'eip155:84532', 'asset' => '0xUsdc', 'value' => '1500000', 'to' => ''];
        $accepts = [$this->accept('eip155:84532', '0xUsdc', '1500000', '0xPayTo')];

        $result = PrismValidator::validate($summary, $accepts);
        $this->assertIsString($result);
        $this->assertStringContainsString('recipient', $result);
    }

    public function test_missing_signed_asset_fails(): void
    {
        $summary = ['network' => 'eip155:84532', 'asset' => '', 'value' => '1500000', 'to' => '0xPayTo'];
        $accepts = [$this->accept('eip155:84532', '0xUsdc', '1500000', '0xPayTo')];

        $result = PrismValidator::validate($summary, $accepts);
        $this->assertIsString($result);
        $this->assertStringContainsString('asset', $result);
    }

    public function test_non_integer_amount_fails(): void
    {
        $summary = ['network' => 'eip155:84532', 'asset' => '0xUsdc', 'value' => '15.00', 'to' => '0xPayTo'];
        $accepts = [$this->accept('eip155:84532', '0xUsdc', '1500000', '0xPayTo')];

        $result = PrismValidator::validate($summary, $accepts);
        $this->assertIsString($result);
        $this->assertStringContainsString('integer', $result);
    }
}
