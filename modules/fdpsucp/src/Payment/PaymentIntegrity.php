<?php

namespace FD\PrismUcp\Payment;

use FD\PrismUcp\Ucp\Formatter;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class PaymentIntegrity
{
    public static function quotedTotal(array $session): ?int
    {
        $totals = $session['totals'] ?? null;
        if (is_string($totals)) {
            $totals = json_decode($totals, true);
        }
        if (!is_array($totals)) {
            return null;
        }
        foreach ($totals as $line) {
            if (is_array($line) && ($line['type'] ?? null) === 'total') {
                $amount = $line['amount'] ?? null;

                return is_int($amount) && $amount >= 0 ? $amount : null;
            }
        }

        return null;
    }

    public static function orderTotal(\Cart $cart): int
    {
        return Formatter::toMinor((float) $cart->getOrderTotal(true, \Cart::BOTH));
    }

    public static function quoteError(array $session, \Cart $cart): ?string
    {
        $quoted = self::quotedTotal($session);
        if ($quoted === null) {
            return 'Checkout session has no quoted total';
        }
        $orderTotal = self::orderTotal($cart);
        if ($orderTotal !== $quoted) {
            return "Order total ($orderTotal) does not match the quoted total ($quoted)";
        }

        return null;
    }

    public static function settlementError(array $session, \Cart $cart, mixed $preparedAmount): ?string
    {
        $quoteError = self::quoteError($session, $cart);
        if ($quoteError !== null) {
            return $quoteError;
        }
        if (!is_int($preparedAmount)) {
            return 'Prepared payment amount is missing';
        }
        $quoted = self::quotedTotal($session);
        if ($preparedAmount !== $quoted) {
            return "Prepared payment amount ($preparedAmount) does not match the quoted total ($quoted)";
        }

        return null;
    }
}
