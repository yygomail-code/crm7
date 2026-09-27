<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class ClientManagerRepository
{
    public function findByClient(int $clientId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT cm.client_id, cm.manager_id, cm.assigned_by, cm.assigned_at,
                    u.FULL_NAME AS manager_name, u.LEVEL AS manager_level, u.ACTIVE AS manager_active,
                    a.FULL_NAME AS assigned_by_name
             FROM client_managers cm
             INNER JOIN users u ON u.ID = cm.manager_id
             LEFT JOIN users a ON a.ID = cm.assigned_by
             WHERE cm.client_id = ?
             LIMIT 1'
        );
        $stmt->execute([$clientId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function managerIdFor(int $clientId): ?int
    {
        $stmt = Database::pdo()->prepare('SELECT manager_id FROM client_managers WHERE client_id = ? LIMIT 1');
        $stmt->execute([$clientId]);
        $value = $stmt->fetchColumn();

        return $value !== false && $value !== null ? (int) $value : null;
    }

    public function isManagerOf(int $clientId, int $managerId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM client_managers WHERE client_id = ? AND manager_id = ? LIMIT 1'
        );
        $stmt->execute([$clientId, $managerId]);

        return $stmt->fetchColumn() !== false;
    }

    public function forClients(array $clientIds): array
    {
        $clientIds = array_values(array_unique(array_map('intval', $clientIds)));

        if ($clientIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
        $stmt = Database::pdo()->prepare(
            'SELECT cm.client_id, cm.manager_id, cm.assigned_by, cm.assigned_at,
                    u.FULL_NAME AS manager_name, u.LEVEL AS manager_level, u.ACTIVE AS manager_active
             FROM client_managers cm
             INNER JOIN users u ON u.ID = cm.manager_id
             WHERE cm.client_id IN (' . $placeholders . ')'
        );
        $stmt->execute($clientIds);

        $result = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $result[(int) $row['client_id']] = $row;
        }

        return $result;
    }

    public function clientIdsForManager(int $managerId): array
    {
        $stmt = Database::pdo()->prepare('SELECT client_id FROM client_managers WHERE manager_id = ? LIMIT 1000');
        $stmt->execute([$managerId]);

        return array_map('intval', array_column($stmt->fetchAll() ?: [], 'client_id'));
    }

    public function assign(int $clientId, int $managerId, ?int $assignedBy): void
    {
        Database::pdo()->prepare(
            'INSERT INTO client_managers (client_id, manager_id, assigned_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE manager_id = VALUES(manager_id),
                                     assigned_by = VALUES(assigned_by),
                                     assigned_at = CURRENT_TIMESTAMP'
        )->execute([$clientId, $managerId, $assignedBy]);
    }

    public function unbind(int $clientId): void
    {
        Database::pdo()->prepare('DELETE FROM client_managers WHERE client_id = ?')->execute([$clientId]);
    }

    public function lockClient(int $clientId): void
    {
        $stmt = Database::pdo()->prepare('SELECT ID FROM users WHERE ID = ? FOR UPDATE');
        $stmt->execute([$clientId]);
    }

    public function createTransfer(array $data): int
    {
        Database::pdo()->prepare(
            'INSERT INTO client_transfers
                (client_id, from_manager_id, to_manager_id, date_from, date_to, status, comment, created_by, started_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (int) $data['client_id'],
            $data['from_manager_id'] !== null ? (int) $data['from_manager_id'] : null,
            $data['to_manager_id'] !== null ? (int) $data['to_manager_id'] : null,
            (string) $data['date_from'],
            $data['date_to'] !== null && $data['date_to'] !== '' ? (string) $data['date_to'] : null,
            (string) $data['status'],
            $data['comment'] !== null && $data['comment'] !== '' ? mb_substr((string) $data['comment'], 0, 500) : null,
            (int) $data['created_by'],
            $data['started_at'] !== null && $data['started_at'] !== '' ? (string) $data['started_at'] : null,
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    public function findTransfer(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT t.*, c.FULL_NAME AS client_name,
                    f.FULL_NAME AS from_name, tm.FULL_NAME AS to_name, cb.FULL_NAME AS created_by_name
             FROM client_transfers t
             INNER JOIN users c ON c.ID = t.client_id
             LEFT JOIN users f ON f.ID = t.from_manager_id
             LEFT JOIN users tm ON tm.ID = t.to_manager_id
             INNER JOIN users cb ON cb.ID = t.created_by
             WHERE t.ID = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function findTransferForUpdate(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM client_transfers WHERE ID = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function currentForClient(int $clientId): ?array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT t.*, f.FULL_NAME AS from_name, tm.FULL_NAME AS to_name, cb.FULL_NAME AS created_by_name
             FROM client_transfers t
             LEFT JOIN users f ON f.ID = t.from_manager_id
             LEFT JOIN users tm ON tm.ID = t.to_manager_id
             INNER JOIN users cb ON cb.ID = t.created_by
             WHERE t.client_id = ?
               AND (t.status IN ('pending', 'scheduled') OR (t.status = 'active' AND t.date_to IS NOT NULL))
             ORDER BY t.ID DESC
             LIMIT 1"
        );
        $stmt->execute([$clientId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function pendingForManager(int $managerId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT t.*, c.FULL_NAME AS client_name, f.FULL_NAME AS from_name
             FROM client_transfers t
             INNER JOIN users c ON c.ID = t.client_id
             LEFT JOIN users f ON f.ID = t.from_manager_id
             WHERE t.to_manager_id = ? AND t.status = ?
             ORDER BY t.ID DESC
             LIMIT 200'
        );
        $stmt->execute([$managerId, 'pending']);

        return $stmt->fetchAll() ?: [];
    }

    public function pendingMapForManager(int $managerId): array
    {
        $result = [];

        foreach ($this->pendingForManager($managerId) as $row) {
            $result[(int) $row['client_id']] = $row;
        }

        return $result;
    }

    public function setTransferStatus(int $id, string $status, ?int $decidedBy): void
    {
        Database::pdo()->prepare(
            'UPDATE client_transfers SET status = ?, decided_by = ?, decided_at = NOW() WHERE ID = ?'
        )->execute([$status, $decidedBy, $id]);
    }

    public function markStarted(int $id): void
    {
        Database::pdo()->prepare(
            "UPDATE client_transfers SET status = 'active', started_at = NOW() WHERE ID = ?"
        )->execute([$id]);
    }

    public function cancelOpen(int $clientId, int $exceptId = 0, ?int $decidedBy = null): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT t.*, tm.FULL_NAME AS to_name FROM client_transfers t
             LEFT JOIN users tm ON tm.ID = t.to_manager_id
             WHERE t.client_id = ? AND t.status IN ('pending', 'scheduled') AND t.ID <> ?"
        );
        $stmt->execute([$clientId, $exceptId]);
        $rows = $stmt->fetchAll() ?: [];

        foreach ($rows as $row) {
            $this->setTransferStatus((int) $row['ID'], 'cancelled', $decidedBy);
        }

        return $rows;
    }

    public function scheduledDue(): array
    {
        $stmt = Database::pdo()->query(
            "SELECT * FROM client_transfers
             WHERE status = 'scheduled' AND date_from IS NOT NULL AND date_from <= CURDATE()
             ORDER BY ID ASC
             LIMIT 200"
        );

        return $stmt->fetchAll() ?: [];
    }

    public function expiredTemporary(): array
    {
        $stmt = Database::pdo()->query(
            "SELECT * FROM client_transfers
             WHERE status = 'active' AND date_to IS NOT NULL AND date_to < CURDATE()
             ORDER BY ID ASC
             LIMIT 200"
        );

        return $stmt->fetchAll() ?: [];
    }

    public function activeTemporaryForClient(int $clientId): ?array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM client_transfers
             WHERE client_id = ? AND status = 'active' AND date_to IS NOT NULL AND date_to >= CURDATE()
             ORDER BY ID DESC
             LIMIT 1"
        );
        $stmt->execute([$clientId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }
}
