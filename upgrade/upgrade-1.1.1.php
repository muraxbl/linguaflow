<?php
/**
 * LinguaFlow AI 1.1.1: the product grid bulk action no longer uses a Symfony route and controller.
 *
 * @license AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_1($module)
{
    $module->unregisterHook('actionProductGridDefinitionModifier');

    // Files of the removed Symfony controller: if left behind they keep breaking the container.
    foreach (['config/services.yml', 'config/routes.yml', 'src/Controller/Admin/BulkTranslateController.php'] as $file) {
        if (file_exists($module->getLocalPath() . $file)) {
            @unlink($module->getLocalPath() . $file);
        }
    }

    return true;
}
