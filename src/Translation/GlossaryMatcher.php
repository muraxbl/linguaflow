<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Translation;

/**
 * Finds glossary terms in a source text and turns them into the "mandatory terminology" block of the prompt.
 * Only the terms present in the text are sent: the whole glossary makes the prompt longer and confuses the
 * model with terms that do not apply.
 */
class GlossaryMatcher
{
    /** @var array<string, array<string, string>> source term => [LANG CODE => translation] */
    private $terms;
    /** @var array<string, string> */
    private $regexCache = [];

    public function __construct(array $terms)
    {
        $this->terms = $terms;
    }

    /**
     * Case insensitive, whole word, accepting a plural on every word ("tira LED" matches "Tiras LED").
     */
    public function termRegex(string $term): string
    {
        if (!isset($this->regexCache[$term])) {
            $words = array_map(function ($w) {
                $quoted = preg_quote($w, '/');
                // Spanish plurals drop the accent of a stressed last syllable: plafón → plafones.
                if (preg_match('/^(.*)([áéíóú])([ns])$/iu', $w, $m)) {
                    $plain = strtr($m[2], ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']);
                    $quoted = '(?:' . $quoted . '|' . preg_quote($m[1] . $plain . $m[3], '/') . '(?=es))';
                }

                return $quoted;
            }, preg_split('/\s+/u', trim($term)));
            $this->regexCache[$term] = '/(?<![\p{L}\p{N}])' . implode('(?:s|es)?\s+', $words) . '(?:s|es)?(?![\p{L}\p{N}])/iu';
        }

        return $this->regexCache[$term];
    }

    /**
     * @return array<string, string> term => translation, longest terms first
     */
    public function matches(string $text, string $langCode): array
    {
        $plain = preg_replace(Html::ANY_TAG_RE, ' ', $text);
        $found = [];
        foreach ($this->terms as $term => $translations) {
            $translation = trim((string) ($translations[$langCode] ?? ''));
            if ($translation !== '' && preg_match($this->termRegex((string) $term), $plain)) {
                $found[(string) $term] = $translation;
            }
        }
        uksort($found, function ($a, $b) {
            return mb_strlen($b) - mb_strlen($a);
        });

        return $found;
    }

    public function prompt(string $text, string $langCode, string $sourceName, string $targetName): string
    {
        $matches = $this->matches($text, $langCode);
        if (!$matches) {
            return '';
        }
        $lines = [];
        foreach ($matches as $term => $translation) {
            $lines[] = sprintf('- "%s" → "%s"', $term, $translation);
        }

        return sprintf("Mandatory terminology (%s → %s). Use exactly these translations, adapting only plural, grammatical case and capitalisation:\n", $sourceName, $targetName)
            . implode("\n", $lines);
    }

    /**
     * Glossary terms present in the source whose translation is missing from the output.
     *
     * @return string[] "term → translation"
     */
    public function violations(string $source, string $output, string $langCode): array
    {
        $outLower = mb_strtolower(Html::plainText($output));
        $violations = [];
        foreach ($this->matches($source, $langCode) as $term => $translation) {
            $parts = array_filter(preg_split('/[\s-]+/u', $translation), function ($p) {
                return mb_strlen($p) > 2;
            });
            foreach ($parts as $part) {
                if (mb_strpos($outLower, self::looseStem($part)) === false) {
                    $violations[] = sprintf('"%s" → "%s"', $term, $translation);
                    break;
                }
            }
        }

        return $violations;
    }

    /** Approximate stem so plurals and declensions do not fail the check ("Erdspieß" → "erdspie"). */
    private static function looseStem(string $word): string
    {
        $w = mb_strtolower($word);

        return mb_strlen($w) > 5 ? mb_substr($w, 0, mb_strlen($w) - 2) : $w;
    }
}
