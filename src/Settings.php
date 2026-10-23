<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI;

use LinguaFlowAI\Provider\ProviderConfig;

/**
 * Typed access to the module configuration.
 */
final class Settings
{
    public const PROVIDER = 'LFAI_PROVIDER';
    public const API_KEY = 'LFAI_API_KEY';
    public const BASE_URL = 'LFAI_BASE_URL';
    public const MODEL = 'LFAI_MODEL';
    public const EFFORT = 'LFAI_EFFORT';
    public const TIMEOUT = 'LFAI_TIMEOUT';
    public const SOURCE_LANG = 'LFAI_SOURCE_LANG';
    public const TARGET_LANGS = 'LFAI_TARGET_LANGS';
    public const FIELDS = 'LFAI_FIELDS';
    public const OVERWRITE = 'LFAI_OVERWRITE';
    public const SLUG = 'LFAI_SLUG';
    public const AUTO_NEW = 'LFAI_AUTO_NEW';
    public const AUTO_DELAY = 'LFAI_AUTO_DELAY';
    public const INSTRUCTIONS = 'LFAI_INSTRUCTIONS';
    public const DO_NOT_TRANSLATE = 'LFAI_DO_NOT_TRANSLATE';
    public const BO_WORKER = 'LFAI_BO_WORKER';
    public const BATCH_SIZE = 'LFAI_BATCH_SIZE';
    public const CRON_TOKEN = 'LFAI_CRON_TOKEN';

    /** Translatable product fields => max length (0 = unlimited). */
    public const PRODUCT_FIELDS = [
        'name' => 128,
        'description_short' => 0,
        'description' => 0,
        'meta_title' => 255,
        'meta_description' => 512,
        'available_now' => 255,
        'available_later' => 255,
        'delivery_in_stock' => 255,
        'delivery_out_stock' => 255,
    ];

    public const DEFAULT_FIELDS = ['name', 'description_short', 'description', 'meta_title', 'meta_description'];

    public static function defaults(): array
    {
        return [
            self::PROVIDER => ProviderConfig::ANTHROPIC,
            self::API_KEY => '',
            self::BASE_URL => '',
            self::MODEL => ProviderConfig::DEFAULTS[ProviderConfig::ANTHROPIC]['model'],
            self::EFFORT => 'medium',
            self::TIMEOUT => 120,
            self::SOURCE_LANG => (int) \Configuration::get('PS_LANG_DEFAULT'),
            self::TARGET_LANGS => '[]',
            self::FIELDS => json_encode(self::DEFAULT_FIELDS),
            self::OVERWRITE => 0,
            self::SLUG => 1,
            self::AUTO_NEW => 1,
            self::AUTO_DELAY => 5,
            self::INSTRUCTIONS => '',
            self::DO_NOT_TRANSLATE => '',
            self::BO_WORKER => 1,
            self::BATCH_SIZE => 5,
            self::CRON_TOKEN => bin2hex(random_bytes(16)),
        ];
    }

    public static function get(string $key)
    {
        $value = \Configuration::getGlobalValue($key);

        return $value === false ? (self::defaults()[$key] ?? null) : $value;
    }

    public static function set(string $key, $value): void
    {
        \Configuration::updateGlobalValue($key, $value, $key === self::INSTRUCTIONS);
    }

    public static function providerConfig(): ProviderConfig
    {
        return new ProviderConfig(
            (string) self::get(self::PROVIDER),
            (string) self::get(self::API_KEY),
            (string) self::get(self::BASE_URL),
            (string) self::get(self::MODEL),
            (string) self::get(self::EFFORT),
            (int) self::get(self::TIMEOUT)
        );
    }

    /** @return int[] */
    public static function targetLanguageIds(): array
    {
        $ids = json_decode((string) self::get(self::TARGET_LANGS), true);
        $source = self::sourceLanguageId();

        return array_values(array_filter(array_map('intval', is_array($ids) ? $ids : []), function ($id) use ($source) {
            return $id !== $source && \Language::getLanguage($id);
        }));
    }

    public static function sourceLanguageId(): int
    {
        return (int) self::get(self::SOURCE_LANG) ?: (int) \Configuration::get('PS_LANG_DEFAULT');
    }

    /** @return string[] */
    public static function fields(): array
    {
        $fields = json_decode((string) self::get(self::FIELDS), true);
        $fields = array_values(array_intersect(is_array($fields) ? $fields : [], array_keys(self::PRODUCT_FIELDS)));

        return $fields ?: self::DEFAULT_FIELDS;
    }

    /** @return string[] */
    public static function doNotTranslate(): array
    {
        return preg_split('/[\r\n,]+/', (string) self::get(self::DO_NOT_TRANSLATE)) ?: [];
    }
}
