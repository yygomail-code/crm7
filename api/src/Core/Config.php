<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static array $values = [];

    private static bool $loaded = false;

    public static function load(string $envFile): void
    {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;

        if (!is_file($envFile)) {
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            self::$values[trim($key)] = trim($value, " \t\"'");
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $env = getenv($key);

        if ($env !== false && $env !== '') {
            return $env;
        }

        return self::$values[$key] ?? $default;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);

        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }

    public static function demoMode(): bool
    {
        return filter_var(self::get('DEMO_MODE', 'false'), FILTER_VALIDATE_BOOL);
    }
}
