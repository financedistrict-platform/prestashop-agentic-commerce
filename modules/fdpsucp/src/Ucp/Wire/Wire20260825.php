<?php

namespace FD\PrismUcp\Ucp\Wire;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Wire20260825 extends WireBase
{
    private const VERSION = '2026-08-25';

    public function version(): string
    {
        return self::VERSION;
    }

    protected function profileCapabilities(string $base): array
    {
        $capabilities = parent::profileCapabilities($base);
        $capabilities['dev.ucp.shopping.fulfillment'][0]['extends'] = ['dev.ucp.shopping.checkout'];

        return $capabilities;
    }

    protected function errorSeverity(): string
    {
        return 'unrecoverable';
    }

    protected function handlerRegistry(array $handlers): array|object
    {
        return $this->objectHandlerRegistry($handlers);
    }
}
