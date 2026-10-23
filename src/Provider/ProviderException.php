<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Provider;

class ProviderException extends \RuntimeException
{
    /** @var bool whether retrying later may succeed (rate limit, overload, network) */
    private $retryable;

    public function __construct(string $message, bool $retryable = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->retryable = $retryable;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
