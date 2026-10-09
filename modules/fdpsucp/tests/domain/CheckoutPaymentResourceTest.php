<?php

declare(strict_types=1);

use FD\PrismUcp\Checkout\CheckoutService;
use FD\PrismUcp\Payment\PaymentHandlerInterface;
use FD\PrismUcp\Payment\PaymentRegistry;
use FD\PrismUcp\Ucp\VersionRegistry;
use PHPUnit\Framework\TestCase;

final class CheckoutPaymentResourceTest extends TestCase
{
    private FdTestMemorySessions $sessions;
    private FdTestPreparingHandler $handler;

    protected function setUp(): void
    {
        FdTestStubs::reset();
        VersionRegistry::seedOnUpgrade();
        Product::$prices = [6 => 0.5, 7 => 1.25];
        $this->sessions = new FdTestMemorySessions([]);
        $this->handler = new FdTestPreparingHandler();
    }

    private function service(string $secret = ''): CheckoutService
    {
        $registry = new PaymentRegistry();
        $registry->register($this->handler);
        $service = new CheckoutService(new Context(), $registry, FdTestGoldenRenderer::ENDPOINT, hash('sha256', ''), $secret);
        (new ReflectionProperty(CheckoutService::class, 'sessions'))->setValue($service, $this->sessions);
        (new ReflectionProperty(CheckoutService::class, 'cartBuilder'))->setValue($service, new FdTestCartBuilder());

        return $service;
    }

    private function create(): array
    {
        $response = $this->service()->create(['line_items' => [['item' => ['id' => '6'], 'quantity' => 1]]], null);
        $this->assertSame(201, $response->status, (string) json_encode($response->body));

        return $response->body;
    }

    public function test_create_prepares_payment_for_the_session_it_returns(): void
    {
        $body = $this->create();
        $sessionId = $body['id'];
        $meta = json_decode($this->sessions->rows[$sessionId]['payment_meta'], true);

        $this->assertSame([$sessionId], $this->handler->preparedFor);
        $this->assertSame(FdTestGoldenRenderer::ENDPOINT . '/checkout-sessions/' . $sessionId, $meta['xyz.fd.prism_payment']['resource']);
    }

    public function test_requote_on_update_keeps_the_session_id(): void
    {
        $body = $this->create();
        $sessionId = $body['id'];

        $response = $this->service($body['session_secret'])->update($sessionId, ['line_items' => [['item' => ['id' => '7'], 'quantity' => 1]]]);
        $meta = json_decode($this->sessions->rows[$sessionId]['payment_meta'], true);

        $this->assertSame(200, $response->status, (string) json_encode($response->body));
        $this->assertSame([$sessionId, $sessionId], $this->handler->preparedFor);
        $this->assertSame(125, $meta['xyz.fd.prism_payment']['total']);
        $this->assertSame(FdTestGoldenRenderer::ENDPOINT . '/checkout-sessions/' . $sessionId, $meta['xyz.fd.prism_payment']['resource']);
    }
}

final class FdTestPreparingHandler implements PaymentHandlerInterface
{
    public array $preparedFor = [];

    public function id(): string
    {
        return 'xyz.fd.prism_payment';
    }

    public function name(): string
    {
        return 'Preparing Prism';
    }

    public function getUcpDiscoveryHandlers(): array
    {
        return [];
    }

    public function prepareCheckoutPayment(array $input): ?array
    {
        $this->preparedFor[] = $input['checkout_id'];

        return [
            'resource' => $input['checkout_base_url'] . '/checkout-sessions/' . $input['checkout_id'],
            'total' => $input['total'],
        ];
    }

    public function settlePayment(array $input): array
    {
        return ['success' => false];
    }

    public function preparedAmount(?array $checkoutMeta): ?int
    {
        return null;
    }

    public function getUcpCheckoutHandlers(?array $paymentMeta = null): array
    {
        return [];
    }
}
