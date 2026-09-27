<?php

declare(strict_types=1);

namespace App\Core;

final class Logger
{
    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function dir(): string
    {
        $configured = Config::get('LOG_DIR', '');

        if ($configured !== null && $configured !== '') {
            return $configured;
        }

        return dirname(__DIR__, 2) . '/var/log';
    }

    private static function write(string $level, string $message, array $context): void
    {
        $dir = self::dir();

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $line = sprintf(
            "[%s] %s %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : ''
        );

        file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
    }
}
