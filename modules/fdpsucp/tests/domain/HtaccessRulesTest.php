<?php

declare(strict_types=1);

use FD\PrismUcp\Support\HtaccessRules;
use PHPUnit\Framework\TestCase;

/**
 * Pure-text behaviour of the .htaccess rewrite block the module wires on
 * install: it must land in the region PrestaShop preserves, be idempotent,
 * and come off cleanly on uninstall.
 */
final class HtaccessRulesTest extends TestCase
{
    private const PS_HTACCESS = "# ~~start~~ Do not remove this comment\n"
        . "<IfModule mod_rewrite.c>\n"
        . "RewriteEngine on\n"
        . "RewriteRule ^api(?:/(.*))?\$ webservice/dispatcher.php [QSA,L]\n"
        . "</IfModule>\n"
        . "# ~~end~~ Do not remove this comment\n";

    // ── apply() ──────────────────────────────────────────────

    public function test_apply_inserts_block_before_prestashop_section(): void
    {
        $result = HtaccessRules::apply(self::PS_HTACCESS);

        $this->assertTrue(HtaccessRules::contains($result));
        $this->assertStringContainsString('.well-known/ucp', $result);
        $this->assertStringContainsString('module/fdpsucp/api', $result);

        // Our block must precede PrestaShop's `# ~~start~~` so it survives
        // Tools::generateHtaccess() (which keeps everything before that marker).
        $this->assertLessThan(
            strpos($result, '# ~~start~~'),
            strpos($result, HtaccessRules::MARKER),
            'UCP block must be written above PrestaShop\'s ~~start~~ marker'
        );
    }

    public function test_apply_preserves_existing_prestashop_rules(): void
    {
        $result = HtaccessRules::apply(self::PS_HTACCESS);

        $this->assertStringContainsString('webservice/dispatcher.php', $result);
        $this->assertStringContainsString('# ~~end~~', $result);
    }

    public function test_apply_on_empty_file_yields_just_the_block(): void
    {
        $result = HtaccessRules::apply('');

        $this->assertTrue(HtaccessRules::contains($result));
        $this->assertStringContainsString('RewriteEngine On', $result);
    }

    public function test_apply_is_idempotent(): void
    {
        $once = HtaccessRules::apply(self::PS_HTACCESS);
        $twice = HtaccessRules::apply($once);

        $this->assertSame($once, $twice);
        $this->assertSame(1, substr_count($twice, HtaccessRules::MARKER . ' start'));
    }

    // ── remove() ─────────────────────────────────────────────

    public function test_remove_strips_block_and_restores_original(): void
    {
        $applied = HtaccessRules::apply(self::PS_HTACCESS);
        $removed = HtaccessRules::remove($applied);

        $this->assertFalse(HtaccessRules::contains($removed));
        $this->assertStringContainsString('# ~~start~~', $removed);
        $this->assertStringContainsString('webservice/dispatcher.php', $removed);
    }

    public function test_remove_is_a_noop_when_block_absent(): void
    {
        $this->assertSame(self::PS_HTACCESS, HtaccessRules::remove(self::PS_HTACCESS));
    }

    public function test_apply_then_remove_round_trips_to_original(): void
    {
        $this->assertSame(
            self::PS_HTACCESS,
            HtaccessRules::remove(HtaccessRules::apply(self::PS_HTACCESS))
        );
    }
}
