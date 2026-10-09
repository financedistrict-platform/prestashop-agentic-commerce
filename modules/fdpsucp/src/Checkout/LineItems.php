<?php

namespace FD\PrismUcp\Checkout;

use FD\PrismUcp\Http\Response;
use FD\PrismUcp\Ucp\Formatter;
use FD\PrismUcp\Ucp\UcpError;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class LineItems
{
    public const MAX_QUANTITY = 1000;

    public static function positiveInt(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }
        if (is_string($raw) && ctype_digit($raw) && strlen($raw) <= 10) {
            return (int) $raw > 0 ? (int) $raw : null;
        }

        return null;
    }

    public static function variantId(mixed $raw): ?int
    {
        if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
            return 0;
        }

        return self::positiveInt($raw);
    }

    public static function quantity(mixed $raw): ?int
    {
        if (is_float($raw) && is_finite($raw) && floor($raw) === $raw && $raw >= 1 && $raw <= self::MAX_QUANTITY) {
            return (int) $raw;
        }
        $quantity = self::positiveInt($raw);

        return $quantity !== null && $quantity <= self::MAX_QUANTITY ? $quantity : null;
    }

    public static function format(array $items, int $idLang, bool $keepClientIds = false): array|Response
    {
        $formatted = [];
        foreach ($items as $item) {
            $node = is_array($item) && is_array($item['item'] ?? null) ? $item['item'] : [];
            $label = is_scalar($node['id'] ?? null) ? substr((string) $node['id'], 0, 40) : 'unknown';

            $quantity = self::quantity(is_array($item) ? ($item['quantity'] ?? 1) : null);
            if ($quantity === null) {
                return UcpError::response(
                    'invalid_quantity',
                    "Quantity for product $label must be a whole number between 1 and " . self::MAX_QUANTITY,
                    422
                );
            }

            $idProduct = self::positiveInt($node['id'] ?? null);
            $product = $idProduct === null ? null : new \Product($idProduct, false, $idLang);
            if ($idProduct === null || !\Validate::isLoadedObject($product) || !$product->active) {
                return UcpError::response('invalid_product', "Product $label not found or not purchasable", 422);
            }

            $idAttr = self::variantId($node['variant_id'] ?? null);
            if ($idAttr === null || ($idAttr > 0 && !self::belongsToProduct($idAttr, $idProduct))) {
                return UcpError::response('invalid_variant', "Variant is not a combination of product $idProduct", 422);
            }

            if ($stockError = self::stockError($idProduct, $idAttr, $quantity, $product)) {
                return $stockError;
            }

            $price = Formatter::toMinor((float) \Product::getPriceStatic($idProduct, true, $idAttr ?: null));
            $itemTotal = $price * $quantity;
            $name = is_array($product->name) ? ($product->name[$idLang] ?? reset($product->name)) : $product->name;

            $clientId = is_array($item) ? ($item['id'] ?? null) : null;
            $entry = [
                'id' => $keepClientIds && is_string($clientId) && $clientId !== '' ? $clientId : 'li_' . (count($formatted) + 1),
                'item' => ['id' => (string) $idProduct, 'title' => (string) $name, 'price' => $price],
                'quantity' => $quantity,
                'totals' => [
                    ['type' => 'subtotal', 'amount' => $itemTotal],
                    ['type' => 'total', 'amount' => $itemTotal],
                ],
            ];
            if ($idAttr > 0) {
                $entry['item']['variant_id'] = (string) $idAttr;
            }
            $formatted[] = $entry;
        }

        return $formatted;
    }

    private static function belongsToProduct(int $idAttr, int $idProduct): bool
    {
        $combination = new \Combination($idAttr);

        return \Validate::isLoadedObject($combination) && (int) $combination->id_product === $idProduct;
    }

    private static function stockError(int $idProduct, int $idAttr, int $quantity, \Product $product): ?Response
    {
        if (\Product::isAvailableWhenOutOfStock((int) $product->out_of_stock)) {
            return null;
        }
        $available = (int) \StockAvailable::getQuantityAvailableByProduct($idProduct, $idAttr ?: null);
        if ($quantity > $available) {
            return UcpError::response(
                'insufficient_stock',
                "Requested quantity ($quantity) exceeds available stock ($available) for product $idProduct",
                422
            );
        }

        return null;
    }
}
