<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Provider;

use LinguaFlowAI\Security\ProviderUrl;

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
        ProviderUrl::validate($url);
        foreach ($headers as $name => $value) {
            if (in_array(strtolower($name), ['authorization', 'x-api-key'], true)) {
                ProviderUrl::validateCredentials($url, $value !== '');
            }
            if (preg_match('/[\r\n\x00]/', $name . $value)) {
                throw new ProviderException('Invalid provider header.');
            }
        }
        $ch = curl_init($url);
        $httpHeaders = ['Accept: application/json'];
        foreach ($headers as $name => $value) {
            $httpHeaders[] = $name . ': ' . $value;
        }
        if ($body !== null) {
            $httpHeaders[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $raw = '';
        $tooLarge = false;
        curl_setopt_array($ch, [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$raw, &$tooLarge) {
                if (strlen($raw) + strlen($chunk) > 2097152) {
                    $tooLarge = true;
                    return 0;
                }
                $raw .= $chunk;
                return strlen($chunk);
            },
        ]);

        $success = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlCode = curl_errno($ch);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch); // no-op since PHP 8.0, deprecated in 8.5
        }

        if ($tooLarge) {
            throw new ProviderException('The provider response exceeded the 2 MB limit.');
        }
        if ($success === false) {
            throw new ProviderException('Connection error (cURL ' . $curlCode . ').', true);
        }
        if ($status >= 300 && $status < 400) {
            throw new ProviderException('Provider redirects are not allowed. Configure the final URL.');
        }

        $decoded = json_decode((string) $raw, true);
        if ($status >= 400) {
            $message = is_array($decoded) ? self::extractError($decoded) : 'Provider request failed.';
            foreach ($headers as $value) {
                $message = str_replace([$value, preg_replace('/^Bearer\s+/i', '', $value)], '[redacted]', $message);
            }
            $message = mb_substr(strip_tags($message), 0, 300);
            $retryable = $status === 408 || $status === 409 || $status === 429 || $status >= 500;
            throw new ProviderException(sprintf('HTTP %d: %s', $status, $message), $retryable);
        }
        if (!is_array($decoded)) {
            throw new ProviderException('The provider returned an invalid JSON response.');
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
