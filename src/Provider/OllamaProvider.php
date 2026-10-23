<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Provider;

/**
 * Ollama native API, local (http://localhost:11434) or Ollama Cloud (https://ollama.com + API key).
 */
class OllamaProvider implements ProviderInterface
{
    /** @var ProviderConfig */
    private $config;
    /** @var HttpJsonClient */
    private $http;
    /** @var bool set once the model rejected the "think" option (models without reasoning) */
    private $thinkRejected = false;

    public function __construct(ProviderConfig $config, ?HttpJsonClient $http = null)
    {
        if ($config->provider === ProviderConfig::OLLAMA_CLOUD && $config->apiKey === '') {
            throw new ProviderException('An Ollama Cloud API key is required.');
        }
        $this->config = $config;
        $this->http = $http ?: new HttpJsonClient($config->timeout);
    }

    public function listModels(): array
    {
        $response = $this->http->request('GET', $this->config->baseUrl . '/api/tags', $this->headers());
        $ids = [];
        foreach ($response['models'] ?? [] as $model) {
            $name = $model['model'] ?? $model['name'] ?? null;
            if ($name !== null) {
                $ids[] = (string) $name;
            }
        }
        sort($ids);

        return $ids;
    }

    public function complete(string $prompt, float $temperature): string
    {
        if ($this->config->model === '') {
            throw new ProviderException('No model configured.');
        }
        $options = ['temperature' => $temperature, 'top_p' => 0.9, 'num_predict' => 2048];
        if ($this->config->contextSize > 0) {
            // Same num_ctx as the loaded model, so Ollama does not have to reload it.
            $options['num_ctx'] = $this->config->contextSize;
        }
        $body = [
            'model' => $this->config->model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'stream' => false,
            'keep_alive' => '30m',
            'options' => $options,
        ];
        if ($this->config->disableThinking && !$this->thinkRejected) {
            // Reasoning before translating (Gemma 4, Qwen 3...) only adds time.
            $body['think'] = false;
        }

        try {
            $response = $this->http->request('POST', $this->config->baseUrl . '/api/chat', $this->headers(), $body);
        } catch (ProviderException $e) {
            if (isset($body['think']) && stripos($e->getMessage(), 'think') !== false) {
                $this->thinkRejected = true;

                return $this->complete($prompt, $temperature);
            }
            throw $e;
        }
        if (($response['done_reason'] ?? '') === 'length') {
            throw new ProviderException('The translation was cut off (context/token limit reached).');
        }

        return (string) ($response['message']['content'] ?? '');
    }

    private function headers(): array
    {
        return $this->config->apiKey !== '' ? ['Authorization' => 'Bearer ' . $this->config->apiKey] : [];
    }
}
