<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $server,
        public readonly array $cookies,
        public readonly string $basePath = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $base = rtrim((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        $base = str_ends_with($base, '/index.php') ? substr($base, 0, -10) : dirname($base);
        if ($base !== '' && $base !== '/' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $path = '/' . trim((string)$path, '/');

        Url::setBase($base);

        return new self(
            strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path === '/' ? '/' : rtrim($path, '/'),
            $_GET,
            $_POST,
            $_SERVER,
            $_COOKIE,
            Url::base(),
        );
    }

    public function input(string $key, string $default = ''): string
    {
        $value = $this->post[$key] ?? $this->query[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    }

    public function raw(string $key, string $default = ''): string
    {
        $value = $this->post[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->post[$key] ?? $this->query[$key] ?? null;
        return is_numeric($value) ? (int)$value : $default;
    }

    public function has(string $key): bool
    {
        return isset($this->post[$key]) || isset($this->query[$key]);
    }

    public function bool(string $key): bool
    {
        return in_array($this->input($key), ['1', 'true', 'on', 'yes'], true);
    }

    public function cookie(string $name, string $default = ''): string
    {
        $value = $this->cookies[$name] ?? $default;
        return is_string($value) ? $value : $default;
    }

    public function ip(): string
    {
        return (string)($this->server['REMOTE_ADDR'] ?? '');
    }

    public function userAgent(): string
    {
        return substr((string)($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isHttps(): bool
    {
        return ($this->server['HTTPS'] ?? '') === 'on'
            || ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || (int)($this->server['SERVER_PORT'] ?? 0) === 443;
    }
}
