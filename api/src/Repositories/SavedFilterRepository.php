<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class SavedFilterRepository
{
    public function listForUser(int $userId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT ID AS id, name, params_json, created_at FROM saved_filters WHERE user_id = ? ORDER BY ID DESC LIMIT 50'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll() ?: [];
    }

    public function create(int $userId, string $name, array $params): int
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare('INSERT INTO saved_filters (user_id, name, params_json) VALUES (?, ?, ?)');
        $stmt->execute([$userId, mb_substr($name, 0, 100), (string) json_encode($params, JSON_UNESCAPED_UNICODE)]);

        return (int) $pdo->lastInsertId();
    }

    public function delete(int $id, int $userId): bool
    {
        $stmt = Database::pdo()->prepare('DELETE FROM saved_filters WHERE ID = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);

        return $stmt->rowCount() > 0;
    }
}
