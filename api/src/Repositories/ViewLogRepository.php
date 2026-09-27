<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class ViewLogRepository
{
    public function add(
        string $entityType,
        int $entityId,
        int $userId,
        string $action,
        ?string $meta = null,
        ?int $resultCount = null
    ): void {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO view_log (entity_type, entity_id, user_id, action, meta, result_count)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $entityType,
            $entityId,
            $userId,
            $action,
            $meta !== null && $meta !== '' ? mb_substr($meta, 0, 255) : null,
            $resultCount,
        ]);
    }

    public function recentExists(string $entityType, int $entityId, int $userId, string $action, int $minutes): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM view_log
             WHERE entity_type = ? AND entity_id = ? AND user_id = ? AND action = ?
               AND created_at > (NOW() - INTERVAL ? MINUTE)
             LIMIT 1'
        );
        $stmt->execute([$entityType, $entityId, $userId, $action, $minutes]);

        return $stmt->fetchColumn() !== false;
    }

    public function listForEntity(string $entityType, int $entityId, int $limit = 30): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT v.ID AS id, v.action, v.meta, v.created_at, v.user_id,
                    u.FULL_NAME AS user_name, u.LEVEL AS user_level
             FROM view_log v
             INNER JOIN users u ON u.ID = v.user_id
             WHERE v.entity_type = ? AND v.entity_id = ?
             ORDER BY v.ID DESC
             LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute([$entityType, $entityId]);

        return $stmt->fetchAll() ?: [];
    }
}
