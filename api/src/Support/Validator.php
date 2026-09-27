<?php

declare(strict_types=1);

namespace App\Support;

final class Validator
{
    public static function email(string $value): bool
    {
        return $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function password(string $value): ?string
    {
        if (mb_strlen($value) < 10) {
            return 'Пароль должен быть не короче 10 символов';
        }

        if (mb_strlen($value) > 200) {
            return 'Пароль слишком длинный';
        }

        if (preg_match('/[A-Za-zА-Яа-яЁё]/u', $value) !== 1 || preg_match('/\d/', $value) !== 1) {
            return 'Пароль должен содержать буквы и цифры';
        }

        return null;
    }
}
