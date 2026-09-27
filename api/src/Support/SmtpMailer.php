<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class SmtpMailer
{
    private const TIMEOUT = 15;

    public function __construct(private readonly array $config)
    {
    }

    public function testConnection(): void
    {
        $socket = $this->connect();
        $this->command($socket, 'QUIT', 221);
        @fclose($socket);
    }

    public function send(
        string $from,
        string $fromName,
        string $to,
        string $subject,
        string $body,
        ?array $attachment = null
    ): void {
        $socket = $this->connect();

        try {
            $this->command($socket, 'MAIL FROM:<' . $from . '>', 250);
            $this->command($socket, 'RCPT TO:<' . $to . '>', 250);
            $this->command($socket, 'DATA', 354);

            $boundary = 'crm-' . bin2hex(random_bytes(12));
            $payload = $attachment === null
                ? $this->normalizeBody($body)
                : $this->attachmentBody($body, $attachment, $boundary);

            fwrite(
                $socket,
                $this->headers($from, $fromName, $to, $subject, $attachment, $boundary)
                . "\r\n"
                . $payload
                . "\r\n.\r\n"
            );

            $this->readResponse($socket, 250);
            $this->command($socket, 'QUIT', 221);
        } finally {
            @fclose($socket);
        }
    }

    private function connect()
    {
        $host = trim((string) ($this->config['host'] ?? ''));
        $port = (int) ($this->config['port'] ?? 465);
        $encryption = strtolower((string) ($this->config['encryption'] ?? 'ssl'));

        if ($host === '') {
            throw new RuntimeException('SMTP: не указан хост');
        }

        $prefix = $encryption === 'ssl' ? 'ssl://' : '';

        $socket = @stream_socket_client(
            $prefix . $host . ':' . $port,
            $errno,
            $errstr,
            self::TIMEOUT
        );

        if ($socket === false) {
            throw new RuntimeException('SMTP: не удалось подключиться к ' . $host . ':' . $port . ' (' . $errstr . ')');
        }

        stream_set_timeout($socket, self::TIMEOUT);
        $this->readResponse($socket, 220);

        $hostname = gethostname() ?: 'localhost';
        $this->command($socket, 'EHLO ' . $hostname, 250);

        if ($encryption === 'tls') {
            $this->command($socket, 'STARTTLS', 220);

            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP: не удалось включить TLS');
            }

            $this->command($socket, 'EHLO ' . $hostname, 250);
        }

        $username = (string) ($this->config['username'] ?? '');

        if ($username !== '') {
            $this->command($socket, 'AUTH LOGIN', 334);
            $this->command($socket, base64_encode($username), 334);
            $this->command($socket, base64_encode((string) ($this->config['password'] ?? '')), 235);
        }

        return $socket;
    }

    private function command($socket, string $command, int $expected): void
    {
        fwrite($socket, $command . "\r\n");
        $this->readResponse($socket, $expected);
    }

    private function readResponse($socket, int $expected): void
    {
        $response = '';

        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;

            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);

        if ($code !== $expected) {
            throw new RuntimeException('SMTP: неожиданный ответ ' . $code . ' ' . trim(substr($response, 0, 200)));
        }
    }

    private function headers(
        string $from,
        string $fromName,
        string $to,
        string $subject,
        ?array $attachment,
        string $boundary
    ): string {
        $fromHeader = $fromName !== ''
            ? '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>'
            : $from;

        $lines = [
            'Date: ' . date('D, d M Y H:i:s O'),
            'From: ' . $fromHeader,
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . (gethostname() ?: 'localhost') . '>',
            'MIME-Version: 1.0',
        ];

        if ($attachment === null) {
            $lines[] = 'Content-Type: text/plain; charset=UTF-8';
            $lines[] = 'Content-Transfer-Encoding: 8bit';
        } else {
            $lines[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        }

        return implode("\r\n", $lines);
    }

    private function attachmentBody(string $body, array $attachment, string $boundary): string
    {
        $fileName = (string) $attachment['file_name'];
        $asciiName = (string) preg_replace('/[^\x20-\x7E]/', '_', $fileName);

        $parts = [
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            $this->normalizeBody($body),
            '--' . $boundary,
            'Content-Type: ' . (string) $attachment['mime'] . '; name="' . $asciiName . '"',
            'Content-Transfer-Encoding: base64',
            'Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($fileName),
            '',
            rtrim(chunk_split(base64_encode((string) $attachment['content']), 76, "\r\n"), "\r\n"),
            '--' . $boundary . '--',
        ];

        return implode("\r\n", $parts);
    }

    private function normalizeBody(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        $lines = array_map(
            static fn (string $line): string => str_starts_with($line, '.') ? '.' . $line : $line,
            explode("\n", $body)
        );

        return implode("\r\n", $lines);
    }
}
