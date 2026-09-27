<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class MyReportRepository
{
    public function summary(int $userId, string $from, string $to, array $filters): array
    {
        [$where, $params] = $this->requestWhere($userId, $from, $to, $filters);

        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM requests r WHERE ' . $where);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $stmt = Database::pdo()->prepare(
            'SELECT
                SUM(CASE WHEN s.is_final = 1 THEN 1 ELSE 0 END) AS closed,
                SUM(CASE WHEN s.is_final = 0 AND r.due_at IS NOT NULL AND r.due_at < NOW() THEN 1 ELSE 0 END) AS overdue
             FROM requests r
             INNER JOIN request_statuses s ON s.code = r.status_id
             WHERE ' . $where
        );
        $stmt->execute($params);
        $totals = $stmt->fetch() ?: ['closed' => 0, 'overdue' => 0];

        $stmt = Database::pdo()->prepare(
            'SELECT r.status_id AS code, s.title, s.color, COUNT(*) AS cnt
             FROM requests r
             INNER JOIN request_statuses s ON s.code = r.status_id
             WHERE ' . $where . '
             GROUP BY r.status_id, s.title, s.color, s.sort
             ORDER BY s.sort ASC'
        );
        $stmt->execute($params);
        $byStatus = array_map(static fn (array $row): array => [
            'code' => (string) $row['code'],
            'title' => (string) $row['title'],
            'color' => (string) ($row['color'] ?? ''),
            'count' => (int) $row['cnt'],
        ], $stmt->fetchAll() ?: []);

        $stmt = Database::pdo()->prepare(
            'SELECT r.priority, COUNT(*) AS cnt FROM requests r WHERE ' . $where . ' GROUP BY r.priority ORDER BY r.priority ASC'
        );
        $stmt->execute($params);
        $byPriority = array_map(static fn (array $row): array => [
            'priority' => (int) $row['priority'],
            'count' => (int) $row['cnt'],
        ], $stmt->fetchAll() ?: []);

        [$items, $itemsQuantity] = $this->itemsTotals($userId, $from, $to, $filters);

        return [
            'requests' => [
                'total' => $total,
                'closed' => (int) ($totals['closed'] ?? 0),
                'overdue' => (int) ($totals['overdue'] ?? 0),
                'by_status' => $byStatus,
                'by_priority' => $byPriority,
            ],
            'items' => [
                'total' => $items,
                'quantity' => $itemsQuantity,
            ],
            'dynamics' => $this->dynamics($userId, $from, $to, $filters),
        ];
    }

    public function itemsTotals(int $userId, string $from, string $to, array $filters): array
    {
        [$join, $joinParams] = $this->itemJoin($filters);
        [$where, $whereParams] = $this->requestWhere($userId, $from, $to, $filters, false);

        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(ri.ID) AS cnt, COALESCE(SUM(ri.quantity), 0) AS qty
             FROM requests r' . $join . '
             WHERE ' . $where
        );
        $stmt->execute(array_merge($joinParams, $whereParams));
        $row = $stmt->fetch() ?: ['cnt' => 0, 'qty' => 0];

        return [(int) $row['cnt'], (float) $row['qty']];
    }

    public function dynamics(int $userId, string $from, string $to, array $filters): array
    {
        [$where, $params] = $this->requestWhere($userId, $from, $to, $filters);

        $stmt = Database::pdo()->prepare(
            'SELECT DATE(r.created_at) AS d, COUNT(*) AS cnt FROM requests r WHERE ' . $where . ' GROUP BY d ORDER BY d ASC'
        );
        $stmt->execute($params);
        $requests = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $requests[(string) $row['d']] = (int) $row['cnt'];
        }

        [$join, $joinParams] = $this->itemJoin($filters);
        [$whereItems, $whereItemsParams] = $this->requestWhere($userId, $from, $to, $filters, false);

        $stmt = Database::pdo()->prepare(
            'SELECT DATE(r.created_at) AS d, COUNT(ri.ID) AS cnt
             FROM requests r' . $join . '
             WHERE ' . $whereItems . '
             GROUP BY d ORDER BY d ASC'
        );
        $stmt->execute(array_merge($joinParams, $whereItemsParams));
        $items = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $items[(string) $row['d']] = (int) $row['cnt'];
        }

        $dates = array_unique(array_merge(array_keys($requests), array_keys($items)));
        sort($dates);

        return array_map(static fn (string $date): array => [
            'date' => $date,
            'requests' => $requests[$date] ?? 0,
            'items' => $items[$date] ?? 0,
        ], $dates);
    }

    public function warehouses(int $userId, string $from, string $to, array $filters): array
    {
        [$where, $params] = $this->requestWhere($userId, $from, $to, $filters, false);

        $stmt = Database::pdo()->prepare(
            'SELECT ri.warehouse_id, MAX(ri.warehouse_name) AS warehouse_name
             FROM requests r
             INNER JOIN request_items ri ON ri.request_id = r.ID
             WHERE ' . $where . "
               AND ri.warehouse_id IS NOT NULL
               AND ri.warehouse_name IS NOT NULL
               AND ri.warehouse_name <> ''
             GROUP BY ri.warehouse_id
             ORDER BY warehouse_name ASC
             LIMIT 100"
        );
        $stmt->execute($params);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['warehouse_id'],
            'name' => (string) ($row['warehouse_name'] ?? ''),
        ], $stmt->fetchAll() ?: []);
    }

    public function exportRequests(int $userId, string $from, string $to, array $filters): array
    {
        [$where, $params] = $this->requestWhere($userId, $from, $to, $filters);

        $stmt = Database::pdo()->prepare(
            'SELECT r.number, r.created_at, s.title AS status_title, r.priority, r.subject,
                    (SELECT COUNT(*) FROM request_items ri WHERE ri.request_id = r.ID) AS items_count,
                    CASE WHEN s.is_final = 0 AND r.due_at IS NOT NULL AND r.due_at < NOW() THEN 1 ELSE 0 END AS is_overdue
             FROM requests r
             INNER JOIN request_statuses s ON s.code = r.status_id
             WHERE ' . $where . '
             ORDER BY r.created_at DESC, r.ID DESC
             LIMIT 5000'
        );
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    public function exportItems(int $userId, string $from, string $to, array $filters): array
    {
        $joinParams = [];
        $join = ' INNER JOIN request_items ri ON ri.request_id = r.ID';

        if (!empty($filters['warehouse_id'])) {
            $join .= ' AND ri.warehouse_id = ?';
            $joinParams[] = (int) $filters['warehouse_id'];
        }

        if (($filters['item'] ?? '') !== '') {
            $join .= ' AND ri.name LIKE ?';
            $joinParams[] = '%' . $filters['item'] . '%';
        }

        [$where, $whereParams] = $this->requestWhere($userId, $from, $to, $filters, false);

        $stmt = Database::pdo()->prepare(
            'SELECT r.number, r.created_at, ri.warehouse_name, ri.name, ri.unit, ri.quantity,
                    s.title AS status_title, r.priority
             FROM requests r' . $join . '
             INNER JOIN request_statuses s ON s.code = r.status_id
             WHERE ' . $where . '
             ORDER BY r.created_at DESC, ri.ID ASC
             LIMIT 10000'
        );
        $stmt->execute(array_merge($joinParams, $whereParams));

        return $stmt->fetchAll() ?: [];
    }

    private function requestWhere(int $userId, string $from, string $to, array $filters, bool $withItemFilters = true): array
    {
        $where = ['r.client_id = ?', 'r.created_at BETWEEN ? AND ?'];
        $params = [$userId, $from . ' 00:00:00', $to . ' 23:59:59'];

        if (($filters['status'] ?? '') !== '') {
            $where[] = 'r.status_id = ?';
            $params[] = $filters['status'];
        }

        if (($filters['priority'] ?? 0) > 0) {
            $where[] = 'r.priority = ?';
            $params[] = (int) $filters['priority'];
        }

        if ($withItemFilters) {
            if (!empty($filters['warehouse_id'])) {
                $where[] = 'EXISTS (SELECT 1 FROM request_items ri WHERE ri.request_id = r.ID AND ri.warehouse_id = ?)';
                $params[] = (int) $filters['warehouse_id'];
            }

            if (($filters['item'] ?? '') !== '') {
                $where[] = 'EXISTS (SELECT 1 FROM request_items ri WHERE ri.request_id = r.ID AND ri.name LIKE ?)';
                $params[] = '%' . $filters['item'] . '%';
            }
        }

        return [implode(' AND ', $where), $params];
    }

    private function itemJoin(array $filters): array
    {
        $sql = ' LEFT JOIN request_items ri ON ri.request_id = r.ID';
        $params = [];

        if (!empty($filters['warehouse_id'])) {
            $sql .= ' AND ri.warehouse_id = ?';
            $params[] = (int) $filters['warehouse_id'];
        }

        if (($filters['item'] ?? '') !== '') {
            $sql .= ' AND ri.name LIKE ?';
            $params[] = '%' . $filters['item'] . '%';
        }

        return [$sql, $params];
    }
}
