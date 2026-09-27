<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class ActivityTypeRepository
{
    public function all(bool $onlyActive = true): array
    {
        $sql = 'SELECT code, title, audience, sort, is_active FROM activity_types';

        if ($onlyActive) {
            $sql .= ' WHERE is_active = 1';
        }

        $sql .= ' ORDER BY audience ASC, sort ASC';

        return Database::pdo()->query($sql)->fetchAll() ?: [];
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
