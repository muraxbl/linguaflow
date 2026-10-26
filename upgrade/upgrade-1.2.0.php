<?php
if (!defined('_PS_VERSION_')) { exit; }

function upgrade_module_1_2_0($module)
{
    if (Configuration::getGlobalValue(\LinguaFlowAI\Settings::SHOP_SCOPE) === false) {
        \LinguaFlowAI\Settings::set(\LinguaFlowAI\Settings::SHOP_SCOPE, 'context');
    }
    return true;
}
