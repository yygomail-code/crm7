<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class AuditRepository
{
    public function add(
        ?int $userId,
        string $action,
        ?string $entity,
        ?string $entityId,
        ?array $data,
        ?string $ip
    ): void {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO audit_log (user_id, action, entity, entity_id, data_json, ip) VALUES (?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $userId,
            $action,
            $entity,
            $entityId,
            $data !== null && $data !== [] ? mb_substr((string) json_encode($data, JSON_UNESCAPED_UNICODE), 0, 4000) : null,
            $ip,
        ]);
    }

    public function list(array $filters, int $page, int $perPage): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'a.user_id = ?';
            $params[] = (int) $filters['user_id'];
        }

        if (!empty($filters['action'])) {
            $where[] = 'a.action = ?';
            $params[] = (string) $filters['action'];
        }

        if (!empty($filters['entity'])) {
            $where[] = 'a.entity = ?';
            $params[] = (string) $filters['entity'];
        }

        if (!empty($filters['from'])) {
            $where[] = 'a.created_at >= ?';
            $params[] = (string) $filters['from'] . ' 00:00:00';
        }

        if (!empty($filters['to'])) {
            $where[] = 'a.created_at <= ?';
            $params[] = (string) $filters['to'] . ' 23:59:59';
        }

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $offset = max(0, ($page - 1) * $perPage);

        $stmt = Database::pdo()->prepare(
            'SELECT a.ID AS id, a.user_id, a.action, a.entity, a.entity_id, a.data_json, a.ip, a.created_at,
                    u.FULL_NAME AS user_name
             FROM audit_log a
             LEFT JOIN users u ON u.ID = a.user_id' . $whereSql . '
             ORDER BY a.ID DESC
             LIMIT ' . max(1, min(200, $perPage)) . ' OFFSET ' . $offset
        );
        $stmt->execute($params);

        $items = $stmt->fetchAll() ?: [];

        $countStmt = Database::pdo()->prepare('SELECT COUNT(*) FROM audit_log a' . $whereSql);
        $countStmt->execute($params);

        return ['items' => $items, 'total' => (int) $countStmt->fetchColumn()];
    }

    public function actions(): array
    {
        $stmt = Database::pdo()->query('SELECT DISTINCT action FROM audit_log ORDER BY action ASC LIMIT 100');

        return array_map('strval', array_column($stmt->fetchAll() ?: [], 'action'));
    }
}
