<?php

use FD\PrismUcp\Ucp\VersionRegistry;

final class FdTestUpgradedStore
{
    public const CURRENT = '2026-04-08';
    public const SUPPORTED = ['2026-08-25', '2026-01-23'];

    public static function seed(): void
    {
        VersionRegistry::seedOnUpgrade('0.5.3');
    }

    public static function registry(): VersionRegistry
    {
        self::seed();

        return VersionRegistry::fromConfiguration();
    }
}
