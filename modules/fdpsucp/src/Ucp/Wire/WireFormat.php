<?php

namespace FD\PrismUcp\Ucp\Wire;

use FD\PrismUcp\Payment\PaymentRegistry;

if (!defined('_PS_VERSION_')) {
    exit;
}

interface WireFormat
{
    public function version(): string;

    public function profile(string $endpoint, string $storeName, PaymentRegistry $registry, array $supportedVersionsMap): array;

    public function checkoutSession(array $session, PaymentRegistry $registry): array;

    public function completeResponse(array $session, \Order $order, PaymentRegistry $registry): array;

    public function order(\Order $order, int $idLang): array;

    public function envelope(array $capabilities): array;

    public function error(string $code, string $message): array;

    public function capabilities(): array;

    public function supports(string $logical): bool;
}
