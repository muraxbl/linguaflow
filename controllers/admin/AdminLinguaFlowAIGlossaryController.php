<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

use LinguaFlowAI\Settings;
use LinguaFlowAI\Translation\GlossaryMatcher;
use LinguaFlowAI\Translation\GlossaryRepository;
use LinguaFlowAI\Translation\LanguageInfo;
use LinguaFlowAI\Translation\MemoryInterface;
use LinguaFlowAI\Translation\TranslationMemory;

/**
 * Catalog > LinguaFlow glossary: glossary editor and import/export, translation memory and category hints.
 */
class AdminLinguaFlowAIGlossaryController extends ModuleAdminController
{
    private const PAGE_SIZE = 50;
    private const MAX_UPLOAD = 20971520;

    /** @var LinguaFlowAI */
    public $module;

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent()
    {
        if (Tools::getValue('export') === 'glossary') {
            $this->download('glossary.json', (new GlossaryRepository())->all());
        }

        parent::initContent();
        $glossary = new GlossaryRepository();
        $query = trim((string) Tools::getValue('q'));
        $page = max(1, (int) Tools::getValue('page'));
        $total = $glossary->count($query);
        $edit = (int) Tools::getValue('edit_term');

        $terms = $glossary->search($query, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);
        $editTerm = null;
        foreach ($terms as $term) {
            if ((int) $term['id_term'] === $edit) {
                $editTerm = $term;
            }
        }

        $this->context->smarty->assign([
            'lfai_url' => $this->context->link->getAdminLink($this->controller_name),
            'lfai_token' => Tools::getAdminTokenLite($this->controller_name),
            'lfai_jobs_url' => $this->context->link->getAdminLink(LinguaFlowAI::ADMIN_CONTROLLER),
            'lfai_codes' => $this->languageCodes(),
            'lfai_terms' => $terms,
            'lfai_edit_term' => $editTerm,
            'lfai_query' => $query,
            'lfai_total' => $total,
            'lfai_page' => $page,
            'lfai_pages' => max(1, (int) ceil($total / self::PAGE_SIZE)),
            'lfai_tm_stats' => (new TranslationMemory())->stats(),
            'lfai_categories' => $this->categoryChoices(),
            'lfai_category_hints' => $this->categoryHintRows(),
        ]);
        $this->content .= $this->context->smarty->fetch($this->module->getLocalPath() . 'views/templates/admin/glossary.tpl');
        $this->context->smarty->assign('content', $this->content);
    }

    public function postProcess()
    {
        $glossary = new GlossaryRepository();

        if (Tools::isSubmit('submitLfaiTerm')) {
            $term = trim((string) Tools::getValue('term'));
            if ($term === '') {
                $this->errors[] = $this->module->l('The term cannot be empty.', 'adminlinguaflowaiglossarycontroller');
            } else {
                $glossary->save($term, (array) Tools::getValue('tr', []), (int) Tools::getValue('id_term'));
                $this->confirmations[] = $this->module->l('Term saved.', 'adminlinguaflowaiglossarycontroller');
            }
        } elseif (Tools::isSubmit('submitLfaiDeleteTerm')) {
            $glossary->delete((int) Tools::getValue('id_term'));
            $this->confirmations[] = $this->module->l('Term deleted.', 'adminlinguaflowaiglossarycontroller');
        } elseif (Tools::isSubmit('submitLfaiImportGlossary')) {
            $data = $this->readUploadedJson('glossary_file');
            if ($data !== null) {
                $count = $glossary->import($data, (bool) Tools::getValue('replace'));
                $this->confirmations[] = sprintf($this->module->l('%d terms imported.', 'adminlinguaflowaiglossarycontroller'), $count);
            }
        } elseif (Tools::isSubmit('submitLfaiImportTm')) {
            $data = $this->readUploadedJson('tm_file');
            if ($data !== null) {
                $source = new Language(Settings::sourceLanguageId());
                $sourceCode = LanguageInfo::code((string) $source->iso_code, (string) $source->language_code);
                $result = (new TranslationMemory())->import($data, new GlossaryMatcher($glossary->all()), LanguageInfo::name($sourceCode, (string) $source->name));
                $this->confirmations[] = sprintf($this->module->l('%d memory entries imported, %d skipped.', 'adminlinguaflowaiglossarycontroller'), $result['imported'], $result['skipped']);
            }
        } elseif (Tools::isSubmit('submitLfaiPurgeTm')) {
            $status = Tools::getValue('status') === MemoryInterface::APPROVED ? MemoryInterface::APPROVED : MemoryInterface::MACHINE;
            (new TranslationMemory())->purge($status);
            $this->confirmations[] = $this->module->l('Memory entries deleted.', 'adminlinguaflowaiglossarycontroller');
        } elseif (Tools::isSubmit('submitLfaiCategoryHint')) {
            $hints = Settings::categoryHints();
            $idCategory = (int) Tools::getValue('id_category');
            $hint = trim(mb_substr((string) Tools::getValue('hint'), 0, 1000));
            if ($hint === '') {
                unset($hints[$idCategory]);
            } elseif ($idCategory > 0) {
                $hints[$idCategory] = $hint;
            }
            Settings::set(Settings::CATEGORY_HINTS, json_encode($hints, JSON_UNESCAPED_UNICODE));
            $this->confirmations[] = $this->module->l('Category hint saved.', 'adminlinguaflowaiglossarycontroller');
        }

        return parent::postProcess();
    }

    /** Glossary columns: the code of every installed language except the source one. */
    private function languageCodes(): array
    {
        $codes = [];
        foreach (Language::getLanguages(false) as $lang) {
            if ((int) $lang['id_lang'] !== Settings::sourceLanguageId()) {
                $codes[] = LanguageInfo::code((string) $lang['iso_code'], (string) ($lang['language_code'] ?? ''));
            }
        }

        return array_values(array_unique($codes));
    }

    private function categoryChoices(): array
    {
        $rows = Db::getInstance()->executeS('SELECT c.id_category, c.level_depth, cl.name FROM `' . _DB_PREFIX_ . 'category` c
            INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.id_category = c.id_category AND cl.id_lang = ' . (int) $this->context->language->id . ' AND cl.id_shop = ' . (int) $this->context->shop->id . '
            WHERE c.level_depth > 0 ORDER BY c.nleft') ?: [];
        foreach ($rows as &$row) {
            $row['label'] = str_repeat('— ', max(0, (int) $row['level_depth'] - 1)) . $row['name'];
        }

        return $rows;
    }

    private function categoryHintRows(): array
    {
        $rows = [];
        foreach (Settings::categoryHints() as $idCategory => $hint) {
            $category = new Category((int) $idCategory, (int) $this->context->language->id);
            $rows[] = ['id_category' => (int) $idCategory, 'name' => $category->name ?: '#' . $idCategory, 'hint' => $hint];
        }

        return $rows;
    }

    private function readUploadedJson(string $name): ?array
    {
        $file = $_FILES[$name] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $this->errors[] = $this->module->l('Choose a JSON file to import.', 'adminlinguaflowaiglossarycontroller');

            return null;
        }
        if ($file['size'] > self::MAX_UPLOAD) {
            $this->errors[] = $this->module->l('The file is too large (20 MB maximum).', 'adminlinguaflowaiglossarycontroller');

            return null;
        }
        $data = json_decode((string) file_get_contents($file['tmp_name']), true);
        if (!is_array($data)) {
            $this->errors[] = $this->module->l('The file is not valid JSON.', 'adminlinguaflowaiglossarycontroller');

            return null;
        }

        return $data;
    }

    private function download(string $filename, array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
