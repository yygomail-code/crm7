<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class UserHistoryRepository
{
    public function add(int $userId, string $event, ?int $actorId = null, ?string $comment = null): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO user_history (user_id, event, actor_id, comment) VALUES (?, ?, ?, ?)'
        );

        $stmt->execute([
            $userId,
            $event,
            $actorId,
            $comment !== null && trim($comment) !== '' ? mb_substr(trim($comment), 0, 500) : null,
        ]);
    }

    public function listByUser(int $userId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT h.ID AS id, h.event, h.comment, h.created_at, h.actor_id,
                    a.FULL_NAME AS actor_name, a.LEVEL AS actor_level
             FROM user_history h
             LEFT JOIN users a ON a.ID = h.actor_id
             WHERE h.user_id = ?
             ORDER BY h.ID DESC'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll() ?: [];
    }
}
