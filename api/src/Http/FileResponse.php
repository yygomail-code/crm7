<?php

declare(strict_types=1);

namespace App\Http;

final class FileResponse
{
    public function __construct(
        private readonly string $absolutePath,
        private readonly string $downloadName,
        private readonly string $mime
    ) {
    }

    public function send(): void
    {
        if (!is_file($this->absolutePath)) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => ['code' => 'not_found', 'message' => 'Файл не найден']]);

            return;
        }

        header('Content-Type: ' . ($this->mime !== '' ? $this->mime : 'application/octet-stream'));
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($this->downloadName));
        header('Content-Length: ' . (string) filesize($this->absolutePath));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');

        readfile($this->absolutePath);
    }
}
