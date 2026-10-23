<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Provider;

/**
 * Minimal cURL JSON client for the HTTP based providers (OpenAI, OpenAI compatible, Ollama, Open WebUI).
 */
class HttpJsonClient
{
    /** @var int */
    private $timeout;

    public function __construct(int $timeout = 120)
    {
        $this->timeout = $timeout;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    public function request(string $method, string $url, array $headers = [], ?array $body = null): array
    {
        $ch = curl_init($url);
        $httpHeaders = ['Accept: application/json'];
        foreach ($headers as $name => $value) {
            $httpHeaders[] = $name . ': ' . $value;
        }
        if ($body !== null) {
            $httpHeaders[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch); // no-op since PHP 8.0, deprecated in 8.5
        }

        if ($raw === false) {
            throw new ProviderException('Connection error: ' . $curlError, true);
        }

        $decoded = json_decode((string) $raw, true);
        if ($status >= 400) {
            $message = is_array($decoded) ? self::extractError($decoded) : substr(strip_tags((string) $raw), 0, 300);
            $retryable = $status === 408 || $status === 409 || $status === 429 || $status >= 500;
            throw new ProviderException(sprintf('HTTP %d: %s', $status, $message), $retryable);
        }
        if (!is_array($decoded)) {
            throw new ProviderException('The provider returned a non-JSON response: ' . substr((string) $raw, 0, 300));
        }

        return $decoded;
    }

    private static function extractError(array $decoded): string
    {
        if (isset($decoded['error']['message'])) {
            return (string) $decoded['error']['message'];
        }
        if (isset($decoded['error']) && is_string($decoded['error'])) {
            return $decoded['error'];
        }
        if (isset($decoded['detail'])) {
            return is_string($decoded['detail']) ? $decoded['detail'] : json_encode($decoded['detail']);
        }

        return json_encode($decoded);
    }
}
