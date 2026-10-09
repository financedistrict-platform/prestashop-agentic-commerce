<?php

declare(strict_types=1);

use FD\PrismPayment\Prism\PrismValidator;
use FD\PrismPayment\Prism\VerifiedPayment;
use PHPUnit\Framework\TestCase;

final class PrismValidatorTest extends TestCase
{
    private function quote(string $amount = '4695'): array
    {
        $quote = PrismValidator::parseQuote(FdTestX402::quote($amount));
        $this->assertNotNull($quote);

        return $quote;
    }

    private function verify(array $credential, ?array $quote = null): VerifiedPayment|string
    {
        return PrismValidator::verify($credential, $quote ?? $this->quote());
    }

    public function test_parse_quote_keeps_the_resource_and_settleable_accepts(): void
    {
        $config = FdTestX402::quote('4695');
        $config['accepts'][] = ['scheme' => 'upto'] + FdTestX402::accept('1');
        $config['accepts'][] = ['network' => 'solana:mainnet'] + FdTestX402::accept('1');
        $config['accepts'][] = 'garbage';

        $quote = PrismValidator::parseQuote($config);

        $this->assertSame([FdTestX402::accept('4695')], $quote['accepts']);
        $this->assertSame($config['resource'], $quote['resource']);
        $this->assertSame(2, $quote['x402Version']);
    }

    public function test_parse_quote_rejects_what_cannot_bind_a_credential(): void
    {
        $this->assertNull(PrismValidator::parseQuote(null));
        $this->assertNull(PrismValidator::parseQuote([]));
        $this->assertNull(PrismValidator::parseQuote(['accepts' => FdTestX402::quote('1')['accepts']]));
        $this->assertNull(PrismValidator::parseQuote(['x402Version' => '2'] + FdTestX402::quote('1')));
        $this->assertNull(PrismValidator::parseQuote(['accepts' => ['k' => FdTestX402::accept('1')]] + FdTestX402::quote('1')));
        $this->assertNull(PrismValidator::parseQuote(['accepts' => [['amount' => '1.5'] + FdTestX402::accept('1')]] + FdTestX402::quote('1')));
    }

    public function test_decode_accepts_arrays_json_and_base64(): void
    {
        $credential = FdTestX402::credential('4695');

        $this->assertSame($credential, PrismValidator::decode($credential));
        $this->assertSame($credential, PrismValidator::decode(json_encode($credential)));
        $this->assertSame($credential, PrismValidator::decode(base64_encode((string) json_encode($credential))));
    }

    public function test_decode_unwraps_one_authorization_envelope(): void
    {
        $inner = ['paymentPayload' => FdTestX402::credential('4695')['paymentPayload']];

        $this->assertSame($inner, PrismValidator::decode(['authorization' => base64_encode((string) json_encode($inner))]));
        $this->assertSame($inner, PrismValidator::decode(['authorization' => $inner]));
    }

    public function test_decode_rejects_garbage_and_ambiguous_envelopes(): void
    {
        $this->assertNull(PrismValidator::decode('not-valid'));
        $this->assertNull(PrismValidator::decode(42));
        $this->assertNull(PrismValidator::decode(null));
        $this->assertNull(PrismValidator::decode(FdTestX402::credential('1') + ['authorization' => 'eyJhIjoxfQ==']));
    }

    public function test_verify_builds_the_settlement_from_the_stored_entry(): void
    {
        $payment = $this->verify(FdTestX402::credential('4695'));

        $this->assertInstanceOf(VerifiedPayment::class, $payment);
        $this->assertSame(2, $payment->version);
        $this->assertSame(FdTestX402::accept('4695'), $payment->requirements);
        $this->assertSame(FdTestX402::NETWORK, $payment->network);
        $this->assertSame(['x402Version', 'resource', 'accepted', 'payload'], array_keys($payment->paymentPayload));
    }

    public function test_verify_accepts_a_bare_payment_payload(): void
    {
        $payment = $this->verify(FdTestX402::credential('4695')['paymentPayload']);

        $this->assertInstanceOf(VerifiedPayment::class, $payment);
    }

    public function test_verify_matches_asset_and_recipient_case_insensitively(): void
    {
        $credential = FdTestX402::credential('4695');
        $credential['paymentPayload']['accepted']['asset'] = strtolower(FdTestX402::ASSET);
        $credential['paymentPayload']['accepted']['payTo'] = strtoupper(FdTestX402::PAY_TO);
        $credential['paymentPayload']['payload']['authorization']['to'] = strtolower(FdTestX402::PAY_TO);

        $this->assertInstanceOf(VerifiedPayment::class, $this->verify($credential));
    }

    public function test_verify_tolerates_a_small_clock_skew_on_valid_after(): void
    {
        $credential = FdTestX402::credential('4695');
        $credential['paymentPayload']['payload']['authorization']['validAfter'] = (string) (time() + 10);

        $this->assertInstanceOf(VerifiedPayment::class, $this->verify($credential));
    }

    public function test_verify_rejects_underpayment_and_overpayment(): void
    {
        foreach (['4694', '4696', '99999999999999999999'] as $value) {
            $credential = FdTestX402::credential('4695');
            $credential['paymentPayload']['payload']['authorization']['value'] = $value;

            $this->assertStringContainsString('does not equal the required amount', (string) $this->verify($credential));
        }
    }

    public function test_verify_does_not_echo_buyer_strings_in_errors(): void
    {
        $credential = FdTestX402::credential('4695');
        $credential['paymentPayload']['payload']['authorization']['value'] = '<script>alert(1)</script>';

        $error = (string) $this->verify($credential);

        $this->assertStringContainsString('decimal string', $error);
        $this->assertStringNotContainsString('script', $error);
    }

    public function test_verify_names_numeric_fields_that_are_not_strings(): void
    {
        $credential = FdTestX402::credential('4695');
        $credential['paymentPayload']['payload']['authorization']['value'] = 4695;

        $this->assertStringContainsString('must be a non-empty string', (string) $this->verify($credential));
    }

    public function test_verify_rejects_a_recipient_other_than_the_quoted_one(): void
    {
        $credential = FdTestX402::credential('4695');
        $credential['paymentPayload']['payload']['authorization']['to'] = FdTestX402::PAYER;

        $this->assertStringContainsString('recipient', (string) $this->verify($credential));
    }

    public function test_verify_rejects_a_network_that_was_not_quoted(): void
    {
        $credential = FdTestX402::credential('4695');
        $credential['paymentPayload']['accepted']['network'] = 'eip155:1';

        $this->assertStringContainsString('does not match any stored payment requirement', (string) $this->verify($credential));
    }

    public function test_verify_rejects_a_token_that_was_not_quoted(): void
    {
        $credential = FdTestX402::credential('4695');
        $credential['paymentPayload']['accepted']['asset'] = FdTestX402::OTHER_ASSET;

        $this->assertStringContainsString('does not match any stored payment requirement', (string) $this->verify($credential));
    }

    public function test_verify_rejects_a_flat_credential(): void
    {
        $flat = ['network' => FdTestX402::NETWORK, 'asset' => FdTestX402::ASSET, 'value' => '4695', 'to' => FdTestX402::PAY_TO];

        $this->assertIsString($this->verify($flat));
    }

    public function test_verify_rejects_expired_and_future_authorizations(): void
    {
        $expired = FdTestX402::credential('4695');
        $expired['paymentPayload']['payload']['authorization']['validBefore'] = (string) time();
        $future = FdTestX402::credential('4695');
        $future['paymentPayload']['payload']['authorization']['validAfter'] = (string) (time() + 120);

        $this->assertStringContainsString('expired', (string) $this->verify($expired));
        $this->assertStringContainsString('not valid yet', (string) $this->verify($future));
    }

    public function test_verify_rejects_a_resource_of_another_session(): void
    {
        $credential = FdTestX402::credential('4695');
        $credential['paymentPayload']['resource']['url'] = 'https://shop.example/checkout-sessions/other';

        $this->assertStringContainsString('another resource', (string) $this->verify($credential));
    }

    public function test_verify_allows_a_missing_resource_because_the_stored_one_is_sent(): void
    {
        $credential = FdTestX402::credential('4695');
        unset($credential['paymentPayload']['resource']);

        $payment = $this->verify($credential);

        $this->assertInstanceOf(VerifiedPayment::class, $payment);
        $this->assertSame(FdTestX402::RESOURCE, $payment->paymentPayload['resource']['url']);
    }
}
