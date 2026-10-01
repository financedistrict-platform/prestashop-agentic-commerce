<?php

namespace FD\PrismUcp\Ucp\Wire;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Wire20260408 extends WireBase
{
    private const VERSION = '2026-04-08';

    public function version(): string
    {
        return self::VERSION;
    }

    protected function profileDocument(array $ucp, string $storeName): array
    {
        return [
            'ucp' => $ucp,
            'name' => $storeName,
            'signing_keys' => [],
        ];
    }
}
