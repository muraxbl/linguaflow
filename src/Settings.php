<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI;

use LinguaFlowAI\Provider\ProviderConfig;
use LinguaFlowAI\Translation\LanguageInfo;

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
    public const SHOP_SCOPE = 'LFAI_SHOP_SCOPE';
    public const LANG_STYLES = 'LFAI_LANG_STYLES';
    public const CATEGORY_HINTS = 'LFAI_CATEGORY_HINTS';
    public const PROTECTED_PATTERNS = 'LFAI_PROTECTED_PATTERNS';
    public const CONTEXT_SIZE = 'LFAI_CONTEXT_SIZE';
    public const DISABLE_THINKING = 'LFAI_DISABLE_THINKING';
    public const PROTECT_MANUFACTURERS = 'LFAI_PROTECT_MANUFACTURERS';

    /** Codes that are never translated (regular expressions, one per line). */
    public const DEFAULT_PROTECTED_PATTERNS = "I[PK]\\s?\\d{2}\nSMD\\s?\\d{4}\n(?:E27|E14|E40|GU10|GU5[.,]3|MR16|MR11|G9|G4|GX53|GX5[.,]3|G13|G5|2G11|B22|R7s|AR111|PAR(?:16|20|30|38)|T5|T8)";
    public const DEFAULT_BRANDS = "Masterled\nMean Well\nPhilips\nOsram\nLedvance\nSamsung\nBridgelux\nEpistar\nCree\nNichia\nLumileds\nTridonic\nLifud\nSosen\nMoso\nInventronics\nSchneider Electric\nLegrand\nNiessen\nHager";

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
            self::DO_NOT_TRANSLATE => self::DEFAULT_BRANDS,
            self::BO_WORKER => 1,
            self::BATCH_SIZE => 5,
            self::CRON_TOKEN => bin2hex(random_bytes(16)),
            self::SHOP_SCOPE => 'context',
            self::LANG_STYLES => json_encode(LanguageInfo::DEFAULT_STYLES),
            self::CATEGORY_HINTS => '{}',
            self::PROTECTED_PATTERNS => self::DEFAULT_PROTECTED_PATTERNS,
            self::CONTEXT_SIZE => 0,
            self::DISABLE_THINKING => 1,
            self::PROTECT_MANUFACTURERS => 1,
        ];
    }

    public static function get(string $key)
    {
        $value = \Configuration::getGlobalValue($key);

        return $value === false ? (self::defaults()[$key] ?? null) : $value;
    }

    public static function set(string $key, $value): void
    {
        // html=true keeps < > in free text settings (regular expressions, instructions) instead of stripping them.
        \Configuration::updateGlobalValue($key, $value, in_array($key, [self::INSTRUCTIONS, self::PROTECTED_PATTERNS, self::LANG_STYLES, self::CATEGORY_HINTS], true));
    }

    public static function providerConfig(): ProviderConfig
    {
        return new ProviderConfig(
            (string) self::get(self::PROVIDER),
            (string) self::get(self::API_KEY),
            (string) self::get(self::BASE_URL),
            (string) self::get(self::MODEL),
            (string) self::get(self::EFFORT),
            (int) self::get(self::TIMEOUT),
            (int) self::get(self::CONTEXT_SIZE),
            (bool) self::get(self::DISABLE_THINKING)
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
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) self::get(self::DO_NOT_TRANSLATE)) ?: []), 'strlen'));
    }

    /** @return string[] */
    public static function protectedPatterns(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', (string) self::get(self::PROTECTED_PATTERNS)) ?: []), 'strlen'));
    }

    /** @return array<string, string> language code => style instructions */
    public static function languageStyles(): array
    {
        $styles = json_decode((string) self::get(self::LANG_STYLES), true);

        return is_array($styles) ? $styles : [];
    }

    /** @return array<int, string> id_category => hint */
    public static function categoryHints(): array
    {
        $hints = json_decode((string) self::get(self::CATEGORY_HINTS), true);

        return is_array($hints) ? $hints : [];
    }
}
