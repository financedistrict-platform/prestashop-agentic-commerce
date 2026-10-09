<?php

declare(strict_types=1);

use FD\PrismDummy\DummyHandler;
use FD\PrismUcp\Payment\PaymentRegistry;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

if (!defined('_PS_MODE_DEV_')) {
    define('_PS_MODE_DEV_', true);
}

require_once dirname(__DIR__, 3) . '/fdpsdummy/src/DummyHandler.php';
require_once dirname(__DIR__, 3) . '/fdpsdummy/fdpsdummy.php';

final class DummyGateTest extends TestCase
{
    private const QUOTED = 4695;

    protected function setUp(): void
    {
        FdTestStubs::reset();
    }

    private function settle(PaymentModule $module): array
    {
        $cart = new Cart();

        return (new DummyHandler($module))->settlePayment([
            'session' => [],
            'cart' => $cart,
            'handler_id' => 'dummy',
            'credential' => [],
            'checkout_meta' => null,
            'paid_amount' => self::QUOTED,
        ]);
    }

    private function discovery(): array
    {
        $registry = new PaymentRegistry();
        $registry->register(new DummyHandler(new PaymentModule()));

        return $registry->getUcpDiscoveryHandlers();
    }

    private function collected(): PaymentRegistry
    {
        $registry = new PaymentRegistry();
        (new ReflectionClass(FdPsDummy::class))
            ->newInstanceWithoutConstructor()
            ->hookActionUcpCollectPaymentHandlers(['registry' => $registry]);

        return $registry;
    }

    public function test_without_explicit_opt_in_no_order_is_placed(): void
    {
        $module = new PaymentModule();

        $result = $this->settle($module);

        $this->assertFalse($result['success']);
        $this->assertSame([], $module->validated);
    }

    public function test_without_explicit_opt_in_the_handler_is_hidden_from_discovery(): void
    {
        $this->assertSame([], $this->discovery());
    }

    public function test_without_explicit_opt_in_the_module_does_not_register_the_handler(): void
    {
        $registry = $this->collected();

        $this->assertNull($registry->get('dummy'));
        $this->assertSame([], $registry->getUcpDiscoveryHandlers());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_configured_prism_gateway_closes_the_handler_even_when_opted_in(): void
    {
        define('FDPSDUMMY_ENABLED', true);
        Configuration::$values['FDPSPRISM_API_KEY'] = 'sk_live_configured';
        $module = new PaymentModule();

        $result = $this->settle($module);

        $this->assertFalse($result['success']);
        $this->assertSame([], $module->validated);
        $this->assertSame([], $this->discovery());
        $this->assertNull($this->collected()->get('dummy'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_handler_is_open_only_when_opted_in_in_dev_mode_without_prism(): void
    {
        define('FDPSDUMMY_ENABLED', true);
        $module = new PaymentModule();

        $result = $this->settle($module);

        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));
        $this->assertCount(1, $module->validated);
        $this->assertArrayHasKey('com.fd.dummy', $this->discovery());
        $this->assertNotNull($this->collected()->get('dummy'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_handler_is_closed_when_the_shop_is_not_in_dev_mode(): void
    {
        define('FDPSDUMMY_ENABLED', true);
        $module = new PaymentModule();
        $handler = new DummyHandler($module, new \FD\PrismDummy\DummyGate(true, false, false));

        $result = $handler->settlePayment(['cart' => new Cart(), 'paid_amount' => self::QUOTED]);

        $this->assertFalse($result['success']);
        $this->assertSame([], $module->validated);
    }

    public function test_the_prism_key_name_matches_the_prism_module(): void
    {
        $this->assertSame(\FD\PrismPayment\Config\ConfigResolver::KEY_API, \FD\PrismDummy\DummyGate::PRISM_API_KEY);
    }
}
