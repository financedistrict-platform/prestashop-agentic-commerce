<?php
/**
 * Upgrade to 0.4.0 — register the `moduleRoutes` hook so the UCP shopping
 * service is exposed at the clean native namespace `/ucp/v1/<path>` (the
 * PrestaShop analog of WordPress's register_rest_route), instead of relying
 * solely on a web-server rewrite to `/module/fdpsucp/api`.
 *
 * Existing 0.3.x installs already have the tables; they only need the new hook.
 * A cache/route-table rebuild is required for the route to take effect — clear
 * the PrestaShop cache after upgrading (run bin/console as www-data, not root).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_4_0($module)
{
    return (bool) $module->registerHook('moduleRoutes');
}
