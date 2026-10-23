<?php
/**
 * LinguaFlow AI 1.1.0: glossary, translation memory, review of held translations.
 *
 * @license AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

use LinguaFlowAI\Queue\JobRepository;
use LinguaFlowAI\Settings;

function upgrade_module_1_1_0($module)
{
    $db = Db::getInstance();
    foreach (JobRepository::installSql() as $sql) {
        $db->execute($sql);
    }

    $addColumn = function ($table, $column, $definition) use ($db) {
        $exists = $db->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . $table . "` LIKE '" . pSQL($column) . "'");
        if (!$exists) {
            $db->execute('ALTER TABLE `' . _DB_PREFIX_ . $table . '` ADD `' . $column . '` ' . $definition);
        }
    };
    $addColumn('linguaflowai_job', 'review', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER `failed`');
    $addColumn('linguaflowai_item', 'payload', 'MEDIUMTEXT NULL AFTER `error`');

    foreach (Settings::defaults() as $key => $value) {
        if (Configuration::getGlobalValue($key) === false) {
            Settings::set($key, $value);
        }
    }

    if (!Tab::getIdFromClassName(LinguaFlowAI::GLOSSARY_CONTROLLER)) {
        $tab = new Tab();
        $tab->class_name = LinguaFlowAI::GLOSSARY_CONTROLLER;
        $tab->module = $module->name;
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminCatalog');
        $tab->active = 1;
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[(int) $lang['id_lang']] = 'LinguaFlow glossary';
        }
        $tab->add();
    }

    return true;
}
