<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class LegalRepository
{
    public function all(): array
    {
        $stmt = Database::pdo()->query(
            'SELECT code, title, body, updated_at FROM legal_documents ORDER BY code ASC'
        );

        return $stmt->fetchAll() ?: [];
    }

    public function find(string $code): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT code, title, body, updated_at FROM legal_documents WHERE code = ? LIMIT 1'
        );
        $stmt->execute([$code]);

        return $stmt->fetch() ?: null;
    }

    public function save(string $code, string $title, string $body, int $userId): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO legal_documents (code, title, body, updated_by) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body), updated_by = VALUES(updated_by)'
        );
        $stmt->execute([$code, $title, $body, $userId]);
    }
}
