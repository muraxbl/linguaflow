<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Provider;

final class ProviderConfig
{
    public const ANTHROPIC = 'anthropic';
    public const OPENAI = 'openai';
    public const OPENAI_COMPATIBLE = 'openai_compatible';
    public const OLLAMA_CLOUD = 'ollama_cloud';
    public const OLLAMA_LOCAL = 'ollama_local';
    public const OPEN_WEBUI = 'openwebui';

    /** Default base URL and model per provider. An empty base URL means the user must supply one. */
    public const DEFAULTS = [
        self::ANTHROPIC => ['base_url' => 'https://api.anthropic.com', 'model' => 'claude-opus-5-5', 'needs_key' => true],
        self::OPENAI => ['base_url' => 'https://api.openai.com/v1', 'model' => '', 'needs_key' => true],
        self::OPENAI_COMPATIBLE => ['base_url' => '', 'model' => '', 'needs_key' => false],
        self::OLLAMA_CLOUD => ['base_url' => 'https://ollama.com', 'model' => '', 'needs_key' => true],
        self::OLLAMA_LOCAL => ['base_url' => 'http://localhost:11434', 'model' => '', 'needs_key' => false],
        self::OPEN_WEBUI => ['base_url' => 'http://localhost:3000', 'model' => '', 'needs_key' => true],
    ];

    public const LABELS = [
        self::ANTHROPIC => 'Anthropic (Claude)',
        self::OPENAI => 'OpenAI',
        self::OPENAI_COMPATIBLE => 'OpenAI compatible (OpenRouter, Groq, Mistral, LM Studio, vLLM...)',
        self::OLLAMA_CLOUD => 'Ollama Cloud (API key)',
        self::OLLAMA_LOCAL => 'Ollama (local / self-hosted)',
        self::OPEN_WEBUI => 'Open WebUI',
    ];

    /** @var string */
    public $provider;
    /** @var string */
    public $apiKey;
    /** @var string */
    public $baseUrl;
    /** @var string */
    public $model;
    /** @var string effort level, only used by providers that support it (Anthropic) */
    public $effort;
    /** @var int request timeout in seconds */
    public $timeout;
    /** @var int Ollama num_ctx (0 = model default) */
    public $contextSize;
    /** @var bool Ollama: send think=false to skip the reasoning phase of thinking models */
    public $disableThinking;

    public function __construct(string $provider, string $apiKey, string $baseUrl, string $model, string $effort = 'medium', int $timeout = 120, int $contextSize = 0, bool $disableThinking = true)
    {
        if (!isset(self::DEFAULTS[$provider])) {
            throw new ProviderException(sprintf('Unknown provider "%s".', $provider));
        }
        $this->provider = $provider;
        $this->apiKey = trim($apiKey);
        $this->baseUrl = rtrim(trim($baseUrl) !== '' ? trim($baseUrl) : self::DEFAULTS[$provider]['base_url'], '/');
        $this->model = trim($model) !== '' ? trim($model) : self::DEFAULTS[$provider]['model'];
        $this->effort = $effort;
        $this->timeout = $timeout;
        $this->contextSize = $contextSize;
        $this->disableThinking = $disableThinking;
    }
}
