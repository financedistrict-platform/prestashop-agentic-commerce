<?php

namespace FD\PrismUcp\Ucp;

use FD\PrismUcp\Ucp\Wire\Wire20260123;
use FD\PrismUcp\Ucp\Wire\Wire20260408;
use FD\PrismUcp\Ucp\Wire\Wire20260825;
use FD\PrismUcp\Ucp\Wire\WireFormat;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class VersionRegistry
{
    public const LATEST = '2026-08-25';
    public const DEFAULT_CURRENT = self::LATEST;
    public const DEFAULT_SUPPORTED = ['2026-04-08', '2026-01-23'];
    public const NEGOTIATION_LENIENT = 'lenient';
    public const NEGOTIATION_STRICT = 'strict';

    public const KEY_CURRENT = 'FDPSUCP_UCP_VERSION';
    public const KEY_SUPPORTED = 'FDPSUCP_UCP_SUPPORTED_VERSIONS';
    public const KEY_NEGOTIATION = 'FDPSUCP_UCP_NEGOTIATION';

    private const WIRES = [
        '2026-08-25' => Wire20260825::class,
        '2026-04-08' => Wire20260408::class,
        '2026-01-23' => Wire20260123::class,
    ];

    private array $supported;

    private array $wires = [];

    public function __construct(
        private string $current = self::DEFAULT_CURRENT,
        array $supported = self::DEFAULT_SUPPORTED,
        private string $negotiation = self::NEGOTIATION_LENIENT,
        private ?string $storedError = null
    ) {
        $this->supported = array_values(array_unique(array_filter(
            array_map('strval', $supported),
            static fn (string $v): bool => $v !== $current
        )));
    }

    public static function fromConfiguration(): self
    {
        $current = \Configuration::get(self::KEY_CURRENT);
        $negotiation = \Configuration::get(self::KEY_NEGOTIATION);
        $rawSupported = \Configuration::get(self::KEY_SUPPORTED);

        $supported = self::DEFAULT_SUPPORTED;
        $storedError = null;
        if (is_string($rawSupported) && $rawSupported !== '') {
            $decoded = json_decode($rawSupported, true);
            if (is_array($decoded) && array_is_list($decoded)) {
                $supported = $decoded;
            } else {
                $storedError = 'Stored supported UCP versions are not a JSON list';
            }
        }

        return new self(
            is_string($current) && $current !== '' ? $current : self::DEFAULT_CURRENT,
            $supported,
            is_string($negotiation) && $negotiation !== '' ? $negotiation : self::NEGOTIATION_LENIENT,
            $storedError
        );
    }

    public static function seedOnInstall(): void
    {
        \Configuration::updateValue(self::KEY_CURRENT, self::DEFAULT_CURRENT);
        \Configuration::updateValue(self::KEY_SUPPORTED, (string) json_encode(self::DEFAULT_SUPPORTED));
    }

    public static function seedOnUpgrade(): void
    {
        $stored = \Configuration::get(self::KEY_CURRENT);
        if (is_string($stored) && $stored !== '') {
            return;
        }

        \Configuration::updateValue(self::KEY_CURRENT, self::LATEST);

        $rawSupported = \Configuration::get(self::KEY_SUPPORTED);
        if (!is_string($rawSupported) || $rawSupported === '') {
            \Configuration::updateValue(self::KEY_SUPPORTED, (string) json_encode(self::DEFAULT_SUPPORTED));
        }
    }

    public static function deleteConfiguration(): void
    {
        foreach ([self::KEY_CURRENT, self::KEY_SUPPORTED, self::KEY_NEGOTIATION] as $key) {
            \Configuration::deleteByName($key);
        }
    }

    public static function known(): array
    {
        return array_keys(self::WIRES);
    }

    public static function negotiationModes(): array
    {
        return [self::NEGOTIATION_LENIENT, self::NEGOTIATION_STRICT];
    }

    public static function isKnown(string $version): bool
    {
        return isset(self::WIRES[$version]);
    }

    public function current(): string
    {
        return $this->current;
    }

    public function supported(): array
    {
        return array_values(array_filter($this->supported, [self::class, 'isKnown']));
    }

    public function enabled(): array
    {
        return array_merge([$this->current], $this->supported());
    }

    public function negotiation(): string
    {
        return $this->negotiation;
    }

    public function isStrict(): bool
    {
        return $this->negotiation === self::NEGOTIATION_STRICT;
    }

    public function isEnabled(string $version): bool
    {
        return in_array($version, $this->enabled(), true);
    }

    public function wire(string $version): WireFormat
    {
        if (!self::isKnown($version)) {
            throw new \InvalidArgumentException("Unknown UCP version: $version");
        }
        if (!isset($this->wires[$version])) {
            $class = self::WIRES[$version];
            $this->wires[$version] = new $class();
        }

        return $this->wires[$version];
    }

    public function currentWire(): WireFormat
    {
        return $this->wire(self::isKnown($this->current) ? $this->current : self::DEFAULT_CURRENT);
    }

    public function assertValid(): ?string
    {
        if ($this->storedError !== null) {
            return $this->storedError;
        }
        if (!self::isKnown($this->current)) {
            return "Unknown UCP version: {$this->current}";
        }
        foreach ($this->supported as $version) {
            if (!self::isKnown($version)) {
                return "Unknown supported UCP version: $version";
            }
        }
        if (!in_array($this->negotiation, self::negotiationModes(), true)) {
            return "Unknown UCP version negotiation: {$this->negotiation}";
        }

        return null;
    }
}
