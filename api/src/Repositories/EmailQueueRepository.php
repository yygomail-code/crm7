<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class EmailQueueRepository
{
    public function add(string $toEmail, string $subject, string $body): int
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'INSERT INTO email_queue (to_email, subject, body) VALUES (?, ?, ?)'
        );
        $stmt->execute([$toEmail, $subject, $body]);

        return (int) $pdo->lastInsertId();
    }

    public function pending(int $limit): array
    {
        $sql = 'SELECT ID AS id, to_email, subject, body, attempts
                FROM email_queue
                WHERE status = \'pending\' AND attempts < 3
                ORDER BY created_at ASC, ID ASC
                LIMIT ' . max(1, min(200, $limit));

        return Database::pdo()->query($sql)->fetchAll() ?: [];
    }

    public function markSent(int $id): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE email_queue SET status = \'sent\', sent_at = NOW(), attempts = attempts + 1, last_error = NULL
             WHERE ID = ?'
        );
        $stmt->execute([$id]);
    }

    public function markFailed(int $id, string $error): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE email_queue
             SET attempts = attempts + 1, last_error = ?, status = IF(attempts + 1 >= 3, \'failed\', \'pending\')
             WHERE ID = ?'
        );
        $stmt->execute([mb_substr($error, 0, 500), $id]);
    }

    public function recent(int $limit): array
    {
        $sql = 'SELECT ID AS id, to_email, subject, status, attempts, last_error, created_at, sent_at
                FROM email_queue
                ORDER BY ID DESC
                LIMIT ' . max(1, min(100, $limit));

        return Database::pdo()->query($sql)->fetchAll() ?: [];
    }
}
