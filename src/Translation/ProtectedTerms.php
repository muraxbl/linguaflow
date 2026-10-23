<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Translation;

/**
 * Brands and codes that are never translated. They travel as plain text (with markers the model does not
 * know what they stand for and misplaces them), but the output must contain them intact. Figures with
 * units (6W, 3.7V) are NOT protected: in FR/DE/IT/PT the decimal separator is a comma.
 */
class ProtectedTerms
{
    /** @var string|null */
    private $regex;

    /**
     * @param string[] $brands   literal names (spaces match any whitespace)
     * @param string[] $patterns regular expression fragments (no delimiters)
     */
    public function __construct(array $brands, array $patterns)
    {
        $alternatives = [];
        $brands = array_values(array_filter(array_map('trim', $brands), 'strlen'));
        if ($brands) {
            usort($brands, function ($a, $b) {
                return mb_strlen($b) - mb_strlen($a);
            });
            $alternatives[] = '(?:' . implode('|', array_map(function ($b) {
                return preg_replace('/\\\\?\s+/u', '\\s+', preg_quote($b, '/'));
            }, $brands)) . ')';
        }
        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);
            if ($pattern !== '' && self::isValidPattern($pattern)) {
                $alternatives[] = '(?:' . $pattern . ')';
            }
        }
        $this->regex = $alternatives
            ? '/(?<![\p{L}\p{N}])(?:' . implode('|', $alternatives) . ')(?![\p{L}\p{N}])/iu'
            : null;
    }

    public static function isValidPattern(string $pattern): bool
    {
        return @preg_match('/' . str_replace('/', '\/', $pattern) . '/u', '') !== false;
    }

    /** @return string[] */
    public function find(string $text): array
    {
        if ($this->regex === null || !preg_match_all($this->regex, preg_replace(Html::ANY_TAG_RE, ' ', $text), $m)) {
            return [];
        }

        return array_values(array_unique($m[0]));
    }
}
