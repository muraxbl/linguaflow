<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Translation;

use LinguaFlowAI\Provider\ProviderException;
use LinguaFlowAI\Provider\ProviderInterface;

/**
 * Turns a set of named text fields into the same set translated into one target language.
 * Free of PrestaShop dependencies so it can be exercised on its own.
 */
class Translator
{
    /** @var ProviderInterface */
    private $provider;
    /** @var string extra merchant instructions (tone, audience...) */
    private $instructions;
    /** @var string[] terms that must be kept verbatim */
    private $doNotTranslate;

    public function __construct(ProviderInterface $provider, string $instructions = '', array $doNotTranslate = [])
    {
        $this->provider = $provider;
        $this->instructions = trim($instructions);
        $this->doNotTranslate = array_values(array_filter(array_map('trim', $doNotTranslate), 'strlen'));
    }

    /**
     * @param array<string, string> $fields    field key => source text (non empty)
     * @param array<string, int>    $maxLength field key => max characters (optional)
     *
     * @return array<string, string> field key => translated text
     */
    public function translate(array $fields, string $sourceLanguage, string $targetLanguage, string $targetIso, array $maxLength = []): array
    {
        if ($fields === []) {
            return [];
        }

        $properties = [];
        foreach (array_keys($fields) as $key) {
            $properties[$key] = ['type' => 'string'];
        }
        $schema = [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($fields),
            'additionalProperties' => false,
        ];

        $result = $this->provider->completeJson(
            $this->systemPrompt(),
            $this->userPrompt($fields, $sourceLanguage, $targetLanguage, $targetIso, $maxLength),
            $schema
        );

        $translated = [];
        foreach ($fields as $key => $source) {
            if (!isset($result[$key]) || !is_string($result[$key]) || trim($result[$key]) === '') {
                throw new ProviderException(sprintf('The model answer is missing the "%s" field.', $key), true);
            }
            $value = trim($result[$key]);
            if (isset($maxLength[$key]) && mb_strlen($value) > $maxLength[$key]) {
                $value = self::truncate($value, $maxLength[$key]);
            }
            $translated[$key] = $value;
        }

        return $translated;
    }

    private function systemPrompt(): string
    {
        $prompt = "You are a professional e-commerce translator and localizer. You translate online store content "
            . "(product names, descriptions, SEO metadata) so it reads as if it had been written natively for shoppers "
            . "in the target market.\n\n"
            . "How to translate:\n"
            . "- Keep every HTML tag, attribute, entity and inline style exactly as in the source; translate only the human-readable text between tags and in alt/title attributes. Do not add or remove tags.\n"
            . "- Keep brand names, model numbers, SKUs, measurements, URLs, e-mail addresses and placeholders such as {name} or %s unchanged.\n"
            . "- Adapt idioms and marketing tone naturally instead of translating word by word, but do not invent product claims or drop information.\n"
            . "- SEO fields (meta_title, meta_description) should stay concise and keyword-rich in the target language.\n"
            . "- Answer with a single JSON object that has exactly the same keys as the input object and the translated text as values. No commentary.";

        if ($this->doNotTranslate !== []) {
            $prompt .= "\n\nNever translate these terms, keep them exactly as written: " . implode(', ', $this->doNotTranslate) . '.';
        }
        if ($this->instructions !== '') {
            $prompt .= "\n\nAdditional instructions from the store owner:\n" . $this->instructions;
        }

        return $prompt;
    }

    private function userPrompt(array $fields, string $sourceLanguage, string $targetLanguage, string $targetIso, array $maxLength): string
    {
        $limits = [];
        foreach ($maxLength as $key => $max) {
            if (isset($fields[$key])) {
                $limits[] = sprintf('%s: at most %d characters', $key, $max);
            }
        }

        $prompt = sprintf("Translate the values of this JSON object from %s into %s (locale code: %s).\n", $sourceLanguage, $targetLanguage, $targetIso);
        if ($limits !== []) {
            $prompt .= 'Length limits: ' . implode('; ', $limits) . ".\n";
        }

        return $prompt . "\n" . json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    private static function truncate(string $value, int $max): string
    {
        $cut = mb_substr($value, 0, $max);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > $max * 0.6 ? mb_substr($cut, 0, $space) : $cut, " ,;:-");
    }
}
