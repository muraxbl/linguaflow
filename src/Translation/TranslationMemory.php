<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Translation;

/**
 * Translation memory stored in PrestaShop.
 */
class TranslationMemory implements MemoryInterface
{
    /** @var \Db */
    private $db;

    public function __construct(?\Db $db = null)
    {
        $this->db = $db ?: \Db::getInstance();
    }

    public function get(string $key): ?string
    {
        $tgt = $this->db->getValue('SELECT tgt FROM `' . _DB_PREFIX_ . "linguaflowai_tm` WHERE tm_key = '" . pSQL($key) . "'", false);

        return $tgt === false || $tgt === null ? null : (string) $tgt;
    }

    public function put(string $key, string $status, string $langCode, string $field, string $model, string $source, string $target): void
    {
        $this->db->execute('INSERT INTO `' . _DB_PREFIX_ . 'linguaflowai_tm` (tm_key, status, lang, field, model, src, tgt, date_upd) VALUES ('
            . "'" . pSQL($key) . "', '" . pSQL($status) . "', '" . pSQL($langCode) . "', '" . pSQL($field) . "', '" . pSQL(mb_substr($model, 0, 128)) . "', "
            . "'" . pSQL($source, true) . "', '" . pSQL($target, true) . "', '" . date('Y-m-d H:i:s') . "')
            ON DUPLICATE KEY UPDATE status = VALUES(status), tgt = VALUES(tgt), model = VALUES(model), date_upd = VALUES(date_upd)");
    }

    /**
     * Stores an approved field. When the block structure of the translation matches the source, every paragraph
     * is stored too: a corrected paragraph is then reused in the other products that repeat it.
     */
    public function approve(string $langCode, string $field, string $source, string $target): void
    {
        $source = Html::tidyInline(Html::decodeEntities($source));
        $this->put(SegmentTranslator::approvedKey($langCode, $field, $source), self::APPROVED, $langCode, $field, '', trim($source), $target);

        $srcParts = Html::splitBlocks($source);
        $tgtParts = Html::splitBlocks($target);
        if (count($srcParts) < 3 || count($srcParts) !== count($tgtParts)) {
            return;
        }
        for ($i = 1; $i < count($srcParts); $i += 2) {
            if ($srcParts[$i] !== $tgtParts[$i]) {
                return;
            }
        }
        for ($i = 0; $i < count($srcParts); $i += 2) {
            $core = trim($srcParts[$i]);
            if (Html::hasLetters($core)) {
                $this->put(SegmentTranslator::approvedKey($langCode, $field, $core), self::APPROVED, $langCode, $field, '', $core, trim($tgtParts[$i]));
            }
        }
    }

    /**
     * Imports a tm.json file from the LinguaFlow / Nasito web translator:
     * {"key": {"s": "ok"|"mt", "model": "...", "lang": "French", "field": "Description", "src": "...", "tgt": "..."}}.
     * Machine entries are keyed with the current glossary, so they are reused while it does not change.
     *
     * @return array{imported: int, skipped: int}
     */
    public function import(array $entries, GlossaryMatcher $glossary, string $sourceName): array
    {
        $result = ['imported' => 0, 'skipped' => 0];
        foreach ($entries as $entry) {
            $code = is_array($entry) ? LanguageInfo::codeFromName((string) ($entry['lang'] ?? '')) : '';
            $field = (string) ($entry['field'] ?? '');
            // Same normalisation as the translator applies before looking a text up.
            $src = trim(Html::tidyInline(Html::decodeEntities((string) ($entry['src'] ?? ''))));
            $tgt = (string) ($entry['tgt'] ?? '');
            if ($code === '' || !isset(PromptBuilder::FIELD_HINTS[$field]) || $src === '' || trim($tgt) === '') {
                ++$result['skipped'];
                continue;
            }

            if (($entry['s'] ?? '') === self::APPROVED) {
                $this->put(SegmentTranslator::approvedKey($code, $field, $src), self::APPROVED, $code, $field, '', $src, $tgt);
            } else {
                $model = (string) ($entry['model'] ?? '');
                $glossaryPrompt = $glossary->prompt($src, $code, $sourceName, LanguageInfo::name($code));
                $this->put(SegmentTranslator::machineKey($model, $code, $field, '', $glossaryPrompt, $src), self::MACHINE, $code, $field, $model, $src, $tgt);
            }
            ++$result['imported'];
        }

        return $result;
    }

    /** @return array<string, int> status => count */
    public function stats(): array
    {
        $stats = [self::APPROVED => 0, self::MACHINE => 0];
        foreach ($this->db->executeS('SELECT status, COUNT(*) n FROM `' . _DB_PREFIX_ . 'linguaflowai_tm` GROUP BY status') ?: [] as $row) {
            $stats[$row['status']] = (int) $row['n'];
        }

        return $stats;
    }

    /** Deletes the machine entries (for example after changing the prompt instructions). */
    public function purge(string $status): void
    {
        $this->db->delete('linguaflowai_tm', "status = '" . pSQL($status) . "'");
    }
}
