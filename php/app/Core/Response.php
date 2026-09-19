<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    private function __construct(
        private readonly int $status,
        private readonly string $body,
        private array $headers = [],
        private readonly array $cookies = [],
    ) {
    }

    public static function html(string $body, int $status = 200, array $cookies = []): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=utf-8'], $cookies);
    }

    public static function json(array $data, int $status = 200): self
    {
        return new self($status, (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function redirect(string $location, array $cookies = []): self
    {
        return new self(303, '', ['Location' => $location], $cookies);
    }

    public function send(): void
    {
        http_response_code($this->status);
        $this->headers += [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options'        => 'DENY',
            'Referrer-Policy'        => 'no-referrer',
            'Cache-Control'          => 'no-store',
        ];
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        foreach ($this->cookies as $cookie) {
            setcookie($cookie['name'], $cookie['value'], $cookie['options']);
        }
        echo $this->body;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function status(): int
    {
        return $this->status;
    }
}
