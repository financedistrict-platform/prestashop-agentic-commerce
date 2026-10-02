<?php

declare(strict_types=1);

use FD\PrismUcp\Ucp\VersionRegistry;
use PHPUnit\Framework\TestCase;

final class VersionSeedTest extends TestCase
{
    protected function setUp(): void
    {
        FdTestStubs::reset();
    }

    public function test_latest_is_the_newest_known_version(): void
    {
        $known = VersionRegistry::known();
        sort($known);

        $this->assertSame(end($known), VersionRegistry::LATEST);
        $this->assertSame(VersionRegistry::LATEST, VersionRegistry::DEFAULT_CURRENT);
    }

    public function test_default_supported_plus_latest_is_every_known_version(): void
    {
        $enabled = array_merge(VersionRegistry::DEFAULT_SUPPORTED, [VersionRegistry::LATEST]);

        $this->assertEqualsCanonicalizing(VersionRegistry::known(), $enabled);
        $this->assertCount(count(VersionRegistry::known()), array_unique($enabled));
    }

    public function test_every_known_version_is_an_iso_date(): void
    {
        foreach (VersionRegistry::known() as $version) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $version);
        }
    }

    public function test_upgrade_without_a_stored_version_seeds_the_latest_version(): void
    {
        VersionRegistry::seedOnUpgrade();

        $this->assertSame(VersionRegistry::LATEST, Configuration::get(VersionRegistry::KEY_CURRENT));
        $this->assertSame(VersionRegistry::DEFAULT_SUPPORTED, json_decode(Configuration::get(VersionRegistry::KEY_SUPPORTED), true));
        $this->assertEqualsCanonicalizing(VersionRegistry::known(), VersionRegistry::fromConfiguration()->enabled());
    }

    public function test_upgrade_leaves_an_existing_choice_untouched(): void
    {
        Configuration::$values[VersionRegistry::KEY_CURRENT] = '2026-01-23';
        Configuration::$values[VersionRegistry::KEY_SUPPORTED] = '[]';

        VersionRegistry::seedOnUpgrade();

        $this->assertSame('2026-01-23', Configuration::get(VersionRegistry::KEY_CURRENT));
        $this->assertSame('[]', Configuration::get(VersionRegistry::KEY_SUPPORTED));
    }

    public function test_upgrade_keeps_a_stored_supported_list_when_seeding_current(): void
    {
        Configuration::$values[VersionRegistry::KEY_SUPPORTED] = '["2026-01-23"]';

        VersionRegistry::seedOnUpgrade();

        $this->assertSame(VersionRegistry::LATEST, Configuration::get(VersionRegistry::KEY_CURRENT));
        $this->assertSame('["2026-01-23"]', Configuration::get(VersionRegistry::KEY_SUPPORTED));
    }

    public function test_upgrade_script_seeds_the_latest_version(): void
    {
        require_once dirname(__DIR__, 2) . '/upgrade/upgrade-0.7.0.php';
        $module = new class {
            public int $htaccessRuns = 0;

            public function installHtaccessRules(): bool
            {
                $this->htaccessRuns++;

                return true;
            }
        };

        $this->assertTrue(upgrade_module_0_7_0($module));
        $this->assertSame(VersionRegistry::LATEST, Configuration::get(VersionRegistry::KEY_CURRENT));
        $this->assertSame(1, $module->htaccessRuns);
    }

    public function test_install_writes_the_latest_version_and_default_supported_list(): void
    {
        Configuration::$values[VersionRegistry::KEY_CURRENT] = '2026-01-23';
        if (!defined('_MYSQL_ENGINE_')) {
            define('_MYSQL_ENGINE_', 'InnoDB');
        }
        if (!defined('_PS_ROOT_DIR_')) {
            $root = sys_get_temp_dir() . '/fdpsucp-install-' . bin2hex(random_bytes(4));
            mkdir($root);
            define('_PS_ROOT_DIR_', $root);
        }

        $this->assertTrue((new ReflectionClass(FdPsUcp::class))->newInstanceWithoutConstructor()->install());

        $this->assertSame('2026-08-25', Configuration::get(VersionRegistry::KEY_CURRENT));
        $this->assertSame(VersionRegistry::DEFAULT_SUPPORTED, json_decode(Configuration::get(VersionRegistry::KEY_SUPPORTED), true));
    }

    public function test_uninstall_deletes_the_version_settings(): void
    {
        Configuration::$values = [
            VersionRegistry::KEY_CURRENT => '2026-08-25',
            VersionRegistry::KEY_SUPPORTED => '[]',
            VersionRegistry::KEY_NEGOTIATION => 'strict',
            'FDPSUCP_AGENT_TOKEN' => 'keep',
        ];

        $this->assertTrue((new ReflectionClass(FdPsUcp::class))->newInstanceWithoutConstructor()->uninstall());

        $this->assertSame(['FDPSUCP_AGENT_TOKEN' => 'keep'], Configuration::$values);
    }
}
