<?php

declare(strict_types=1);

use FD\PrismUcp\Checkout\CheckoutService;
use FD\PrismUcp\Payment\PaymentRegistry;
use PHPUnit\Framework\TestCase;

final class InstrumentToleranceTest extends TestCase
{
    protected function setUp(): void
    {
        FdTestStubs::reset();
    }

    public function test_guard_requires_only_handler_id_and_credential(): void
    {
        $this->assertNull(CheckoutService::instrumentError(['handler_id' => 'x402', 'credential' => ['a' => 1]]));
        $this->assertNull(CheckoutService::instrumentError(['handler_id' => 'x402', 'credential' => 'eyJhIjoxfQ==']));
        $this->assertNull(CheckoutService::instrumentError(['handler_id' => 'xyz.fd.prism_payment', 'credential' => []]));
        $this->assertNotNull(CheckoutService::instrumentError(['credential' => ['a' => 1]]));
        $this->assertNotNull(CheckoutService::instrumentError(['handler_id' => 'x402']));
        $this->assertNotNull(CheckoutService::instrumentError(['handler_id' => ['x402'], 'credential' => 'a']));
        $this->assertNotNull(CheckoutService::instrumentError(null));
    }

    public function test_guard_keeps_the_original_error_message(): void
    {
        $this->assertSame('payment.instruments[0].handler_id and credential are required', CheckoutService::instrumentError([]));
    }

    public function test_legacy_alias_resolves_to_the_prism_handler(): void
    {
        $registry = FdTestGoldenRenderer::prismRegistry(null);

        $this->assertSame('xyz.fd.prism_payment', $registry->canonicalId('x402'));
        $this->assertSame('xyz.fd.prism_payment', $registry->get('x402')->id());
        $this->assertSame('other', $registry->canonicalId('other'));
        $this->assertNull($registry->get('other'));
    }

    public function test_alias_is_not_applied_without_the_prism_handler(): void
    {
        $registry = new PaymentRegistry();

        $this->assertSame('x402', $registry->canonicalId('x402'));
        $this->assertNull($registry->get('x402'));
    }

    public function test_unknown_handler_stays_loud(): void
    {
        $this->assertSame('Unknown payment handler: other', FdTestGoldenRenderer::prismRegistry(null)->settle('other', [])['error']);
    }

    public function test_no_public_alias_interface_exists(): void
    {
        $this->assertSame([], glob(dirname(__DIR__, 2) . '/src/Payment/*[Aa]lias*'));
    }
}
