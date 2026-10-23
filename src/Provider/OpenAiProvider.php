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

    public function completeJson(string $system, string $user, array $schema): array
    {
        if ($this->config->model === '') {
            throw new ProviderException('No model configured.');
        }
        $body = [
            'model' => $this->config->model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'stream' => false,
        ];
        if ($this->config->provider === ProviderConfig::OPENAI) {
            // Only OpenAI itself is guaranteed to support strict schemas; elsewhere the prompt carries the format.
            $body['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'translation', 'strict' => true, 'schema' => $schema],
            ];
        }

        $response = $this->http->request('POST', $this->url('chat/completions'), $this->headers(), $body);
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

        return JsonExtractor::decode((string) ($choice['message']['content'] ?? ''));
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
