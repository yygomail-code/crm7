<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class RequestCommentRepository
{
    public function add(int $requestId, int $userId, string $body, bool $isInternal): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO request_comments (request_id, user_id, body, is_internal) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$requestId, $userId, $body, $isInternal ? 1 : 0]);
    }

    public function listByRequest(int $requestId, bool $includeInternal): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT c.ID AS id, c.user_id, c.body, c.is_internal, c.created_at,
                    u.FULL_NAME AS user_name, u.LEVEL AS user_level
             FROM request_comments c
             INNER JOIN users u ON u.ID = c.user_id
             WHERE c.request_id = ? AND (c.is_internal = 0 OR ? = 1)
             ORDER BY c.created_at ASC, c.ID ASC'
        );
        $stmt->execute([$requestId, $includeInternal ? 1 : 0]);

        return $stmt->fetchAll() ?: [];
    }
}
