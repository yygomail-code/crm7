<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class ItemGroupRepository
{
    public function all(): array
    {
        $rows = Database::pdo()->query(
            'SELECT g.ID AS id, g.TITLE AS title, g.SORT AS sort,
                    (SELECT COUNT(*) FROM nomenclature n WHERE n.group_id = g.ID) AS positions_count
             FROM item_groups g
             ORDER BY g.SORT ASC, g.ID ASC'
        )->fetchAll() ?: [];

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'sort' => (int) $row['sort'],
            'positions_count' => (int) $row['positions_count'],
        ], $rows);
    }

    public function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT ID AS id, TITLE AS title, SORT AS sort FROM item_groups WHERE ID = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'sort' => (int) $row['sort'],
        ];
    }

    public function titleTaken(string $title, ?int $excludeId = null): bool
    {
        $sql = 'SELECT ID FROM item_groups WHERE TITLE = ?';
        $params = [$title];

        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND ID <> ?';
            $params[] = $excludeId;
        }

        $stmt = Database::pdo()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        return $stmt->fetch() !== false;
    }

    public function nextSort(): int
    {
        $value = Database::pdo()->query('SELECT COALESCE(MAX(SORT), 0) + 10 FROM item_groups')->fetchColumn();

        return (int) $value;
    }

    public function create(string $title, int $sort): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('INSERT INTO item_groups (TITLE, SORT) VALUES (?, ?)');
        $stmt->execute([$title, $sort]);

        return (int) $pdo->lastInsertId();
    }

    public function update(int $id, string $title): void
    {
        $stmt = Database::pdo()->prepare('UPDATE item_groups SET TITLE = ? WHERE ID = ?');
        $stmt->execute([$title, $id]);
    }

    public function delete(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM item_groups WHERE ID = ?')->execute([$id]);
    }

    public function clearAssignments(int $id): void
    {
        Database::pdo()->prepare('UPDATE nomenclature SET group_id = NULL WHERE group_id = ?')->execute([$id]);
    }
}
