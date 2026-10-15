<?php

namespace FD\PrismPayment\Prism;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class SettlementSummary
{
    public const UNAVAILABLE_TEXT = 'Refund limit unavailable — check Prism portal';

    public static function load(string $transactionHash, string $apiUrl, string $apiKey, ?PrismClient $client = null): array
    {
        if ($transactionHash === '' || $apiKey === '') {
            return ['state' => 'unavailable'];
        }
        $client ??= new PrismClient($apiUrl, $apiKey);

        return self::fromPrism($client->settlementSummary($transactionHash));
    }

    public static function transactionHash(iterable $payments, string $paymentMethod): string
    {
        foreach ($payments as $payment) {
            if (($payment->payment_method ?? null) === $paymentMethod && !empty($payment->transaction_id)) {
                return (string) $payment->transaction_id;
            }
        }

        return '';
    }

    public static function fromPrism(?array $body): array
    {
        if ($body === null) {
            return ['state' => 'unavailable'];
        }
        $status = $body['status'] ?? null;
        if ($status === 'broadcasted') {
            return ['state' => 'pending'];
        }
        if ($status === 'confirmed' && self::hasText($body, ['net', 'cashback', 'fee', 'asset'])) {
            return [
                'state' => 'confirmed',
                'net' => $body['net'],
                'cashback' => $body['cashback'],
                'fee' => $body['fee'],
                'asset' => $body['asset'],
            ];
        }

        return ['state' => 'unavailable'];
    }

    public static function render(array $summary): string
    {
        $state = $summary['state'] ?? 'unavailable';
        $rows = [];
        if ($state === 'confirmed') {
            $asset = (string) $summary['asset'];
            $rows[] = ['Net received', $summary['net'] . ' ' . $asset];
            $rows[] = ['Buyer cashback', $summary['cashback'] . ' ' . $asset];
            $rows[] = ['Fees', $summary['fee'] . ' ' . $asset];
            $rows[] = ['Refund up to', $summary['net'] . ' ' . $asset];
        } elseif ($state === 'pending') {
            $rows[] = ['Net received', 'Pending'];
            $rows[] = ['Refund up to', 'Pending'];
        } else {
            $rows[] = ['Net received', 'Unavailable'];
            $rows[] = ['Refund up to', self::UNAVAILABLE_TEXT];
        }

        $html = '<div class="card mt-2" id="fdpsprism-settlement">'
            . '<div class="card-header"><h3 class="card-header-title">Prism settlement</h3></div>'
            . '<div class="card-body"><dl class="row mb-0">';
        foreach ($rows as [$label, $value]) {
            $html .= '<dt class="col-sm-4">' . self::escape($label) . '</dt><dd class="col-sm-8">' . self::escape($value) . '</dd>';
        }

        return $html . '</dl></div></div>';
    }

    private static function hasText(array $body, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!isset($body[$key]) || !is_string($body[$key]) || $body[$key] === '') {
                return false;
            }
        }

        return true;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
