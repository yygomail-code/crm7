<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly array $payload,
        public readonly array $headers = []
    ) {
    }

    public static function ok(mixed $data = null, int $status = 200): self
    {
        return new self($status, ['ok' => true, 'data' => $data]);
    }

    public static function error(string $code, string $message, int $status): self
    {
        return new self($status, [
            'ok' => false,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
