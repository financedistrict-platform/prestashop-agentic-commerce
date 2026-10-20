<?php

namespace FD\PrismUcp\Payment;

if (!defined('_PS_VERSION_')) {
    exit;
}

interface ReplayLedger
{
    public function claim(string $sessionUid, string $authorizationKey): bool;

    public function recordTransaction(string $authorizationKey, string $transactionKey): bool;
}
