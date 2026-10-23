<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Translation;

use LinguaFlowAI\Settings;

/**
 * Translates the fields of one product into one language. Fields go in order: the name first, which is then
 * given as context to the other fields and reused for "Name | Brand" meta titles.
 */
class ProductTranslationService
{
    public const RESULT_TRANSLATED = 'translated';
    public const RESULT_NOTHING_TO_DO = 'nothing';
    public const RESULT_NO_SOURCE = 'no_source';

    /** PrestaShop field => field type used by the prompt, the checks and the memory. */
    public const FIELD_TYPES = [
        'name' => 'Name',
        'description_short' => 'Description',
        'description' => 'Description',
        'meta_title' => 'Meta title',
        'meta_description' => 'Meta description',
        'available_now' => 'Label',
        'available_later' => 'Label',
        'delivery_in_stock' => 'Label',
        'delivery_out_stock' => 'Label',
    ];

    /** @var SegmentTranslator */
    private $translator;
    /** @var MemoryInterface */
    private $memory;

    public function __construct(SegmentTranslator $translator, MemoryInterface $memory)
    {
        $this->translator = $translator;
        $this->memory = $memory;
    }

    /**
     * @param array $options job options: fields (string[]), overwrite (bool)
     *
     * @return array{result: string, fields: array<string, array{source: string, output: string, issues: string[], from: string}>, has_issues: bool}
     */
    public function translate(int $idProduct, int $idSourceLang, int $idTargetLang, int $idShop, array $options, bool $noCache = false): array
    {
        $empty = ['fields' => [], 'has_issues' => false];
        $fields = array_values(array_intersect(array_keys(Settings::PRODUCT_FIELDS), $options['fields'] ?? Settings::fields()));
        $overwrite = !empty($options['overwrite']);

        $source = ProductWriter::loadLangRow($idProduct, $idSourceLang, $idShop);
        if (!$source || trim((string) $source['name']) === '') {
            return ['result' => self::RESULT_NO_SOURCE] + $empty;
        }
        $target = ProductWriter::loadLangRow($idProduct, $idTargetLang, $idShop) ?: [];

        $toTranslate = [];
        foreach ($fields as $field) {
            $sourceText = trim((string) ($source[$field] ?? ''));
            if ($sourceText === '' || !Html::hasLetters($sourceText)) {
                continue;
            }
            $targetText = trim((string) ($target[$field] ?? ''));
            // PrestaShop copies the source text into every language on creation: treat that as untranslated.
            if ($overwrite || $targetText === '' || $targetText === $sourceText) {
                $toTranslate[$field] = $sourceText;
            }
        }
        if (!$toTranslate) {
            return ['result' => self::RESULT_NOTHING_TO_DO] + $empty;
        }

        $sourceLang = new \Language($idSourceLang);
        $targetLang = new \Language($idTargetLang);
        $sourceCode = LanguageInfo::code((string) $sourceLang->iso_code, (string) $sourceLang->language_code);
        $targetCode = LanguageInfo::code((string) $targetLang->iso_code, (string) $targetLang->language_code);
        $product = new \Product($idProduct, false, null, $idShop);
        $this->translator->setProtectedTerms($this->protectedTermsFor($product));

        $sourceName = trim((string) $source['name']);
        $translatedName = trim((string) ($target['name'] ?? ''));
        $nameContext = $translatedName !== '' && $translatedName !== $sourceName ? ['source' => $sourceName, 'translated' => $translatedName] : null;

        $job = [
            'sourceName' => LanguageInfo::name($sourceCode, (string) $sourceLang->name),
            'sourceCode' => $sourceCode,
            'langName' => LanguageInfo::name($targetCode, (string) $targetLang->name),
            'langCode' => $targetCode,
            'categoryHint' => $this->categoryHint((int) $product->id_category_default),
            'noCache' => $noCache,
        ];

        $results = [];
        $hasIssues = false;
        foreach ($toTranslate as $field => $text) {
            $type = self::FIELD_TYPES[$field];
            $fieldJob = $job + ['field' => $type, 'nameContext' => $field === 'name' ? null : $nameContext];
            $notes = [];
            $from = 'model';
            $approved = $noCache ? null : $this->memory->get(SegmentTranslator::approvedKey($targetCode, $type, Html::tidyInline(Html::decodeEntities($text))));
            $brandSplit = $type === 'Meta title' ? self::splitBrandSuffix($text) : null;

            if ($approved !== null) {
                // Approved in an earlier review: it has priority and needs no checks.
                $output = $approved;
                $from = 'memory';
            } elseif ($brandSplit && $nameContext && self::normalize($brandSplit[0]) === self::normalize($sourceName)) {
                // "Name | Brand" with the same name: reuse the translated name, no model call.
                $output = $nameContext['translated'] . ' | ' . $brandSplit[1];
                $from = 'name';
            } elseif ($brandSplit) {
                $output = $this->translator->translateText($brandSplit[0], $fieldJob, $notes) . ' | ' . $brandSplit[1];
            } else {
                $output = $this->translator->translateText($text, $fieldJob, $notes);
            }

            $issues = $from === 'memory' ? [] : array_merge(
                $notes,
                QualityCheck::issues($text, $output, $type, $sourceCode, $targetCode, $this->translator->glossary())
            );
            $max = Settings::PRODUCT_FIELDS[$field];
            if ($max > 0 && mb_strlen($output) > $max) {
                $issues[] = sprintf('longer than PrestaShop allows (%d / %d characters)', mb_strlen($output), $max);
            }

            $results[$field] = ['source' => $text, 'output' => $output, 'issues' => $issues, 'from' => $from];
            $hasIssues = $hasIssues || $issues !== [];
            if ($field === 'name' && $output !== '') {
                $nameContext = ['source' => $sourceName, 'translated' => $output];
            }
        }

        return ['result' => self::RESULT_TRANSLATED, 'fields' => $results, 'has_issues' => $hasIssues];
    }

    /** Configured brands and patterns, plus the product's own manufacturer. */
    private function protectedTermsFor(\Product $product): ProtectedTerms
    {
        $brands = Settings::doNotTranslate();
        if (Settings::get(Settings::PROTECT_MANUFACTURERS) && $product->id_manufacturer) {
            $brands[] = (string) \Manufacturer::getNameById((int) $product->id_manufacturer);
        }

        return new ProtectedTerms($brands, Settings::protectedPatterns());
    }

    /** Hint of the default category, or of its closest parent that has one. */
    private function categoryHint(int $idCategory): string
    {
        $hints = Settings::categoryHints();
        for ($depth = 0; $idCategory > 0 && $depth < 15; ++$depth) {
            if (!empty($hints[$idCategory])) {
                return (string) $hints[$idCategory];
            }
            $idCategory = (int) \Db::getInstance()->getValue('SELECT id_parent FROM `' . _DB_PREFIX_ . 'category` WHERE id_category = ' . $idCategory);
        }

        return '';
    }

    /** Splits "Product name | Brand" into name and brand. */
    private static function splitBrandSuffix(string $text): ?array
    {
        return preg_match('/^(.*\S)\s*\|\s*([^|]{1,40})$/u', $text, $m) ? [$m[1], trim($m[2])] : null;
    }

    private static function normalize(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text)));
    }
}
