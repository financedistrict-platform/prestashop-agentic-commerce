<?php

namespace FD\PrismUcp\Ucp\Wire;

use FD\PrismUcp\Payment\PaymentRegistry;
use FD\PrismUcp\Ucp\Formatter;
use FD\PrismUcp\Ucp\UcpStatus;

if (!defined('_PS_VERSION_')) {
    exit;
}

abstract class WireBase implements WireFormat
{
    abstract public function version(): string;

    public function profile(string $endpoint, string $storeName, PaymentRegistry $registry, array $supportedVersionsMap): array
    {
        $v = $this->version();
        $ucp = [
            'version' => $v,
            'services' => [
                'dev.ucp.shopping' => [[
                    'version' => $v,
                    'spec' => 'https://ucp.dev/' . $v . '/specification/overview',
                    'transport' => 'rest',
                    'schema' => 'https://ucp.dev/' . $v . '/services/shopping/' . $this->serviceSchemaFile(),
                    'endpoint' => $endpoint,
                ]],
            ],
            'capabilities' => $this->profileCapabilities('https://ucp.dev/' . $v),
            'payment_handlers' => $this->handlerRegistry($this->discoveryHandlers($registry)),
        ];

        return $this->profileDocument($this->withSupportedVersions($ucp, $supportedVersionsMap), $storeName);
    }

    public function checkoutSession(array $session, PaymentRegistry $registry): array
    {
        $v = $this->version();
        $paymentMeta = $this->decode($session['payment_meta'] ?? null);
        $status = UcpStatus::resolve($session);
        $messages = UcpStatus::missingMessages(UcpStatus::missingRequirements($session));

        $response = [
            'ucp' => [
                'version' => $v,
                'status' => 'success',
                'capabilities' => [
                    'dev.ucp.shopping.checkout' => [['version' => $v]],
                    'dev.ucp.shopping.fulfillment' => [['version' => $v, 'extends' => 'dev.ucp.shopping.checkout']],
                ],
                'payment_handlers' => $this->handlerRegistry($registry->getUcpCheckoutHandlers($paymentMeta)),
            ],
            'id' => $session['session_uid'],
            'status' => $status,
            'currency' => $session['currency'] ?? 'USD',
            'line_items' => $this->decode($session['line_items'] ?? null),
            'totals' => $this->decode($session['totals'] ?? null),
            'messages' => $messages,
            'links' => [],
        ];

        $buyer = $this->decode($session['buyer'] ?? null);
        if ($buyer) {
            $response['buyer'] = $buyer;
        }
        $fulfillment = $this->decode($session['fulfillment'] ?? null);
        if ($fulfillment) {
            $response['fulfillment'] = $fulfillment;
        }
        if (!empty($session['expires_at'])) {
            $response['expires_at'] = $session['expires_at'];
        }

        return $response;
    }

    public function completeResponse(array $session, \Order $order, PaymentRegistry $registry): array
    {
        $response = $this->checkoutSession($session, $registry);
        $response['status'] = 'completed';

        $response['order'] = [
            'id' => (string) $order->id,
            'label' => $order->reference,
            'permalink_url' => '',
        ];

        if (!empty($session['payment_meta'])) {
            $meta = $this->decode($session['payment_meta']);
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

    public function order(\Order $order, int $idLang): array
    {
        $lineItems = [];
        foreach ($order->getProducts() as $p) {
            $qty = max(1, (int) $p['product_quantity']);
            $lineItems[] = [
                'id' => (string) $p['id_order_detail'],
                'item' => [
                    'id' => (string) $p['product_id'],
                    'title' => $p['product_name'],
                    'price' => Formatter::toMinor((float) $p['unit_price_tax_incl']),
                ],
                'quantity' => [
                    'original' => $qty,
                    'total' => $qty,
                    'fulfilled' => 0,
                ],
                'totals' => [
                    ['type' => 'subtotal', 'amount' => Formatter::toMinor((float) $p['total_price_tax_excl'])],
                    ['type' => 'total', 'amount' => Formatter::toMinor((float) $p['total_price_tax_incl'])],
                ],
            ];
        }

        $currency = new \Currency((int) $order->id_currency);
        $response = [
            'ucp' => $this->envelope([]),
            'id' => (string) $order->id,
            'label' => $order->reference,
            'status' => $this->orderStatus((int) $order->getCurrentState()),
            'currency' => $currency->iso_code,
            'line_items' => $lineItems,
            'totals' => [
                ['type' => 'subtotal', 'amount' => Formatter::toMinor((float) $order->total_products_wt)],
                ['type' => 'shipping', 'amount' => Formatter::toMinor((float) $order->total_shipping)],
                ['type' => 'total', 'amount' => Formatter::toMinor((float) $order->total_paid)],
            ],
        ];

        foreach ($order->getOrderPaymentCollection() as $payment) {
            if (!empty($payment->transaction_id)) {
                $response['transaction_reference'] = $payment->transaction_id;
                break;
            }
        }

        return $response;
    }

    public function envelope(array $capabilities): array
    {
        $declared = $this->capabilities();
        $block = [];
        foreach ($capabilities as $logical) {
            if (!isset($declared[$logical])) {
                continue;
            }
            $block[$declared[$logical]] = [['version' => $this->version()]];
        }

        $envelope = [
            'version' => $this->version(),
            'status' => 'success',
        ];
        if ($block) {
            $envelope['capabilities'] = $block;
        }

        return $envelope;
    }

    public function error(string $code, string $message): array
    {
        return [
            'ucp' => [
                'version' => $this->version(),
                'status' => 'error',
            ],
            'messages' => [
                [
                    'type' => 'error',
                    'code' => $code,
                    'content' => $message,
                    'severity' => $this->errorSeverity(),
                ],
            ],
        ];
    }

    public function capabilities(): array
    {
        return [
            'cart' => 'dev.ucp.shopping.cart',
            'catalog.search' => 'dev.ucp.shopping.catalog.search',
            'catalog.lookup' => 'dev.ucp.shopping.catalog.lookup',
            'order' => 'dev.ucp.shopping.order',
        ];
    }

    public function supports(string $logical): bool
    {
        return isset($this->capabilities()[$logical]);
    }

    protected function serviceSchemaFile(): string
    {
        return 'rest.openapi.json';
    }

    protected function profileCapabilities(string $base): array
    {
        $v = $this->version();

        return [
            'dev.ucp.shopping.catalog.search' => [[
                'version' => $v,
                'spec' => $base . '/specification/catalog/search',
                'schema' => $base . '/schemas/shopping/catalog_search.json',
            ]],
            'dev.ucp.shopping.catalog.lookup' => [[
                'version' => $v,
                'spec' => $base . '/specification/catalog/lookup',
                'schema' => $base . '/schemas/shopping/catalog_lookup.json',
            ]],
            'dev.ucp.shopping.cart' => [[
                'version' => $v,
                'spec' => $base . '/specification/cart',
                'schema' => $base . '/schemas/shopping/cart.json',
            ]],
            'dev.ucp.shopping.checkout' => [[
                'version' => $v,
                'spec' => $base . '/specification/checkout',
                'schema' => $base . '/schemas/shopping/checkout.json',
            ]],
            'dev.ucp.shopping.fulfillment' => [[
                'version' => $v,
                'spec' => $base . '/specification/fulfillment',
                'schema' => $base . '/schemas/shopping/fulfillment.json',
                'extends' => [
                    'dev.ucp.shopping.checkout',
                    'dev.ucp.shopping.catalog.search',
                    'dev.ucp.shopping.catalog.lookup',
                ],
            ]],
            'dev.ucp.shopping.order' => [[
                'version' => $v,
                'spec' => $base . '/specification/order',
                'schema' => $base . '/schemas/shopping/order.json',
            ]],
        ];
    }

    protected function profileDocument(array $ucp, string $storeName): array
    {
        return [
            'ucp' => $ucp,
            'name' => $storeName,
        ];
    }

    protected function discoveryHandlers(PaymentRegistry $registry): array
    {
        return $registry->getUcpDiscoveryHandlers($this->version());
    }

    protected function errorSeverity(): string
    {
        return 'fatal';
    }

    protected function handlerRegistry(array $handlers): array|object
    {
        return $handlers ?: (object) [];
    }

    protected function objectHandlerRegistry(array $handlers): array|object
    {
        if ($handlers === []) {
            return (object) [];
        }
        foreach ($handlers as $ns => $entries) {
            foreach ($entries as $i => $entry) {
                if (is_array($entry['config'] ?? null)) {
                    $handlers[$ns][$i]['config'] = (object) $entry['config'];
                }
            }
        }

        return $handlers;
    }

    protected function withSupportedVersions(array $ucp, array $supportedVersionsMap): array
    {
        if ($supportedVersionsMap === []) {
            return $ucp;
        }
        $result = [];
        foreach ($ucp as $key => $value) {
            $result[$key] = $value;
            if ($key === 'version') {
                $result['supported_versions'] = $supportedVersionsMap;
            }
        }

        return $result;
    }

    protected function decode(mixed $value): array
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

    private function orderStatus(int $idState): string
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
}
