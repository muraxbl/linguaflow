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

    public function completeJson(string $system, string $user, array $schema): array
    {
        if ($this->config->model === '') {
            throw new ProviderException('No model configured.');
        }
        $response = $this->http->request('POST', $this->config->baseUrl . '/api/chat', $this->headers(), [
            'model' => $this->config->model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'format' => $schema,
            'stream' => false,
            'options' => ['temperature' => 0.2],
        ]);
        if (($response['done_reason'] ?? '') === 'length') {
            throw new ProviderException('The translation was cut off (context/token limit reached).');
        }

        return JsonExtractor::decode((string) ($response['message']['content'] ?? ''));
    }

    private function headers(): array
    {
        return $this->config->apiKey !== '' ? ['Authorization' => 'Bearer ' . $this->config->apiKey] : [];
    }
}
