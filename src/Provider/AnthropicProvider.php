<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Provider;

/**
 * Claude through the Messages API over raw HTTP. The official PHP SDK needs PHP 8.1,
 * which PrestaShop 1.7.6 shops often do not have.
 */
class AnthropicProvider implements ProviderInterface
{
    private const API_VERSION = '2026-10-21';

    /** Models where the server side refusal fallback ("fallbacks": "default") is available. */
    private const FALLBACK_MODELS = ['claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'];
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /** @var ProviderConfig */
    private $config;
    /** @var HttpJsonClient */
    private $http;

    public function __construct(ProviderConfig $config, ?HttpJsonClient $http = null)
    {
        if ($config->apiKey === '') {
            throw new ProviderException('An Anthropic API key is required.');
        }
        $this->config = $config;
        $this->http = $http ?: new HttpJsonClient($config->timeout);
    }

    public function listModels(): array
    {
        $ids = [];
        $afterId = null;
        do {
            $query = '?limit=100' . ($afterId !== null ? '&after_id=' . rawurlencode($afterId) : '');
            $page = $this->http->request('GET', $this->config->baseUrl . '/v1/models' . $query, $this->headers());
            foreach ($page['data'] ?? [] as $model) {
                if (isset($model['id'])) {
                    $ids[] = (string) $model['id'];
                }
            }
            $afterId = !empty($page['has_more']) && !empty($page['last_id']) ? (string) $page['last_id'] : null;
        } while ($afterId !== null && count($ids) < 1000);

        return $ids;
    }

    public function completeJson(string $system, string $user, array $schema): array
    {
        $body = [
            'model' => $this->config->model,
            'max_tokens' => 16000,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
            'output_config' => [
                'effort' => $this->config->effort,
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
        ];
        $headers = $this->headers();
        if (in_array($this->config->model, self::FALLBACK_MODELS, true)) {
            // A declined request is retried server side on Anthropic's recommended fallback model.
            $headers['anthropic-beta'] = self::FALLBACK_BETA;
            $body['fallbacks'] = 'default';
        }

        $message = $this->http->request('POST', $this->config->baseUrl . '/v1/messages', $headers, $body);

        $stopReason = $message['stop_reason'] ?? '';
        if ($stopReason === 'refusal') {
            $category = (string) ($message['stop_details']['category'] ?? '');
            throw new ProviderException('The model declined to translate this content' . ($category !== '' ? ' (' . $category . ')' : '') . '.');
        }
        if ($stopReason === 'max_tokens') {
            throw new ProviderException('The translation was cut off (max_tokens reached). The source text is probably too long.');
        }

        $text = '';
        foreach ($message['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }

        return JsonExtractor::decode($text);
    }

    private function headers(): array
    {
        return [
            'x-api-key' => $this->config->apiKey,
            'anthropic-version' => self::API_VERSION,
        ];
    }
}
