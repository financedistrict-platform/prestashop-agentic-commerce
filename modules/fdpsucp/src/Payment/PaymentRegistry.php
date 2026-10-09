<?php

namespace FD\PrismUcp\Payment;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Holds the active payment handlers. Populated by executing the
 * `actionUcpCollectPaymentHandlers` hook — each handler module adds itself.
 * Ported from FD_Payment_Registry.
 */
final class PaymentRegistry
{
    private const ALIASES = ['x402' => 'xyz.fd.prism_payment'];

    /** @var array<string,PaymentHandlerInterface> */
    private array $handlers = [];

    public function register(PaymentHandlerInterface $handler): void
    {
        $this->handlers[$handler->id()] = $handler;
    }

    public function canonicalId(string $id): string
    {
        $canonical = self::ALIASES[$id] ?? null;

        return ($canonical !== null && isset($this->handlers[$canonical])) ? $canonical : $id;
    }

    public function get(string $id): ?PaymentHandlerInterface
    {
        return $this->handlers[$this->canonicalId($id)] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->handlers === [];
    }

    /**
     * Build the registry by asking every installed module to contribute via
     * the collection hook. Returns a ready-to-use registry.
     */
    public static function collect(): self
    {
        $registry = new self();
        \Hook::exec('actionUcpCollectPaymentHandlers', ['registry' => $registry]);
        return $registry;
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    public function getUcpDiscoveryHandlers(?string $version = null): array
    {
        $merged = [];
        foreach ($this->handlers as $handler) {
            $entriesByNs = ($version !== null && $handler instanceof VersionedPaymentHandlerInterface)
                ? $handler->getUcpDiscoveryHandlersForVersion($version)
                : $handler->getUcpDiscoveryHandlers();
            foreach ($entriesByNs as $ns => $entries) {
                $merged[$ns] = array_merge($merged[$ns] ?? [], $entries);
            }
        }
        return $merged;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> keyed by handler id
     */
    public function prepareAll(array $input): array
    {
        $results = [];
        foreach ($this->handlers as $handler) {
            $results[$handler->id()] = $handler->prepareCheckoutPayment($input);
        }
        return $results;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function settle(string $handlerId, array $input): array
    {
        $handler = $this->get($handlerId);
        if (!$handler) {
            return ['success' => false, 'error' => "Unknown payment handler: $handlerId"];
        }
        $paidAmount = $handler->preparedAmount($input['checkout_meta'] ?? null);
        $amountError = PaymentIntegrity::settlementError($input['session'] ?? [], $input['cart'], $paidAmount);
        if ($amountError !== null) {
            return ['success' => false, 'error' => $amountError];
        }

        return $handler->settlePayment(['paid_amount' => $paidAmount] + $input);
    }

    /**
     * @param array<string,mixed>|null $paymentMeta
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function getUcpCheckoutHandlers(?array $paymentMeta = null): array
    {
        $merged = [];
        foreach ($this->handlers as $handler) {
            foreach ($handler->getUcpCheckoutHandlers($paymentMeta) as $ns => $entries) {
                $merged[$ns] = array_merge($merged[$ns] ?? [], $entries);
            }
        }
        return $merged;
    }
}
