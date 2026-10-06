<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class SearchLogRepository
{
    public function add(int $userId, string $query, int $results): void
    {
        $query = trim($query);

        if ($query === '') {
            return;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO search_log (user_id, query, results) VALUES (?, ?, ?)'
        );
        $stmt->execute([$userId, mb_substr($query, 0, 255), max(0, $results)]);
    }

    /**
     * @return array<int, array{query: string, searches: int, last_at: string}>
     */
    public function top(int $limit = 50): array
    {
        $stmt = Database::pdo()->query(
            'SELECT query, COUNT(*) AS searches, MAX(created_at) AS last_at
             FROM search_log
             GROUP BY query
             ORDER BY searches DESC, last_at DESC
             LIMIT ' . max(1, min(200, $limit))
        );

        return array_map(static fn (array $row): array => [
            'query' => (string) $row['query'],
            'searches' => (int) $row['searches'],
            'last_at' => (string) $row['last_at'],
        ], $stmt->fetchAll() ?: []);
    }

    /**
     * @return array<int, array{id: int, query: string, results: int, user_id: int, user_name: string, created_at: string}>
     */
    public function recent(int $limit = 100): array
    {
        $stmt = Database::pdo()->query(
            'SELECT s.id, s.query, s.results, s.created_at, s.user_id, u.FULL_NAME AS user_name
             FROM search_log s
             LEFT JOIN users u ON u.ID = s.user_id
             ORDER BY s.id DESC
             LIMIT ' . max(1, min(200, $limit))
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'query' => (string) $row['query'],
            'results' => (int) $row['results'],
            'user_id' => (int) $row['user_id'],
            'user_name' => (string) ($row['user_name'] ?? ''),
            'created_at' => (string) $row['created_at'],
        ], $stmt->fetchAll() ?: []);
    }

    /**
     * @return array{total: int, users: int, unique_queries: int}
     */
    public function stats(): array
    {
        $row = Database::pdo()->query(
            'SELECT COUNT(*) AS total,
                    COUNT(DISTINCT user_id) AS users,
                    COUNT(DISTINCT query) AS unique_queries
             FROM search_log'
        )->fetch() ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'users' => (int) ($row['users'] ?? 0),
            'unique_queries' => (int) ($row['unique_queries'] ?? 0),
        ];
    }
}
