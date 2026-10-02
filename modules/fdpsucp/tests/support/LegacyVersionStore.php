<?php

use FD\PrismUcp\Ucp\VersionRegistry;

final class FdTestLegacyVersionStore
{
    public const CURRENT = '2026-04-08';
    public const SUPPORTED = ['2026-08-25', '2026-01-23'];

    public static function seed(): void
    {
        \Configuration::updateValue(VersionRegistry::KEY_CURRENT, self::CURRENT);
        \Configuration::updateValue(VersionRegistry::KEY_SUPPORTED, (string) json_encode(self::SUPPORTED));
    }

    public static function registry(): VersionRegistry
    {
        self::seed();

        return VersionRegistry::fromConfiguration();
    }
}
