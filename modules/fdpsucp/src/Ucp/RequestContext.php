<?php

namespace FD\PrismUcp\Ucp;

use FD\PrismUcp\Http\Response;
use FD\PrismUcp\Ucp\Wire\WireFormat;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class RequestContext
{
    public const OUTCOME_NONE = 'none';
    public const OUTCOME_MATCHED = 'matched';
    public const OUTCOME_UNREACHABLE = 'unreachable';
    public const OUTCOME_UNDECLARED = 'undeclared';
    public const OUTCOME_UNKNOWN = 'unknown';
    public const OUTCOME_DISABLED = 'disabled';

    private static ?self $current = null;

    public function __construct(
        private VersionRegistry $versions,
        private string $version,
        private string $outcome = self::OUTCOME_NONE,
        private ?string $declared = null,
        private ?array $rejection = null
    ) {
    }

    public static function current(): self
    {
        if (self::$current === null) {
            $versions = VersionRegistry::fromConfiguration();
            self::$current = new self($versions, $versions->currentWire()->version());
        }

        return self::$current;
    }

    public static function set(?self $context): void
    {
        self::$current = $context;
    }

    public static function forVersion(string $version, ?VersionRegistry $versions = null): self
    {
        return new self($versions ?? new VersionRegistry($version, []), $version);
    }

    public function version(): string
    {
        return $this->version;
    }

    public function wire(): WireFormat
    {
        return $this->versions->wire($this->version);
    }

    public function versions(): VersionRegistry
    {
        return $this->versions;
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function declared(): ?string
    {
        return $this->declared;
    }

    public function sessionPin(): ?string
    {
        return $this->outcome === self::OUTCOME_MATCHED ? $this->version : null;
    }

    public function rejection(): ?array
    {
        return $this->rejection;
    }

    public function rejectionResponse(): ?Response
    {
        if ($this->rejection === null) {
            return null;
        }

        return Response::json(
            $this->rejection['status'],
            $this->versions->currentWire()->error($this->rejection['code'], $this->rejection['message'])
        );
    }

    public function supportedVersionsMap(string $storeBase): array
    {
        $map = [];
        foreach ($this->versions->enabled() as $version) {
            if ($version !== $this->version) {
                $map[$version] = rtrim($storeBase, '/') . '/.well-known/ucp/' . $version;
            }
        }

        return $map;
    }
}
