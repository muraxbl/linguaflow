<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Queue;

use LinguaFlowAI\Provider\ProviderException;
use LinguaFlowAI\Provider\ProviderFactory;
use LinguaFlowAI\Settings;
use LinguaFlowAI\Translation\ProductTranslationService;
use LinguaFlowAI\Translation\Translator;

/**
 * Processes the queue a few items at a time. Called by the back office poller and by the cron URL;
 * a MySQL named lock makes sure only one worker runs at once.
 */
class Worker
{
    private const LOCK = 'linguaflowai_worker';
    /** Products without a name yet (created moments ago) are re-checked this often. */
    private const NO_SOURCE_RETRY_SECONDS = 300;

    /** @var JobRepository */
    private $jobs;

    public function __construct(?JobRepository $jobs = null)
    {
        $this->jobs = $jobs ?: new JobRepository();
    }

    /**
     * @return array{processed: int, translated: int, failed: int, locked: bool, completed: int[]}
     */
    public function run(int $maxItems, int $timeBudgetSeconds): array
    {
        $stats = ['processed' => 0, 'translated' => 0, 'failed' => 0, 'locked' => false, 'completed' => []];
        $db = \Db::getInstance();
        if ((int) $db->getValue("SELECT GET_LOCK('" . self::LOCK . "', 0)") !== 1) {
            $stats['locked'] = true;

            return $stats;
        }

        try {
            $this->jobs->releaseStale();
            if ($this->jobs->countDue() === 0) {
                return $stats;
            }

            @set_time_limit(max(60, $timeBudgetSeconds + 150));
            $service = new ProductTranslationService(new Translator(
                ProviderFactory::create(Settings::providerConfig()),
                (string) Settings::get(Settings::INSTRUCTIONS),
                Settings::doNotTranslate()
            ));

            $deadline = microtime(true) + $timeBudgetSeconds;
            while ($stats['processed'] < $maxItems && microtime(true) < $deadline) {
                $item = $this->jobs->claimNext();
                if ($item === null) {
                    break;
                }
                ++$stats['processed'];
                $this->process($service, $item, $stats);

                if ($this->jobs->refreshCounters((int) $item['id_job'])) {
                    $stats['completed'][] = (int) $item['id_job'];
                    $this->signalCompleted((int) $item['id_job']);
                }
            }
        } catch (ProviderException $e) {
            // Misconfiguration (missing key, unknown provider): leave the queue untouched.
            \PrestaShopLogger::addLog('LinguaFlow AI: ' . $e->getMessage(), 3, null, 'linguaflowai');
            throw $e;
        } finally {
            $db->getValue("SELECT RELEASE_LOCK('" . self::LOCK . "')");
        }

        return $stats;
    }

    private function process(ProductTranslationService $service, array $item, array &$stats): void
    {
        $job = $this->jobs->getJob((int) $item['id_job']);
        $options = $job['options'] ?? [];

        try {
            $result = $service->translate(
                (int) $item['id_product'],
                (int) ($options['source_lang'] ?? Settings::sourceLanguageId()),
                (int) $item['id_lang'],
                (int) ($job['id_shop'] ?? \Configuration::get('PS_SHOP_DEFAULT')),
                $options
            );
        } catch (ProviderException $e) {
            $this->jobs->markFailed($item, $e->getMessage(), $e->isRetryable());
            ++$stats['failed'];

            return;
        } catch (\Throwable $e) {
            $this->jobs->markFailed($item, get_class($e) . ': ' . $e->getMessage(), false);
            ++$stats['failed'];

            return;
        }

        if ($result === ProductTranslationService::RESULT_TRANSLATED) {
            $this->jobs->markDone($item);
            ++$stats['translated'];
        } elseif ($result === ProductTranslationService::RESULT_NOTHING_TO_DO) {
            $this->jobs->markDone($item, JobRepository::ITEM_SKIPPED, 'Already translated');
        } elseif ((int) $item['attempts'] < JobRepository::MAX_ATTEMPTS) {
            // Brand new products may still be empty while the merchant fills them in.
            \Db::getInstance()->update('linguaflowai_item', [
                'status' => JobRepository::ITEM_PENDING,
                'available_at' => date('Y-m-d H:i:s', time() + self::NO_SOURCE_RETRY_SECONDS),
                'error' => 'Waiting for source content',
            ], 'id_item = ' . (int) $item['id_item']);
        } else {
            $this->jobs->markDone($item, JobRepository::ITEM_SKIPPED, 'Product has no name in the source language');
        }
    }

    /**
     * Lets PrestaShop and other modules know a batch finished. The back office notifier
     * picks the job up through JobRepository::pullCompletedNotices().
     */
    private function signalCompleted(int $idJob): void
    {
        $job = $this->jobs->getJob($idJob);
        \PrestaShopLogger::addLog(
            sprintf('LinguaFlow AI: job #%d completed (%d translated, %d failed).', $idJob, $job['done'] ?? 0, $job['failed'] ?? 0),
            1,
            null,
            'linguaflowai',
            $idJob
        );
        \Hook::exec('actionLinguaFlowAIJobCompleted', ['id_job' => $idJob, 'job' => $job]);
    }
}
