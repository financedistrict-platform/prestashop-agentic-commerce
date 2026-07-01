<?php

namespace FD\PrismUcp\Ucp;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Derive UCP checkout status + missing-requirement messages from a session
 * row. Ported from FD_UCP_Status (woocommerce-ucp).
 */
final class UcpStatus
{
    /** @param array<string,mixed> $session */
    public static function resolve(array $session): string
    {
        if (($session['status'] ?? '') === 'canceled') {
            return 'canceled';
        }
        if (($session['status'] ?? '') === 'completed') {
            return 'completed';
        }
        return self::missingRequirements($session) === [] ? 'ready_for_complete' : 'incomplete';
    }

    /**
     * @param array<string,mixed> $session
     * @return string[]
     */
    public static function missingRequirements(array $session): array
    {
        $missing = [];

        $lineItems = self::decode($session['line_items'] ?? null);
        if (empty($lineItems)) {
            $missing[] = 'items';
        }

        $buyer = self::decode($session['buyer'] ?? null);
        if (empty($buyer['email'])) {
            $missing[] = 'email';
        }

        $fulfillment = self::decode($session['fulfillment'] ?? null);
        $hasAddress = !empty($fulfillment['methods'][0]['destinations'][0]['address_country']);
        if (!$hasAddress) {
            $missing[] = 'shipping_address';
        }

        $group = $fulfillment['methods'][0]['groups'][0] ?? null;
        if ($hasAddress && $group && !empty($group['options']) && empty($group['selected_option_id'])) {
            $missing[] = 'selected_fulfillment_option';
        }

        return $missing;
    }

    /**
     * @param string[] $missing
     * @return array<int,array<string,string>>
     */
    public static function missingMessages(array $missing): array
    {
        $map = [
            'items' => ['path' => '$.line_items', 'content' => 'At least one line item is required'],
            'email' => ['path' => '$.buyer.email', 'content' => 'Buyer email is required'],
            'shipping_address' => ['path' => '$.fulfillment.methods[0].destinations[0]', 'content' => 'Shipping address is required'],
            'selected_fulfillment_option' => ['path' => '$.fulfillment.methods[0].groups[0].selected_option_id', 'content' => 'Please select a fulfillment option'],
        ];

        $messages = [];
        foreach ($missing as $field) {
            if (isset($map[$field])) {
                $messages[] = [
                    'type' => 'error',
                    'code' => "missing_$field",
                    'content' => $map[$field]['content'],
                    'severity' => 'recoverable',
                    'path' => $map[$field]['path'],
                ];
            }
        }
        return $messages;
    }

    /** @return array<string,mixed> */
    private static function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
