<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_7_11($module)
{
    return $module->registerHook('displayAdminOrderMainBottom');
}
