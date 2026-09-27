<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class TokenRepository
{
    public function create(int $userId, string $tokenHash, string $ip, string $userAgent, int $ttlMinutes): string
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'INSERT INTO user_tokens (user_id, token_hash, ip, user_agent, expires_at)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))'
        );
        $stmt->execute([$userId, $tokenHash, $ip, $userAgent, $ttlMinutes]);

        $expiresAt = $pdo->query('SELECT expires_at FROM user_tokens WHERE ID = LAST_INSERT_ID()')
            ->fetchColumn();

        return (string) $expiresAt;
    }

    public function findActiveByHash(string $tokenHash): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT ID AS id, user_id, token_hash, ip, user_agent, created_at, last_used_at, expires_at, revoked_at
             FROM user_tokens
             WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([$tokenHash]);

        return $stmt->fetch() ?: null;
    }

    public function touch(int $id): void
    {
        $stmt = Database::pdo()->prepare('UPDATE user_tokens SET last_used_at = NOW() WHERE ID = ?');
        $stmt->execute([$id]);
    }

    public function revoke(int $id): void
    {
        $stmt = Database::pdo()->prepare('UPDATE user_tokens SET revoked_at = NOW() WHERE ID = ?');
        $stmt->execute([$id]);
    }

    public function revokeAllForUser(int $userId, ?int $exceptId = null): int
    {
        if ($exceptId !== null) {
            $stmt = Database::pdo()->prepare(
                'UPDATE user_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL AND ID != ?'
            );
            $stmt->execute([$userId, $exceptId]);

            return $stmt->rowCount();
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE user_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$userId]);

        return $stmt->rowCount();
    }
}
