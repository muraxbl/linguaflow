<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Translation;

use LinguaFlowAI\Settings;
use LinguaFlowAI\Security\ProductContent;
use LinguaFlowAI\Security\ShopContext;

/**
 * Writes translated fields only to the job's shop.
 */
class ProductWriter
{
    public static function loadLangRow(int $idProduct, int $idLang, int $idShop): ?array
    {
        $row = \Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'product_lang`
            WHERE id_product = ' . $idProduct . ' AND id_lang = ' . $idLang . ' AND id_shop = ' . $idShop, false);
        return $row ?: null;
    }

    /**
     * @param array<string, string> $outputs field => translated text
     * @param array                 $options job options: overwrite, slug
     */
    public function write(int $idProduct, int $idSourceLang, int $idTargetLang, int $idShop, array $outputs, array $options): void
    {
        ShopContext::run($idShop, function () use ($idProduct, $idSourceLang, $idTargetLang, $idShop, $outputs, $options) {
            $this->writeInShop($idProduct, $idSourceLang, $idTargetLang, $idShop, $outputs, $options);
        });
    }

    private function writeInShop(int $idProduct, int $idSourceLang, int $idTargetLang, int $idShop, array $outputs, array $options): void
    {
        $db = \Db::getInstance();
        if ($idShop < 1 || $idProduct < 1 || $idTargetLang < 1 || $idSourceLang === $idTargetLang
            || !$db->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'product_shop` WHERE id_product = ' . $idProduct . ' AND id_shop = ' . $idShop)
            || !$db->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'lang_shop` WHERE id_lang = ' . $idTargetLang . ' AND id_shop = ' . $idShop)) {
            throw new \DomainException('The product and target language must belong to the job shop, and the source language cannot be overwritten.');
        }
        $update = [];
        foreach ($outputs as $field => $value) {
            if (isset(Settings::PRODUCT_FIELDS[$field])) {
                $max = Settings::PRODUCT_FIELDS[$field];
                $value = ProductContent::sanitize($field, (string) $value);
                $update[$field] = pSQL($max > 0 ? mb_substr($value, 0, $max) : $value, true);
            }
        }
        if (!$update) {
            return;
        }

        $regenerateSlug = !isset($options['slug']) || !empty($options['slug']);
        if ($regenerateSlug && isset($outputs['name'])) {
            $source = self::loadLangRow($idProduct, $idSourceLang, $idShop) ?: [];
            $target = self::loadLangRow($idProduct, $idTargetLang, $idShop) ?: [];
            $currentSlug = (string) ($target['link_rewrite'] ?? '');
            if (!empty($options['overwrite']) || $currentSlug === '' || $currentSlug === (string) ($source['link_rewrite'] ?? '')) {
                $slug = \Tools::str2url($outputs['name']);
                if ($slug !== '') {
                    $update['link_rewrite'] = pSQL($slug);
                }
            }
        }

        $where = 'id_product = ' . $idProduct . ' AND id_lang = ' . $idTargetLang . ' AND id_shop = ' . $idShop;
        if ((int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_lang` WHERE ' . $where)) {
            $db->update('product_lang', $update, $where);
        } else {
            $db->insert('product_lang', array_merge($update, ['id_product' => $idProduct, 'id_lang' => $idTargetLang, 'id_shop' => $idShop]));
        }

        $db->update('product', ['date_upd' => date('Y-m-d H:i:s')], 'id_product = ' . $idProduct);
        \Cache::clean('Product::*');
        if (method_exists('Product', 'resetStaticCache')) {
            \Product::resetStaticCache();
        }
        \Search::indexation(false, $idProduct);

        $product = new \Product($idProduct);
        \Hook::exec('actionLinguaFlowAIProductTranslated', ['id_product' => $idProduct, 'id_lang' => $idTargetLang, 'product' => $product]);
        \Hook::exec('actionProductUpdate', ['id_product' => $idProduct, 'product' => $product]);
    }
}
