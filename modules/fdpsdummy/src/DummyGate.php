<?php

namespace FD\PrismDummy;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class DummyGate
{
    public const OPT_IN_CONSTANT = 'FDPSDUMMY_ENABLED';
    public const PRISM_API_KEY = 'FDPSPRISM_API_KEY';

    public function __construct(
        private bool $optedIn,
        private bool $devMode,
        private bool $prismConfigured
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            defined(self::OPT_IN_CONSTANT) && constant(self::OPT_IN_CONSTANT) === true,
            defined('_PS_MODE_DEV_') && _PS_MODE_DEV_,
            trim((string) \Configuration::get(self::PRISM_API_KEY)) !== ''
        );
    }

    public function isOpen(): bool
    {
        return $this->optedIn && $this->devMode && !$this->prismConfigured;
    }
}
