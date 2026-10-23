<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Provider;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\RateLimitException;

/**
 * Claude through the official Anthropic PHP SDK.
 */
class AnthropicProvider implements ProviderInterface
{
    /** Models where the server side refusal fallback ("fallbacks": "default") is available. */
    private const FALLBACK_MODELS = ['claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'];

    /** @var ProviderConfig */
    private $config;
    /** @var Client */
    private $client;

    public function __construct(ProviderConfig $config)
    {
        if ($config->apiKey === '') {
            throw new ProviderException('An Anthropic API key is required.');
        }
        $this->config = $config;
        $this->client = new Client(
            apiKey: $config->apiKey,
            baseUrl: $config->baseUrl,
            requestOptions: ['timeout' => (float) $config->timeout, 'maxRetries' => 2],
        );
    }

    public function listModels(): array
    {
        return $this->call(function () {
            $ids = [];
            foreach ($this->client->models->list(limit: 100)->pagingEachItem() as $model) {
                $ids[] = $model->id;
            }

            return $ids;
        });
    }

    public function completeJson(string $system, string $user, array $schema): array
    {
        $params = [
            'model' => $this->config->model,
            'maxTokens' => 16000,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
            'outputConfig' => [
                'effort' => $this->config->effort,
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
        ];
        if (in_array($this->config->model, self::FALLBACK_MODELS, true)) {
            // A declined request is retried server side on Anthropic's recommended fallback model.
            $params['betas'] = ['server-side-fallback-2026-07-01'];
            $params['fallbacks'] = 'default';
        }

        $message = $this->call(function () use ($params) {
            return $this->client->beta->messages->create(...$params);
        });

        if ($message->stopReason === 'refusal') {
            $category = $message->stopDetails !== null ? (string) $message->stopDetails->category : '';
            throw new ProviderException('The model declined to translate this content' . ($category !== '' ? ' (' . $category . ')' : '') . '.');
        }
        if ($message->stopReason === 'max_tokens') {
            throw new ProviderException('The translation was cut off (max_tokens reached). The source text is probably too long.');
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return JsonExtractor::decode($text);
    }

    /**
     * Maps SDK exceptions onto ProviderException, keeping the retryable / permanent distinction.
     */
    private function call(callable $fn)
    {
        try {
            return $fn();
        } catch (AuthenticationException $e) {
            throw new ProviderException('Anthropic rejected the API key: ' . $e->getMessage(), false, $e);
        } catch (NotFoundException $e) {
            throw new ProviderException('Unknown model or endpoint: ' . $e->getMessage(), false, $e);
        } catch (BadRequestException $e) {
            throw new ProviderException('Invalid request: ' . $e->getMessage(), false, $e);
        } catch (RateLimitException $e) {
            throw new ProviderException('Rate limited by Anthropic, will retry later.', true, $e);
        } catch (APIStatusException $e) {
            throw new ProviderException('Anthropic API error: ' . $e->getMessage(), ($e->status ?? 500) >= 500, $e);
        } catch (APIConnectionException $e) {
            throw new ProviderException('Could not reach the Anthropic API: ' . $e->getMessage(), true, $e);
        }
    }
}
