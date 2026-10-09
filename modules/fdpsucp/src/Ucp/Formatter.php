<?php

namespace FD\PrismUcp\Ucp;

use FD\PrismUcp\Payment\PaymentRegistry;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Maps PrestaShop entities <-> UCP wire shapes. Ported from FD_UCP_Formatter.
 *
 * Order/checkout amounts are integer minor units (cents) — the UCP `amount` type.
 * Catalog product prices are the deliberate exception: major-units floats (e.g.
 * 11.55) so agents can budget/filter by price without a 100x error, matching the
 * WooCommerce and Shopware catalog handlers. (This knowingly diverges from the
 * UCP `amount: integer` schema on the browse surface; settlement is unaffected.)
 */
final class Formatter
{
    public const UCP_VERSION = VersionRegistry::DEFAULT_CURRENT;

    public static function toMinor(float $amount): int
    {
        return (int) round($amount * 100);
    }

    public static function toMajor(int $minor): float
    {
        return $minor / 100;
    }

    public static function profile(string $endpoint, string $storeName, PaymentRegistry $registry, array $supportedVersionsMap = []): array
    {
        return RequestContext::current()->wire()->profile($endpoint, $storeName, $registry, $supportedVersionsMap);
    }

    public static function discovery(VersionRegistry $versions, string $leafVersion, PaymentRegistry $registry, string $storeBase, string $endpoint, string $storeName): array
    {
        if ($versions->assertValid() !== null) {
            RequestContext::set(new RequestContext($versions, $versions->currentWire()->version()));

            return ['status' => 500, 'body' => $versions->currentWire()->error('configuration_invalid', 'UCP configuration is invalid')];
        }

        if ($leafVersion === '') {
            $context = new RequestContext($versions, $versions->current());
            RequestContext::set($context);

            return ['status' => 200, 'body' => $context->wire()->profile($endpoint, $storeName, $registry, $context->supportedVersionsMap($storeBase))];
        }

        if (!$versions->isEnabled($leafVersion)) {
            return ['status' => 404, 'body' => $versions->currentWire()->error('version_unsupported', sprintf(
                'Version %s is not supported. This business implements versions %s.',
                $leafVersion,
                implode(', ', $versions->enabled())
            ))];
        }

        $context = new RequestContext($versions, $leafVersion);
        RequestContext::set($context);

        return ['status' => 200, 'body' => $context->wire()->profile($endpoint, $storeName, $registry, [])];
    }

    /**
     * Format a PrestaShop product for the catalog response.
     *
     * @return array<string,mixed>
     */
    public static function product(\Product $product, int $idLang, string $currencyIso, \Link $link): array
    {
        $idProduct = (int) $product->id;
        // Major-units float (e.g. 11.55), NOT minor-unit cents — this is the catalog
        // browse price agents filter/budget on. Order/checkout amounts stay minor.
        $price = (float) \Product::getPriceStatic($idProduct, true);
        $name = is_array($product->name) ? ($product->name[$idLang] ?? reset($product->name)) : $product->name;
        $shortDesc = is_array($product->description_short)
            ? ($product->description_short[$idLang] ?? '')
            : (string) $product->description_short;
        $linkRewrite = is_array($product->link_rewrite)
            ? ($product->link_rewrite[$idLang] ?? reset($product->link_rewrite))
            : $product->link_rewrite;

        $formatted = [
            'id' => (string) $idProduct,
            'handle' => (string) $linkRewrite,
            'title' => (string) $name,
            'description' => trim(strip_tags((string) $shortDesc)),
            'url' => $link->getProductLink($product),
            'price_range' => [
                'min' => ['amount' => $price, 'currency' => $currencyIso],
                'max' => ['amount' => $price, 'currency' => $currencyIso],
            ],
            'variants' => [],
            'media' => [],
        ];

        $combinations = $product->getAttributeCombinations($idLang);
        if (!empty($combinations)) {
            $grouped = [];
            foreach ($combinations as $combo) {
                $idAttr = (int) $combo['id_product_attribute'];
                $grouped[$idAttr]['options'][] = [
                    'name' => $combo['group_name'],
                    'value' => $combo['attribute_name'],
                ];
            }
            $prices = [];
            foreach ($grouped as $idAttr => $data) {
                $variantPrice = (float) \Product::getPriceStatic($idProduct, true, $idAttr);
                $prices[] = $variantPrice;
                $available = \StockAvailable::getQuantityAvailableByProduct($idProduct, $idAttr) > 0;
                $formatted['variants'][] = [
                    'id' => (string) $idAttr,
                    'title' => (string) $name,
                    'price' => ['amount' => $variantPrice, 'currency' => $currencyIso],
                    'availability' => $available ? 'in_stock' : 'out_of_stock',
                    'options' => $data['options'],
                ];
            }
            if ($prices !== []) {
                $formatted['price_range']['min']['amount'] = min($prices);
                $formatted['price_range']['max']['amount'] = max($prices);
            }
        } else {
            $available = \StockAvailable::getQuantityAvailableByProduct($idProduct) > 0;
            $formatted['variants'][] = [
                'id' => (string) $idProduct,
                'title' => (string) $name,
                'price' => ['amount' => $price, 'currency' => $currencyIso],
                'availability' => $available ? 'in_stock' : 'out_of_stock',
            ];
        }

        $idImage = \Product::getCover($idProduct);
        if (!empty($idImage['id_image'])) {
            $formatted['media'][] = [
                'type' => 'image',
                'url' => $link->getImageLink($linkRewrite, (string) $idImage['id_image']),
                'alt' => (string) $name,
            ];
        }

        return $formatted;
    }

    public static function checkoutSession(array $session, PaymentRegistry $registry): array
    {
        return RequestContext::current()->wire()->checkoutSession($session, $registry);
    }

    public static function completeResponse(array $session, \Order $order, PaymentRegistry $registry): array
    {
        return RequestContext::current()->wire()->completeResponse($session, $order, $registry);
    }

    public static function order(\Order $order, int $idLang): array
    {
        return RequestContext::current()->wire()->order($order, $idLang);
    }
}
