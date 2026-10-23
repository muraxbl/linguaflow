<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Translation;

/**
 * Builds the translation prompt. What is fixed per language goes first and what varies (glossary terms of
 * the text, context, input) goes last, so providers with prompt caching (Ollama, Anthropic, OpenAI) reuse
 * the common prefix between requests.
 */
class PromptBuilder
{
    public const FIELD_HINTS = [
        'Name' => 'The input is a product name for an online shop. Translate it as a concise product name in title style, with no final period and no explanations.',
        'Description' => 'The input is one paragraph or heading of an HTML product description.',
        'Meta title' => 'The input is an SEO meta title. Keep it concise (at most 60 characters).',
        'Meta description' => 'The input is an SEO meta description. Write natural text of at most 160 characters; shorten slightly if needed, but keep the key figures.',
        'Label' => 'The input is a short label shown in an online shop (availability or delivery time). Translate it concisely.',
    ];

    /** @var array<string, string> language code => style instructions */
    private $styles;
    /** @var string */
    private $instructions;

    public function __construct(array $styles, string $instructions = '')
    {
        $this->styles = $styles;
        $this->instructions = trim($instructions);
    }

    /**
     * @param array $job sourceName, langName, langCode, field, categoryHint, nameContext (null or [source, translated])
     */
    public function build(string $input, array $job, bool $hasMarkers, array $keepTerms, string $retryReason, string $glossaryPrompt): string
    {
        $lines = [
            sprintf('Translate the %s input to %s.', $job['sourceName'], $job['langName']),
            (string) ($this->styles[$job['langCode']] ?? ''),
            '',
            'Rules:',
            '- Return only the translation, with no comments, quotes, code fences or <translation> tags.',
            '- Do not use Markdown; never use ** or __ for emphasis.',
            '- Do not add HTML or XML tags.',
            sprintf('- Translate every %s word; never leave %s words in the output.', $job['sourceName'], $job['sourceName']),
            '- Never write whole words in capital letters unless they are acronyms or codes in the input.',
            '- Keep brand names, model codes and figures unchanged; adapt only the decimal separator to the target language.',
        ];

        if ($hasMarkers) {
            $lines[] = '- The input contains markers such as <t1>…</t1> and <t2/>. Keep every marker exactly as written, each one exactly once.';
            $lines[] = '- Place each marker pair around the translated words that correspond to the original words inside it.';
            $lines[] = '- Do not add, remove, rename or translate markers.';
        }
        if ($keepTerms) {
            $lines[] = '- Keep these terms exactly as written: ' . implode(', ', $keepTerms) . '.';
        }
        if ($this->instructions !== '') {
            $lines[] = '';
            $lines[] = 'Store owner instructions: ' . $this->instructions;
        }

        $lines[] = '';
        $lines[] = self::FIELD_HINTS[$job['field']] ?? '';
        $lines[] = (string) ($job['categoryHint'] ?? '');

        if ($glossaryPrompt !== '') {
            $lines[] = '';
            $lines[] = $glossaryPrompt;
        }

        if (!empty($job['nameContext'])) {
            $lines[] = '';
            $lines[] = sprintf(
                'Product context: this text belongs to the product "%s", whose approved %s name is "%s". Refer to the product with the same terminology.',
                $job['nameContext']['source'],
                $job['langName'],
                $job['nameContext']['translated']
            );
        }

        if ($retryReason !== '') {
            $lines[] = '';
            $lines[] = sprintf('Your previous answer was rejected (%s). Follow the rules strictly.', $retryReason);
        }

        $lines[] = '';
        $lines[] = 'Input:';
        $lines[] = '';
        $lines[] = $input;

        // Collapse runs of empty lines left by optional parts.
        $out = [];
        foreach ($lines as $line) {
            if ($line === '' && ($out === [] || end($out) === '')) {
                continue;
            }
            $out[] = $line;
        }

        return implode("\n", $out);
    }
}
