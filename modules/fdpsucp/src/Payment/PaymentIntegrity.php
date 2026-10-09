<?php

namespace FD\PrismUcp\Payment;

use FD\PrismUcp\Checkout\Fulfillment;
use FD\PrismUcp\Ucp\Formatter;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class PaymentIntegrity
{
    public const QUOTE_TTL = 1800;

    public static function quoteExpiry(): string
    {
        return date('Y-m-d H:i:s', time() + self::QUOTE_TTL);
    }

    public static function quoteExpired(array $session): bool
    {
        $expiresAt = $session['expires_at'] ?? null;
        $timestamp = is_string($expiresAt) ? strtotime($expiresAt) : false;

        return $timestamp === false || $timestamp <= time();
    }

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
        if (self::quoteExpired($session)) {
            return 'Checkout quote has expired';
        }
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
        $carrierError = Fulfillment::selectionError($cart, $session);
        if ($carrierError !== null) {
            return $carrierError;
        }
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
