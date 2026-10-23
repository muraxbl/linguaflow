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
     * Sends a system + user prompt and returns the decoded JSON object the model answered with.
     * $schema is a JSON schema (type=object) describing the expected answer.
     *
     * @return array<string, mixed>
     */
    public function completeJson(string $system, string $user, array $schema): array;
}
