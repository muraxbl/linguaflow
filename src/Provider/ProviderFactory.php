<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Provider;

final class ProviderFactory
{
    public static function create(ProviderConfig $config): ProviderInterface
    {
        switch ($config->provider) {
            case ProviderConfig::ANTHROPIC:
                return new AnthropicProvider($config);
            case ProviderConfig::OLLAMA_CLOUD:
            case ProviderConfig::OLLAMA_LOCAL:
                return new OllamaProvider($config);
            case ProviderConfig::OPENAI:
            case ProviderConfig::OPENAI_COMPATIBLE:
            case ProviderConfig::OPEN_WEBUI:
                return new OpenAiProvider($config);
        }

        throw new ProviderException(sprintf('Unknown provider "%s".', $config->provider));
    }
}
