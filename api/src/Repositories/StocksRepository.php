<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDOStatement;

final class StocksRepository
{
    public function warehousesForUser(int $level, string $sid, bool $all): array
    {
        $base = 'SELECT s.*,
                    (SELECT COUNT(*) FROM stock_levels l WHERE l.STOCK_SID = s.SID) AS positions,
                    (SELECT MAX(l.ACTUAL_DATE) FROM stock_levels l WHERE l.STOCK_SID = s.SID) AS actual_date,
                    EXISTS (
                        SELECT 1 FROM user_level_stock uls2
                        WHERE uls2.STOCK_SID = s.SID AND uls2.USER_SID = ? AND uls2.STATUS = ? AND uls2.ACTIVE = ?
                    ) AS personal
                 FROM stocks s
                 WHERE s.ACTIVE = ? AND s.STATUS = ?';

        if ($all) {
            $stmt = Database::pdo()->prepare($base . ' ORDER BY personal DESC, s.SORT ASC, s.NAME ASC');
            $stmt->execute([$sid, 'Y', 'Y', 'Y', 'Y']);

            return $stmt->fetchAll() ?: [];
        }

        $stmt = Database::pdo()->prepare(
            $base . ' AND (s.LEVEL LIKE ? OR EXISTS (
                        SELECT 1 FROM user_level_stock uls
                        WHERE uls.STOCK_SID = s.SID AND uls.USER_SID = ? AND uls.STATUS = ? AND uls.ACTIVE = ?
                     ))
                 ORDER BY personal DESC, s.SORT ASC, s.NAME ASC'
        );
        $stmt->execute([$sid, 'Y', 'Y', 'Y', 'Y', '%{' . $level . '}%', $sid, 'Y', 'Y']);

        return $stmt->fetchAll() ?: [];
    }

    public function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM stocks WHERE ID = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function levels(string $stockSid, array $filters, int $page, int $perPage): array
    {
        $params = [$stockSid];
        $where = 'STOCK_SID = ?' . $this->filterSql($filters, $params);

        $offset = max(0, ($page - 1) * $perPage);

        $stmt = Database::pdo()->prepare(
            'SELECT ID AS id, NAME AS name, UNIT AS unit, QUANTITY AS quantity, ACTUAL_DATE AS actual_date
             FROM stock_levels
             WHERE ' . $where . '
             ' . $this->orderSql((string) ($filters['sort'] ?? '')) . '
             LIMIT ' . max(1, min(200, $perPage)) . ' OFFSET ' . $offset
        );
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    public function levelsCount(string $stockSid, array $filters): int
    {
        $params = [$stockSid];
        $where = 'STOCK_SID = ?' . $this->filterSql($filters, $params);

        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM stock_levels WHERE ' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function searchCounts(array $stockSids, array $filters): array
    {
        if ($stockSids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($stockSids), '?'));
        $params = array_values($stockSids);
        $where = 'STOCK_SID IN (' . $placeholders . ')' . $this->filterSql($filters, $params);

        $stmt = Database::pdo()->prepare(
            'SELECT STOCK_SID, COUNT(*) AS cnt FROM stock_levels WHERE ' . $where . ' GROUP BY STOCK_SID'
        );
        $stmt->execute($params);

        $counts = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $counts[(string) $row['STOCK_SID']] = (int) $row['cnt'];
        }

        return $counts;
    }

    public function levelsAll(string $stockSid, array $filters, int $limit = 5000): array
    {
        $params = [$stockSid];
        $where = 'STOCK_SID = ?' . $this->filterSql($filters, $params);

        $stmt = Database::pdo()->prepare(
            'SELECT NAME AS name, UNIT AS unit, QUANTITY AS quantity, ACTUAL_DATE AS actual_date
             FROM stock_levels
             WHERE ' . $where . '
             ' . $this->orderSql((string) ($filters['sort'] ?? '')) . '
             LIMIT ' . max(1, min(20000, $limit))
        );
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    private function filterSql(array $filters, array &$params): string
    {
        $sql = '';

        if (($filters['q'] ?? '') !== '') {
            $sql .= ' AND NAME LIKE ?';
            $params[] = '%' . $filters['q'] . '%';
        }

        if (($filters['qty_op'] ?? '') !== '' && isset($filters['qty'])) {
            $sql .= ' AND QUANTITY ' . ($filters['qty_op'] === 'lt' ? '<' : '>') . ' ?';
            $params[] = $filters['qty'];
        }

        return $sql;
    }

    private function orderSql(string $sort): string
    {
        return match ($sort) {
            'name_desc' => 'ORDER BY NAME DESC',
            'qty_asc' => 'ORDER BY QUANTITY ASC, NAME ASC',
            'qty_desc' => 'ORDER BY QUANTITY DESC, NAME ASC',
            default => 'ORDER BY NAME ASC',
        };
    }

    public function upsertWarehouse(string $name): ?array
    {
        $pdo = Database::pdo();

        $pdo->prepare('INSERT IGNORE INTO stocks (NAME, NAME_1C, SORT) VALUES (?, ?, 500)')
            ->execute([$name, $name]);

        $stmt = $pdo->prepare('SELECT * FROM stocks WHERE NAME_1C = ? LIMIT 1');
        $stmt->execute([$name]);

        return $stmt->fetch() ?: null;
    }

    public function touchWarehouse(string $sid, string $updateSid): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE stocks SET LAST_ACTIVITY_DATE = NOW(), LAST_STOCK_UPDATE_SID = ? WHERE SID = ?'
        );
        $stmt->execute([$updateSid, $sid]);
    }

    public function upsertNomenclature(string $name, string $unit): ?array
    {
        $pdo = Database::pdo();

        $pdo->prepare('INSERT IGNORE INTO nomenclature (NAME, NAME_1C, UNIT) VALUES (?, ?, ?)')
            ->execute([$name, $name, $unit !== '' ? $unit : null]);

        $stmt = $pdo->prepare('SELECT * FROM nomenclature WHERE NAME_1C = ? LIMIT 1');
        $stmt->execute([$name]);

        return $stmt->fetch() ?: null;
    }

    public function insertUpdate(string $sid, string $actualDate, string $fileName): int
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            "INSERT INTO last_stock_update (SID, ACTUAL_DATE, FILE, STATUS_UPLOAD) VALUES (?, ?, ?, 'New')"
        );
        $stmt->execute([$sid, $actualDate, $fileName]);

        return (int) $pdo->lastInsertId();
    }

    public function deleteAllLevels(): void
    {
        Database::pdo()->exec('DELETE FROM stock_levels');
    }

    public function prepareLevelInsert(): PDOStatement
    {
        return Database::pdo()->prepare(
            'INSERT INTO stock_levels (LAST_STOCK_UPDATE_SID, ACTUAL_DATE, STOCK, STOCK_SID, NAME, NAME_SID, UNIT, QUANTITY)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
    }

    public function updates(int $limit = 50): array
    {
        $stmt = Database::pdo()->query(
            'SELECT u.ID AS id, u.SID AS sid, u.ACTUAL_DATE AS actual_date, u.FILE AS file,
                    u.STATUS_UPLOAD AS status, u.TIME_ADD AS created_at,
                    (SELECT COUNT(*) FROM stock_levels l WHERE l.LAST_STOCK_UPDATE_SID = u.SID) AS rows_count
             FROM last_stock_update u
             ORDER BY u.ID DESC
             LIMIT ' . max(1, min(200, $limit))
        );

        return $stmt->fetchAll() ?: [];
    }

    public function createJob(string $fileName, string $actualDate, int $userId): int
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            "INSERT INTO stock_import_jobs (FILE_NAME, ACTUAL_DATE, STATUS, user_id) VALUES (?, ?, 'processing', ?)"
        );
        $stmt->execute([$fileName, $actualDate, $userId]);

        return (int) $pdo->lastInsertId();
    }

    public function finishJob(int $jobId, string $status, int $total, int $imported, int $skipped, array $errors): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE stock_import_jobs
             SET STATUS = ?, ROWS_TOTAL = ?, ROWS_IMPORTED = ?, ROWS_SKIPPED = ?, ERRORS = ?, finished_at = NOW()
             WHERE ID = ?'
        );

        $stmt->execute([
            $status,
            $total,
            $imported,
            $skipped,
            $errors !== [] ? mb_substr(implode("\n", array_slice($errors, 0, 50)), 0, 4000) : null,
            $jobId,
        ]);
    }

    public function jobs(int $limit = 20): array
    {
        $stmt = Database::pdo()->query(
            'SELECT j.ID AS id, j.FILE_NAME AS file_name, j.ACTUAL_DATE AS actual_date, j.STATUS AS status,
                    j.ROWS_TOTAL AS rows_total, j.ROWS_IMPORTED AS rows_imported, j.ROWS_SKIPPED AS rows_skipped,
                    j.ERRORS AS errors, j.created_at, j.finished_at, u.FULL_NAME AS user_name
             FROM stock_import_jobs j
             LEFT JOIN users u ON u.ID = j.user_id
             ORDER BY j.ID DESC
             LIMIT ' . max(1, min(100, $limit))
        );

        return $stmt->fetchAll() ?: [];
    }
}
