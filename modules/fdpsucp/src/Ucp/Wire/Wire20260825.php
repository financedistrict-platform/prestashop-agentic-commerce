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

    protected function errorSeverity(): string
    {
        return 'unrecoverable';
    }

    protected function handlerRegistry(array $handlers): array|object
    {
        return $this->objectHandlerRegistry($handlers);
    }
}
