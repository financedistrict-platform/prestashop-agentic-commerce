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
    public const UCP_VERSION = '2026-04-08';

    public static function toMinor(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /**
     * /.well-known/ucp discovery profile.
     *
     * @return array<string,mixed>
     */
    public static function profile(string $endpoint, string $storeName, PaymentRegistry $registry): array
    {
        $v = self::UCP_VERSION;
        $base = 'https://ucp.dev/' . $v;

        return [
            'ucp' => [
                'version' => $v,
                'services' => [
                    'dev.ucp.shopping' => [[
                        'version' => $v,
                        'spec' => $base . '/specification/overview',
                        'transport' => 'rest',
                        'schema' => $base . '/services/shopping/rest.openapi.json',
                        'endpoint' => $endpoint,
                    ]],
                ],
                // Capability namespaces are singular (spec) even though the REST
                // resource paths are plural (e.g. dev.ucp.shopping.order vs GET /orders/{id}).
                // Per-capability spec/schema URLs are omitted: the equivalent
                // ucp.dev pages 404, and the reference implementations (Saleor,
                // Medusa) declare only version (+ extends) per capability. The
                // valid spec/schema URLs live at the service level above.
                'capabilities' => [
                    'dev.ucp.shopping.catalog.search' => [['version' => $v]],
                    'dev.ucp.shopping.catalog.lookup' => [['version' => $v]],
                    'dev.ucp.shopping.cart' => [['version' => $v]],
                    'dev.ucp.shopping.checkout' => [['version' => $v]],
                    'dev.ucp.shopping.fulfillment' => [[
                        'version' => $v,
                        'extends' => [
                            'dev.ucp.shopping.checkout',
                            'dev.ucp.shopping.catalog.search',
                            'dev.ucp.shopping.catalog.lookup',
                        ],
                    ]],
                    'dev.ucp.shopping.order' => [['version' => $v]],
                ],
                'payment_handlers' => $registry->getUcpDiscoveryHandlers() ?: (object) [],
            ],
            'name' => $storeName,
            'signing_keys' => [],
        ];
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

    /**
     * Format a checkout-session row for a UCP response.
     *
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public static function checkoutSession(array $session, PaymentRegistry $registry): array
    {
        $paymentMeta = self::decode($session['payment_meta'] ?? null);
        $status = UcpStatus::resolve($session);
        $messages = UcpStatus::missingMessages(UcpStatus::missingRequirements($session));

        $response = [
            'ucp' => [
                'version' => self::UCP_VERSION,
                'status' => 'success',
                'capabilities' => [
                    'dev.ucp.shopping.checkout' => [['version' => self::UCP_VERSION]],
                    'dev.ucp.shopping.fulfillment' => [['version' => self::UCP_VERSION, 'extends' => 'dev.ucp.shopping.checkout']],
                ],
                'payment_handlers' => $registry->getUcpCheckoutHandlers($paymentMeta) ?: (object) [],
            ],
            'id' => $session['session_uid'],
            'status' => $status,
            'currency' => $session['currency'] ?? 'USD',
            'line_items' => self::decode($session['line_items'] ?? null),
            'totals' => self::decode($session['totals'] ?? null),
            'messages' => $messages,
            'links' => [],
        ];

        $buyer = self::decode($session['buyer'] ?? null);
        if ($buyer) {
            $response['buyer'] = $buyer;
        }
        $fulfillment = self::decode($session['fulfillment'] ?? null);
        if ($fulfillment) {
            $response['fulfillment'] = $fulfillment;
        }
        if (!empty($session['expires_at'])) {
            $response['expires_at'] = $session['expires_at'];
        }

        return $response;
    }

    /**
     * Format a completed session with its order confirmation.
     *
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public static function completeResponse(array $session, \Order $order, PaymentRegistry $registry): array
    {
        $response = self::checkoutSession($session, $registry);
        $response['status'] = 'completed';

        $response['order'] = [
            'id' => (string) $order->id,
            'label' => $order->reference,
            'permalink_url' => '',
        ];

        if (!empty($session['payment_meta'])) {
            $meta = self::decode($session['payment_meta']);
            $txRef = $meta['transaction_reference'] ?? null;
            if ($txRef) {
                $response['order']['transaction_reference'] = $txRef;
            }
            if (!empty($meta['network'])) {
                $response['order']['network'] = $meta['network'];
            }
        }

        return $response;
    }

    /**
     * Format a PrestaShop order for the orders endpoint.
     *
     * @return array<string,mixed>
     */
    public static function order(\Order $order, int $idLang): array
    {
        $lineItems = [];
        foreach ($order->getProducts() as $p) {
            $qty = max(1, (int) $p['product_quantity']);
            $lineItems[] = [
                'id' => (string) $p['id_order_detail'],
                'item' => [
                    'id' => (string) $p['product_id'],
                    'title' => $p['product_name'],
                    'price' => self::toMinor((float) $p['unit_price_tax_incl']),
                ],
                'quantity' => [
                    'original' => $qty,
                    'total' => $qty,
                    'fulfilled' => 0,
                ],
                'totals' => [
                    ['type' => 'subtotal', 'amount' => self::toMinor((float) $p['total_price_tax_excl'])],
                    ['type' => 'total', 'amount' => self::toMinor((float) $p['total_price_tax_incl'])],
                ],
            ];
        }

        $currency = new \Currency((int) $order->id_currency);
        $response = [
            'ucp' => [
                'version' => self::UCP_VERSION,
                'status' => 'success',
            ],
            'id' => (string) $order->id,
            'label' => $order->reference,
            'status' => self::orderStatusToUcp((int) $order->getCurrentState()),
            'currency' => $currency->iso_code,
            'line_items' => $lineItems,
            'totals' => [
                ['type' => 'subtotal', 'amount' => self::toMinor((float) $order->total_products_wt)],
                ['type' => 'shipping', 'amount' => self::toMinor((float) $order->total_shipping)],
                ['type' => 'total', 'amount' => self::toMinor((float) $order->total_paid)],
            ],
        ];

        $payments = $order->getOrderPaymentCollection();
        foreach ($payments as $payment) {
            if (!empty($payment->transaction_id)) {
                $response['transaction_reference'] = $payment->transaction_id;
                break;
            }
        }

        return $response;
    }

    private static function orderStatusToUcp(int $idState): string
    {
        $paid = (int) \Configuration::get('PS_OS_PAYMENT');
        $shipped = (int) \Configuration::get('PS_OS_SHIPPING');
        $delivered = (int) \Configuration::get('PS_OS_DELIVERED');
        $canceled = (int) \Configuration::get('PS_OS_CANCELED');
        $awaiting = (int) \Configuration::get('PS_OS_PREPARATION');

        return match ($idState) {
            $paid, $awaiting => 'confirmed',
            $shipped => 'shipped',
            $delivered => 'delivered',
            $canceled => 'canceled',
            default => 'pending',
        };
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
