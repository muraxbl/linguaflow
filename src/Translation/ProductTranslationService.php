<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Translation;

use LinguaFlowAI\Settings;

/**
 * Translates one product into one language and writes the result to product_lang.
 */
class ProductTranslationService
{
    public const RESULT_TRANSLATED = 'translated';
    public const RESULT_NOTHING_TO_DO = 'nothing';
    public const RESULT_NO_SOURCE = 'no_source';

    /** @var Translator */
    private $translator;

    public function __construct(Translator $translator)
    {
        $this->translator = $translator;
    }

    /**
     * @param array $options job options: fields (string[]), overwrite (bool), slug (bool)
     *
     * @return string one of the RESULT_* constants
     */
    public function translate(int $idProduct, int $idSourceLang, int $idTargetLang, int $idShop, array $options): string
    {
        $fields = array_values(array_intersect($options['fields'] ?? Settings::fields(), array_keys(Settings::PRODUCT_FIELDS)));
        $overwrite = !empty($options['overwrite']);
        $regenerateSlug = !isset($options['slug']) || !empty($options['slug']);

        $source = $this->loadLangRow($idProduct, $idSourceLang, $idShop);
        if (!$source || trim((string) $source['name']) === '') {
            return self::RESULT_NO_SOURCE;
        }
        $target = $this->loadLangRow($idProduct, $idTargetLang, $idShop) ?: [];

        $toTranslate = [];
        $maxLength = [];
        foreach ($fields as $field) {
            $sourceText = trim((string) ($source[$field] ?? ''));
            if ($sourceText === '' || trim(strip_tags($sourceText)) === '') {
                continue;
            }
            $targetText = trim((string) ($target[$field] ?? ''));
            // PrestaShop copies the source text into every language on creation: treat that as untranslated.
            if ($overwrite || $targetText === '' || $targetText === $sourceText) {
                $toTranslate[$field] = $sourceText;
                if (Settings::PRODUCT_FIELDS[$field] > 0) {
                    $maxLength[$field] = Settings::PRODUCT_FIELDS[$field];
                }
            }
        }
        if ($toTranslate === []) {
            return self::RESULT_NOTHING_TO_DO;
        }

        $sourceLang = new \Language($idSourceLang);
        $targetLang = new \Language($idTargetLang);
        $translated = $this->translator->translate(
            $toTranslate,
            $sourceLang->name,
            $targetLang->name,
            $targetLang->locale ?: $targetLang->iso_code,
            $maxLength
        );

        $update = [];
        foreach ($translated as $field => $value) {
            $update[$field] = pSQL($value, true);
        }
        if ($regenerateSlug && isset($translated['name'])) {
            $currentSlug = (string) ($target['link_rewrite'] ?? '');
            if ($overwrite || $currentSlug === '' || $currentSlug === (string) $source['link_rewrite']) {
                $slug = \Tools::str2url($translated['name']);
                if ($slug !== '') {
                    $update['link_rewrite'] = pSQL($slug);
                }
            }
        }

        $this->write($idProduct, $idTargetLang, $update);

        return self::RESULT_TRANSLATED;
    }

    private function loadLangRow(int $idProduct, int $idLang, int $idShop): ?array
    {
        $row = \Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'product_lang`
            WHERE id_product = ' . $idProduct . ' AND id_lang = ' . $idLang . ' AND id_shop = ' . $idShop, false);
        if (!$row && $idShop !== (int) \Configuration::get('PS_SHOP_DEFAULT')) {
            return $this->loadLangRow($idProduct, $idLang, (int) \Configuration::get('PS_SHOP_DEFAULT'));
        }

        return $row ?: null;
    }

    /**
     * Writes the translation to every shop the product belongs to, then lets PrestaShop
     * and other modules refresh caches and the search index.
     */
    private function write(int $idProduct, int $idLang, array $update): void
    {
        $db = \Db::getInstance();
        $shopIds = array_map('intval', array_column($db->executeS('SELECT id_shop FROM `' . _DB_PREFIX_ . 'product_shop` WHERE id_product = ' . $idProduct) ?: [], 'id_shop'));

        foreach ($shopIds as $idShop) {
            $exists = (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_lang` WHERE id_product = ' . $idProduct . ' AND id_lang = ' . $idLang . ' AND id_shop = ' . $idShop);
            if ($exists) {
                $db->update('product_lang', $update, 'id_product = ' . $idProduct . ' AND id_lang = ' . $idLang . ' AND id_shop = ' . $idShop);
            } else {
                $db->insert('product_lang', array_merge($update, ['id_product' => $idProduct, 'id_lang' => $idLang, 'id_shop' => $idShop]));
            }
        }

        $db->update('product', ['date_upd' => date('Y-m-d H:i:s')], 'id_product = ' . $idProduct);
        \Cache::clean('Product::*');
        if (method_exists('Product', 'resetStaticCache')) {
            \Product::resetStaticCache();
        }
        \Search::indexation(false, $idProduct);

        $product = new \Product($idProduct);
        \Hook::exec('actionLinguaFlowAIProductTranslated', ['id_product' => $idProduct, 'id_lang' => $idLang, 'product' => $product]);
        \Hook::exec('actionProductUpdate', ['id_product' => $idProduct, 'product' => $product]);
    }
}
