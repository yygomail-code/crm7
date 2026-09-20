<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use App\Core\Logger;

final class Mailer
{
    public static function send(string $to, string $subject, string $body): bool
    {
        $transport = Config::get('MAIL_TRANSPORT', 'log');

        if ($transport === 'log') {
            return self::sendToLog($to, $subject, $body);
        }

        Logger::error('Mailer transport is not configured', ['transport' => $transport]);

        return false;
    }

    private static function sendToLog(string $to, string $subject, string $body): bool
    {
        $dir = Logger::dir();

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $line = sprintf(
            "[%s] TO: %s\nSUBJECT: %s\n%s\n%s\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject,
            $body,
            str_repeat('-', 60)
        );

        return file_put_contents($dir . '/mail.log', $line, FILE_APPEND | LOCK_EX) !== false;
    }
}
