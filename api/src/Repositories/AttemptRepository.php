<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class AttemptRepository
{
    public function countRecent(string $type, string $login, string $ip, int $minutes, bool $onlyFailed = true): int
    {
        $sql = 'SELECT COUNT(*) FROM login_attempts
                WHERE type = ? AND login = ? AND ip = ? AND created_at > (NOW() - INTERVAL ? MINUTE)';

        if ($onlyFailed) {
            $sql .= ' AND success = 0';
        }

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([$type, $login, $ip, $minutes]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Неудачные попытки по конкретному логину (с любых IP) — защита аккаунта.
     */
    public function countRecentForLogin(string $type, string $login, int $minutes, bool $onlyFailed = true): int
    {
        $sql = 'SELECT COUNT(*) FROM login_attempts
                WHERE type = ? AND login = ? AND created_at > (NOW() - INTERVAL ? MINUTE)';

        if ($onlyFailed) {
            $sql .= ' AND success = 0';
        }

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([$type, $login, $minutes]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Неудачные попытки с конкретного IP (по любым логинам) — защита от перебора.
     */
    public function countRecentForIp(string $type, string $ip, int $minutes, bool $onlyFailed = true): int
    {
        $sql = 'SELECT COUNT(*) FROM login_attempts
                WHERE type = ? AND ip = ? AND created_at > (NOW() - INTERVAL ? MINUTE)';

        if ($onlyFailed) {
            $sql .= ' AND success = 0';
        }

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([$type, $ip, $minutes]);

        return (int) $stmt->fetchColumn();
    }

    public function record(string $type, string $login, string $ip, bool $success): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO login_attempts (type, login, ip, success) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$type, $login, $ip, $success ? 1 : 0]);
    }

    public function clear(string $type, string $login, string $ip): void
    {
        $stmt = Database::pdo()->prepare(
            'DELETE FROM login_attempts WHERE type = ? AND login = ? AND ip = ?'
        );
        $stmt->execute([$type, $login, $ip]);
    }
}
