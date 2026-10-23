<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Translation;

/**
 * Two levels of checks:
 *  - check(): hard failures that make the segment be asked again (empty, all caps, missing protected term...).
 *  - issues(): warnings on a finished field. A field with warnings is held for human review.
 */
final class QualityCheck
{
    /** Words that only exist in Spanish; the ones valid in a target language are excluded for it. */
    private const SPANISH_ONLY_WORDS = [
        'y', 'del', 'los', 'las', 'el', 'con', 'para', 'sin', 'una', 'muy', 'también',
        'más', 'está', 'puede', 'mediante', 'permite', 'además', 'según', 'cuenta', 'luz',
    ];
    private const SPANISH_VALID_IN = [
        'IT' => ['con', 'una', 'del'],
        'PT' => ['para', 'luz', 'permite', 'mediante', 'está'],
        'FR' => ['y'],
    ];

    /**
     * @param string[] $protectedTerms terms found in the source that must appear unchanged
     *
     * @throws RetryException
     */
    public static function check(string $source, string $output, string $field, array $protectedTerms): void
    {
        if (trim($output) === '') {
            throw new RetryException('empty answer');
        }

        $squash = function ($s) {
            return mb_strtolower(preg_replace('/\s+/u', '', $s));
        };
        $missing = array_filter($protectedTerms, function ($t) use ($output, $squash) {
            return mb_strpos($output, $t) === false && mb_strpos($squash($output), $squash($t)) === false;
        });
        if ($missing) {
            throw new RetryException('missing ' . implode(', ', $missing));
        }

        if (mb_strlen(self::letters($output)) > 12 && self::upperRatio($output) > 0.8 && self::upperRatio($source) < 0.5) {
            throw new RetryException('answer in capital letters');
        }

        // Margin over 160: the review warns anyway; retrying for a few characters only wastes time.
        if ($field === 'Meta description' && mb_strlen($output) > 175 && mb_strlen($source) <= 165) {
            throw new RetryException(sprintf('meta description of %d characters, maximum 160', mb_strlen($output)));
        }
    }

    /**
     * @return string[] warnings, empty when the field looks fine
     */
    public static function issues(string $source, string $output, string $field, string $sourceCode, string $targetCode, GlossaryMatcher $glossary): array
    {
        $issues = [];
        if (trim($output) === '') {
            return ['empty'];
        }

        $srcPlain = Html::plainText(Html::decodeEntities($source));
        $outPlain = Html::plainText($output);

        if (count(explode(' ', $srcPlain)) >= 3 && mb_strtolower($srcPlain) === mb_strtolower($outPlain)) {
            $issues[] = 'not translated';
        }

        $outNumbers = self::numbers($output);
        $missing = [];
        foreach (self::numbers($source) as $n) {
            $i = array_search($n, $outNumbers, true);
            if ($i === false) {
                $missing[] = $n;
            } else {
                unset($outNumbers[$i]);
            }
        }
        if ($missing) {
            $issues[] = 'missing figures: ' . implode(', ', array_unique($missing));
        }

        if ($sourceCode === 'ES') {
            $allowed = self::SPANISH_VALID_IN[$targetCode] ?? [];
            preg_match_all('/\p{L}+/u', mb_strtolower($outPlain), $m);
            $spanish = array_values(array_unique(array_filter($m[0], function ($w) use ($allowed) {
                return (in_array($w, self::SPANISH_ONLY_WORDS, true) && !in_array($w, $allowed, true)) || preg_match('/ción(es)?$/u', $w);
            })));
            $hasCion = (bool) preg_grep('/ción/u', $spanish);
            if (count($spanish) >= 2 || $hasCion) {
                $issues[] = 'possible Spanish left: ' . implode(', ', array_slice($spanish, 0, 5));
            }
        }

        if (mb_strlen(self::letters($outPlain)) > 12 && self::upperRatio($outPlain) > 0.8 && self::upperRatio($srcPlain) < 0.5) {
            $issues[] = 'in capital letters';
        }

        // Only when the translation goes over the limit and the source did not (or clearly grew).
        $len = mb_strlen($output);
        $tooLong = function ($limit) use ($len, $source) {
            return $len > $limit && (mb_strlen($source) <= $limit || $len > mb_strlen($source) * 1.1);
        };
        if ($field === 'Meta title' && $tooLong(70)) {
            $issues[] = sprintf('meta title of %d characters (max ~60)', $len);
        }
        if ($field === 'Meta description' && $tooLong(160)) {
            $issues[] = sprintf('meta description of %d characters (max 160)', $len);
        }

        $countTags = function ($s) {
            return preg_match_all('/<(strong|b|em|i|a)\b/i', $s);
        };
        if ($countTags(Html::tidyInline(Html::decodeEntities($source))) !== $countTags($output)) {
            $issues[] = 'bold text or links lost';
        }

        foreach ($glossary->violations($srcPlain, $output, $targetCode) as $violation) {
            $issues[] = 'glossary: ' . $violation;
        }

        return $issues;
    }

    /**
     * Figures of a text, normalised: "3,7" = "3.7" and "2.200" = "2 200" = "2200".
     *
     * @return string[]
     */
    public static function numbers(string $text): array
    {
        preg_match_all('/\d+(?:[.,\x{00a0}\x{202f} ]\d+)*/u', Html::plainText($text), $m);
        $out = [];
        foreach ($m[0] as $n) {
            if (preg_match('/^\d{1,3}([.,\x{00a0}\x{202f} ]\d{3})+$/u', $n)) {
                $out[] = preg_replace('/\D/', '', $n);
                continue;
            }
            foreach (preg_split('/[\x{00a0}\x{202f} ]/u', $n) as $part) {
                $out[] = preg_replace('/,/', '.', $part, 1);
            }
        }

        return $out;
    }

    private static function letters(string $s): string
    {
        return preg_replace('/[^\p{L}]/u', '', preg_replace(Html::ANY_TAG_RE, '', $s));
    }

    private static function upperRatio(string $s): float
    {
        $letters = self::letters($s);
        $len = mb_strlen($letters);

        return $len ? mb_strlen(preg_replace('/[^\p{Lu}]/u', '', $letters)) / $len : 0.0;
    }
}
