<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use RuntimeException;

final class Crypto
{
    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';

        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($cipher === false) {
            throw new RuntimeException('Не удалось зашифровать значение');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $payload): ?string
    {
        $raw = base64_decode($payload, true);

        if ($raw === false || strlen($raw) < 29) {
            return null;
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);

        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? null : $plain;
    }

    private static function key(): string
    {
        $appKey = (string) Config::get('APP_KEY', '');

        if ($appKey === '') {
            throw new RuntimeException('APP_KEY не настроен');
        }

        return hash('sha256', $appKey, true);
    }
}
