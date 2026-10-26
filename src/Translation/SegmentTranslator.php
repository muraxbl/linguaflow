<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Translation;

use LinguaFlowAI\Provider\ProviderInterface;

/**
 * Translates one field: HTML is split into blocks, every block with text is translated on its own with
 * inline tags as markers, the answer is validated and retried, and results go to the translation memory.
 */
class SegmentTranslator
{
    /** Raising it invalidates the "mt" memory entries when the prompt changes. */
    public const PROMPT_VERSION = 'lf1';
    public const MAX_ATTEMPTS = 3;
    /** At temperature 0 a retry returns exactly the same, so each attempt raises it a little. */
    private const ATTEMPT_TEMPERATURES = [0.0, 0.3, 0.6];

    private const TECHNICAL_PATTERNS = [
        '/^[0-9\s.,-]+$/', '/^[0-9]+W$/i', '/^[0-9]+V$/i', '/^[0-9]+Hz$/i',
        '/^[0-9]+K$/i', '/^[0-9]+lm$/i', '/^IP[0-9]+$/i', '/^[A-Z0-9_-]{5,}$/i',
    ];

    /** @var ProviderInterface */
    private $provider;
    /** @var string */
    private $model;
    /** @var PromptBuilder */
    private $prompts;
    /** @var GlossaryMatcher */
    private $glossary;
    /** @var ProtectedTerms */
    private $protected;
    /** @var MemoryInterface */
    private $memory;
    /** @var array<string, string> cache for the current run */
    private $cache = [];
    /** @var array{calls: int, memory: int} */
    public $stats = ['calls' => 0, 'memory' => 0];

    public function __construct(ProviderInterface $provider, string $model, PromptBuilder $prompts, GlossaryMatcher $glossary, ProtectedTerms $protected, MemoryInterface $memory)
    {
        $this->provider = $provider;
        $this->model = $model;
        $this->prompts = $prompts;
        $this->glossary = $glossary;
        $this->protected = $protected;
        $this->memory = $memory;
    }

    public static function approvedKey(string $langCode, string $field, string $source): string
    {
        return sha1(implode("\x01", [MemoryInterface::APPROVED, $langCode, $field, trim($source)]));
    }

    public static function machineKey(string $model, string $langCode, string $field, string $categoryHint, string $glossaryPrompt, string $source): string
    {
        return sha1(implode("\x01", [MemoryInterface::MACHINE, self::PROMPT_VERSION, $model, $langCode, $field, $categoryHint, $glossaryPrompt, trim($source)]));
    }

    /** Protected terms depend on the product (its manufacturer is protected too). */
    public function setProtectedTerms(ProtectedTerms $protected): void
    {
        $this->protected = $protected;
    }

    public function glossary(): GlossaryMatcher
    {
        return $this->glossary;
    }

    /**
     * @param array    $job   sourceName, sourceCode, langName, langCode, field, categoryHint, nameContext, noCache
     * @param string[] $notes receives notes about degraded results (lost bold text, doubtful answers)
     */
    public function translateText(string $text, array $job, array &$notes): string
    {
        if (trim($text) === '') {
            return '';
        }
        foreach (self::TECHNICAL_PATTERNS as $pattern) {
            if (preg_match($pattern, trim($text))) {
                return $text;
            }
        }

        // Odd parts of the split are block tags and are copied as they are.
        $parts = Html::splitBlocks(Html::tidyInline(Html::decodeEntities($text)));
        $out = '';
        foreach ($parts as $i => $part) {
            $out .= ($i % 2 === 1 || !Html::hasLetters($part)) ? $part : $this->translateSegment($part, $job, $notes);
        }

        return $out;
    }

    private function translateSegment(string $segment, array $job, array &$notes): string
    {
        preg_match('/^\s*/u', $segment, $lead);
        preg_match('/\s*$/u', $segment, $trail);
        $core = trim($segment);
        $glossaryPrompt = $this->glossary->prompt($core, $job['langCode'], $job['sourceName'], $job['langName']);

        // The product context is not part of the key: a paragraph repeated in many products is translated once.
        $cacheKey = implode("\x01", [$job['langCode'], $job['field'], $job['categoryHint'], $core]);
        $machineKey = self::machineKey($this->model, $job['langCode'], $job['field'], (string) $job['categoryHint'], $glossaryPrompt, $core);
        if (empty($job['noCache'])) {
            if (isset($this->cache[$cacheKey])) {
                return $lead[0] . $this->cache[$cacheKey] . $trail[0];
            }
            $remembered = $this->memory->get(self::approvedKey($job['langCode'], $job['field'], $core));
            if ($remembered === null) {
                $remembered = $this->memory->get($machineKey);
            }
            if ($remembered !== null) {
                ++$this->stats['memory'];
                $this->cache[$cacheKey] = $remembered;

                return $lead[0] . $remembered . $trail[0];
            }
        }
        // When regenerating, start with some temperature so a different version comes out.
        $tempOffset = empty($job['noCache']) ? 0 : 1;

        list($protectedText, $tags) = Html::protect($core);
        $keepTerms = $this->protected->find($core);
        $result = null;
        $lastOutput = '';
        $retryReason = '';

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS && $result === null; ++$attempt) {
            $raw = Html::normalizeMarkers($this->ask(
                $this->prompts->build($protectedText, $job, count($tags) > 0, $keepTerms, $retryReason, $glossaryPrompt),
                self::ATTEMPT_TEMPERATURES[min($attempt - 1 + $tempOffset, count(self::ATTEMPT_TEMPERATURES) - 1)]
            ));
            try {
                Html::validateMarkers($raw, $tags);
                $lastOutput = Html::restoreMarkers($raw, $tags);
                QualityCheck::check($core, $lastOutput, $job['field'], $keepTerms);
                // Glossary misses are worth another try, but never block: the review flags what remains.
                $missed = $this->glossary->violations($core, $lastOutput, $job['langCode']);
                if ($missed && $attempt < self::MAX_ATTEMPTS) {
                    throw new RetryException('mandatory terminology not used: ' . implode(', ', $missed));
                }
                $result = $lastOutput;
                $this->memory->put($machineKey, MemoryInterface::MACHINE, $job['langCode'], $job['field'], $this->model, $core, $result);
            } catch (RetryException $e) {
                $retryReason = $e->getMessage();
            }
        }

        // Last resort: translate without markers. The paragraph loses its bold text, not its translation.
        if ($result === null && $tags) {
            $notes[] = 'a paragraph lost its bold text / links (the model kept breaking the tags)';
            $plain = preg_replace(Html::ANY_TAG_RE, '', $core);
            $lastOutput = $this->ask($this->prompts->build($plain, $job, false, $this->protected->find($plain), '', $glossaryPrompt), 0.0);
            try {
                QualityCheck::check($core, $lastOutput, $job['field'], $keepTerms);
                $result = $lastOutput;
            } catch (RetryException $e) {
                $retryReason = $e->getMessage();
            }
        }

        if ($result === null) {
            $notes[] = 'doubtful result, check it: ' . $retryReason;
            $result = $lastOutput;
        }

        $this->cache[$cacheKey] = $result;

        return $lead[0] . $result . $trail[0];
    }

    private function ask(string $prompt, float $temperature): string
    {
        ++$this->stats['calls'];

        return Html::cleanModelOutput($this->provider->complete($prompt, $temperature));
    }
}
