<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class AttachmentRepository
{
    public function add(
        int $requestId,
        int $userId,
        string $fileName,
        string $storagePath,
        string $mime,
        int $size
    ): int {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'INSERT INTO request_attachments (request_id, user_id, file_name, storage_path, mime, size)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$requestId, $userId, $fileName, $storagePath, $mime, $size]);

        return (int) $pdo->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM request_attachments WHERE ID = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function listByRequest(int $requestId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT a.ID AS id, a.user_id, a.file_name, a.mime, a.size, a.created_at,
                    u.FULL_NAME AS user_name
             FROM request_attachments a
             INNER JOIN users u ON u.ID = a.user_id
             WHERE a.request_id = ?
             ORDER BY a.created_at ASC, a.ID ASC'
        );
        $stmt->execute([$requestId]);

        return $stmt->fetchAll() ?: [];
    }

    public function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM request_attachments WHERE ID = ?');
        $stmt->execute([$id]);
    }
}
