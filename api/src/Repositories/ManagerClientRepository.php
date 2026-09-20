<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class ManagerClientRepository
{
    public function primaryManagerForClient(int $clientId): ?int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT manager_id FROM manager_clients
             WHERE client_id = ?
             ORDER BY is_primary DESC, ID ASC
             LIMIT 1'
        );
        $stmt->execute([$clientId]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (int) $value : null;
    }

    public function clientIdsForManager(int $managerId): array
    {
        $stmt = Database::pdo()->prepare('SELECT client_id FROM manager_clients WHERE manager_id = ? LIMIT 500');
        $stmt->execute([$managerId]);

        return array_map('intval', array_column($stmt->fetchAll() ?: [], 'client_id'));
    }

    public function isManagerOf(int $clientId, int $managerId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM manager_clients WHERE client_id = ? AND manager_id = ? LIMIT 1'
        );
        $stmt->execute([$clientId, $managerId]);

        return $stmt->fetchColumn() !== false;
    }

    public function assign(int $clientId, int $managerId, int $assignedBy): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $pdo->prepare('UPDATE manager_clients SET is_primary = 0 WHERE client_id = ?')->execute([$clientId]);

            $pdo->prepare(
                'INSERT INTO manager_clients (client_id, manager_id, is_primary, assigned_by)
                 VALUES (?, ?, 1, ?)
                 ON DUPLICATE KEY UPDATE is_primary = 1, assigned_at = NOW(), assigned_by = VALUES(assigned_by)'
            )->execute([$clientId, $managerId, $assignedBy]);

            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public function forClients(array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
        $stmt = Database::pdo()->prepare(
            'SELECT mc.client_id, mc.manager_id, mc.is_primary, u.FULL_NAME AS manager_name
             FROM manager_clients mc
             INNER JOIN users u ON u.ID = mc.manager_id
             WHERE mc.client_id IN (' . $placeholders . ')
             ORDER BY mc.is_primary DESC, mc.ID ASC'
        );
        $stmt->execute(array_map('intval', $clientIds));

        $result = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $clientId = (int) $row['client_id'];

            if (isset($result[$clientId])) {
                continue;
            }

            $result[$clientId] = [
                'manager_id' => (int) $row['manager_id'],
                'manager_name' => (string) $row['manager_name'],
                'is_primary' => (bool) $row['is_primary'],
            ];
        }

        return $result;
    }
}
