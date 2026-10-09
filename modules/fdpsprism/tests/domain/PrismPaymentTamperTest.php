<?php

declare(strict_types=1);

use FD\PrismPayment\Config\ConfigResolver;
use FD\PrismPayment\Prism\PrismHandler;
use FD\PrismUcp\Ucp\RequestContext;
use PHPUnit\Framework\TestCase;

final class PrismPaymentTamperTest extends TestCase
{
    private const PAY_TO = '0x1111111111111111111111111111111111111111';
    private const ASSET = '0x036CbD53842c5426634e7929541eC2318f3dCF7e';
    private const SETTLE = 'POST /api/v2/payment/settle';

    private FdTestPrismClient $client;
    private PaymentModule $module;
    private Cart $cart;

    protected function setUp(): void
    {
        Configuration::$values = [
            ConfigResolver::KEY_URL => 'https://prism-gw.example',
            ConfigResolver::KEY_API => 'key',
        ];
        PrestaShopLogger::$logs = [];
        RequestContext::set(null);
        $this->client = new FdTestPrismClient();
        $this->client->responses[self::SETTLE] = ['success' => true, 'transaction' => '0x' . str_repeat('cd', 32), 'network' => 'eip155:84532'];
        $this->module = new PaymentModule();
        $this->cart = new Cart();
    }

    private function settle(int $quotedTotal, ?int $preparedAmount, string $tokenAmount): array
    {
        $node = ['ucp' => [PrismHandler::NS => [['config' => ['accepts' => [[
            'network' => 'eip155:84532',
            'asset' => self::ASSET,
            'amount' => $tokenAmount,
            'payTo' => self::PAY_TO,
        ]]]]]]];
        if ($preparedAmount !== null) {
            $node['prepared_amount'] = $preparedAmount;
        }

        return (new PrismHandler($this->module, $this->client))->settlePayment([
            'session' => ['totals' => json_encode([['type' => 'total', 'amount' => $quotedTotal]])],
            'cart' => $this->cart,
            'instrument_type' => 'x402',
            'credential' => [
                'type' => 'x402',
                'paymentPayload' => [
                    'network' => 'eip155:84532',
                    'accepted' => ['network' => 'eip155:84532', 'asset' => self::ASSET],
                    'payload' => ['authorization' => ['to' => self::PAY_TO, 'value' => $tokenAmount]],
                ],
            ],
            'checkout_meta' => [PrismHandler::NS => $node],
        ]);
    }

    private function assertNothingCharged(array $result): void
    {
        $this->assertFalse($result['success']);
        $this->assertNotContains(self::SETTLE, $this->client->paths);
        $this->assertSame([], $this->module->validated);
    }

    public function test_quote_below_the_order_total_is_never_settled(): void
    {
        $this->assertNothingCharged($this->settle(1500, 1500, '1500'));
    }

    public function test_prepared_amount_that_differs_from_the_quote_is_never_settled(): void
    {
        $this->assertNothingCharged($this->settle(4695, 1500, '1500'));
    }

    public function test_missing_prepared_amount_is_never_settled(): void
    {
        $this->assertNothingCharged($this->settle(4695, null, '4695'));
    }

    public function test_missing_quote_is_never_settled(): void
    {
        $this->cart->total = 0.0;

        $result = (new PrismHandler($this->module, $this->client))->settlePayment([
            'session' => ['totals' => null],
            'cart' => $this->cart,
            'instrument_type' => 'x402',
            'credential' => ['type' => 'x402', 'network' => 'eip155:84532', 'asset' => self::ASSET, 'value' => '0', 'to' => self::PAY_TO],
            'checkout_meta' => [PrismHandler::NS => ['prepared_amount' => 0, 'ucp' => [PrismHandler::NS => [['config' => ['accepts' => [[
                'network' => 'eip155:84532', 'asset' => self::ASSET, 'amount' => '0', 'payTo' => self::PAY_TO,
            ]]]]]]]],
        ]);

        $this->assertNothingCharged($result);
    }

    public function test_paid_amount_reported_to_prestashop_is_the_settled_quote(): void
    {
        $result = $this->settle(4695, 4695, '4695');

        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));
        $this->assertContains(self::SETTLE, $this->client->paths);
        $this->assertSame(46.95, $this->module->validated[0][2]);
    }
}
