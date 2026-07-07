<?php

namespace FD\PrismDummy;

use FD\PrismUcp\Payment\PaymentHandlerInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Always-succeeds payment handler. On settle it turns the session's transient
 * Cart into a paid PrestaShop order via PaymentModule::validateOrder() and
 * returns a fake transaction reference.
 */
final class DummyHandler implements PaymentHandlerInterface
{
    public const NS = 'com.fd.dummy';

    public function __construct(private \PaymentModule $module)
    {
    }

    public function id(): string
    {
        return 'dummy';
    }

    public function name(): string
    {
        return 'Dummy (test) payment';
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    public function getUcpDiscoveryHandlers(): array
    {
        return [
            self::NS => [[
                'id' => $this->id(),
                'name' => $this->name(),
                'version' => '2026-04-08',
                'spec' => 'https://ucp.dev/2026-04-08/specification/overview',
                'config' => [
                    'tokenization' => false,
                    'description' => 'Test handler — always succeeds, no real payment. Local testing only.',
                ],
            ]],
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>|null
     */
    public function prepareCheckoutPayment(array $input): ?array
    {
        // No gateway round-trip; just echo the amount we'd charge.
        return [
            'handler' => $this->id(),
            'amount' => $input['total'] ?? 0,
            'currency' => $input['currency'] ?? 'USD',
        ];
    }

    /**
     * @param array{session:array,cart:\Cart,handler_id:string,credential:mixed,checkout_meta:?array} $input
     * @return array<string,mixed>
     */
    public function settlePayment(array $input): array
    {
        // Safety rail: this handler always "succeeds" with no real payment, so
        // it must never place paid orders on a production store. Refuse unless
        // the shop is explicitly in developer mode.
        if (!(defined('_PS_MODE_DEV_') && _PS_MODE_DEV_)) {
            return ['success' => false, 'error' => 'Dummy payment handler is disabled outside developer mode'];
        }

        /** @var \Cart $cart */
        $cart = $input['cart'];
        if (!\Validate::isLoadedObject($cart)) {
            return ['success' => false, 'error' => 'Invalid cart'];
        }

        $customer = new \Customer((int) $cart->id_customer);
        if (!\Validate::isLoadedObject($customer)) {
            return ['success' => false, 'error' => 'Invalid customer'];
        }

        $total = (float) $cart->getOrderTotal(true, \Cart::BOTH);
        $txRef = 'DUMMY-' . strtoupper(bin2hex(random_bytes(8)));

        try {
            $this->module->validateOrder(
                (int) $cart->id,
                (int) \Configuration::get('PS_OS_PAYMENT'),
                $total,
                $this->name(),
                null,
                ['transaction_id' => $txRef],
                (int) $cart->id_currency,
                false,
                $customer->secure_key
            );
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'validateOrder failed: ' . $e->getMessage()];
        }

        $idOrder = (int) $this->module->currentOrder;
        if ($idOrder <= 0) {
            return ['success' => false, 'error' => 'Order was not created'];
        }

        return [
            'success' => true,
            'id_order' => $idOrder,
            'transaction_reference' => $txRef,
            'network' => 'test',
        ];
    }

    /**
     * @param array<string,mixed>|null $paymentMeta
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function getUcpCheckoutHandlers(?array $paymentMeta = null): array
    {
        return [
            self::NS => [[
                'id' => $this->id(),
                'version' => '2026-04-08',
                // Object, not []: an empty PHP array JSON-encodes to `[]`, but the
                // UCP schema requires `config` to be an object (`{}`).
                'config' => (object) [],
            ]],
        ];
    }
}
