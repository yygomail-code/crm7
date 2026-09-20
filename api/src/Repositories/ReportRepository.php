<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class ReportRepository
{
    public function totals(string $scopeSql, array $scopeParams, string $from, string $to): array
    {
        $sql = 'SELECT
                  COALESCE(SUM(CASE WHEN r.created_at >= ? AND r.created_at < ? THEN 1 ELSE 0 END), 0) AS created_in_period,
                  COALESCE(SUM(CASE WHEN r.closed_at >= ? AND r.closed_at < ? THEN 1 ELSE 0 END), 0) AS closed_in_period,
                  COALESCE(SUM(CASE WHEN s.is_final = 0 THEN 1 ELSE 0 END), 0) AS open_now,
                  COALESCE(SUM(CASE WHEN s.is_final = 0 AND r.due_at IS NOT NULL AND r.due_at < NOW() THEN 1 ELSE 0 END), 0) AS overdue_now
                FROM requests r
                INNER JOIN request_statuses s ON s.code = r.status_id
                WHERE 1 = 1' . $scopeSql;

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute(array_merge([$from, $to, $from, $to], $scopeParams));
        $row = $stmt->fetch() ?: [];

        return [
            'created_in_period' => (int) ($row['created_in_period'] ?? 0),
            'closed_in_period' => (int) ($row['closed_in_period'] ?? 0),
            'open_now' => (int) ($row['open_now'] ?? 0),
            'overdue_now' => (int) ($row['overdue_now'] ?? 0),
        ];
    }

    public function byStatus(string $scopeSql, array $scopeParams, string $from, string $to): array
    {
        $sql = 'SELECT r.status_id, s.title, s.color, s.is_final, COUNT(*) AS cnt
                FROM requests r
                INNER JOIN request_statuses s ON s.code = r.status_id
                WHERE r.created_at >= ? AND r.created_at < ?' . $scopeSql . '
                GROUP BY r.status_id, s.title, s.color, s.is_final, s.sort
                ORDER BY s.sort ASC';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute(array_merge([$from, $to], $scopeParams));

        return $stmt->fetchAll() ?: [];
    }

    public function byManager(string $scopeSql, array $scopeParams, string $from, string $to): array
    {
        $sql = 'SELECT m.ID AS manager_id, m.FULL_NAME AS manager_name,
                  COALESCE(SUM(CASE WHEN r.created_at >= ? AND r.created_at < ? THEN 1 ELSE 0 END), 0) AS created_in_period,
                  COALESCE(SUM(CASE WHEN s.is_final = 0 THEN 1 ELSE 0 END), 0) AS open_now,
                  COALESCE(SUM(CASE WHEN r.closed_at >= ? AND r.closed_at < ? THEN 1 ELSE 0 END), 0) AS closed_in_period,
                  COALESCE(SUM(CASE WHEN s.is_final = 0 AND r.due_at IS NOT NULL AND r.due_at < NOW() THEN 1 ELSE 0 END), 0) AS overdue_now,
                  ROUND(AVG(CASE WHEN r.first_response_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, r.created_at, r.first_response_at) END)) AS avg_response_minutes,
                  ROUND(AVG(CASE WHEN r.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, r.created_at, r.resolved_at) END)) AS avg_resolution_minutes
                FROM requests r
                INNER JOIN request_statuses s ON s.code = r.status_id
                INNER JOIN users m ON m.ID = r.manager_id
                WHERE 1 = 1' . $scopeSql . '
                GROUP BY m.ID, m.FULL_NAME
                HAVING created_in_period > 0 OR open_now > 0 OR closed_in_period > 0
                ORDER BY created_in_period DESC, open_now DESC';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute(array_merge([$from, $to, $from, $to], $scopeParams));

        return $stmt->fetchAll() ?: [];
    }

    public function byClient(string $scopeSql, array $scopeParams, string $from, string $to, int $limit = 20): array
    {
        $sql = 'SELECT c.ID AS client_id, c.FULL_NAME AS client_name, c.COMPANY AS company,
                  COALESCE(SUM(CASE WHEN r.created_at >= ? AND r.created_at < ? THEN 1 ELSE 0 END), 0) AS created_in_period,
                  COALESCE(SUM(CASE WHEN s.is_final = 0 THEN 1 ELSE 0 END), 0) AS open_now,
                  COALESCE(SUM(CASE WHEN r.closed_at >= ? AND r.closed_at < ? THEN 1 ELSE 0 END), 0) AS closed_in_period,
                  COALESCE(SUM(CASE WHEN s.is_final = 0 AND r.due_at IS NOT NULL AND r.due_at < NOW() THEN 1 ELSE 0 END), 0) AS overdue_now,
                  MAX(r.created_at) AS last_request_at
                FROM requests r
                INNER JOIN request_statuses s ON s.code = r.status_id
                INNER JOIN users c ON c.ID = r.client_id
                WHERE 1 = 1' . $scopeSql . '
                GROUP BY c.ID, c.FULL_NAME, c.COMPANY
                HAVING created_in_period > 0 OR open_now > 0 OR closed_in_period > 0
                ORDER BY created_in_period DESC, open_now DESC
                LIMIT ' . max(1, min(100, $limit));

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute(array_merge([$from, $to, $from, $to], $scopeParams));

        return $stmt->fetchAll() ?: [];
    }

    public function stockSearches(string $from, string $to, int $limit = 20): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT meta, COUNT(*) AS cnt,
                    SUM(CASE WHEN result_count = 0 THEN 1 ELSE 0 END) AS zero_results
             FROM view_log
             WHERE entity_type = ? AND action = ? AND meta IS NOT NULL
               AND created_at >= ? AND created_at < ?
             GROUP BY meta
             ORDER BY cnt DESC, meta ASC
             LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute(['stocks', 'search', $from, $to]);

        return $stmt->fetchAll() ?: [];
    }

    public function stockActivityByWarehouse(string $from, string $to): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT v.entity_id AS warehouse_id, s.NAME AS warehouse_name,
                    SUM(CASE WHEN v.action = ? THEN 1 ELSE 0 END) AS views,
                    SUM(CASE WHEN v.action = ? THEN 1 ELSE 0 END) AS searches,
                    SUM(CASE WHEN v.action = ? THEN 1 ELSE 0 END) AS exports
             FROM view_log v
             LEFT JOIN stocks s ON s.ID = v.entity_id
             WHERE v.entity_type = ? AND v.created_at >= ? AND v.created_at < ?
             GROUP BY v.entity_id, s.NAME
             ORDER BY SUM(CASE WHEN v.action = ? THEN 1 ELSE 0 END)
                    + SUM(CASE WHEN v.action = ? THEN 1 ELSE 0 END)
                    + SUM(CASE WHEN v.action = ? THEN 1 ELSE 0 END) DESC
             LIMIT 50'
        );
        $stmt->execute(['list', 'search', 'export', 'stocks', $from, $to, 'list', 'search', 'export']);

        return $stmt->fetchAll() ?: [];
    }

    public function stockActivityByClient(string $from, string $to, ?array $managerIds, int $limit = 50): array
    {
        $where = '';
        $params = ['stocks', $from, $to];

        if ($managerIds !== null) {
            if ($managerIds === []) {
                return [];
            }

            $placeholders = implode(',', array_fill(0, count($managerIds), '?'));
            $where = ' AND EXISTS (SELECT 1 FROM manager_clients mc WHERE mc.client_id = v.user_id AND mc.manager_id IN (' . $placeholders . '))';

            foreach ($managerIds as $managerId) {
                $params[] = (int) $managerId;
            }
        }

        $stmt = Database::pdo()->prepare(
            'SELECT v.user_id AS client_id, u.FULL_NAME AS client_name, u.COMPANY AS company,
                    SUM(CASE WHEN v.action = ? THEN 1 ELSE 0 END) AS views,
                    SUM(CASE WHEN v.action = ? THEN 1 ELSE 0 END) AS searches,
                    SUM(CASE WHEN v.action = ? THEN 1 ELSE 0 END) AS exports,
                    MAX(v.created_at) AS last_activity_at
             FROM view_log v
             INNER JOIN users u ON u.ID = v.user_id
             WHERE v.entity_type = ? AND v.created_at >= ? AND v.created_at < ?' . $where . '
             GROUP BY v.user_id, u.FULL_NAME, u.COMPANY
             ORDER BY searches DESC, views DESC, last_activity_at DESC
             LIMIT ' . max(1, min(200, $limit))
        );
        $stmt->execute(array_merge(['list', 'search', 'export'], $params));

        return $stmt->fetchAll() ?: [];
    }

    public function stockRecentActivity(string $from, string $to, int $limit = 30): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT v.action, v.meta, v.result_count, v.created_at,
                    u.FULL_NAME AS user_name, u.LEVEL AS user_level,
                    s.NAME AS warehouse_name
             FROM view_log v
             INNER JOIN users u ON u.ID = v.user_id
             LEFT JOIN stocks s ON s.ID = v.entity_id
             WHERE v.entity_type = ? AND v.created_at >= ? AND v.created_at < ?
             ORDER BY v.ID DESC
             LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute(['stocks', $from, $to]);

        return $stmt->fetchAll() ?: [];
    }

    public function unassigned(string $scopeSql, array $scopeParams): int
    {
        $sql = 'SELECT COUNT(*)
                FROM requests r
                INNER JOIN request_statuses s ON s.code = r.status_id
                WHERE s.is_final = 0 AND r.manager_id IS NULL' . $scopeSql;

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($scopeParams);

        return (int) $stmt->fetchColumn();
    }

    public function dynamics(string $scopeSql, array $scopeParams, string $from, string $to): array
    {
        $created = $this->groupByDate('r.created_at', $scopeSql, $scopeParams, $from, $to);
        $closed = $this->groupByDate('r.closed_at', $scopeSql, $scopeParams, $from, $to);

        $dates = array_unique(array_merge(array_keys($created), array_keys($closed)));
        sort($dates);

        $result = [];

        foreach ($dates as $date) {
            $result[] = [
                'date' => $date,
                'created' => $created[$date] ?? 0,
                'closed' => $closed[$date] ?? 0,
            ];
        }

        return $result;
    }

    public function exportRows(string $scopeSql, array $scopeParams, string $from, string $to, int $limit = 5000): array
    {
        $sql = 'SELECT r.number, r.created_at, s.title AS status_title, r.priority,
                       c.FULL_NAME AS client_name, m.FULL_NAME AS manager_name,
                       r.subject, r.due_at, r.first_response_at, r.resolved_at, r.closed_at,
                       CASE WHEN s.is_final = 0 AND r.due_at IS NOT NULL AND r.due_at < NOW() THEN 1 ELSE 0 END AS is_overdue
                FROM requests r
                INNER JOIN request_statuses s ON s.code = r.status_id
                INNER JOIN users c ON c.ID = r.client_id
                LEFT JOIN users m ON m.ID = r.manager_id
                WHERE r.created_at >= ? AND r.created_at < ?' . $scopeSql . '
                ORDER BY r.created_at DESC
                LIMIT ' . max(1, min(20000, $limit));

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute(array_merge([$from, $to], $scopeParams));

        return $stmt->fetchAll() ?: [];
    }

    private function groupByDate(string $column, string $scopeSql, array $scopeParams, string $from, string $to): array
    {
        $sql = 'SELECT DATE(' . $column . ') AS d, COUNT(*) AS cnt
                FROM requests r
                WHERE ' . $column . ' >= ? AND ' . $column . ' < ?' . $scopeSql . '
                GROUP BY DATE(' . $column . ')';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute(array_merge([$from, $to], $scopeParams));

        $result = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $result[(string) $row['d']] = (int) $row['cnt'];
        }

        return $result;
    }
}
