<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class RequestAssignmentRepository
{
    public function add(
        int $requestId,
        ?int $fromManagerId,
        ?int $toManagerId,
        int $userId,
        ?string $comment
    ): void {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO request_assignments (request_id, from_manager_id, to_manager_id, user_id, comment)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$requestId, $fromManagerId, $toManagerId, $userId, $comment]);
    }

    public function listByRequest(int $requestId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT a.ID AS id, a.from_manager_id, a.to_manager_id, a.comment, a.created_at,
                    a.user_id, u.FULL_NAME AS user_name,
                    fm.FULL_NAME AS from_name, tm.FULL_NAME AS to_name
             FROM request_assignments a
             INNER JOIN users u ON u.ID = a.user_id
             LEFT JOIN users fm ON fm.ID = a.from_manager_id
             LEFT JOIN users tm ON tm.ID = a.to_manager_id
             WHERE a.request_id = ?
             ORDER BY a.created_at ASC, a.ID ASC'
        );
        $stmt->execute([$requestId]);

        return $stmt->fetchAll() ?: [];
    }

    public function isParticipant(int $requestId, int $userId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM request_assignments
             WHERE request_id = ?
               AND (user_id = ? OR from_manager_id = ? OR to_manager_id = ?)
             LIMIT 1'
        );
        $stmt->execute([$requestId, $userId, $userId, $userId]);

        return $stmt->fetchColumn() !== false;
    }

    public function previousManager(int $requestId, int $currentManagerId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT fm.ID AS id, fm.FULL_NAME AS name
             FROM request_assignments a
             INNER JOIN users fm ON fm.ID = a.from_manager_id
             WHERE a.request_id = ? AND a.to_manager_id = ? AND a.from_manager_id IS NOT NULL
             ORDER BY a.created_at DESC, a.ID DESC
             LIMIT 1'
        );
        $stmt->execute([$requestId, $currentManagerId]);

        return $stmt->fetch() ?: null;
    }
}
