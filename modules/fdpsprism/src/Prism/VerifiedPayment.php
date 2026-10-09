<?php

namespace FD\PrismPayment\Prism;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class VerifiedPayment
{
    public function __construct(
        public readonly int $version,
        public readonly array $requirements,
        public readonly array $paymentPayload,
        public readonly string $network,
    ) {
    }
}
