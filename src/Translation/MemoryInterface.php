<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Translation;

/**
 * Translation memory. Two kinds of entries:
 *   ok → approved or hand edited translation: used with any model.
 *   mt → validated machine translation: only reused with the same model, prompt version,
 *        category hint and applied glossary, since any of them can change the result.
 */
interface MemoryInterface
{
    public const APPROVED = 'ok';
    public const MACHINE = 'mt';

    /** Returns the stored target text, or null. */
    public function get(string $key): ?string;

    public function put(string $key, string $status, string $langCode, string $field, string $model, string $source, string $target): void;
}
