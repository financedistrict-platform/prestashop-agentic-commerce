<?php

namespace FD\PrismUcp\Payment;

if (!defined('_PS_VERSION_')) {
    exit;
}

interface VersionedPaymentHandlerInterface
{
    public function getUcpDiscoveryHandlersForVersion(string $ucpVersion): array;
}
