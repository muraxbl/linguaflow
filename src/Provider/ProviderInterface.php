<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Provider;

interface ProviderInterface
{
    /**
     * Returns the model identifiers the provider exposes, or throws ProviderException
     * when the endpoint cannot be reached or does not support listing.
     *
     * @return string[]
     */
    public function listModels(): array;

    /**
     * Sends a single user prompt and returns the model's text answer.
     * $temperature is a hint: providers or models that reject it ignore it.
     */
    public function complete(string $prompt, float $temperature): string;
}
