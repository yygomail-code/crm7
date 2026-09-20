<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class ResetRepository
{
    public function create(int $userId, string $tokenHash, int $ttlMinutes): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))'
        );
        $stmt->execute([$userId, $tokenHash, $ttlMinutes]);
    }

    public function findActiveByHash(string $tokenHash): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT ID AS id, user_id, token_hash, created_at, expires_at, used_at
             FROM password_resets
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([$tokenHash]);

        return $stmt->fetch() ?: null;
    }

    public function markUsed(int $id): void
    {
        $stmt = Database::pdo()->prepare('UPDATE password_resets SET used_at = NOW() WHERE ID = ?');
        $stmt->execute([$id]);
    }

    public function invalidateForUser(int $userId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL'
        );
        $stmt->execute([$userId]);
    }
}
