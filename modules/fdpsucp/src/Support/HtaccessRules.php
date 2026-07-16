<?php

namespace FD\PrismUcp\Support;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Pure text transform for the module's web-server rewrites inside the store's
 * .htaccess. Deliberately free of PrestaShop and filesystem calls so it is
 * unit-testable in isolation; the Module class does the file IO around it.
 *
 * The rules map the two paths the module serves onto its front controllers —
 * needed because Friendly URLs are off (the Back Office needs them off on the
 * official image) and `.well-known/ucp` cannot be a PrestaShop route:
 *   /.well-known/ucp             -> discovery front controller
 *   /module/fdpsucp/api/<path>   -> api front controller (ucp_path=<path>)
 *
 * The block is inserted ABOVE PrestaShop's `# ~~start~~` marker, which
 * Tools::generateHtaccess() preserves verbatim (as $specific_before), so the
 * rewrites survive every .htaccess regeneration.
 */
final class HtaccessRules
{
    /** Delimits our block inside the store's .htaccess. */
    public const MARKER = '# ~~fdpsucp~~';

    /** Start of PrestaShop's own generated section. */
    private const PS_MARKER = '# ~~start~~';

    /** The rewrite block, delimited by MARKER start/end. */
    public static function block(): string
    {
        return self::MARKER . " start — Finance District UCP (do not edit inside this block)\n"
            . "<IfModule mod_rewrite.c>\n"
            . "RewriteEngine On\n"
            . "RewriteRule ^\\.well-known/ucp/?$ index.php?fc=module&module=fdpsucp&controller=discovery [QSA,L]\n"
            . "RewriteRule ^module/fdpsucp/api(?:/(.*))?$ index.php?fc=module&module=fdpsucp&controller=api&ucp_path=$1 [QSA,L]\n"
            . "</IfModule>\n"
            . self::MARKER . " end\n";
    }

    /** Whether $htaccess already carries our block. */
    public static function contains(string $htaccess): bool
    {
        return strpos($htaccess, self::MARKER) !== false;
    }

    /**
     * Return $htaccess with our block present exactly once. Inserted just
     * before PrestaShop's `# ~~start~~` section when there is one (so it lands
     * in the preserved region), otherwise prepended. Idempotent.
     */
    public static function apply(string $htaccess): string
    {
        if (self::contains($htaccess)) {
            return $htaccess;
        }

        $block = self::block();
        $pos = strpos($htaccess, self::PS_MARKER);

        if ($pos === false) {
            return $htaccess === '' ? $block : $block . "\n" . $htaccess;
        }

        return substr($htaccess, 0, $pos) . $block . "\n" . substr($htaccess, $pos);
    }

    /** Return $htaccess with our block removed. Idempotent. */
    public static function remove(string $htaccess): string
    {
        $marker = preg_quote(self::MARKER, '#');
        $pattern = '#' . $marker . ' start.*?' . $marker . " end\\n?\\n?#s";

        return (string) preg_replace($pattern, '', $htaccess);
    }
}
