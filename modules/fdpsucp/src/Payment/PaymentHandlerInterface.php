<?php

namespace FD\PrismUcp\Payment;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Contract a payment handler module (fdpsdummy / fdpsprism) implements and
 * registers with the UCP core via the actionUcpCollectPaymentHandlers hook.
 * Ported from interface-fd-payment-handler.php.
 */
interface PaymentHandlerInterface
{
    /** Stable handler id, e.g. "x402" or "dummy". */
    public function id(): string;

    /** Human-readable name. */
    public function name(): string;

    /**
     * Entries for the /.well-known/ucp `payment_handlers` block.
     * Shape: [ '<handler_namespace>' => [ { id, name, version, spec, config, ... } ] ]
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function getUcpDiscoveryHandlers(): array;

    /**
     * Prepare payment requirements for a checkout session (called on
     * create/update). Returns data to persist in payment_meta, or null.
     *
     * @param array{checkout_id:string,total:int,currency:string,checkout_base_url:string,store_name:string,checkout_meta:?array} $input
     * @return array<string,mixed>|null
     */
    public function prepareCheckoutPayment(array $input): ?array;

    /**
     * Settle a payment with the agent's credential and place the paid order.
     * Returns [
     *   'success' => bool,
     *   'id_order' => ?int,
     *   'transaction_reference' => ?string,
     *   'network' => ?string,
     *   'error' => ?string,
     * ]
     *
     * @param array{session:array,cart:\Cart,handler_id:string,credential:mixed,checkout_meta:?array} $input
     * @return array<string,mixed>
     */
    public function settlePayment(array $input): array;

    /**
     * Handler config to embed in checkout-session responses.
     * Shape: [ '<handler_namespace>' => [ { id, version, config } ] ]
     *
     * @param array<string,mixed>|null $paymentMeta
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function getUcpCheckoutHandlers(?array $paymentMeta = null): array;
}
