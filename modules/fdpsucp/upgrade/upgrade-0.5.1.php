<?php
/**
 * Upgrade to 0.5.1 — self-wiring web-server rewrites.
 *
 * Earlier versions required the merchant to add the `.well-known/ucp` and
 * `/module/fdpsucp/api` rewrites to the web server by hand. This upgrade wires
 * them automatically into the store's .htaccess (above PrestaShop's
 * `# ~~start~~` marker, which is preserved across regeneration), so an existing
 * install starts serving those paths after re-uploading the module — no manual
 * Apache editing. On nginx (no per-directory .htaccess) it is a harmless no-op.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_5_1($module)
{
    // Delegates to the module's own idempotent wiring routine.
    return $module->installHtaccessRules();
}
