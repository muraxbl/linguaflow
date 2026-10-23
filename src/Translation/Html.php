<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Translation;

/**
 * HTML handling for translation: descriptions are split on block tags so each paragraph is translated
 * on its own, and inline tags travel to the model as short markers (<t1>…</t1>, <t2/>) that are
 * validated and swapped back afterwards. Block tags never reach the model, so it cannot lose them.
 */
final class Html
{
    public const BLOCK_TAG_RE = '/(<\/?(?:p|h[1-6]|ul|ol|li|div|table|thead|tbody|tfoot|tr|td|th|blockquote|section|article|header|footer)\b[^>]*>)/iu';
    public const ANY_TAG_RE = '/<\/?[a-zA-Z][^>]*>/u';

    /**
     * Alternating [text, block tag, text, block tag, ..., text]: even indexes are content.
     *
     * @return string[]
     */
    public static function splitBlocks(string $html): array
    {
        return preg_split(self::BLOCK_TAG_RE, $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];
    }

    /**
     * Turns entities (&oacute; &ntilde; &#233;...) into real characters: models understand them better
     * and they cost fewer tokens. &lt; &gt; &amp; &quot; &apos; stay as they are so no fake tags appear.
     */
    public static function decodeEntities(string $text): string
    {
        return preg_replace_callback('/&(#\d+|#x[0-9a-f]+|[a-z][a-z0-9]*);/i', function ($m) {
            if (preg_match('/^(lt|gt|amp|quot|apos)$/i', $m[1])) {
                return $m[0];
            }

            return html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }, $text);
    }

    /**
     * Fixes inline HTML no model can respect:
     *   <strong>two </strong><strong>modes</strong> → a single bold
     *   a<strong>utonomy…  → <strong>autonomy…
     *   operatio</strong>n → operation</strong>
     *   radar </strong>that → radar</strong> that
     */
    public static function tidyInline(string $text): string
    {
        // Spaces at the edge of an inline tag go outside it ("radar </strong>que" → "radar</strong> que"):
        // models drop them, gluing words together.
        $text = preg_replace_callback('/\s+(<\/(?:strong|b|em|i|u|a|span)>)(\s*)(?=(.?))/iu', function ($m) {
            return $m[1] . ($m[2] !== '' || $m[3] === '' || preg_match('/[.,;:!?)\]]/', $m[3]) ? $m[2] : ' ');
        }, $text);
        $text = preg_replace('/(?<=[^\s>])(<(?:strong|b|em|i|u|a|span)\b[^>]*>)\s+/iu', ' $1', $text);
        $text = preg_replace('/(<(?:strong|b|em|i|u|a|span)\b[^>]*>)\s+/iu', '$1', $text);
        $text = preg_replace('/<\/(strong|b|em|i|u)>(\s*)<\1>/i', '$2', $text);
        $text = preg_replace('/(\p{L}+)(<(strong|b|em|i|u)\b[^>]*>)(?=\p{L})/iu', '$2$1', $text);

        return preg_replace('/(?<=\p{L})(<\/(strong|b|em|i|u)>)(\p{L}+)/iu', '$3$1', $text);
    }

    public static function hasLetters(string $text): bool
    {
        return (bool) preg_match('/\p{L}/u', preg_replace(self::ANY_TAG_RE, '', $text));
    }

    public static function plainText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', preg_replace(self::ANY_TAG_RE, ' ', $text)));
    }

    /**
     * Replaces inline tags with short markers: <tN>…</tN> for pairs and <tN/> for single tags.
     *
     * @return array{0: string, 1: array<int, array{marker: string, tag: string}>}
     */
    public static function protect(string $text): array
    {
        $tags = [];
        $stack = [];
        $nextId = 0;

        $protected = preg_replace_callback(self::ANY_TAG_RE, function ($m) use (&$tags, &$stack, &$nextId) {
            $tag = $m[0];
            $name = preg_match('/^<\/?([a-zA-Z0-9]+)/', $tag, $nm) ? strtolower($nm[1]) : '';

            if (strpos($tag, '</') === 0) {
                for ($i = count($stack) - 1; $i >= 0; --$i) {
                    if ($stack[$i]['name'] === $name) {
                        $open = $stack[$i];
                        array_splice($stack, $i);
                        $tags[] = ['marker' => '</t' . $open['id'] . '>', 'tag' => $tag];

                        return '</t' . $open['id'] . '>';
                    }
                }
                $id = ++$nextId;
                $tags[] = ['marker' => '<t' . $id . '/>', 'tag' => $tag];

                return '<t' . $id . '/>';
            }

            $id = ++$nextId;
            if (substr($tag, -2) === '/>' || preg_match('/^(br|img|hr|wbr)$/', $name)) {
                $tags[] = ['marker' => '<t' . $id . '/>', 'tag' => $tag];

                return '<t' . $id . '/>';
            }

            $tags[] = ['marker' => '<t' . $id . '>', 'tag' => $tag, 'id' => $id];
            $stack[] = ['name' => $name, 'id' => $id, 'index' => count($tags) - 1];

            return '<t' . $id . '>';
        }, $text);

        // Openings without a closing tag (broken HTML) become single markers.
        foreach ($stack as $open) {
            $solo = '<t' . $open['id'] . '/>';
            $protected = str_replace('<t' . $open['id'] . '>', $solo, $protected);
            $tags[$open['index']] = ['marker' => $solo, 'tag' => $tags[$open['index']]['tag']];
        }

        return [$protected, array_map(function ($t) {
            return ['marker' => $t['marker'], 'tag' => $t['tag']];
        }, $tags)];
    }

    /** Fixes small variations models introduce: spaces, capitals or "< t1 / >". */
    public static function normalizeMarkers(string $text): string
    {
        return preg_replace_callback('/<\s*(\/?)\s*t\s*(\d+)\s*(\/?)\s*>/i', function ($m) {
            return '<' . $m[1] . 't' . $m[2] . $m[3] . '>';
        }, $text);
    }

    /**
     * Every marker exactly once and pairs properly nested. The order between pairs may change
     * (translation reorders sentences); the structure may not.
     *
     * @throws RetryException
     */
    public static function validateMarkers(string $text, array $tags): void
    {
        foreach ($tags as $t) {
            $count = substr_count($text, $t['marker']);
            if ($count !== 1) {
                throw new RetryException(sprintf('marker %s appears %d times', $t['marker'], $count));
            }
        }

        preg_match_all('/<\/?t\d+\/?>/', $text, $found);
        if (count($found[0]) !== count($tags)) {
            throw new RetryException('the model added markers that did not exist');
        }

        $stack = [];
        foreach ($found[0] as $m) {
            if (substr($m, -2) === '/>') {
                continue;
            }
            if (strpos($m, '</') === 0) {
                if (array_pop($stack) !== substr($m, 2, -1)) {
                    throw new RetryException('badly nested markers near ' . $m);
                }
            } else {
                $stack[] = substr($m, 1, -1);
            }
        }
        if ($stack) {
            throw new RetryException('unclosed markers');
        }
    }

    public static function restoreMarkers(string $text, array $tags): string
    {
        foreach ($tags as $t) {
            $text = str_replace($t['marker'], $t['tag'], $text);
        }

        return $text;
    }

    /** Removes wrappers some models add despite the instructions. */
    public static function cleanModelOutput(string $text): string
    {
        $text = preg_replace('/<think>.*?<\/think>/s', '', $text);
        $text = trim($text);
        $text = preg_replace('/^```[a-z]*\s*\n?/i', '', $text);
        $text = preg_replace('/\n?```$/', '', $text);
        $text = preg_replace('/^<translation>\s*/i', '', $text);
        $text = preg_replace('/\s*<\/translation>$/i', '', $text);
        $text = preg_replace('/\*\*(.+?)\*\*/u', '$1', $text);

        return trim($text);
    }
}
