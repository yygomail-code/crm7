<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class RequestActivityRepository
{
    public function add(int $requestId, int $userId, string $typeCode, string $title, ?string $body): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO request_activities (request_id, user_id, type_code, title, body)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$requestId, $userId, $typeCode, $title, $body]);
    }

    public function listByRequest(int $requestId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT a.ID AS id, a.user_id, a.type_code, a.title, a.body, a.created_at,
                    u.FULL_NAME AS user_name, u.LEVEL AS user_level
             FROM request_activities a
             INNER JOIN users u ON u.ID = a.user_id
             WHERE a.request_id = ?
             ORDER BY a.created_at ASC, a.ID ASC'
        );
        $stmt->execute([$requestId]);

        return $stmt->fetchAll() ?: [];
    }
}
