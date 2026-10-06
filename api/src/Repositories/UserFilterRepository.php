<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class UserFilterRepository
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(int $userId): array
    {
        $stmt = Database::pdo()->prepare('SELECT `key`, value FROM user_filters WHERE user_id = ?');
        $stmt->execute([$userId]);

        $result = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $decoded = json_decode((string) $row['value'], true);

            if (is_array($decoded)) {
                $result[(string) $row['key']] = $decoded;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $value
     */
    public function save(int $userId, string $key, array $value): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO user_filters (user_id, `key`, value) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        $stmt->execute([$userId, $key, (string) json_encode($value, JSON_UNESCAPED_UNICODE)]);
    }

    public function delete(int $userId, string $key): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM user_filters WHERE user_id = ? AND `key` = ?');
        $stmt->execute([$userId, $key]);
    }
}
