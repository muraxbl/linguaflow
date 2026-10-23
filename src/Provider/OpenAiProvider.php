<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Provider;

/**
 * OpenAI, any OpenAI compatible endpoint and Open WebUI (which exposes the same chat format under /api).
 */
class OpenAiProvider implements ProviderInterface
{
    /** @var ProviderConfig */
    private $config;
    /** @var HttpJsonClient */
    private $http;
    /** @var bool set once the model rejected the temperature parameter (reasoning models) */
    private $temperatureRejected = false;

    public function __construct(ProviderConfig $config, ?HttpJsonClient $http = null)
    {
        if ($config->baseUrl === '') {
            throw new ProviderException('A base URL is required for this provider.');
        }
        if ($config->provider === ProviderConfig::OPENAI && $config->apiKey === '') {
            throw new ProviderException('An OpenAI API key is required.');
        }
        $this->config = $config;
        $this->http = $http ?: new HttpJsonClient($config->timeout);
    }

    public function listModels(): array
    {
        $response = $this->http->request('GET', $this->url('models'), $this->headers());
        $ids = [];
        foreach ($response['data'] ?? [] as $model) {
            if (isset($model['id'])) {
                $ids[] = (string) $model['id'];
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
        $body = [
            'model' => $this->config->model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'stream' => false,
        ];
        if (!$this->temperatureRejected) {
            $body['temperature'] = $temperature;
        }

        try {
            $response = $this->http->request('POST', $this->url('chat/completions'), $this->headers(), $body);
        } catch (ProviderException $e) {
            if (isset($body['temperature']) && stripos($e->getMessage(), 'temperature') !== false) {
                // Reasoning models only accept their default temperature: drop it from now on.
                $this->temperatureRejected = true;

                return $this->complete($prompt, $temperature);
            }
            throw $e;
        }

        $choice = $response['choices'][0] ?? null;
        if (!is_array($choice)) {
            throw new ProviderException('Unexpected response: no choices returned.', true);
        }
        if (($choice['finish_reason'] ?? '') === 'length') {
            throw new ProviderException('The translation was cut off (token limit reached).');
        }
        if (!empty($choice['message']['refusal'])) {
            throw new ProviderException('The model refused: ' . $choice['message']['refusal']);
        }

        return (string) ($choice['message']['content'] ?? '');
    }

    private function url(string $path): string
    {
        // Open WebUI serves its OpenAI style API under /api (models at /api/models, chat at /api/chat/completions).
        if ($this->config->provider === ProviderConfig::OPEN_WEBUI && !preg_match('#/api$#', $this->config->baseUrl)) {
            return $this->config->baseUrl . '/api/' . $path;
        }

        return $this->config->baseUrl . '/' . $path;
    }

    private function headers(): array
    {
        return $this->config->apiKey !== '' ? ['Authorization' => 'Bearer ' . $this->config->apiKey] : [];
    }
}
