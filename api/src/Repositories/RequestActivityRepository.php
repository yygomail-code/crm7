<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class RequestActivityRepository
{
    public function add(int $requestId, int $userId, string $typeCode, string $title, ?string $body, ?string $createdAt = null): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO request_activities (request_id, user_id, type_code, title, body, created_at)
             VALUES (?, ?, ?, ?, ?, COALESCE(?, NOW()))'
        );
        $stmt->execute([$requestId, $userId, $typeCode, $title, $body, $createdAt]);
    }

    public function listByRequest(int $requestId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT a.ID AS id, a.user_id, a.type_code, a.title, a.body, a.created_at,
                    u.FULL_NAME AS user_name, u.LEVEL AS user_level, u.DOLGNOST AS user_position,
                    t.audience AS audience
             FROM request_activities a
             INNER JOIN users u ON u.ID = a.user_id
             LEFT JOIN activity_types t ON t.code = a.type_code
             WHERE a.request_id = ?
             ORDER BY a.created_at ASC, a.ID ASC'
        );
        $stmt->execute([$requestId]);

        return $stmt->fetchAll() ?: [];
    }
}
