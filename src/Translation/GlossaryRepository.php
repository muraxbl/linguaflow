<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Translation;

/**
 * Glossary stored in PrestaShop: source term => [LANG CODE => translation].
 * Same shape as a glossary.json file ({"plafón": {"EN": "ceiling light", "FR": "plafonnier"}}).
 */
class GlossaryRepository
{
    /** @var \Db */
    private $db;

    public function __construct(?\Db $db = null)
    {
        $this->db = $db ?: \Db::getInstance();
    }

    /** @return array<string, array<string, string>> */
    public function all(): array
    {
        $terms = [];
        foreach ($this->db->executeS('SELECT term, translations FROM `' . _DB_PREFIX_ . 'linguaflowai_glossary` ORDER BY term') ?: [] as $row) {
            $terms[$row['term']] = json_decode($row['translations'], true) ?: [];
        }

        return $terms;
    }

    /** Rows for the editor, optionally filtered. */
    public function search(string $query, int $limit, int $offset): array
    {
        $where = $query !== '' ? " WHERE term LIKE '%" . pSQL(addcslashes($query, '%_')) . "%' OR translations LIKE '%" . pSQL(addcslashes($query, '%_')) . "%'" : '';
        $rows = $this->db->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'linguaflowai_glossary`' . $where . ' ORDER BY term LIMIT ' . (int) $offset . ', ' . (int) $limit) ?: [];
        foreach ($rows as &$row) {
            $row['translations'] = json_decode($row['translations'], true) ?: [];
        }

        return $rows;
    }

    public function count(string $query = ''): int
    {
        $where = $query !== '' ? " WHERE term LIKE '%" . pSQL(addcslashes($query, '%_')) . "%' OR translations LIKE '%" . pSQL(addcslashes($query, '%_')) . "%'" : '';

        return (int) $this->db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'linguaflowai_glossary`' . $where);
    }

    /**
     * Creates or updates a term. Empty translations are dropped.
     *
     * @param array<string, string> $translations
     */
    public function save(string $term, array $translations, int $idTerm = 0): void
    {
        $term = trim(preg_replace('/\s+/u', ' ', $term));
        if ($term === '') {
            return;
        }
        $clean = [];
        foreach ($translations as $code => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $clean[strtoupper((string) $code)] = $value;
            }
        }
        $data = [
            'term' => pSQL(mb_substr($term, 0, 191)),
            'translations' => pSQL(json_encode($clean, JSON_UNESCAPED_UNICODE)),
            'date_upd' => date('Y-m-d H:i:s'),
        ];

        if ($idTerm > 0) {
            $this->db->update('linguaflowai_glossary', $data, 'id_term = ' . $idTerm);

            return;
        }
        $existing = (int) $this->db->getValue('SELECT id_term FROM `' . _DB_PREFIX_ . "linguaflowai_glossary` WHERE term = '" . pSQL($term) . "'");
        if ($existing) {
            $this->db->update('linguaflowai_glossary', $data, 'id_term = ' . $existing);
        } else {
            $this->db->insert('linguaflowai_glossary', $data);
        }
    }

    public function delete(int $idTerm): void
    {
        $this->db->delete('linguaflowai_glossary', 'id_term = ' . $idTerm);
    }

    /**
     * Imports a glossary.json structure. "merge" keeps the existing terms and overwrites the languages present
     * in the file; "replace" empties the glossary first.
     *
     * @return int terms imported
     */
    public function import(array $data, bool $replace): int
    {
        if ($replace) {
            $this->db->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . 'linguaflowai_glossary`');
        }
        $current = $replace ? [] : $this->all();
        $count = 0;
        foreach ($data as $term => $translations) {
            if (!is_string($term) || !is_array($translations)) {
                continue;
            }
            $existingKey = null;
            foreach (array_keys($current) as $key) {
                if (mb_strtolower($key) === mb_strtolower(trim($term))) {
                    $existingKey = $key;
                    break;
                }
            }
            $merged = array_merge($existingKey !== null ? $current[$existingKey] : [], array_change_key_case($translations, CASE_UPPER));
            $this->save($existingKey !== null ? $existingKey : $term, $merged);
            ++$count;
        }

        return $count;
    }
}
