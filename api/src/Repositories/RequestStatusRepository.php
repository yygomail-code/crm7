<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class RequestStatusRepository
{
    public function all(bool $onlyActive = true): array
    {
        $sql = 'SELECT code, title, sort, color, is_final, is_active FROM request_statuses';

        if ($onlyActive) {
            $sql .= ' WHERE is_active = 1';
        }

        $sql .= ' ORDER BY sort ASC';

        return Database::pdo()->query($sql)->fetchAll() ?: [];
    }

    public function exists(string $code): bool
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM request_statuses WHERE code = ? AND is_active = 1');
        $stmt->execute([$code]);

        return $stmt->fetchColumn() !== false;
    }

    public function map(): array
    {
        $map = [];

        foreach ($this->all() as $row) {
            $map[(string) $row['code']] = $row;
        }

        return $map;
    }
}
