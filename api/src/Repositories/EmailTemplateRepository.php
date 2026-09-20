<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class EmailTemplateRepository
{
    public function all(): array
    {
        return Database::pdo()->query(
            'SELECT code, subject, body, updated_at FROM email_templates ORDER BY code ASC'
        )->fetchAll() ?: [];
    }

    public function findByCode(string $code): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM email_templates WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);

        return $stmt->fetch() ?: null;
    }

    public function save(string $code, string $subject, string $body): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO email_templates (code, subject, body) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body)'
        );
        $stmt->execute([$code, $subject, $body]);
    }
}
