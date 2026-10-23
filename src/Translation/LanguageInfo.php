<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Translation;

/**
 * Two letter language codes (as used by the glossary: EN, FR, IT, PT, DE...), English names
 * for the prompt and the default register/variant instructions per language.
 */
final class LanguageInfo
{
    private const NAMES = [
        'ES' => 'Spanish', 'EN' => 'English', 'FR' => 'French', 'IT' => 'Italian', 'PT' => 'Portuguese',
        'DE' => 'German', 'NL' => 'Dutch', 'CA' => 'Catalan', 'GL' => 'Galician', 'EU' => 'Basque',
        'PL' => 'Polish', 'SV' => 'Swedish', 'DA' => 'Danish', 'NO' => 'Norwegian', 'FI' => 'Finnish',
        'CS' => 'Czech', 'SK' => 'Slovak', 'HU' => 'Hungarian', 'RO' => 'Romanian', 'EL' => 'Greek',
        'BG' => 'Bulgarian', 'HR' => 'Croatian', 'SL' => 'Slovenian', 'LT' => 'Lithuanian',
        'LV' => 'Latvian', 'ET' => 'Estonian', 'RU' => 'Russian', 'UK' => 'Ukrainian', 'TR' => 'Turkish',
    ];

    /** Defaults for the per language style setting. */
    public const DEFAULT_STYLES = [
        'EN' => 'Write in British English (colour, aluminium, earth). Neutral, professional tone.',
        'FR' => 'Write in French from France. Address the customer formally with "vous".',
        'IT' => 'Write in Italian from Italy. Neutral, formal tone; do not address the customer with "tu".',
        'PT' => 'Write in European Portuguese from Portugal, never Brazilian Portuguese: use "o seu / a sua", "detetor", "candeeiro", "utilizar", "ecrã". Formal tone.',
        'DE' => 'Write in German from Germany. Address the customer formally with "Sie", never "du" or "Dein". Join compound nouns with hyphens as German requires (Solar-LED-Strahler, SMD-LED, IP65-Schutz), never with spaces.',
    ];

    /**
     * @param string $isoCode      PrestaShop iso_code (en, gb, fr...)
     * @param string $languageCode PrestaShop language_code (en-us, pt-pt...)
     */
    public static function code(string $isoCode, string $languageCode = ''): string
    {
        $code = strtoupper(substr($languageCode !== '' ? $languageCode : $isoCode, 0, 2));

        return $code === 'GB' ? 'EN' : $code;
    }

    public static function name(string $code, string $fallback = ''): string
    {
        return self::NAMES[$code] ?? ($fallback !== '' ? $fallback : $code);
    }

    /** Reverse lookup used when importing a translation memory keyed by English language names. */
    public static function codeFromName(string $name): string
    {
        $code = array_search(ucfirst(strtolower(trim($name))), self::NAMES, true);

        return $code === false ? '' : $code;
    }
}
