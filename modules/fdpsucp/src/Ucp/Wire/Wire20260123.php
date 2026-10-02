<?php

namespace FD\PrismUcp\Ucp\Wire;

use FD\PrismUcp\Payment\PaymentRegistry;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Wire20260123 extends WireBase
{
    private const VERSION = '2026-01-23';

    private const LATER_HANDLER_FIELDS = ['available_instruments'];

    public function version(): string
    {
        return self::VERSION;
    }

    public function profile(string $endpoint, string $storeName, PaymentRegistry $registry, array $supportedVersionsMap): array
    {
        return parent::profile($endpoint, $storeName, $registry, []);
    }

    public function error(string $code, string $message): array
    {
        return [
            'ucp' => ['version' => self::VERSION],
            'status' => 'requires_escalation',
            'messages' => [
                [
                    'type' => 'error',
                    'code' => $code,
                    'content' => $message,
                    'severity' => $this->errorSeverity(),
                ],
            ],
        ];
    }

    public function capabilities(): array
    {
        return [
            'order' => 'dev.ucp.shopping.order',
        ];
    }

    protected function profileCapabilities(string $base): array
    {
        $v = $this->version();

        return [
            'dev.ucp.shopping.checkout' => [[
                'version' => $v,
                'spec' => $base . '/specification/checkout',
                'schema' => $base . '/schemas/shopping/checkout.json',
            ]],
            'dev.ucp.shopping.fulfillment' => [[
                'version' => $v,
                'spec' => $base . '/specification/fulfillment',
                'schema' => $base . '/schemas/shopping/fulfillment.json',
                'extends' => 'dev.ucp.shopping.checkout',
            ]],
            'dev.ucp.shopping.order' => [[
                'version' => $v,
                'spec' => $base . '/specification/order',
                'schema' => $base . '/schemas/shopping/order.json',
            ]],
        ];
    }

    protected function profileDocument(array $ucp, string $storeName): array
    {
        return [
            'ucp' => $ucp,
            'name' => $storeName,
            'signing_keys' => [],
        ];
    }

    protected function discoveryHandlers(PaymentRegistry $registry): array
    {
        $handlers = parent::discoveryHandlers($registry);
        foreach ($handlers as $ns => $entries) {
            foreach ($entries as $i => $entry) {
                foreach (self::LATER_HANDLER_FIELDS as $field) {
                    unset($handlers[$ns][$i][$field]);
                }
            }
        }

        return $handlers;
    }

    protected function errorSeverity(): string
    {
        return 'requires_buyer_input';
    }

    protected function handlerRegistry(array $handlers): array|object
    {
        return $this->objectHandlerRegistry($handlers);
    }
}
