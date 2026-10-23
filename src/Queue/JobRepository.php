<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Queue;

/**
 * Persistence for translation jobs (batches) and their items (one product x one language).
 */
class JobRepository
{
    public const TYPE_AUTO = 'auto';
    public const TYPE_BULK = 'bulk';
    public const TYPE_MANUAL = 'manual';

    public const JOB_QUEUED = 'queued';
    public const JOB_RUNNING = 'running';
    public const JOB_COMPLETED = 'completed';
    public const JOB_CANCELLED = 'cancelled';

    public const ITEM_PENDING = 'pending';
    public const ITEM_PROCESSING = 'processing';
    public const ITEM_DONE = 'done';
    public const ITEM_FAILED = 'failed';
    public const ITEM_SKIPPED = 'skipped';

    public const MAX_ATTEMPTS = 3;

    /** @var \Db */
    private $db;

    public function __construct(?\Db $db = null)
    {
        $this->db = $db ?: \Db::getInstance();
    }

    public static function installSql(): array
    {
        $p = _DB_PREFIX_;
        $engine = _MYSQL_ENGINE_;

        return [
            "CREATE TABLE IF NOT EXISTS `{$p}linguaflowai_job` (
                `id_job` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `type` VARCHAR(16) NOT NULL,
                `label` VARCHAR(255) NOT NULL DEFAULT '',
                `id_employee` INT UNSIGNED NOT NULL DEFAULT 0,
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
                `status` VARCHAR(16) NOT NULL,
                `options` TEXT NULL,
                `total` INT UNSIGNED NOT NULL DEFAULT 0,
                `done` INT UNSIGNED NOT NULL DEFAULT 0,
                `failed` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                `date_completed` DATETIME NULL,
                PRIMARY KEY (`id_job`),
                KEY `status` (`status`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$p}linguaflowai_item` (
                `id_item` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_job` INT UNSIGNED NOT NULL,
                `id_product` INT UNSIGNED NOT NULL,
                `id_lang` INT UNSIGNED NOT NULL,
                `status` VARCHAR(16) NOT NULL,
                `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `error` TEXT NULL,
                `available_at` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_item`),
                UNIQUE KEY `job_product_lang` (`id_job`, `id_product`, `id_lang`),
                KEY `queue` (`status`, `available_at`),
                KEY `product_lang` (`id_product`, `id_lang`, `status`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$p}linguaflowai_notice` (
                `id_job` INT UNSIGNED NOT NULL,
                `id_employee` INT UNSIGNED NOT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_job`, `id_employee`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4",
        ];
    }

    public static function uninstallSql(): array
    {
        $p = _DB_PREFIX_;

        return [
            "DROP TABLE IF EXISTS `{$p}linguaflowai_notice`",
            "DROP TABLE IF EXISTS `{$p}linguaflowai_item`",
            "DROP TABLE IF EXISTS `{$p}linguaflowai_job`",
        ];
    }

    /**
     * Creates a job with one item per product x language. Products that already have an
     * unfinished item for the same language in another job are not queued twice.
     *
     * @param int[] $productIds
     * @param int[] $langIds
     *
     * @return int the job id, 0 when nothing needed queueing
     */
    public function createJob(string $type, string $label, array $productIds, array $langIds, array $options, int $idEmployee, int $idShop, int $delaySeconds = 0): int
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        $langIds = array_values(array_unique(array_filter(array_map('intval', $langIds))));
        if ($productIds === [] || $langIds === []) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->insert('linguaflowai_job', [
            'type' => pSQL($type),
            'label' => pSQL(mb_substr($label, 0, 255)),
            'id_employee' => $idEmployee,
            'id_shop' => $idShop,
            'status' => self::JOB_QUEUED,
            'options' => pSQL(json_encode($options)),
            'date_add' => $now,
            'date_upd' => $now,
        ]);
        $idJob = (int) $this->db->Insert_ID();

        $added = $this->addItems($idJob, $productIds, $langIds, $delaySeconds);
        if ($added === 0) {
            $this->db->delete('linguaflowai_job', 'id_job = ' . $idJob);

            return 0;
        }

        return $idJob;
    }

    /**
     * Adds products to the open automatic job (creating it when needed). Used for newly created products.
     */
    public function enqueueAuto(array $productIds, array $langIds, array $options, int $idShop, int $delaySeconds, string $label): int
    {
        $idJob = (int) $this->db->getValue('SELECT id_job FROM `' . _DB_PREFIX_ . 'linguaflowai_job`
            WHERE type = \'' . self::TYPE_AUTO . '\' AND id_shop = ' . (int) $idShop . '
            AND status IN (\'' . self::JOB_QUEUED . '\', \'' . self::JOB_RUNNING . '\')
            ORDER BY id_job DESC');
        if (!$idJob) {
            return $this->createJob(self::TYPE_AUTO, $label, $productIds, $langIds, $options, 0, $idShop, $delaySeconds);
        }
        $this->addItems($idJob, $productIds, $langIds, $delaySeconds);

        return $idJob;
    }

    private function addItems(int $idJob, array $productIds, array $langIds, int $delaySeconds): int
    {
        $p = _DB_PREFIX_;
        $now = date('Y-m-d H:i:s');
        $availableAt = date('Y-m-d H:i:s', time() + max(0, $delaySeconds));
        $added = 0;

        foreach (array_chunk($productIds, 200) as $chunk) {
            $busy = [];
            $rows = $this->db->executeS("SELECT id_product, id_lang FROM `{$p}linguaflowai_item`
                WHERE id_product IN (" . implode(',', $chunk) . ")
                AND status IN ('" . self::ITEM_PENDING . "', '" . self::ITEM_PROCESSING . "')") ?: [];
            foreach ($rows as $row) {
                $busy[$row['id_product'] . '-' . $row['id_lang']] = true;
            }

            $values = [];
            foreach ($chunk as $idProduct) {
                foreach ($langIds as $idLang) {
                    if (!isset($busy[$idProduct . '-' . $idLang])) {
                        $values[] = sprintf("(%d, %d, %d, '%s', '%s', '%s')", $idJob, $idProduct, $idLang, self::ITEM_PENDING, $availableAt, $now);
                    }
                }
            }
            if ($values !== []) {
                $this->db->execute("INSERT IGNORE INTO `{$p}linguaflowai_item` (id_job, id_product, id_lang, status, available_at, date_upd) VALUES " . implode(',', $values));
                $added += (int) $this->db->Affected_Rows();
            }
        }

        $this->refreshCounters($idJob);

        return $added;
    }

    /**
     * Atomically takes the next item that is due. Returns null when the queue is empty.
     */
    public function claimNext(): ?array
    {
        $p = _DB_PREFIX_;
        for ($i = 0; $i < 5; ++$i) {
            $row = $this->db->getRow("SELECT i.* FROM `{$p}linguaflowai_item` i
                INNER JOIN `{$p}linguaflowai_job` j ON j.id_job = i.id_job
                WHERE i.status = '" . self::ITEM_PENDING . "' AND i.available_at <= NOW()
                AND j.status IN ('" . self::JOB_QUEUED . "', '" . self::JOB_RUNNING . "')
                ORDER BY i.id_item ASC", false);
            if (!$row) {
                return null;
            }
            $this->db->execute("UPDATE `{$p}linguaflowai_item`
                SET status = '" . self::ITEM_PROCESSING . "', attempts = attempts + 1, date_upd = NOW()
                WHERE id_item = " . (int) $row['id_item'] . " AND status = '" . self::ITEM_PENDING . "'");
            if ($this->db->Affected_Rows() === 1) {
                $this->db->execute("UPDATE `{$p}linguaflowai_job` SET status = '" . self::JOB_RUNNING . "', date_upd = NOW()
                    WHERE id_job = " . (int) $row['id_job'] . " AND status = '" . self::JOB_QUEUED . "'");
                $row['attempts'] = (int) $row['attempts'] + 1;

                return $row;
            }
        }

        return null;
    }

    public function markDone(array $item, string $status = self::ITEM_DONE, string $note = ''): void
    {
        $this->db->update('linguaflowai_item', [
            'status' => pSQL($status),
            'error' => pSQL($note),
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_item = ' . (int) $item['id_item']);
    }

    /**
     * Records a failure. Retryable errors go back to the queue with a growing delay until MAX_ATTEMPTS.
     */
    public function markFailed(array $item, string $error, bool $retryable): void
    {
        $retry = $retryable && (int) $item['attempts'] < self::MAX_ATTEMPTS;
        $this->db->update('linguaflowai_item', [
            'status' => $retry ? self::ITEM_PENDING : self::ITEM_FAILED,
            'error' => pSQL(mb_substr($error, 0, 2000)),
            'available_at' => date('Y-m-d H:i:s', time() + ($retry ? 60 * (int) $item['attempts'] : 0)),
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_item = ' . (int) $item['id_item']);
    }

    /** Puts back items whose worker died mid-request. */
    public function releaseStale(int $minutes = 15): void
    {
        $this->db->execute('UPDATE `' . _DB_PREFIX_ . "linguaflowai_item` SET status = '" . self::ITEM_PENDING . "'
            WHERE status = '" . self::ITEM_PROCESSING . "' AND date_upd < DATE_SUB(NOW(), INTERVAL " . (int) $minutes . ' MINUTE)');
    }

    /**
     * Updates the job counters and closes it when nothing is left. Returns true when this call completed the job.
     */
    public function refreshCounters(int $idJob): bool
    {
        $p = _DB_PREFIX_;
        $counts = ['total' => 0, 'done' => 0, 'failed' => 0, 'open' => 0];
        foreach ($this->db->executeS("SELECT status, COUNT(*) n FROM `{$p}linguaflowai_item` WHERE id_job = " . $idJob . ' GROUP BY status') ?: [] as $row) {
            $n = (int) $row['n'];
            $counts['total'] += $n;
            if ($row['status'] === self::ITEM_DONE || $row['status'] === self::ITEM_SKIPPED) {
                $counts['done'] += $n;
            } elseif ($row['status'] === self::ITEM_FAILED) {
                $counts['failed'] += $n;
            } else {
                $counts['open'] += $n;
            }
        }

        $this->db->update('linguaflowai_job', [
            'total' => $counts['total'],
            'done' => $counts['done'],
            'failed' => $counts['failed'],
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_job = ' . $idJob);

        if ($counts['open'] === 0 && $counts['total'] > 0) {
            $this->db->execute("UPDATE `{$p}linguaflowai_job` SET status = '" . self::JOB_COMPLETED . "', date_completed = NOW()
                WHERE id_job = " . $idJob . " AND status IN ('" . self::JOB_QUEUED . "', '" . self::JOB_RUNNING . "')");

            return $this->db->Affected_Rows() === 1;
        }

        return false;
    }

    public function getJob(int $idJob): ?array
    {
        $job = $this->db->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'linguaflowai_job` WHERE id_job = ' . $idJob, false);
        if (!$job) {
            return null;
        }
        $job['options'] = json_decode((string) $job['options'], true) ?: [];

        return $job;
    }

    public function listJobs(int $limit = 50): array
    {
        return $this->db->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'linguaflowai_job` ORDER BY id_job DESC LIMIT ' . (int) $limit) ?: [];
    }

    public function failedItems(int $idJob, int $limit = 200): array
    {
        return $this->db->executeS('SELECT i.*, pl.name product_name, l.iso_code FROM `' . _DB_PREFIX_ . 'linguaflowai_item` i
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.id_product = i.id_product AND pl.id_lang = ' . (int) \Configuration::get('PS_LANG_DEFAULT') . ' AND pl.id_shop = ' . (int) \Configuration::get('PS_SHOP_DEFAULT') . '
            LEFT JOIN `' . _DB_PREFIX_ . 'lang` l ON l.id_lang = i.id_lang
            WHERE i.id_job = ' . $idJob . " AND i.status = '" . self::ITEM_FAILED . "'
            ORDER BY i.id_item LIMIT " . (int) $limit) ?: [];
    }

    public function cancelJob(int $idJob): void
    {
        $this->db->update('linguaflowai_item', ['status' => self::ITEM_SKIPPED, 'error' => 'Cancelled'], 'id_job = ' . $idJob . " AND status = '" . self::ITEM_PENDING . "'");
        $this->db->update('linguaflowai_job', ['status' => self::JOB_CANCELLED, 'date_upd' => date('Y-m-d H:i:s')], 'id_job = ' . $idJob . " AND status IN ('" . self::JOB_QUEUED . "', '" . self::JOB_RUNNING . "')");
    }

    public function retryFailed(int $idJob): void
    {
        $this->db->update('linguaflowai_item', [
            'status' => self::ITEM_PENDING,
            'attempts' => 0,
            'error' => '',
            'available_at' => date('Y-m-d H:i:s'),
        ], 'id_job = ' . $idJob . " AND status = '" . self::ITEM_FAILED . "'");
        if ($this->db->Affected_Rows() > 0) {
            $this->db->update('linguaflowai_job', ['status' => self::JOB_QUEUED, 'date_completed' => null], 'id_job = ' . $idJob, 0, true);
            $this->db->execute('DELETE FROM `' . _DB_PREFIX_ . 'linguaflowai_notice` WHERE id_job = ' . $idJob);
            $this->refreshCounters($idJob);
        }
    }

    public function deleteJob(int $idJob): void
    {
        $this->db->delete('linguaflowai_item', 'id_job = ' . $idJob);
        $this->db->delete('linguaflowai_notice', 'id_job = ' . $idJob);
        $this->db->delete('linguaflowai_job', 'id_job = ' . $idJob);
    }

    public function countDue(): int
    {
        return (int) $this->db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'linguaflowai_item` i
            INNER JOIN `' . _DB_PREFIX_ . 'linguaflowai_job` j ON j.id_job = i.id_job
            WHERE i.status = \'' . self::ITEM_PENDING . '\' AND i.available_at <= NOW()
            AND j.status IN (\'' . self::JOB_QUEUED . '\', \'' . self::JOB_RUNNING . '\')');
    }

    /** Jobs still in progress, for the back office progress indicator. */
    public function activeJobs(): array
    {
        return $this->db->executeS('SELECT id_job, type, label, status, total, done, failed FROM `' . _DB_PREFIX_ . "linguaflowai_job`
            WHERE status IN ('" . self::JOB_QUEUED . "', '" . self::JOB_RUNNING . "') ORDER BY id_job") ?: [];
    }

    /**
     * Completed jobs this employee has not been told about yet (last 7 days). They are marked as seen.
     */
    public function pullCompletedNotices(int $idEmployee): array
    {
        $p = _DB_PREFIX_;
        $jobs = $this->db->executeS("SELECT j.id_job, j.type, j.label, j.total, j.done, j.failed, j.date_completed
            FROM `{$p}linguaflowai_job` j
            LEFT JOIN `{$p}linguaflowai_notice` n ON n.id_job = j.id_job AND n.id_employee = " . $idEmployee . "
            WHERE j.status = '" . self::JOB_COMPLETED . "' AND n.id_job IS NULL
            AND j.date_completed > DATE_SUB(NOW(), INTERVAL 7 DAY)
            ORDER BY j.id_job") ?: [];
        foreach ($jobs as $job) {
            $this->db->insert('linguaflowai_notice', [
                'id_job' => (int) $job['id_job'],
                'id_employee' => $idEmployee,
                'date_add' => date('Y-m-d H:i:s'),
            ], false, true, \Db::INSERT_IGNORE);
        }

        return $jobs;
    }
}
