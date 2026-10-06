<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $headers,
        public readonly string $ip
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

        $headers = [];

        if (function_exists('getallheaders')) {
            $raw = getallheaders();

            if (is_array($raw)) {
                $headers = array_change_key_case($raw, CASE_LOWER);
            }
        }

        if ($headers === []) {
            foreach ($_SERVER as $key => $value) {
                if (str_starts_with($key, 'HTTP_')) {
                    $name = strtolower(str_replace('_', '-', substr($key, 5)));
                    $headers[$name] = (string) $value;
                }
            }
        }

        $body = [];

        $rawBody = file_get_contents('php://input') ?: '';

        if ($rawBody !== '') {
            $decoded = json_decode($rawBody, true);

            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        if ($body === [] && $_POST !== []) {
            $body = $_POST;
        }

        return new self($method, $path, $_GET, $body, $headers, self::resolveIp($headers));
    }

    /**
     * Реальный IP клиента. За обратным прокси (Caddy) REMOTE_ADDR — это адрес
     * прокси, поэтому берём последний адрес из X-Forwarded-For (его добавляет
     * наш доверенный прокси), затем X-Real-IP, затем REMOTE_ADDR.
     *
     * @param array<string,string> $headers
     */
    private static function resolveIp(array $headers): string
    {
        $forwarded = (string) ($headers['x-forwarded-for'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');

        if ($forwarded !== '') {
            $parts = array_values(array_filter(
                array_map('trim', explode(',', $forwarded)),
                static fn (string $part): bool => $part !== ''
            ));

            $candidate = $parts === [] ? '' : (string) end($parts);

            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        $real = (string) ($headers['x-real-ip'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? '');

        if (filter_var($real, FILTER_VALIDATE_IP) !== false) {
            return $real;
        }

        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function withPath(string $path): self
    {
        return new self($this->method, $path, $this->query, $this->body, $this->headers, $this->ip);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $value = $this->input($key);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function queryParam(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function queryAll(): array
    {
        return $this->query;
    }

    public function bodyAll(): array
    {
        return $this->body;
    }

    public function bearerToken(): ?string
    {
        $header = (string) ($this->headers['authorization'] ?? '');

        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    public function userAgent(): string
    {
        return (string) ($this->headers['user-agent'] ?? '');
    }
}
