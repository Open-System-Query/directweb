<?php
declare(strict_types=1);

namespace Lumui\Http;

final class Request
{
    
    private $method;
    
    private $uri;
    
    private $headers;
    
    private $query;
    
    private $body;

    
    public function __construct(string $method, string $uri, array $headers = [], array $query = [], string $body = '')
    {
        $this->method = strtoupper($method);
        $this->uri = $uri;
        $this->headers = [];
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
        $this->query = $query;
        $this->body = $body;
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (strpos($name, 'HTTP_') === 0) {
                $header = str_replace('_', '-', substr($name, 5));
                $headers[$header] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['Content-Length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }
        $headers['X-LUMUI-Request-Scheme'] = self::requestScheme($_SERVER);
        $headers['X-LUMUI-Request-Host'] = self::requestHost($_SERVER);

        $query = [];
        foreach ($_GET as $name => $value) {
            if (is_scalar($value)) {
                $query[(string) $name] = (string) $value;
            }
        }

        $body = file_get_contents('php://input');
        return new self(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            $headers,
            $query,
            is_string($body) ? $body : ''
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function path(): string
    {
        return (string) (parse_url($this->uri, PHP_URL_PATH) ?: '/');
    }

    public function header(string $name, string $default = ''): string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function query(string $name, string $default = ''): string
    {
        return $this->query[$name] ?? $default;
    }

    public function body(): string
    {
        return $this->body;
    }

    
    private static function requestScheme(array $server): string
    {
        $https = strtolower(trim((string) ($server['HTTPS'] ?? '')));
        if ($https !== '' && $https !== 'off' && $https !== '0') {
            return 'https';
        }

        $forwarded = (string) ($server['HTTP_FORWARDED'] ?? '');
        if (preg_match('/(?:^|[;,]\s*)proto="?(https?)"?/i', $forwarded, $match) === 1) {
            $scheme = strtolower((string) ($match[1] ?? ''));
            if (in_array($scheme, ['http', 'https'], true)) {
                return $scheme;
            }
        }

        $forwardedProto = explode(',', (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0];
        $scheme = strtolower(trim($forwardedProto));
        if (in_array($scheme, ['http', 'https'], true)) {
            return $scheme;
        }

        $scheme = strtolower(trim((string) ($server['REQUEST_SCHEME'] ?? '')));
        if (in_array($scheme, ['http', 'https'], true)) {
            return $scheme;
        }

        return (int) ($server['SERVER_PORT'] ?? 0) === 443 ? 'https' : 'http';
    }

    
    private static function requestHost(array $server): string
    {
        $forwarded = (string) ($server['HTTP_FORWARDED'] ?? '');
        $candidates = [];
        if (preg_match('/(?:^|[;,]\s*)host="?([^";,\s]+)"?/i', $forwarded, $match) === 1) {
            $candidates[] = (string) ($match[1] ?? '');
        }
        $candidates[] = explode(',', (string) ($server['HTTP_X_FORWARDED_HOST'] ?? ''))[0];
        $candidates[] = (string) ($server['HTTP_HOST'] ?? '');
        $candidates[] = (string) ($server['SERVER_NAME'] ?? '');

        foreach ($candidates as $candidate) {
            $host = strtolower(trim($candidate));
            if (
                preg_match(
                    '/^(?:\[[0-9a-f:.]+\]|[a-z0-9.-]+)(?::[0-9]{1,5})?$/i',
                    $host
                ) === 1
            ) {
                return $host;
            }
        }

        return '';
    }
}

