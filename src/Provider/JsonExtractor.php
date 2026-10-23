<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Provider;

/**
 * Decodes the JSON object out of a model answer. Providers without schema-constrained output
 * sometimes wrap the object in a code fence or add a sentence around it.
 */
final class JsonExtractor
{
    /**
     * @return array<string, mixed>
     */
    public static function decode(string $text): array
    {
        $text = trim($text);
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $m)) {
            $decoded = json_decode($m[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        throw new ProviderException('The model answer is not valid JSON: ' . substr($text, 0, 300), true);
    }
}
