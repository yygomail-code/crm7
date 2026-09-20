<?php

declare(strict_types=1);

namespace App\Http;

final class DownloadResponse
{
    public function __construct(
        private readonly string $content,
        private readonly string $fileName,
        private readonly string $mime
    ) {
    }

    public function send(): void
    {
        header('Content-Type: ' . $this->mime);
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($this->fileName));
        header('Content-Length: ' . strlen($this->content));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');

        echo $this->content;
    }
}
