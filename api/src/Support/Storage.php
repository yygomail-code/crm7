<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use App\Http\HttpException;

final class Storage
{
    public static function baseDir(): string
    {
        $configured = Config::get('STORAGE_DIR', '');

        if ($configured !== null && $configured !== '') {
            return rtrim($configured, '/\\');
        }

        return dirname(__DIR__, 2) . '/storage';
    }

    public static function save(array $file, string $subdir = 'attachments'): array
    {
        $relativeDir = $subdir . '/' . date('Y/m');
        $dir = self::baseDir() . '/' . $relativeDir;

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new HttpException(500, 'storage_error', 'Не удалось сохранить файл');
        }

        $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $name = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');
        $absolute = $dir . '/' . $name;

        if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $absolute)) {
            throw new HttpException(500, 'storage_error', 'Не удалось сохранить файл');
        }

        return [
            'relative' => $relativeDir . '/' . $name,
            'absolute' => $absolute,
        ];
    }

    public static function absolute(string $relativePath): string
    {
        return self::baseDir() . '/' . $relativePath;
    }

    public static function delete(string $relativePath): void
    {
        $absolute = self::absolute($relativePath);

        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }
}
