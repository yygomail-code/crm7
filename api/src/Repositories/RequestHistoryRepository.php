<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class RequestHistoryRepository
{
    public function add(int $requestId, int $userId, ?string $fromStatus, ?string $toStatus, ?string $comment): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO request_history (request_id, user_id, from_status_id, to_status_id, comment)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$requestId, $userId, $fromStatus, $toStatus, $comment]);
    }

    public function listByRequest(int $requestId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT h.ID AS id, h.user_id, h.from_status_id, h.to_status_id, h.comment, h.created_at,
                    u.FULL_NAME AS user_name, u.LEVEL AS user_level, u.DOLGNOST AS user_position,
                    fs.title AS from_title, ts.title AS to_title
             FROM request_history h
             INNER JOIN users u ON u.ID = h.user_id
             LEFT JOIN request_statuses fs ON fs.code = h.from_status_id
             LEFT JOIN request_statuses ts ON ts.code = h.to_status_id
             WHERE h.request_id = ?
             ORDER BY h.created_at ASC, h.ID ASC'
        );
        $stmt->execute([$requestId]);

        return $stmt->fetchAll() ?: [];
    }
}
