<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class SubstitutionRepository
{
    public function all(): array
    {
        return Database::pdo()->query(
            'SELECT s.*, m.FULL_NAME AS manager_name, r.FULL_NAME AS substitute_name
             FROM substitutions s
             INNER JOIN users m ON m.ID = s.manager_id
             INNER JOIN users r ON r.ID = s.substitute_id
             ORDER BY s.date_from DESC, s.ID DESC'
        )->fetchAll() ?: [];
    }

    public function forParticipant(int $userId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT s.*, m.FULL_NAME AS manager_name, r.FULL_NAME AS substitute_name
             FROM substitutions s
             INNER JOIN users m ON m.ID = s.manager_id
             INNER JOIN users r ON r.ID = s.substitute_id
             WHERE s.manager_id = ? OR s.substitute_id = ?
             ORDER BY s.date_from DESC, s.ID DESC'
        );
        $stmt->execute([$userId, $userId]);

        return $stmt->fetchAll() ?: [];
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT s.*, m.FULL_NAME AS manager_name, r.FULL_NAME AS substitute_name
             FROM substitutions s
             INNER JOIN users m ON m.ID = s.manager_id
             INNER JOIN users r ON r.ID = s.substitute_id
             WHERE s.ID = ? LIMIT 1'
        );
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'INSERT INTO substitutions (manager_id, substitute_id, date_from, date_to, reason, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, 1, ?)'
        );
        $stmt->execute([
            $data['manager_id'],
            $data['substitute_id'],
            $data['date_from'],
            $data['date_to'],
            $data['reason'],
            $data['created_by'],
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function hasOverlap(int $managerId, string $from, string $to): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM substitutions
             WHERE manager_id = ? AND is_active = 1 AND date_to >= CURDATE() AND date_from <= ? AND date_to >= ?
             LIMIT 1'
        );
        $stmt->execute([$managerId, $to, $from]);

        return $stmt->fetchColumn() !== false;
    }

    public function lockForManager(int $managerId): void
    {
        $stmt = Database::pdo()->prepare('SELECT ID FROM substitutions WHERE manager_id = ? FOR UPDATE');
        $stmt->execute([$managerId]);
        $stmt->fetchAll();
    }

    public function activeForManager(int $managerId, ?string $date = null): ?array
    {
        $date ??= date('Y-m-d');

        $stmt = Database::pdo()->prepare(
            'SELECT * FROM substitutions
             WHERE manager_id = ? AND is_active = 1 AND date_from <= ? AND date_to >= ?
             ORDER BY ID DESC LIMIT 1'
        );
        $stmt->execute([$managerId, $date, $date]);

        return $stmt->fetch() ?: null;
    }

    public function activeSubstitutedIds(int $substituteId, ?string $date = null): array
    {
        $date ??= date('Y-m-d');

        $stmt = Database::pdo()->prepare(
            'SELECT manager_id FROM substitutions
             WHERE substitute_id = ? AND is_active = 1 AND date_from <= ? AND date_to >= ?'
        );
        $stmt->execute([$substituteId, $date, $date]);

        return array_map('intval', array_column($stmt->fetchAll() ?: [], 'manager_id'));
    }

    public function ended(): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM substitutions WHERE is_active = 1 AND date_to < CURDATE() ORDER BY ID ASC'
        );
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    public function deactivate(int $id): void
    {
        $stmt = Database::pdo()->prepare('UPDATE substitutions SET is_active = 0 WHERE ID = ?');
        $stmt->execute([$id]);
    }

    public function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM substitutions WHERE ID = ?');
        $stmt->execute([$id]);
    }

    public function requestsCount(int $substitutionId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM requests WHERE substitution_id = ?'
        );
        $stmt->execute([$substitutionId]);

        return (int) $stmt->fetchColumn();
    }
}
