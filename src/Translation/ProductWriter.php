<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Translation;

use LinguaFlowAI\Settings;

/**
 * Writes translated fields to product_lang, in every shop the product belongs to.
 */
class ProductWriter
{
    public static function loadLangRow(int $idProduct, int $idLang, int $idShop): ?array
    {
        $row = \Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'product_lang`
            WHERE id_product = ' . $idProduct . ' AND id_lang = ' . $idLang . ' AND id_shop = ' . $idShop, false);
        if (!$row && $idShop !== (int) \Configuration::get('PS_SHOP_DEFAULT')) {
            return self::loadLangRow($idProduct, $idLang, (int) \Configuration::get('PS_SHOP_DEFAULT'));
        }

        return $row ?: null;
    }

    /**
     * @param array<string, string> $outputs field => translated text
     * @param array                 $options job options: overwrite, slug
     */
    public function write(int $idProduct, int $idSourceLang, int $idTargetLang, int $idShop, array $outputs, array $options): void
    {
        $update = [];
        foreach ($outputs as $field => $value) {
            if (isset(Settings::PRODUCT_FIELDS[$field])) {
                $max = Settings::PRODUCT_FIELDS[$field];
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

        $db = \Db::getInstance();
        $shopIds = array_map('intval', array_column($db->executeS('SELECT id_shop FROM `' . _DB_PREFIX_ . 'product_shop` WHERE id_product = ' . $idProduct) ?: [], 'id_shop'));
        foreach ($shopIds as $shop) {
            $where = 'id_product = ' . $idProduct . ' AND id_lang = ' . $idTargetLang . ' AND id_shop = ' . $shop;
            if ((int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_lang` WHERE ' . $where)) {
                $db->update('product_lang', $update, $where);
            } else {
                $db->insert('product_lang', array_merge($update, ['id_product' => $idProduct, 'id_lang' => $idTargetLang, 'id_shop' => $shop]));
            }
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
