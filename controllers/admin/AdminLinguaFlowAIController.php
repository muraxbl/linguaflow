<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

use LinguaFlowAI\Provider\ProviderConfig;
use LinguaFlowAI\Provider\ProviderException;
use LinguaFlowAI\Provider\ProviderFactory;
use LinguaFlowAI\Queue\JobRepository;
use LinguaFlowAI\Queue\Worker;
use LinguaFlowAI\Settings;
use LinguaFlowAI\Security\AdminAccess;
use LinguaFlowAI\Security\ShopScope;
use LinguaFlowAI\Translation\ProductTranslationService;
use LinguaFlowAI\Translation\ProductWriter;
use LinguaFlowAI\Translation\TranslationMemory;

/**
 * Catalog > LinguaFlow AI: batch dashboard, mass translation form and the AJAX endpoints
 * used by the notifier, the settings page and the product page panel.
 */
class AdminLinguaFlowAIController extends ModuleAdminController
{
    /** Seconds a back office tick may spend translating before answering. */
    private const TICK_BUDGET = 20;
    private const REVIEW_PAGE_SIZE = 20;

    /** @var LinguaFlowAI */
    public $module;

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent()
    {
        parent::initContent();
        if (Tools::getValue('view') === 'review') {
            $this->renderReview();

            return;
        }
        $jobs = $this->jobs();
        $viewJob = (int) Tools::getValue('view_job');
        $jobList = $jobs->listJobs();
        $active = [];
        foreach ($jobs->activeJobs() as $row) {
            $active[(int) $row['id_job']] = $row;
        }
        foreach ($jobList as &$job) {
            $job['next_at'] = $active[(int) $job['id_job']]['next_at'] ?? null;
            $job['waiting'] = !empty($active[(int) $job['id_job']]['waiting']);
            $job['last_error'] = (string) ($active[(int) $job['id_job']]['last_error'] ?? '');
            $job['pct'] = $job['total'] > 0 ? (int) round(((int) $job['done'] + (int) $job['failed'] + (int) $job['review']) * 100 / (int) $job['total']) : 0;
        }
        unset($job);

        $this->context->smarty->assign([
            'lfai_url' => $this->context->link->getAdminLink($this->controller_name),
            'lfai_jobs' => $jobList,
            'lfai_shop_scope' => (string) Settings::get(Settings::SHOP_SCOPE),
            'lfai_shops' => Shop::getShops(true),
            'lfai_view_job' => $viewJob ? $jobs->getJob($viewJob) : null,
            'lfai_failed_items' => $viewJob ? $jobs->failedItems($viewJob) : [],
            'lfai_languages' => $this->module->targetLanguageChoices(),
            'lfai_categories' => $this->categoryChoices(),
            'lfai_overwrite' => (bool) Settings::get(Settings::OVERWRITE),
            'lfai_configured' => Settings::targetLanguageIds() !== [],
            'lfai_config_url' => $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->module->name]),
            'lfai_provider_label' => ProviderConfig::LABELS[(string) Settings::get(Settings::PROVIDER)] ?? '',
            'lfai_model' => (string) Settings::get(Settings::MODEL),
            'lfai_bo_worker' => (bool) Settings::get(Settings::BO_WORKER),
            'lfai_review_count' => $jobs->countReview(),
            'lfai_glossary_url' => $this->context->link->getAdminLink(LinguaFlowAI::GLOSSARY_CONTROLLER),
        ]);
        $this->content .= $this->context->smarty->fetch($this->module->getLocalPath() . 'views/templates/admin/dashboard.tpl');
        $this->context->smarty->assign('content', $this->content);
    }

    private function jobs(): JobRepository
    {
        return new JobRepository(null, ShopScope::authorizedIds());
    }

    public function postProcess()
    {
        $permission = 'view';
        $products = false;
        $configure = false;
        $mutating = false;
        if ($this->ajax) {
            $actions = ['status' => 'view', 'tick' => 'edit', 'listmodels' => 'edit', 'enqueueproduct' => 'add', 'enqueueproducts' => 'add'];
            $action = strtolower((string) Tools::getValue('action'));
            if (!isset($actions[$action])) {
                http_response_code(400);
                $this->json(['ok' => false, 'error' => 'Unknown action.']);
            }
            $permission = $actions[$action];
            $products = in_array($action, ['tick', 'enqueueproduct', 'enqueueproducts'], true);
            $configure = $action === 'listmodels';
            $mutating = true; // status also acknowledges completed notices.
        } else {
            foreach (['submitLfaiCreateJob' => 'add', 'submitLfaiCancel' => 'edit', 'submitLfaiRetry' => 'edit', 'submitLfaiDelete' => 'delete', 'submitLfaiApprove' => 'edit', 'submitLfaiRegenerate' => 'edit', 'submitLfaiReject' => 'edit'] as $submit => $needed) {
                if (Tools::isSubmit($submit)) {
                    $permission = $needed;
                    $products = true;
                    $mutating = true;
                    break;
                }
            }
        }
        if (!AdminAccess::allows($this, $permission, $products, $configure) || ($mutating && !AdminAccess::isPost())) {
            http_response_code(403);
            if ($this->ajax) {
                $this->json(['ok' => false, 'error' => 'Access denied. Use POST with a valid token and the required permissions.']);
            }
            $this->errors[] = 'Access denied. Use POST with a valid token and the required permissions.';
            return false;
        }
        try {
            return $this->processAuthorizedRequest();
        } catch (DomainException | ProviderException $e) {
            http_response_code(403);
            if ($this->ajax) {
                $this->json(['ok' => false, 'error' => $e->getMessage()]);
            }
            $this->errors[] = $e->getMessage();
            return false;
        }
    }

    private function processAuthorizedRequest()
    {
        if ($this->ajax) {
            return parent::postProcess();
        }
        $jobs = $this->jobs();
        $idJob = (int) Tools::getValue('id_job');

        if (Tools::isSubmit('submitLfaiCreateJob')) {
            $this->createJobFromForm();
        } elseif (Tools::isSubmit('submitLfaiCancel') && $idJob) {
            $jobs->cancelJob($idJob);
            $this->confirmations[] = $this->module->l('Job cancelled.', 'adminlinguaflowaicontroller');
        } elseif (Tools::isSubmit('submitLfaiRetry') && $idJob) {
            $jobs->retryFailed($idJob);
            $this->confirmations[] = $this->module->l('Failed items queued again.', 'adminlinguaflowaicontroller');
        } elseif (Tools::isSubmit('submitLfaiDelete') && $idJob) {
            $jobs->deleteJob($idJob);
            $this->confirmations[] = $this->module->l('Job deleted.', 'adminlinguaflowaicontroller');
        } elseif (Tools::isSubmit('submitLfaiApprove')) {
            $this->approveReviewItem((int) Tools::getValue('id_item'));
        } elseif (Tools::isSubmit('submitLfaiRegenerate')) {
            $jobs->requeue((int) Tools::getValue('id_item'));
            $this->confirmations[] = $this->module->l('Queued to be translated again (ignoring the memory).', 'adminlinguaflowaicontroller');
        } elseif (Tools::isSubmit('submitLfaiReject')) {
            $item = $jobs->getItem((int) Tools::getValue('id_item'));
            if ($item && $item['status'] === JobRepository::ITEM_REVIEW) {
                $jobs->markDone($item, JobRepository::ITEM_SKIPPED, 'Rejected in review');
                $jobs->refreshCounters((int) $item['id_job']);
                $this->confirmations[] = $this->module->l('Translation discarded.', 'adminlinguaflowaicontroller');
            }
        }

        return parent::postProcess();
    }

    private function renderReview(): void
    {
        $jobs = $this->jobs();
        $page = max(1, (int) Tools::getValue('page'));
        $total = $jobs->countReview();
        $items = $jobs->reviewItems(self::REVIEW_PAGE_SIZE, ($page - 1) * self::REVIEW_PAGE_SIZE);
        $labels = $this->module->fieldLabels();
        foreach ($items as &$item) {
            $product = new Product((int) $item['id_product'], false, (int) $this->context->language->id, (int) $item['id_shop']);
            $item['product_name'] = $product->name;
            $item['product_url'] = $this->context->link->getAdminLink('AdminProducts', true, ['id_product' => (int) $item['id_product']], ['id_product' => (int) $item['id_product'], 'updateproduct' => 1]);
            foreach ($item['payload']['fields'] ?? [] as $field => &$data) {
                $data['label'] = $labels[$field] ?? $field;
                $data['html'] = in_array($field, ['description', 'description_short'], true);
            }
        }
        unset($item, $data);

        $this->context->smarty->assign([
            'lfai_url' => $this->context->link->getAdminLink($this->controller_name),
            'lfai_items' => $items,
            'lfai_total' => $total,
            'lfai_page' => $page,
            'lfai_pages' => max(1, (int) ceil($total / self::REVIEW_PAGE_SIZE)),
        ]);
        $this->content .= $this->context->smarty->fetch($this->module->getLocalPath() . 'views/templates/admin/review.tpl');
        $this->context->smarty->assign('content', $this->content);
    }

    /**
     * Writes the (possibly edited) translation to the product and stores it as approved in the memory,
     * so the same text is never sent to the model again.
     */
    private function approveReviewItem(int $idItem): void
    {
        $jobs = $this->jobs();
        $item = $jobs->getItem($idItem);
        if (!$item || $item['status'] !== JobRepository::ITEM_REVIEW) {
            $this->errors[] = $this->module->l('This translation is no longer waiting for review.', 'adminlinguaflowaicontroller');

            return;
        }
        $job = $jobs->getJob((int) $item['id_job']);
        $edited = (array) Tools::getValue('outputs', []);
        $fields = $item['payload']['fields'] ?? [];
        $outputs = [];
        foreach ($fields as $field => $data) {
            $outputs[$field] = isset($edited[$field]) ? trim((string) $edited[$field]) : (string) $data['output'];
        }
        $outputs = array_filter($outputs, 'strlen');

        $idSourceLang = (int) ($item['payload']['source_lang'] ?? Settings::sourceLanguageId());
        (new ProductWriter())->write(
            (int) $item['id_product'],
            $idSourceLang,
            (int) $item['id_lang'],
            (int) $job['id_shop'],
            $outputs,
            $job['options'] ?? []
        );

        $memory = new TranslationMemory();
        $code = Worker::languageCode((int) $item['id_lang']);
        foreach ($outputs as $field => $output) {
            $memory->approve($code, ProductTranslationService::FIELD_TYPES[$field], (string) $fields[$field]['source'], $output);
        }

        $jobs->markDone($item, JobRepository::ITEM_DONE, 'Approved in review');
        $jobs->refreshCounters((int) $item['id_job']);
        $this->confirmations[] = $this->module->l('Translation approved, saved to the product and to the translation memory.', 'adminlinguaflowaicontroller');
    }

    private function createJobFromForm(): void
    {
        $langIds = array_map('intval', (array) Tools::getValue('lfai_langs', []));
        $langIds = array_values(array_diff($langIds, [Settings::sourceLanguageId()]));
        if ($langIds === []) {
            $this->errors[] = $this->module->l('Select at least one target language.', 'adminlinguaflowaicontroller');

            return;
        }

        $options = $this->module->defaultJobOptions();
        $options['overwrite'] = (bool) Tools::getValue('lfai_overwrite');
        $count = 0;
        foreach (ShopScope::targets((string) Tools::getValue('shop_scope', Settings::get(Settings::SHOP_SCOPE))) as $idShop) {
            $productIds = $this->resolveProducts((string) Tools::getValue('lfai_scope'), $langIds, $idShop);
            $label = trim((string) Tools::getValue('lfai_label')) ?: sprintf($this->module->l('Mass translation (%d products)', 'adminlinguaflowaicontroller'), count($productIds));
            $id = $this->jobs()->createJob(JobRepository::TYPE_MANUAL, $label, $productIds, $langIds, $options, (int) $this->context->employee->id, $idShop);
            if ($id) { ++$count; }
        }
        if ($count) {
            $this->confirmations[] = sprintf($this->module->l('%d shop jobs created.', 'adminlinguaflowaicontroller'), $count);
        } else {
            $this->errors[] = $this->module->l('No matching products, or the selection is already queued.', 'adminlinguaflowaicontroller');
        }
    }

    /** @return int[] */
    private function resolveProducts(string $scope, array $langIds, int $idShop): array
    {
        $db = Db::getInstance();
        $p = _DB_PREFIX_;
        $activeOnly = Tools::getValue('lfai_active_only') ? ' AND ps.active = 1' : '';

        switch ($scope) {
            case 'ids':
                $ids = array_filter(array_map('intval', preg_split('/[^0-9]+/', (string) Tools::getValue('lfai_ids'))));
                if ($ids === []) {
                    return [];
                }
                $sql = "SELECT ps.id_product FROM `{$p}product_shop` ps WHERE ps.id_shop = {$idShop} AND ps.id_product IN (" . implode(',', $ids) . "){$activeOnly}";
                break;
            case 'category':
                $category = new Category((int) Tools::getValue('lfai_category'));
                if (!Validate::isLoadedObject($category)) {
                    return [];
                }
                $categoryFilter = Tools::getValue('lfai_subcategories')
                    ? "c.nleft >= {$category->nleft} AND c.nright <= {$category->nright}"
                    : 'c.id_category = ' . (int) $category->id;
                $sql = "SELECT DISTINCT ps.id_product FROM `{$p}product_shop` ps
                    INNER JOIN `{$p}category_product` cp ON cp.id_product = ps.id_product
                    INNER JOIN `{$p}category` c ON c.id_category = cp.id_category
                    WHERE ps.id_shop = {$idShop} AND {$categoryFilter}{$activeOnly}";
                break;
            case 'missing':
                $source = Settings::sourceLanguageId();
                $sql = "SELECT DISTINCT ps.id_product FROM `{$p}product_shop` ps
                    INNER JOIN `{$p}product_lang` s ON s.id_product = ps.id_product AND s.id_shop = ps.id_shop AND s.id_lang = {$source}
                    LEFT JOIN `{$p}product_lang` t ON t.id_product = ps.id_product AND t.id_shop = ps.id_shop AND t.id_lang IN (" . implode(',', $langIds) . ")
                    WHERE ps.id_shop = {$idShop} AND s.name <> '' AND (t.id_product IS NULL OR t.name = '' OR t.name = s.name){$activeOnly}";
                break;
            case 'all':
                $sql = "SELECT ps.id_product FROM `{$p}product_shop` ps WHERE ps.id_shop = {$idShop}{$activeOnly}";
                break;
            default:
                return [];
        }

        return array_map('intval', array_column($db->executeS($sql . ' ORDER BY ps.id_product') ?: [], 'id_product'));
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

    /* --------------------------------------------------------------------
     * AJAX
     * ------------------------------------------------------------------ */

    /** Progress of running jobs + completed jobs this employee has not been notified about. */
    public function ajaxProcessStatus()
    {
        $jobs = $this->jobs();
        $this->json([
            'ok' => true,
            'due' => $jobs->countDue(),
            'review' => $jobs->countReview(),
            'reviewUrl' => $this->context->link->getAdminLink($this->controller_name) . '&view=review',
            'active' => $jobs->activeJobs(),
            'completed' => $jobs->pullCompletedNotices((int) $this->context->employee->id),
        ]);
    }

    /** Runs a slice of the queue; called repeatedly by the back office notifier while work is due. */
    public function ajaxProcessTick()
    {
        if (!Settings::get(Settings::BO_WORKER)) {
            $this->json(['ok' => false, 'error' => 'Back office worker disabled']);
        }
        session_write_close();
        try {
            $stats = (new Worker($this->jobs()))->run((int) Settings::get(Settings::BATCH_SIZE), self::TICK_BUDGET);
            $this->json(['ok' => true, 'stats' => $stats]);
        } catch (Throwable $e) {
            // Shown as a toast by the notifier: a silent failure looks like a queue that never moves.
            PrestaShopLogger::addLog('LinguaFlow AI worker: ' . $e->getMessage(), 3, null, 'linguaflowai');
            $this->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** Model list for the settings page. Uses the saved key when none is typed for the same provider. */
    public function ajaxProcessListModels()
    {
        $saved = Settings::providerConfig();
        $provider = (string) Tools::getValue('provider');
        $requested = new ProviderConfig($provider, '', (string) Tools::getValue('base_url'), '');
        if ($provider !== $saved->provider || $requested->baseUrl !== $saved->baseUrl) {
            throw new DomainException('Save the provider and its URL before loading models. Saved keys are only used with their saved endpoint.');
        }
        $apiKey = trim((string) Tools::getValue('api_key'));
        if ($apiKey === '' && $provider === (string) Settings::get(Settings::PROVIDER)) {
            $apiKey = (string) Settings::get(Settings::API_KEY);
        }

        try {
            $config = new ProviderConfig($provider, $apiKey, $saved->baseUrl, '', 'medium', 20);
            $models = ProviderFactory::create($config)->listModels();
            $this->json(['ok' => true, 'models' => $models]);
        } catch (ProviderException $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** "Translate now" from the product page. */
    public function ajaxProcessEnqueueProduct()
    {
        $idProduct = (int) Tools::getValue('id_product');
        $langs = array_values(array_diff(array_map('intval', (array) Tools::getValue('langs', [])), [Settings::sourceLanguageId()]));
        $options = $this->module->defaultJobOptions();
        $options['overwrite'] = (bool) Tools::getValue('overwrite');
        $this->enqueueSelection([$idProduct], $langs, $options, JobRepository::TYPE_MANUAL);
    }

    public function ajaxProcessEnqueueProducts()
    {
        $this->enqueueSelection((array) Tools::getValue('products', []), Settings::targetLanguageIds(), $this->module->defaultJobOptions(), JobRepository::TYPE_BULK);
    }

    private function enqueueSelection(array $productIds, array $langIds, array $options, string $type): void
    {
        $ids = [];
        foreach (ShopScope::targets((string) Tools::getValue('shop_scope', Settings::get(Settings::SHOP_SCOPE))) as $idShop) {
            $id = $this->jobs()->createJob($type, sprintf('Translation (%d selected products)', count($productIds)), $productIds, $langIds, $options, (int) $this->context->employee->id, $idShop);
            if ($id) { $ids[] = $id; }
        }
        $this->json(['ok' => (bool) $ids, 'id_job' => $ids ? $ids[0] : 0, 'id_jobs' => $ids,
            'message' => sprintf('%d shop jobs queued.', count($ids)),
            'error' => $ids ? '' : 'Select products and languages belonging to the selected shops; they may already be queued.']);
    }

    private function json(array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data);
        exit;
    }
}
