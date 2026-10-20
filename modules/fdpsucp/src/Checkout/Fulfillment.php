<?php

namespace FD\PrismUcp\Checkout;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Builds the UCP `fulfillment` block (shipping destination + carrier options)
 * from a transient Cart that already has a delivery address. Mirrors the
 * shape woocommerce-ucp produces in process_fulfillment().
 */
final class Fulfillment
{
    private const OPTION_ID = '/^\d+(,\d+)*$/';

    /**
     * @param array<string,mixed> $destination UCP destination (echoed back, given an id)
     * @param string[] $lineItemIds
     * @param string|null $selectedOptionId carrier id the agent asked for, if any
     * @return array<string,mixed>
     */
    public static function fromCart(\Cart $cart, array $destination, array $lineItemIds, ?string $selectedOptionId): array
    {
        $idAddress = (int) $cart->id_address_delivery;
        $optionsList = $cart->getDeliveryOptionList();

        $options = [];
        $firstId = null;

        if (!empty($optionsList[$idAddress])) {
            foreach ($optionsList[$idAddress] as $key => $option) {
                $id = rtrim((string) $key, ',');
                $title = $option['carrier_list'][(int) $id]['instance']->name
                    ?? ($option['name'] ?? 'Shipping');
                $costFloat = $option['total_price_with_tax']
                    ?? $option['totalPriceWithTax']
                    ?? 0;
                $cost = (int) round(((float) $costFloat) * 100);
                if ($firstId === null) {
                    $firstId = $id;
                }
                $options[] = [
                    'id' => $id,
                    'title' => $title,
                    'totals' => [['type' => 'total', 'amount' => $cost]],
                ];
            }
        }

        $validIds = array_column($options, 'id');
        $effective = ($selectedOptionId && in_array($selectedOptionId, $validIds, true))
            ? $selectedOptionId
            : $firstId;

        if (empty($destination['id'])) {
            $destination['id'] = 'dest_1';
        }

        return [
            'methods' => [[
                'id' => 'shipping_1',
                'type' => 'shipping',
                'line_item_ids' => $lineItemIds,
                'selected_destination_id' => $destination['id'],
                'destinations' => [$destination],
                'groups' => [[
                    'id' => 'package_1',
                    'line_item_ids' => $lineItemIds,
                    'selected_option_id' => $effective,
                    'options' => $options,
                ]],
            ]],
        ];
    }

    /**
     * The carrier id the session has selected, or null.
     *
     * @param array<string,mixed>|null $fulfillment
     */
    public static function selectedCarrierId(?array $fulfillment): ?string
    {
        $sel = $fulfillment['methods'][0]['groups'][0]['selected_option_id'] ?? null;
        return is_string($sel) ? $sel : null;
    }

    /** @return string[] */
    public static function offeredOptionKeys(\Cart $cart): array
    {
        $idAddress = (int) $cart->id_address_delivery;
        $options = $idAddress > 0 ? ($cart->getDeliveryOptionList()[$idAddress] ?? []) : [];

        return array_map('strval', array_keys($options));
    }

    public static function appliedOptionId(\Cart $cart): ?string
    {
        $idAddress = (int) $cart->id_address_delivery;
        $applied = $idAddress > 0 ? $cart->getDeliveryOption(null, true, false) : false;
        $key = is_array($applied) ? ($applied[$idAddress] ?? null) : null;
        $id = is_string($key) ? rtrim($key, ',') : '';

        return preg_match(self::OPTION_ID, $id) === 1 ? $id : null;
    }

    public static function coverageError(\Cart $cart): ?string
    {
        if ((int) $cart->id_address_delivery <= 0) {
            return 'The delivery address is not in a country this shop ships to';
        }
        if (!$cart->isVirtualCart() && self::offeredOptionKeys($cart) === []) {
            return 'No carrier delivers to this address';
        }

        return null;
    }

    public static function selectionError(\Cart $cart, array $session): ?string
    {
        $coverageError = self::coverageError($cart);
        if ($coverageError !== null || $cart->isVirtualCart()) {
            return $coverageError;
        }
        $fulfillment = $session['fulfillment'] ?? null;
        $fulfillment = is_string($fulfillment) ? json_decode($fulfillment, true) : $fulfillment;
        $selected = self::selectedCarrierId(is_array($fulfillment) ? $fulfillment : null);
        $isCarrier = $selected !== null && preg_match(self::OPTION_ID, $selected) === 1;
        if (!$isCarrier || self::appliedOptionId($cart) !== $selected) {
            return 'The selected carrier is not available for this delivery address';
        }

        return null;
    }
}
