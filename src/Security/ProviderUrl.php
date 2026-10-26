<?php
namespace LinguaFlowAI\Security;

use LinguaFlowAI\Provider\ProviderException;

final class ProviderUrl
{
    public static function validateCredentials(string $url, bool $hasCredentials): void
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($hasCredentials && strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
            && !in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
            throw new ProviderException('Use HTTPS when sending an API key (loopback services are the only HTTP exception).');
        }
    }

    /** Reject URL parser ambiguity and non-HTTP cURL protocols before attaching credentials. */
    public static function validate(string $url, bool $base = false): void
    {
        $parts = parse_url($url);
        if (!$parts || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || ($base && isset($parts['query'])) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new ProviderException('The provider URL must be an HTTP(S) URL without credentials or a fragment (base URLs cannot contain a query).');
        }
    }
}
