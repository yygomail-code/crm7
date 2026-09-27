<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class RequestRepository
{
    private const DETAIL_SELECT = 'SELECT r.*, s.title AS status_title, s.color AS status_color,
               s.is_final AS status_is_final, s.sort AS status_sort,
               CASE WHEN r.due_at IS NOT NULL AND r.due_at < NOW() AND s.is_final = 0 THEN 1 ELSE 0 END AS is_overdue,
               c.FULL_NAME AS client_name, c.EMAIL AS client_email, c.PHONE AS client_phone, c.INN AS client_inn,
               m.FULL_NAME AS manager_name,
               (SELECT COUNT(*) FROM request_items ri WHERE ri.request_id = r.ID) AS items_count
        FROM requests r
        INNER JOIN request_statuses s ON s.code = r.status_id
        INNER JOIN users c ON c.ID = r.client_id
        LEFT JOIN users m ON m.ID = r.manager_id';

    public function create(array $data): int
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'INSERT INTO requests (subject, body, client_id, manager_id, status_id, priority, source, due_at, substitution_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR), ?)'
        );

        $stmt->execute([
            $data['subject'],
            $data['body'],
            $data['client_id'],
            $data['manager_id'],
            $data['status_id'],
            $data['priority'],
            $data['source'],
            $data['sla_hours'],
            $data['substitution_id'] ?? null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function setNumber(int $id): string
    {
        $pdo = Database::pdo();

        $pdo->prepare(
            "UPDATE requests SET number = CONCAT('REQ-', YEAR(created_at), '-', LPAD(ID, 6, '0')) WHERE ID = ?"
        )->execute([$id]);

        $stmt = $pdo->prepare('SELECT number FROM requests WHERE ID = ?');
        $stmt->execute([$id]);

        return (string) $stmt->fetchColumn();
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM requests WHERE ID = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function findByIdForUpdate(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM requests WHERE ID = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function detail(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(self::DETAIL_SELECT . ' WHERE r.ID = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function applyTransition(int $id, string $status, array $timestampOps): void
    {
        $allowed = ['first_response_at', 'resolved_at', 'closed_at'];

        $sets = ['status_id = ?', 'version = version + 1', 'updated_at = NOW()'];
        $params = [$status];

        foreach ($timestampOps as $column => $op) {
            if (in_array($column, $allowed, true)) {
                $sets[] = $column . ' = ' . ($op === 'set' ? 'NOW()' : 'NULL');
            }
        }

        $params[] = $id;

        $stmt = Database::pdo()->prepare('UPDATE requests SET ' . implode(', ', $sets) . ' WHERE ID = ?');
        $stmt->execute($params);
    }

    public function assignManager(int $id, ?int $managerId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE requests SET manager_id = ?, version = version + 1, updated_at = NOW() WHERE ID = ?'
        );
        $stmt->execute([$managerId, $id]);
    }

    public function listForClient(int $clientId, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));

        $stmt = Database::pdo()->prepare(
            self::DETAIL_SELECT . ' WHERE r.client_id = ? ORDER BY r.ID DESC LIMIT ' . $limit
        );
        $stmt->execute([$clientId]);

        return $stmt->fetchAll() ?: [];
    }

    public function findBySubstitution(int $substitutionId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM requests WHERE substitution_id = ? ORDER BY ID ASC');
        $stmt->execute([$substitutionId]);

        return $stmt->fetchAll() ?: [];
    }

    public function openByManager(int $managerId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT r.* FROM requests r
             INNER JOIN request_statuses s ON s.code = r.status_id
             WHERE r.manager_id = ? AND s.is_final = 0 AND r.substitution_id IS NULL
             ORDER BY r.ID ASC'
        );
        $stmt->execute([$managerId]);

        return $stmt->fetchAll() ?: [];
    }

    public function openByClient(int $clientId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT r.* FROM requests r
             INNER JOIN request_statuses s ON s.code = r.status_id
             WHERE r.client_id = ? AND s.is_final = 0
             ORDER BY r.ID ASC'
        );
        $stmt->execute([$clientId]);

        return $stmt->fetchAll() ?: [];
    }

    public function applySubstitution(int $id, ?int $managerId, ?int $substitutionId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE requests SET manager_id = ?, substitution_id = ?, version = version + 1, updated_at = NOW() WHERE ID = ?'
        );
        $stmt->execute([$managerId, $substitutionId, $id]);
    }

    public function list(array $filters, array $scope, int $page, int $perPage): array
    {
        [$where, $params] = $this->scopeConditions($scope);

        if (!empty($filters['status'])) {
            $where[] = 'r.status_id = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['manager_id'])) {
            $where[] = 'r.manager_id = ?';
            $params[] = (int) $filters['manager_id'];
        }

        if (!empty($filters['client_id'])) {
            $where[] = 'r.client_id = ?';
            $params[] = (int) $filters['client_id'];
        }

        $state = (string) ($filters['state'] ?? '');

        if ($state === 'open') {
            $where[] = 's.is_final = 0';
        } elseif ($state === 'closed') {
            $where[] = 's.is_final = 1';
        } elseif ($state === 'overdue') {
            $where[] = 's.is_final = 0 AND r.due_at IS NOT NULL AND r.due_at < NOW()';
        }

        if (!empty($filters['warehouse_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM request_items ri WHERE ri.request_id = r.ID AND ri.warehouse_id = ?)';
            $params[] = (int) $filters['warehouse_id'];
        }

        if (!empty($filters['from'])) {
            $where[] = 'r.created_at >= ?';
            $params[] = (string) $filters['from'] . ' 00:00:00';
        }

        if (!empty($filters['to'])) {
            $where[] = 'r.created_at <= ?';
            $params[] = (string) $filters['to'] . ' 23:59:59';
        }

        if (!empty($filters['q'])) {
            $where[] = '(r.subject LIKE ? OR r.body LIKE ? OR r.number LIKE ? OR c.FULL_NAME LIKE ? OR c.PHONE LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $managerScope = (string) ($filters['manager_scope'] ?? '');
        $scopeUserId = (int) ($filters['manager_scope_user'] ?? 0);

        if ($scopeUserId > 0) {
            if ($managerScope === 'none') {
                $where[] = 'r.manager_id IS NULL AND s.is_final = 0';
            } elseif ($managerScope === 'mine') {
                $where[] = 'r.manager_id = ?';
                $params[] = $scopeUserId;
            } elseif ($managerScope === 'others') {
                $where[] = 'r.manager_id IS NOT NULL AND r.manager_id <> ?';
                $params[] = $scopeUserId;
            }
        }

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        $countStmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM requests r
             INNER JOIN request_statuses s ON s.code = r.status_id
             INNER JOIN users c ON c.ID = r.client_id' . $whereSql
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = max(0, ($page - 1) * $perPage);

        $sql = self::DETAIL_SELECT . $whereSql . $this->sortSql((string) ($filters['sort'] ?? '')) . ' LIMIT ' . $perPage . ' OFFSET ' . $offset;

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return ['items' => $stmt->fetchAll() ?: [], 'total' => $total];
    }

    public function activeManagerForClient(int $clientId): ?int
    {
        $stmt = Database::pdo()->prepare(
            "SELECT r.manager_id
             FROM requests r
             INNER JOIN request_statuses s ON s.code = r.status_id
             WHERE r.client_id = ? AND s.is_final = 0 AND r.manager_id IS NOT NULL
             ORDER BY r.ID DESC
             LIMIT 1"
        );
        $stmt->execute([$clientId]);
        $value = $stmt->fetchColumn();

        return $value !== false && $value !== null ? (int) $value : null;
    }

    public function clientInterests(int $clientId, string $query = '', string $sort = 'qty_desc', int $limit = 20, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        [$where, $params] = $this->interestsWhere($clientId, $query);

        $order = match ($sort) {
            'qty_asc' => 'total_qty ASC, ri.name ASC',
            'orders_desc' => 'orders DESC, ri.name ASC',
            'orders_asc' => 'orders ASC, ri.name ASC',
            'last_desc' => 'last_at DESC, ri.name ASC',
            'last_asc' => 'last_at ASC, ri.name ASC',
            'name_asc' => 'ri.name ASC, ri.unit ASC',
            'name_desc' => 'ri.name DESC, ri.unit DESC',
            default => 'total_qty DESC, ri.name ASC',
        };

        $stmt = Database::pdo()->prepare(
            "SELECT ri.name, ri.unit,
                    MAX(ri.description) AS description,
                    COUNT(*) AS orders,
                    SUM(ri.quantity) AS total_qty,
                    MAX(r.created_at) AS last_at,
                    SUBSTRING_INDEX(GROUP_CONCAT(ri.warehouse_name ORDER BY ri.quantity DESC), ',', 1) AS warehouse_name
             FROM request_items ri
             INNER JOIN requests r ON r.ID = ri.request_id
             WHERE " . $where . "
             GROUP BY ri.name, ri.unit
             ORDER BY " . $order . "
             LIMIT " . $limit . ' OFFSET ' . max(0, $offset)
        );
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    public function countClientInterests(int $clientId, string $query = ''): int
    {
        [$where, $params] = $this->interestsWhere($clientId, $query);

        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM (
                SELECT 1
                FROM request_items ri
                INNER JOIN requests r ON r.ID = ri.request_id
                WHERE ' . $where . '
                GROUP BY ri.name, ri.unit
             ) t'
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    private function interestsWhere(int $clientId, string $query): array
    {
        $where = "r.client_id = ? AND r.status_id <> 'canceled'";
        $params = [$clientId];

        if (trim($query) !== '') {
            $where .= ' AND ri.name LIKE ?';
            $params[] = '%' . trim($query) . '%';
        }

        return [$where, $params];
    }

    public function statsForClient(int $clientId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN s.is_final = 0 THEN 1 ELSE 0 END), 0) AS open_now,
                COALESCE(SUM(CASE WHEN s.is_final = 1 THEN 1 ELSE 0 END), 0) AS closed_now,
                COALESCE(SUM(CASE WHEN s.is_final = 0 AND r.due_at IS NOT NULL AND r.due_at < NOW() THEN 1 ELSE 0 END), 0) AS overdue
             FROM requests r
             INNER JOIN request_statuses s ON s.code = r.status_id
             WHERE r.client_id = ?'
        );
        $stmt->execute([$clientId]);
        $row = $stmt->fetch() ?: [];

        $byStatusStmt = Database::pdo()->prepare(
            'SELECT r.status_id, COUNT(*) AS cnt
             FROM requests r
             WHERE r.client_id = ?
             GROUP BY r.status_id'
        );
        $byStatusStmt->execute([$clientId]);

        $byStatus = [];

        foreach ($byStatusStmt->fetchAll() ?: [] as $statusRow) {
            $byStatus[(string) $statusRow['status_id']] = (int) $statusRow['cnt'];
        }

        return [
            'total' => (int) ($row['total'] ?? 0),
            'open' => (int) ($row['open_now'] ?? 0),
            'closed' => (int) ($row['closed_now'] ?? 0),
            'overdue' => (int) ($row['overdue'] ?? 0),
            'by_status' => $byStatus,
        ];
    }

    public function warehousesForScope(array $scope): array
    {
        [$where, $params] = $this->scopeConditions($scope);

        $conditions = $where;
        $conditions[] = 'ri.warehouse_id IS NOT NULL';
        $conditions[] = "ri.warehouse_name IS NOT NULL AND ri.warehouse_name <> ''";

        $stmt = Database::pdo()->prepare(
            'SELECT ri.warehouse_id, MAX(ri.warehouse_name) AS warehouse_name
             FROM request_items ri
             INNER JOIN requests r ON r.ID = ri.request_id
             WHERE ' . implode(' AND ', $conditions) . '
             GROUP BY ri.warehouse_id
             ORDER BY warehouse_name ASC
             LIMIT 100'
        );
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    public function createItems(int $requestId, array $items): void
    {
        if ($items === []) {
            return;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO request_items (request_id, warehouse_id, warehouse_name, stock_level_id, name, unit, description, quantity)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($items as $item) {
            $stmt->execute([
                $requestId,
                $item['warehouse_id'] ?? null,
                $item['warehouse_name'] ?? null,
                $item['stock_level_id'] ?? null,
                $item['name'],
                $item['unit'] ?? null,
                ($item['description'] ?? '') !== '' ? $item['description'] : null,
                $item['quantity'],
            ]);
        }
    }

    public function updateDetails(int $id, string $subject, string $body): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE requests SET subject = ?, body = ?, version = version + 1, updated_at = NOW() WHERE ID = ?'
        );
        $stmt->execute([$subject, $body, $id]);
    }

    public function updatePriority(int $id, int $priority): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE requests SET priority = ?, version = version + 1, updated_at = NOW() WHERE ID = ?'
        );
        $stmt->execute([$priority, $id]);
    }

    public function updateDueAt(int $id, ?string $dueAt): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE requests SET due_at = ?, version = version + 1, updated_at = NOW() WHERE ID = ?'
        );
        $stmt->execute([$dueAt, $id]);
    }

    public function replaceItems(int $requestId, array $items): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM request_items WHERE request_id = ?');
        $stmt->execute([$requestId]);

        $this->createItems($requestId, $items);

        $stmt = Database::pdo()->prepare('UPDATE requests SET version = version + 1, updated_at = NOW() WHERE ID = ?');
        $stmt->execute([$requestId]);
    }

    public function itemsForRequests(array $requestIds): array
    {
        if ($requestIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($requestIds), '?'));

        $stmt = Database::pdo()->prepare(
            'SELECT ID AS id, request_id, warehouse_id, warehouse_name, stock_level_id, name, unit, description, quantity
             FROM request_items
             WHERE request_id IN (' . $placeholders . ')
             ORDER BY ID ASC'
        );
        $stmt->execute(array_map('intval', $requestIds));

        $result = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $result[(int) $row['request_id']][] = [
                'id' => (int) $row['id'],
                'warehouse_id' => $row['warehouse_id'] !== null ? (int) $row['warehouse_id'] : null,
                'warehouse_name' => (string) ($row['warehouse_name'] ?? ''),
                'stock_level_id' => $row['stock_level_id'] !== null ? (int) $row['stock_level_id'] : null,
                'name' => (string) $row['name'],
                'unit' => (string) ($row['unit'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'quantity' => (float) $row['quantity'],
            ];
        }

        return $result;
    }

    private function sortSql(string $sort): string
    {
        return match ($sort) {
            'created_asc' => ' ORDER BY r.created_at ASC, r.ID ASC',
            'due_asc' => ' ORDER BY (r.due_at IS NULL) ASC, r.due_at ASC, r.ID DESC',
            'priority_desc' => ' ORDER BY r.priority DESC, r.created_at DESC, r.ID DESC',
            'status' => ' ORDER BY s.sort ASC, r.created_at DESC, r.ID DESC',
            default => ' ORDER BY r.created_at DESC, r.ID DESC',
        };
    }

    private function scopeConditions(array $scope): array
    {
        if (!isset($scope['participant_id'])) {
            return [[], []];
        }

        $condition = '(r.client_id = ? OR r.manager_id = ?';
        $params = [$scope['participant_id'], $scope['participant_id']];

        if (!empty($scope['include_unassigned'])) {
            $condition .= ' OR r.manager_id IS NULL';
        }

        if (!empty($scope['substituted_ids'])) {
            $placeholders = implode(',', array_fill(0, count($scope['substituted_ids']), '?'));
            $condition .= ' OR r.manager_id IN (' . $placeholders . ')';

            foreach ($scope['substituted_ids'] as $substitutedId) {
                $params[] = (int) $substitutedId;
            }
        }

        return [[$condition . ')'], $params];
    }

    public function summary(array $scope): array
    {
        $where = [];
        $params = [];

        if (isset($scope['participant_id'])) {
            $condition = '(r.client_id = ? OR r.manager_id = ?';

            if (!empty($scope['include_unassigned'])) {
                $condition .= ' OR r.manager_id IS NULL';
            }

            if (!empty($scope['substituted_ids'])) {
                $placeholders = implode(',', array_fill(0, count($scope['substituted_ids']), '?'));
                $condition .= ' OR r.manager_id IN (' . $placeholders . ')';
            }

            $where[] = $condition . ')';
            $params[] = $scope['participant_id'];
            $params[] = $scope['participant_id'];

            foreach ($scope['substituted_ids'] ?? [] as $substitutedId) {
                $params[] = (int) $substitutedId;
            }
        }

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $scopeAnd = $where !== [] ? ' AND ' . implode(' AND ', $where) : '';

        $stmt = Database::pdo()->prepare(
            'SELECT r.status_id, COUNT(*) AS cnt FROM requests r' . $whereSql . ' GROUP BY r.status_id'
        );
        $stmt->execute($params);

        $byStatus = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $byStatus[(string) $row['status_id']] = (int) $row['cnt'];
        }

        $overdueStmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM requests r
             INNER JOIN request_statuses s ON s.code = r.status_id
             WHERE s.is_final = 0 AND r.due_at IS NOT NULL AND r.due_at < NOW()' . $scopeAnd
        );
        $overdueStmt->execute($params);
        $overdue = (int) $overdueStmt->fetchColumn();

        $total = array_sum($byStatus);

        $finalStmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM requests r
             INNER JOIN request_statuses s ON s.code = r.status_id
             WHERE s.is_final = 1' . $scopeAnd
        );
        $finalStmt->execute($params);
        $closed = (int) $finalStmt->fetchColumn();

        return [
            'by_status' => $byStatus,
            'total' => $total,
            'open' => $total - $closed,
            'closed' => $closed,
            'overdue' => $overdue,
        ];
    }
}
