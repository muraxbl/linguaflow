<?php
/**
 * LinguaFlow AI - AI powered catalog translation for PrestaShop.
 *
 * @license AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use LinguaFlowAI\Provider\ProviderConfig;
use LinguaFlowAI\Queue\JobRepository;
use LinguaFlowAI\Settings;
use PrestaShop\PrestaShop\Core\Grid\Action\Bulk\Type\SubmitBulkAction;

class LinguaFlowAI extends Module
{
    public const ADMIN_CONTROLLER = 'AdminLinguaFlowAI';

    /** Hooks this module exposes so other modules can react. */
    private const CUSTOM_HOOKS = [
        'actionLinguaFlowAIJobCompleted' => 'LinguaFlow AI: a translation batch finished',
        'actionLinguaFlowAIProductTranslated' => 'LinguaFlow AI: a product was translated into a language',
    ];

    public function __construct()
    {
        $this->name = 'linguaflowai';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'LinguaFlow';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '8.1.0', 'max' => '9.99.99'];

        parent::__construct();

        $this->displayName = $this->l('LinguaFlow AI - Catalog translation');
        $this->description = $this->l('Translates products with AI (Anthropic, OpenAI, Ollama, Open WebUI or any OpenAI compatible API), automatically for new products or in batches from the product list.');
        $this->confirmUninstall = $this->l('All translation jobs and settings will be deleted. Translated products are kept.');

        $this->tabs = [[
            'class_name' => self::ADMIN_CONTROLLER,
            'route_name' => '',
            'name' => 'LinguaFlow AI',
            'parent_class_name' => 'AdminCatalog',
            'visible' => true,
            'icon' => 'translate',
        ]];
    }

    public function install()
    {
        if (PHP_VERSION_ID < 80100) {
            $this->_errors[] = $this->l('LinguaFlow AI requires PHP 8.1 or newer.');

            return false;
        }
        if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
            $this->_errors[] = $this->l('Dependencies are missing: run "composer install --no-dev" in the module folder.');

            return false;
        }

        foreach (JobRepository::installSql() as $sql) {
            if (!Db::getInstance()->execute($sql)) {
                return false;
            }
        }
        foreach (Settings::defaults() as $key => $value) {
            if (Configuration::getGlobalValue($key) === false) {
                Settings::set($key, $value);
            }
        }
        foreach (self::CUSTOM_HOOKS as $name => $title) {
            if (!Hook::getIdByName($name)) {
                $hook = new Hook();
                $hook->name = $name;
                $hook->title = $title;
                $hook->add();
            }
        }

        return parent::install()
            && $this->registerHook('actionObjectProductAddAfter')
            && $this->registerHook('actionAdminControllerSetMedia')
            && $this->registerHook('actionProductGridDefinitionModifier')
            && $this->registerHook('displayAdminProductsExtra');
    }

    public function uninstall()
    {
        foreach (JobRepository::uninstallSql() as $sql) {
            Db::getInstance()->execute($sql);
        }
        foreach (array_keys(Settings::defaults()) as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    /* --------------------------------------------------------------------
     * Hooks
     * ------------------------------------------------------------------ */

    /**
     * New product: queue it for every enabled language. The delay gives the merchant time to fill in
     * the product (the PS 8.1+ product page creates an empty product first).
     */
    public function hookActionObjectProductAddAfter(array $params)
    {
        if (!Settings::get(Settings::AUTO_NEW) || empty($params['object']->id)) {
            return;
        }
        $langIds = Settings::targetLanguageIds();
        if ($langIds === []) {
            return;
        }

        try {
            (new JobRepository())->enqueueAuto(
                [(int) $params['object']->id],
                $langIds,
                $this->defaultJobOptions(),
                (int) $this->context->shop->id,
                60 * (int) Settings::get(Settings::AUTO_DELAY),
                $this->l('New products (automatic)')
            );
        } catch (Throwable $e) {
            // Never break product creation because of the translation queue.
            PrestaShopLogger::addLog('LinguaFlow AI: could not queue product: ' . $e->getMessage(), 2, null, 'linguaflowai');
        }
    }

    /**
     * Loads the notifier (toasts, browser notifications, progress pill, back office worker) on every BO page.
     */
    public function hookActionAdminControllerSetMedia()
    {
        if (empty($this->context->employee->id) || !$this->active) {
            return;
        }
        $link = $this->context->link->getAdminLink(self::ADMIN_CONTROLLER);
        Media::addJsDef(['linguaflowai' => [
            'statusUrl' => $link . '&ajax=1&action=status',
            'tickUrl' => $link . '&ajax=1&action=tick',
            'dashboardUrl' => $link,
            'worker' => (bool) Settings::get(Settings::BO_WORKER),
            'pollInterval' => 15000,
            'icon' => $this->getPathUri() . 'logo.png',
            'i18n' => [
                'completedTitle' => $this->l('Translation finished'),
                'completedBody' => $this->l('%label%: %done% translated, %failed% failed.'),
                'progress' => $this->l('Translating'),
                'open' => $this->l('Open'),
            ],
        ]]);
        $this->context->controller->addCSS($this->getPathUri() . 'views/css/admin.css');
        $this->context->controller->addJS($this->getPathUri() . 'views/js/notifier.js');
    }

    /**
     * Adds "Translate with AI" to the bulk actions of the product list (PS 8.1+ product grid).
     */
    public function hookActionProductGridDefinitionModifier(array $params)
    {
        if (!class_exists(SubmitBulkAction::class)) {
            return;
        }
        $params['definition']->getBulkActions()->add(
            (new SubmitBulkAction('linguaflowai_translate'))
                ->setName($this->l('Translate with AI (LinguaFlow)'))
                ->setOptions(['submit_route' => 'linguaflowai_bulk_translate'])
        );
    }

    /**
     * "Translate now" panel in the Modules tab of the product page.
     */
    public function hookDisplayAdminProductsExtra(array $params)
    {
        $idProduct = (int) ($params['id_product'] ?? Tools::getValue('id_product'));
        if (!$idProduct) {
            return '';
        }
        $this->context->smarty->assign([
            'lfai_id_product' => $idProduct,
            'lfai_languages' => $this->targetLanguageChoices(),
            'lfai_enqueue_url' => $this->context->link->getAdminLink(self::ADMIN_CONTROLLER) . '&ajax=1&action=enqueueProduct',
            'lfai_configured' => Settings::targetLanguageIds() !== [],
            'lfai_config_url' => $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/product_panel.tpl');
    }

    /* --------------------------------------------------------------------
     * Configuration page
     * ------------------------------------------------------------------ */

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitLinguaFlowAI')) {
            $errors = $this->saveConfiguration();
            $output .= $errors ? $this->displayError($errors) : $this->displayConfirmation($this->l('Settings saved.'));
        }

        $provider = (string) Settings::get(Settings::PROVIDER);
        $this->context->controller->addJS($this->getPathUri() . 'views/js/configure.js');
        $this->context->smarty->assign([
            'lfai_action' => $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]),
            'lfai_models_url' => $this->context->link->getAdminLink(self::ADMIN_CONTROLLER) . '&ajax=1&action=listModels',
            'lfai_dashboard_url' => $this->context->link->getAdminLink(self::ADMIN_CONTROLLER),
            'lfai_providers' => ProviderConfig::LABELS,
            'lfai_provider_defaults' => json_encode(ProviderConfig::DEFAULTS),
            'lfai_provider' => $provider,
            'lfai_has_key' => (string) Settings::get(Settings::API_KEY) !== '',
            'lfai_base_url' => (string) Settings::get(Settings::BASE_URL),
            'lfai_model' => (string) Settings::get(Settings::MODEL),
            'lfai_effort' => (string) Settings::get(Settings::EFFORT),
            'lfai_timeout' => (int) Settings::get(Settings::TIMEOUT),
            'lfai_languages' => Language::getLanguages(false),
            'lfai_source_lang' => Settings::sourceLanguageId(),
            'lfai_target_langs' => Settings::targetLanguageIds(),
            'lfai_all_fields' => $this->fieldLabels(),
            'lfai_fields' => Settings::fields(),
            'lfai_overwrite' => (bool) Settings::get(Settings::OVERWRITE),
            'lfai_slug' => (bool) Settings::get(Settings::SLUG),
            'lfai_auto_new' => (bool) Settings::get(Settings::AUTO_NEW),
            'lfai_auto_delay' => (int) Settings::get(Settings::AUTO_DELAY),
            'lfai_instructions' => (string) Settings::get(Settings::INSTRUCTIONS),
            'lfai_do_not_translate' => (string) Settings::get(Settings::DO_NOT_TRANSLATE),
            'lfai_bo_worker' => (bool) Settings::get(Settings::BO_WORKER),
            'lfai_batch_size' => (int) Settings::get(Settings::BATCH_SIZE),
            'lfai_cron_url' => $this->context->link->getModuleLink($this->name, 'cron', ['token' => Settings::get(Settings::CRON_TOKEN)]),
        ]);

        return $output . $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }

    private function saveConfiguration(): array
    {
        $errors = [];
        $provider = (string) Tools::getValue('LFAI_PROVIDER');
        if (!isset(ProviderConfig::DEFAULTS[$provider])) {
            return [$this->l('Unknown provider.')];
        }

        $previousProvider = (string) Settings::get(Settings::PROVIDER);
        $apiKey = trim((string) Tools::getValue('LFAI_API_KEY'));
        if ($apiKey !== '') {
            Settings::set(Settings::API_KEY, $apiKey);
        } elseif (Tools::getValue('LFAI_API_KEY_CLEAR') || $previousProvider !== $provider) {
            // A key belongs to one provider; switching providers without a new key clears it.
            Settings::set(Settings::API_KEY, '');
        }

        $baseUrl = trim((string) Tools::getValue('LFAI_BASE_URL'));
        if ($baseUrl !== '' && !Validate::isAbsoluteUrl($baseUrl)) {
            $errors[] = $this->l('The base URL is not valid.');
        }
        if ($provider === ProviderConfig::OPENAI_COMPATIBLE && $baseUrl === '') {
            $errors[] = $this->l('OpenAI compatible providers need a base URL (for example https://openrouter.ai/api/v1).');
        }
        if (ProviderConfig::DEFAULTS[$provider]['needs_key'] && $apiKey === '' && (string) Settings::get(Settings::API_KEY) === '') {
            $errors[] = $this->l('This provider needs an API key.');
        }

        $model = trim((string) Tools::getValue('LFAI_MODEL'));
        if ($model === '') {
            $errors[] = $this->l('Choose or type a model.');
        }

        $source = (int) Tools::getValue('LFAI_SOURCE_LANG');
        $targets = array_values(array_diff(array_map('intval', (array) Tools::getValue('LFAI_TARGET_LANGS', [])), [$source]));
        $fields = array_values(array_intersect((array) Tools::getValue('LFAI_FIELDS', []), array_keys(Settings::PRODUCT_FIELDS)));
        if ($fields === []) {
            $errors[] = $this->l('Select at least one field to translate.');
        }
        $effort = (string) Tools::getValue('LFAI_EFFORT');

        Settings::set(Settings::PROVIDER, $provider);
        Settings::set(Settings::BASE_URL, $baseUrl);
        Settings::set(Settings::MODEL, $model);
        Settings::set(Settings::EFFORT, in_array($effort, ['low', 'medium', 'high', 'xhigh', 'max'], true) ? $effort : 'medium');
        Settings::set(Settings::TIMEOUT, max(10, min(600, (int) Tools::getValue('LFAI_TIMEOUT'))));
        Settings::set(Settings::SOURCE_LANG, $source);
        Settings::set(Settings::TARGET_LANGS, json_encode($targets));
        if ($fields !== []) {
            Settings::set(Settings::FIELDS, json_encode($fields));
        }
        Settings::set(Settings::OVERWRITE, (int) (bool) Tools::getValue('LFAI_OVERWRITE'));
        Settings::set(Settings::SLUG, (int) (bool) Tools::getValue('LFAI_SLUG'));
        Settings::set(Settings::AUTO_NEW, (int) (bool) Tools::getValue('LFAI_AUTO_NEW'));
        Settings::set(Settings::AUTO_DELAY, max(0, min(1440, (int) Tools::getValue('LFAI_AUTO_DELAY'))));
        Settings::set(Settings::INSTRUCTIONS, mb_substr((string) Tools::getValue('LFAI_INSTRUCTIONS'), 0, 4000));
        Settings::set(Settings::DO_NOT_TRANSLATE, mb_substr((string) Tools::getValue('LFAI_DO_NOT_TRANSLATE'), 0, 4000));
        Settings::set(Settings::BO_WORKER, (int) (bool) Tools::getValue('LFAI_BO_WORKER'));
        Settings::set(Settings::BATCH_SIZE, max(1, min(50, (int) Tools::getValue('LFAI_BATCH_SIZE'))));
        if (Tools::getValue('LFAI_REGENERATE_TOKEN')) {
            Settings::set(Settings::CRON_TOKEN, bin2hex(random_bytes(16)));
        }
        if ($targets === []) {
            $errors[] = $this->l('No target language is enabled: nothing will be translated.');
        }

        return $errors;
    }

    /* --------------------------------------------------------------------
     * Helpers shared with the controllers
     * ------------------------------------------------------------------ */

    public function defaultJobOptions(): array
    {
        return [
            'fields' => Settings::fields(),
            'overwrite' => (bool) Settings::get(Settings::OVERWRITE),
            'slug' => (bool) Settings::get(Settings::SLUG),
            'source_lang' => Settings::sourceLanguageId(),
        ];
    }

    /** Installed languages except the source one, flagged when enabled in the settings. */
    public function targetLanguageChoices(): array
    {
        $enabled = Settings::targetLanguageIds();
        $choices = [];
        foreach (Language::getLanguages(false) as $lang) {
            if ((int) $lang['id_lang'] === Settings::sourceLanguageId()) {
                continue;
            }
            $lang['lfai_enabled'] = in_array((int) $lang['id_lang'], $enabled, true);
            $choices[] = $lang;
        }

        return $choices;
    }

    public function fieldLabels(): array
    {
        return [
            'name' => $this->l('Name'),
            'description_short' => $this->l('Summary (short description)'),
            'description' => $this->l('Description'),
            'meta_title' => $this->l('Meta title'),
            'meta_description' => $this->l('Meta description'),
            'available_now' => $this->l('Label when in stock'),
            'available_later' => $this->l('Label when out of stock'),
            'delivery_in_stock' => $this->l('Delivery time (in stock)'),
            'delivery_out_stock' => $this->l('Delivery time (out of stock)'),
        ];
    }
}
