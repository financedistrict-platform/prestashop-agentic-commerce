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
                // Each $key is a delivery-option string like "2,"; one carrier per group here.
                $carrierId = (int) rtrim($key, ',');
                $title = $option['carrier_list'][$carrierId]['instance']->name
                    ?? ($option['name'] ?? 'Shipping');
                $costFloat = $option['total_price_with_tax']
                    ?? $option['totalPriceWithTax']
                    ?? 0;
                $cost = (int) round(((float) $costFloat) * 100);
                $id = (string) $carrierId;
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

        if ($options === []) {
            $options[] = [
                'id' => 'free_shipping',
                'title' => 'Free Shipping',
                'totals' => [['type' => 'total', 'amount' => 0]],
            ];
            $firstId = 'free_shipping';
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
}
