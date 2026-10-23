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
        $jobs = new JobRepository();
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

    public function postProcess()
    {
        $jobs = new JobRepository();
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
        $jobs = new JobRepository();
        $page = max(1, (int) Tools::getValue('page'));
        $total = $jobs->countReview();
        $items = $jobs->reviewItems(self::REVIEW_PAGE_SIZE, ($page - 1) * self::REVIEW_PAGE_SIZE);
        $labels = $this->module->fieldLabels();
        foreach ($items as &$item) {
            $product = new Product((int) $item['id_product'], false, (int) $this->context->language->id);
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
        $jobs = new JobRepository();
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
            (int) ($item['payload']['id_shop'] ?? $this->context->shop->id),
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

        $scope = (string) Tools::getValue('lfai_scope');
        $productIds = $this->resolveProducts($scope, $langIds);
        if ($productIds === []) {
            $this->errors[] = $this->module->l('No product matches the selection.', 'adminlinguaflowaicontroller');

            return;
        }

        $options = $this->module->defaultJobOptions();
        $options['overwrite'] = (bool) Tools::getValue('lfai_overwrite');
        $label = trim((string) Tools::getValue('lfai_label')) ?: sprintf($this->module->l('Mass translation (%d products)', 'adminlinguaflowaicontroller'), count($productIds));

        $idJob = (new JobRepository())->createJob(
            JobRepository::TYPE_MANUAL,
            $label,
            $productIds,
            $langIds,
            $options,
            (int) $this->context->employee->id,
            (int) $this->context->shop->id
        );
        if ($idJob) {
            $this->confirmations[] = sprintf($this->module->l('Job #%d created: it runs in the background in small batches.', 'adminlinguaflowaicontroller'), $idJob);
        } else {
            $this->errors[] = $this->module->l('Those products are already queued for these languages.', 'adminlinguaflowaicontroller');
        }
    }

    /** @return int[] */
    private function resolveProducts(string $scope, array $langIds): array
    {
        $db = Db::getInstance();
        $p = _DB_PREFIX_;
        $idShop = (int) $this->context->shop->id;
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
        $jobs = new JobRepository();
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
            $stats = (new Worker())->run((int) Settings::get(Settings::BATCH_SIZE), self::TICK_BUDGET);
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
        $provider = (string) Tools::getValue('provider');
        $apiKey = trim((string) Tools::getValue('api_key'));
        if ($apiKey === '' && $provider === (string) Settings::get(Settings::PROVIDER)) {
            $apiKey = (string) Settings::get(Settings::API_KEY);
        }

        try {
            $config = new ProviderConfig($provider, $apiKey, (string) Tools::getValue('base_url'), '', 'medium', 20);
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
        $langIds = array_values(array_diff(array_map('intval', (array) Tools::getValue('langs', [])), [Settings::sourceLanguageId()]));
        if (!$idProduct || $langIds === []) {
            $this->json(['ok' => false, 'error' => $this->module->l('Select at least one language.', 'adminlinguaflowaicontroller')]);
        }
        $options = $this->module->defaultJobOptions();
        $options['overwrite'] = (bool) Tools::getValue('overwrite');
        $product = new Product($idProduct, false, (int) $this->context->language->id);
        $idJob = (new JobRepository())->createJob(
            JobRepository::TYPE_MANUAL,
            sprintf('#%d %s', $idProduct, $product->name),
            [$idProduct],
            $langIds,
            $options,
            (int) $this->context->employee->id,
            (int) $this->context->shop->id
        );
        $this->json($idJob
            ? ['ok' => true, 'id_job' => $idJob, 'message' => $this->module->l('Translation queued. You will be notified when it is done.', 'adminlinguaflowaicontroller')]
            : ['ok' => false, 'error' => $this->module->l('This product is already queued for these languages.', 'adminlinguaflowaicontroller')]);
    }

    /** Bulk action of the legacy product catalog (PrestaShop 1.7.x / 8.0), injected by notifier.js. */
    public function ajaxProcessEnqueueProducts()
    {
        $productIds = array_values(array_filter(array_map('intval', (array) Tools::getValue('products', []))));
        $langIds = Settings::targetLanguageIds();
        if ($langIds === []) {
            $this->json(['ok' => false, 'error' => $this->module->l('Enable at least one target language in the module settings first.', 'adminlinguaflowaicontroller')]);
        }
        if ($productIds === []) {
            $this->json(['ok' => false, 'error' => $this->module->l('Select at least one product.', 'adminlinguaflowaicontroller')]);
        }

        $idJob = (new JobRepository())->createJob(
            JobRepository::TYPE_BULK,
            sprintf($this->module->l('Product list selection (%d products)', 'adminlinguaflowaicontroller'), count($productIds)),
            $productIds,
            $langIds,
            $this->module->defaultJobOptions(),
            (int) $this->context->employee->id,
            (int) $this->context->shop->id
        );
        $this->json($idJob
            ? ['ok' => true, 'id_job' => $idJob, 'message' => sprintf($this->module->l('Job #%d queued: %d products x %d languages. You will be notified when it is done.', 'adminlinguaflowaicontroller'), $idJob, count($productIds), count($langIds))]
            : ['ok' => false, 'error' => $this->module->l('The selected products are already queued for these languages.', 'adminlinguaflowaicontroller')]);
    }

    private function json(array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }
}
