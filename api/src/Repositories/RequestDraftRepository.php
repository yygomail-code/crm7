<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class RequestDraftRepository
{
    public function listForUser(int $userId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT ID AS id, client_id, subject, body, priority, items, created_at, updated_at
             FROM request_drafts
             WHERE user_id = ?
             ORDER BY updated_at DESC, ID DESC
             LIMIT 100'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll() ?: [];
    }

    public function findForUser(int $id, int $userId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT ID AS id, user_id, client_id, subject, body, priority, items, created_at, updated_at
             FROM request_drafts
             WHERE ID = ? AND user_id = ?'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function create(int $userId, array $data): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO request_drafts (user_id, client_id, subject, body, priority, items)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $data['client_id'],
            $data['subject'],
            $data['body'],
            $data['priority'],
            $data['items'],
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE request_drafts
             SET client_id = ?, subject = ?, body = ?, priority = ?, items = ?, updated_at = NOW()
             WHERE ID = ?'
        );
        $stmt->execute([
            $data['client_id'],
            $data['subject'],
            $data['body'],
            $data['priority'],
            $data['items'],
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM request_drafts WHERE ID = ?');
        $stmt->execute([$id]);
    }
}
