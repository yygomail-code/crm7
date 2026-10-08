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
                    (SELECT COUNT(*) FROM stock_levels l WHERE l.STOCK_SID = s.SID AND l.QUANTITY > 0) AS positions,
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

    public function levels(array $stockSids, array $filters, int $page, int $perPage, ?int $priceTypeId = null): array
    {
        if ($stockSids === []) {
            return [];
        }

        $sort = (string) ($filters['sort'] ?? '');
        $priceJoin = '';

        if (str_contains($sort, 'price_')) {
            if ($priceTypeId !== null) {
                $priceJoin = 'LEFT JOIN nomenclature_prices np ON np.stock_sid = l.STOCK_SID
                    AND np.name_sid = l.NAME_SID AND np.price_type_id = ' . (int) $priceTypeId;
            } else {
                $sort = $this->stripPriceSort($sort);
            }
        }

        $placeholders = implode(',', array_fill(0, count($stockSids), '?'));
        $params = array_values($stockSids);
        $where = 'l.STOCK_SID IN (' . $placeholders . ')' . $this->filterSql($filters, $params);

        $limit = $perPage <= 0 ? 100000 : max(1, min(200, $perPage));
        $offset = $perPage <= 0 ? 0 : max(0, ($page - 1) * $perPage);

        $stmt = Database::pdo()->prepare(
            'SELECT l.ID AS id, l.NAME AS name, l.NAME_SID AS name_sid, l.UNIT AS unit, l.QUANTITY AS quantity,
                    l.ACTUAL_DATE AS actual_date, l.STOCK_SID AS stock_sid, s.NAME AS stock_name,
                    n.DESCRIPTION AS description, n.ACTIVE AS active,
                    n.group_id AS group_id, g.TITLE AS group_title
             FROM stock_levels l
             LEFT JOIN nomenclature n ON n.SID = l.NAME_SID
             LEFT JOIN item_groups g ON g.ID = n.group_id
             JOIN stocks s ON s.SID = l.STOCK_SID
             ' . $priceJoin . '
             WHERE ' . $where . '
             ' . $this->orderSql($sort) . '
             LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    public function nameSidForLevel(int $id): ?string
    {
        $stmt = Database::pdo()->prepare('SELECT NAME_SID FROM stock_levels WHERE ID = ? LIMIT 1');
        $stmt->execute([$id]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function levelsCount(array $stockSids, array $filters): int
    {
        if ($stockSids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($stockSids), '?'));
        $params = array_values($stockSids);
        $where = 'l.STOCK_SID IN (' . $placeholders . ')' . $this->filterSql($filters, $params);

        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM stock_levels l WHERE ' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function levelsAllMulti(array $stockSids, array $filters, ?int $priceTypeId = null, int $limit = 50000): array
    {
        if ($stockSids === []) {
            return [];
        }

        $sort = (string) ($filters['sort'] ?? '');
        $priceJoin = '';
        $priceColumn = '';

        if ($priceTypeId !== null) {
            $priceJoin = 'LEFT JOIN nomenclature_prices np ON np.stock_sid = l.STOCK_SID
                AND np.name_sid = l.NAME_SID AND np.price_type_id = ' . (int) $priceTypeId;
            $priceColumn = ', np.price AS price';
        } elseif (str_contains($sort, 'price_')) {
            $sort = $this->stripPriceSort($sort);
        }

        $placeholders = implode(',', array_fill(0, count($stockSids), '?'));
        $params = array_values($stockSids);
        $where = 'l.STOCK_SID IN (' . $placeholders . ')' . $this->filterSql($filters, $params);

        $stmt = Database::pdo()->prepare(
            'SELECT l.NAME AS name, l.UNIT AS unit, l.QUANTITY AS quantity, l.ACTUAL_DATE AS actual_date,
                    l.STOCK_SID AS stock_sid, s.NAME AS stock_name, s.SORT AS stock_sort,
                    n.DESCRIPTION AS description, n.group_id AS group_id, g.TITLE AS group_title' . $priceColumn . '
             FROM stock_levels l
             LEFT JOIN nomenclature n ON n.SID = l.NAME_SID
             LEFT JOIN item_groups g ON g.ID = n.group_id
             JOIN stocks s ON s.SID = l.STOCK_SID
             ' . $priceJoin . '
             WHERE ' . $where . '
             ' . $this->orderSql($sort) . '
             LIMIT ' . max(1, min(100000, $limit))
        );
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    public function searchCounts(array $stockSids, array $filters): array
    {
        if ($stockSids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($stockSids), '?'));
        $params = array_values($stockSids);
        $where = 'l.STOCK_SID IN (' . $placeholders . ')' . $this->filterSql($filters, $params);

        $stmt = Database::pdo()->prepare(
            'SELECT l.STOCK_SID, COUNT(*) AS cnt FROM stock_levels l WHERE ' . $where . ' GROUP BY l.STOCK_SID'
        );
        $stmt->execute($params);

        $counts = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $counts[(string) $row['STOCK_SID']] = (int) $row['cnt'];
        }

        return $counts;
    }

    private function filterSql(array $filters, array &$params): string
    {
        $sql = '';

        if (($filters['q'] ?? '') !== '') {
            $sql .= ' AND l.NAME LIKE ?';
            $params[] = '%' . $filters['q'] . '%';
        }

        if (($filters['qty_op'] ?? '') !== '' && isset($filters['qty'])) {
            $sql .= ' AND l.QUANTITY ' . ($filters['qty_op'] === 'lt' ? '<' : '>') . ' ?';
            $params[] = $filters['qty'];
        }

        $groupIds = $filters['group_ids'] ?? [];
        $noGroup = (bool) ($filters['no_group'] ?? false);

        if ((is_array($groupIds) && $groupIds !== []) || $noGroup) {
            $parts = [];

            if (is_array($groupIds) && $groupIds !== []) {
                $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
                $parts[] = 'group_id IN (' . $placeholders . ')';

                foreach ($groupIds as $groupId) {
                    $params[] = (int) $groupId;
                }
            }

            if ($noGroup) {
                $parts[] = 'group_id IS NULL';
            }

            $sql .= ' AND l.NAME_SID IN (SELECT SID FROM nomenclature WHERE ' . implode(' OR ', $parts) . ')';
        }

        if (!($filters['show_zero'] ?? false)) {
            $sql .= ' AND l.QUANTITY > 0';
        }

        $active = (string) ($filters['active'] ?? '');

        if ($active === 'active') {
            $sql .= " AND l.NAME_SID IN (SELECT SID FROM nomenclature WHERE ACTIVE = 'Y')";
        } elseif ($active === 'inactive') {
            $sql .= " AND l.NAME_SID IN (SELECT SID FROM nomenclature WHERE ACTIVE = 'N')";
        }

        return $sql;
    }

    private function orderSql(string $sort): string
    {
        $terms = [];

        foreach (explode(',', $sort) as $criterion) {
            $term = $this->orderTerm(trim($criterion));

            if ($term !== null) {
                $terms[] = $term;
            }
        }

        $hasName = false;

        foreach ($terms as $term) {
            if (str_contains($term, 'l.NAME')) {
                $hasName = true;
                break;
            }
        }

        if (!$hasName) {
            $terms[] = 'l.NAME ASC';
        }

        return 'ORDER BY ' . implode(', ', $terms);
    }

    private function orderTerm(string $sort): ?string
    {
        return match ($sort) {
            'name_asc' => 'l.NAME ASC',
            'name_desc' => 'l.NAME DESC',
            'qty_asc' => 'l.QUANTITY ASC',
            'qty_desc' => 'l.QUANTITY DESC',
            'warehouse', 'warehouse_asc' => 's.SORT ASC, s.NAME ASC',
            'warehouse_desc' => 's.SORT DESC, s.NAME DESC',
            'price_asc' => 'np.price IS NULL, np.price ASC',
            'price_desc' => 'np.price IS NULL, np.price DESC',
            default => null,
        };
    }

    private function stripPriceSort(string $sort): string
    {
        $parts = [];

        foreach (explode(',', $sort) as $criterion) {
            $criterion = trim($criterion);

            if ($criterion !== '' && !str_starts_with($criterion, 'price_')) {
                $parts[] = $criterion;
            }
        }

        return $parts === [] ? 'name_asc' : implode(',', $parts);
    }

    public function warehouseExists(string $name): bool
    {
        $stmt = Database::pdo()->prepare('SELECT ID FROM stocks WHERE NAME_1C = ? LIMIT 1');
        $stmt->execute([$name]);

        return $stmt->fetch() !== false;
    }

    /**
     * Существует ли номенклатура с таким именем (с учётом коллации БД).
     */
    public function nomenclatureExists(string $name): bool
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM nomenclature WHERE NAME_1C = ? LIMIT 1');
        $stmt->execute([$name]);

        return $stmt->fetch() !== false;
    }

    /**
     * Все активные склады системы (для расширения новых позиций на все склады).
     *
     * @return array<int, array{SID: string, NAME: string}>
     */
    public function allWarehouses(): array
    {
        $rows = Database::pdo()->query(
            "SELECT SID, NAME FROM stocks WHERE ACTIVE = 'Y' AND STATUS = 'Y' ORDER BY SORT ASC, NAME ASC"
        )->fetchAll() ?: [];

        return array_map(static fn (array $row): array => [
            'SID' => (string) $row['SID'],
            'NAME' => (string) $row['NAME'],
        ], $rows);
    }

    /**
     * Все склады для страницы управления (включая неактивные).
     *
     * @return array<int, array<string, mixed>>
     */
    public function warehousesAdmin(): array
    {
        return Database::pdo()->query(
            "SELECT s.ID AS id, s.SID AS sid, s.NAME AS name, s.NAME_1C AS name_1c, s.ADDRESS AS address,
                    s.CONTACT_NAME AS contact_name, s.PHONE AS phone, s.EMAIL AS email, s.NOTE AS note,
                    s.TYPE AS type, s.IS_DEFAULT AS is_default, s.ALLOW_ORDERS AS allow_orders,
                    s.IN_REPORTS AS in_reports, s.STOCK_NUM AS stock_num, s.SORT AS sort, s.LEVEL AS level,
                    s.RESPONSIBLE_SID AS responsible_sid, u.FULL_NAME AS responsible_name,
                    s.ACTIVE AS active,
                    (SELECT COUNT(*) FROM stock_levels l WHERE l.STOCK_SID = s.SID AND l.QUANTITY > 0) AS positions,
                    (SELECT COUNT(*) FROM stock_levels l WHERE l.STOCK_SID = s.SID) AS positions_total,
                    (SELECT COUNT(*) FROM user_level_stock uls WHERE uls.STOCK_SID = s.SID AND uls.ACTIVE = 'Y' AND uls.STATUS = 'Y') AS users,
                    (SELECT MAX(l.ACTUAL_DATE) FROM stock_levels l WHERE l.STOCK_SID = s.SID) AS actual_date
             FROM stocks s
             LEFT JOIN users u ON u.SID = s.RESPONSIBLE_SID
             ORDER BY s.ACTIVE DESC, s.IS_DEFAULT DESC, s.SORT ASC, s.NAME ASC"
        )->fetchAll() ?: [];
    }

    public function updateWarehouse(string $sid, array $fields): void
    {
        $pdo = Database::pdo();

        $pdo->prepare(
            'UPDATE stocks SET NAME = ?, NAME_1C = ?, ADDRESS = ?, CONTACT_NAME = ?, PHONE = ?, EMAIL = ?,
                    `NOTE` = ?, `TYPE` = ?, STOCK_NUM = ?, SORT = ?, LEVEL = ?, ACTIVE = ?,
                    IS_DEFAULT = ?, ALLOW_ORDERS = ?, IN_REPORTS = ?, RESPONSIBLE_SID = ?, LAST_ACTIVITY_DATE = NOW()
             WHERE SID = ?'
        )->execute([
            $fields['name'],
            $fields['name_1c'],
            $fields['address'],
            $fields['contact_name'],
            $fields['phone'],
            $fields['email'],
            $fields['note'],
            $fields['type'],
            $fields['stock_num'],
            $fields['sort'],
            $fields['level'],
            $fields['active'] ? 'Y' : 'N',
            $fields['is_default'] ? 1 : 0,
            $fields['allow_orders'] ? 1 : 0,
            $fields['in_reports'] ? 1 : 0,
            $fields['responsible_sid'],
            $sid,
        ]);

        $pdo->prepare('UPDATE stock_levels SET STOCK = ? WHERE STOCK_SID = ?')->execute([$fields['name'], $sid]);
    }

    public function createWarehouse(array $fields): ?array
    {
        $pdo = Database::pdo();

        $pdo->prepare(
            "INSERT INTO stocks (NAME, NAME_1C, ADDRESS, CONTACT_NAME, PHONE, EMAIL, `NOTE`, `TYPE`,
                                 STOCK_NUM, SORT, LEVEL, ACTIVE, STATUS, IS_DEFAULT, ALLOW_ORDERS, IN_REPORTS, RESPONSIBLE_SID)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Y', ?, ?, ?, ?)"
        )->execute([
            $fields['name'],
            $fields['name_1c'],
            $fields['address'],
            $fields['contact_name'],
            $fields['phone'],
            $fields['email'],
            $fields['note'],
            $fields['type'],
            $fields['stock_num'],
            $fields['sort'],
            $fields['level'],
            $fields['active'] ? 'Y' : 'N',
            $fields['is_default'] ? 1 : 0,
            $fields['allow_orders'] ? 1 : 0,
            $fields['in_reports'] ? 1 : 0,
            $fields['responsible_sid'],
        ]);

        return $this->findWarehouseById((int) $pdo->lastInsertId());
    }

    public function warehouseName1cTaken(string $name1c, ?string $sid): bool
    {
        $sql = 'SELECT ID FROM stocks WHERE NAME_1C = ?' . ($sid !== null ? ' AND SID <> ?' : '') . ' LIMIT 1';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($sid !== null ? [$name1c, $sid] : [$name1c]);

        return $stmt->fetch() !== false;
    }

    public function clearDefaultWarehouse(string $exceptSid): void
    {
        Database::pdo()->prepare('UPDATE stocks SET IS_DEFAULT = 0 WHERE IS_DEFAULT = 1 AND SID <> ?')
            ->execute([$exceptSid]);
    }

    /**
     * Пользователи, которым выдан доступ к складу.
     *
     * @return array<int, array<string, mixed>>
     */
    public function warehouseUsers(string $stockSid): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT uls.ID AS id, uls.USER_SID AS user_sid, uls.FULL_NAME AS full_name,
                    uls.STATUS AS status, uls.ACTIVE AS active,
                    u.LOGIN AS login, u.LEVEL AS level, u.ACTIVE AS user_active
             FROM user_level_stock uls
             LEFT JOIN users u ON u.SID = uls.USER_SID
             WHERE uls.STOCK_SID = ?
             ORDER BY uls.FULL_NAME ASC, uls.ID ASC"
        );
        $stmt->execute([$stockSid]);

        return $stmt->fetchAll() ?: [];
    }

    public function warehouseUserExists(string $stockSid, string $userSid): bool
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM user_level_stock WHERE STOCK_SID = ? AND USER_SID = ? LIMIT 1');
        $stmt->execute([$stockSid, $userSid]);

        return $stmt->fetch() !== false;
    }

    public function addWarehouseUser(string $stockSid, string $userSid, string $fullName): void
    {
        Database::pdo()->prepare(
            "INSERT INTO user_level_stock (FULL_NAME, USER_SID, STOCK_SID, STATUS, ACTIVE) VALUES (?, ?, ?, 'Y', 'Y')"
        )->execute([$fullName, $userSid, $stockSid]);
    }

    public function removeWarehouseUser(string $stockSid, string $userSid): void
    {
        Database::pdo()->prepare('DELETE FROM user_level_stock WHERE STOCK_SID = ? AND USER_SID = ?')
            ->execute([$stockSid, $userSid]);
    }

    public function findWarehouseById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM stocks WHERE ID = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function warehouseNameTaken(string $name, string $sid): bool
    {
        $stmt = Database::pdo()->prepare('SELECT ID FROM stocks WHERE NAME = ? AND SID <> ? LIMIT 1');
        $stmt->execute([$name, $sid]);

        return $stmt->fetch() !== false;
    }

    public function renameWarehouse(string $sid, string $name): void
    {
        $pdo = Database::pdo();

        $pdo->prepare('UPDATE stocks SET NAME = ?, LAST_ACTIVITY_DATE = NOW() WHERE SID = ?')
            ->execute([$name, $sid]);
        $pdo->prepare('UPDATE stock_levels SET STOCK = ? WHERE STOCK_SID = ?')
            ->execute([$name, $sid]);
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

    public function upsertNomenclature(string $name, string $unit, ?string $description = null): ?array
    {
        $pdo = Database::pdo();

        $pdo->prepare('INSERT IGNORE INTO nomenclature (NAME, NAME_1C, UNIT, DESCRIPTION) VALUES (?, ?, ?, ?)')
            ->execute([$name, $name, $unit !== '' ? $unit : null, $description]);

        if ($description !== null) {
            $pdo->prepare('UPDATE nomenclature SET DESCRIPTION = ? WHERE NAME_1C = ?')->execute([$description, $name]);
        }

        $stmt = $pdo->prepare('SELECT * FROM nomenclature WHERE NAME_1C = ? LIMIT 1');
        $stmt->execute([$name]);

        return $stmt->fetch() ?: null;
    }

    public function findLevel(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT l.ID AS id, l.STOCK_SID AS stock_sid, l.NAME AS name, l.NAME_SID AS name_sid, l.UNIT AS unit,
                    l.QUANTITY AS quantity, l.ACTUAL_DATE AS actual_date, n.DESCRIPTION AS description,
                    n.ARTICLE AS article, n.TYPE AS type, n.ACTIVE AS active, n.group_id AS group_id
             FROM stock_levels l
             LEFT JOIN nomenclature n ON n.SID = l.NAME_SID
             WHERE l.ID = ? LIMIT 1'
        );
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function setNomenclatureType(string $sid, string $type): void
    {
        Database::pdo()->prepare('UPDATE nomenclature SET TYPE = ? WHERE SID = ?')
            ->execute([$type, $sid]);
    }

    public function setNomenclatureActive(string $sid, bool $active): void
    {
        Database::pdo()->prepare('UPDATE nomenclature SET ACTIVE = ? WHERE SID = ?')
            ->execute([$active ? 'Y' : 'N', $sid]);
    }

    /**
     * Использование позиции: в составе наборов и в заявках (продажах).
     *
     * @return array{in_sets: int, in_requests: int}
     */
    public function positionUsage(string $nameSid, string $name): array
    {
        $pdo = Database::pdo();

        $sets = $pdo->prepare('SELECT COUNT(*) FROM nomenclature_composition WHERE ITEM_SID = ?');
        $sets->execute([$nameSid]);

        $requests = $pdo->prepare(
            'SELECT COUNT(*) FROM request_items
             WHERE name = ?
                OR stock_level_id IN (SELECT ID FROM stock_levels WHERE NAME_SID = ?)'
        );
        $requests->execute([$name, $nameSid]);

        return [
            'in_sets' => (int) $sets->fetchColumn(),
            'in_requests' => (int) $requests->fetchColumn(),
        ];
    }

    public function deletePosition(string $nameSid): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $pdo->prepare('DELETE FROM nomenclature_composition WHERE SET_SID = ? OR ITEM_SID = ?')
                ->execute([$nameSid, $nameSid]);
            $pdo->prepare('DELETE FROM nomenclature_prices WHERE name_sid = ?')->execute([$nameSid]);
            $pdo->prepare('DELETE FROM stock_levels WHERE NAME_SID = ?')->execute([$nameSid]);
            $pdo->prepare('DELETE FROM nomenclature WHERE SID = ?')->execute([$nameSid]);
            $pdo->commit();
        } catch (\Throwable $error) {
            $pdo->rollBack();

            throw $error;
        }
    }

    /**
     * Из переданных id уровней возвращает те, что принадлежат деактивированным позициям.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    public function inactiveLevelIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::pdo()->prepare(
            "SELECT l.ID AS id FROM stock_levels l
             JOIN nomenclature n ON n.SID = l.NAME_SID
             WHERE l.ID IN ($placeholders) AND n.ACTIVE = 'N'"
        );
        $stmt->execute($ids);

        return array_map('intval', array_column($stmt->fetchAll(), 'id'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function compositionFor(string $setSid): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT c.ITEM_SID AS item_sid, c.QUANTITY AS quantity, n.NAME AS name, n.UNIT AS unit,
                    n.TYPE AS type, n.ARTICLE AS article
             FROM nomenclature_composition c
             JOIN nomenclature n ON n.SID = c.ITEM_SID
             WHERE c.SET_SID = ?
             ORDER BY c.SORT ASC, c.ID ASC'
        );
        $stmt->execute([$setSid]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @param  array<int, array{item_sid: string, quantity: float}>  $items
     */
    public function replaceComposition(string $setSid, array $items): void
    {
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM nomenclature_composition WHERE SET_SID = ?')->execute([$setSid]);

        if ($items === []) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO nomenclature_composition (SET_SID, ITEM_SID, QUANTITY, SORT) VALUES (?, ?, ?, ?)'
        );

        $sort = 0;

        foreach ($items as $item) {
            $stmt->execute([$setSid, $item['item_sid'], $item['quantity'], $sort++]);
        }
    }

    /**
     * Поиск позиций номенклатуры (для состава набора).
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchNomenclature(string $query, int $limit = 30): array
    {
        $like = '%' . $query . '%';
        $stmt = Database::pdo()->prepare(
            'SELECT SID AS sid, NAME AS name, UNIT AS unit, TYPE AS type, ARTICLE AS article
             FROM nomenclature
             WHERE NAME LIKE ? OR ARTICLE LIKE ?
             ORDER BY NAME ASC
             LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute([$like, $like]);

        return $stmt->fetchAll() ?: [];
    }

    public function setNomenclatureArticle(string $sid, ?string $article): void
    {
        Database::pdo()->prepare('UPDATE nomenclature SET ARTICLE = ? WHERE SID = ?')
            ->execute([$article !== null && $article !== '' ? $article : null, $sid]);
    }

    public function setNomenclatureGroup(string $sid, ?int $groupId): void
    {
        $stmt = Database::pdo()->prepare('UPDATE nomenclature SET group_id = ? WHERE SID = ?');
        $stmt->execute([$groupId, $sid]);
    }

    public function findWarehouseBySid(string $sid): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM stocks WHERE SID = ? LIMIT 1');
        $stmt->execute([$sid]);

        return $stmt->fetch() ?: null;
    }

    public function levelExists(string $stockSid, string $nameSid, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM stock_levels WHERE STOCK_SID = ? AND NAME_SID = ?';
        $params = [$stockSid, $nameSid];

        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND ID <> ?';
            $params[] = $excludeId;
        }

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function createLevel(array $data): int
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'INSERT INTO stock_levels (LAST_STOCK_UPDATE_SID, ACTUAL_DATE, STOCK, STOCK_SID, NAME, NAME_SID, UNIT, QUANTITY)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['update_sid'] ?? null,
            $data['actual_date'],
            $data['stock'],
            $data['stock_sid'],
            $data['name'],
            $data['name_sid'],
            $data['unit'] !== '' ? $data['unit'] : null,
            $data['quantity'],
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function updateLevel(int $id, string $name, string $nameSid, string $unit, float $quantity): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE stock_levels SET NAME = ?, NAME_SID = ?, UNIT = ?, QUANTITY = ?, ACTUAL_DATE = CURDATE() WHERE ID = ?'
        );
        $stmt->execute([$name, $nameSid, $unit !== '' ? $unit : null, $quantity, $id]);
    }

    /**
     * Переименование общей позиции: правит nomenclature и переносит имя/ед. изм. во все stock_levels.
     */
    public function renameNomenclature(string $sid, string $name, string $unit, ?string $description): void
    {
        $pdo = Database::pdo();

        $pdo->prepare('UPDATE nomenclature SET NAME = ?, NAME_1C = ?, UNIT = ?, DESCRIPTION = ? WHERE SID = ?')
            ->execute([$name, $name, $unit !== '' ? $unit : null, $description, $sid]);

        $pdo->prepare('UPDATE stock_levels SET NAME = ?, UNIT = ? WHERE NAME_SID = ?')
            ->execute([$name, $unit !== '' ? $unit : null, $sid]);
    }

    public function nomenclatureByName(string $name): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM nomenclature WHERE NAME = ? OR NAME_1C = ? LIMIT 1');
        $stmt->execute([$name, $name]);

        return $stmt->fetch() ?: null;
    }

    public function nomenclatureBySid(string $sid): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM nomenclature WHERE SID = ? LIMIT 1');
        $stmt->execute([$sid]);

        return $stmt->fetch() ?: null;
    }

    public function findNomenclature(string $sid): ?array
    {
        return $this->nomenclatureBySid($sid);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function levelsForName(string $nameSid): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT l.ID AS id, l.STOCK_SID AS stock_sid, s.NAME AS stock_name, l.QUANTITY AS quantity,
                    l.UNIT AS unit, l.ACTUAL_DATE AS actual_date
             FROM stock_levels l
             LEFT JOIN stocks s ON s.SID = l.STOCK_SID
             WHERE l.NAME_SID = ?
             ORDER BY s.NAME ASC'
        );
        $stmt->execute([$nameSid]);

        return $stmt->fetchAll() ?: [];
    }

    public function updateLevelQuantity(int $id, float $quantity): void
    {
        Database::pdo()->prepare('UPDATE stock_levels SET QUANTITY = ?, ACTUAL_DATE = CURDATE() WHERE ID = ?')
            ->execute([$quantity, $id]);
    }

    public function levelsIndex(): array
    {
        $rows = Database::pdo()->query('SELECT ID, STOCK_SID, NAME_SID, QUANTITY FROM stock_levels')->fetchAll() ?: [];
        $index = [];

        foreach ($rows as $row) {
            $index[(string) $row['STOCK_SID'] . '|' . (string) $row['NAME_SID']] = [
                'id' => (int) $row['ID'],
                'quantity' => (float) $row['QUANTITY'],
            ];
        }

        return $index;
    }

    public function zeroAllLevels(): void
    {
        Database::pdo()->exec('UPDATE stock_levels SET QUANTITY = 0');
    }

    /**
     * Остатки, схлопнутые по номенклатуре: количество — сумма, имя/ед./дата — представитель.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mergedLevels(): array
    {
        return Database::pdo()->query(
            'SELECT NAME_SID AS name_sid, MAX(NAME) AS name, MAX(UNIT) AS unit,
                    SUM(QUANTITY) AS quantity, MAX(ACTUAL_DATE) AS actual_date
             FROM stock_levels
             GROUP BY NAME_SID'
        )->fetchAll() ?: [];
    }

    /**
     * Цены, схлопнутые по (номенклатура, тип): максимум.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mergedPrices(): array
    {
        return Database::pdo()->query(
            'SELECT name_sid, price_type_id, MAX(price) AS price
             FROM nomenclature_prices
             GROUP BY name_sid, price_type_id'
        )->fetchAll() ?: [];
    }

    public function updateLevelFromImport(int $id, string $updateSid, string $actualDate, float $quantity): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE stock_levels SET LAST_STOCK_UPDATE_SID = ?, ACTUAL_DATE = ?, QUANTITY = ? WHERE ID = ?'
        );
        $stmt->execute([$updateSid, $actualDate, $quantity, $id]);
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

    public function snapshotLevels(): void
    {
        $pdo = Database::pdo();

        $pdo->exec('DELETE FROM stock_levels_backup');
        $pdo->exec(
            'INSERT INTO stock_levels_backup
                (ID, ACTIVE, TIME_ADD, SID, LAST_STOCK_UPDATE_SID, ACTUAL_DATE, STOCK, STOCK_SID, NAME, NAME_SID, UNIT, QUANTITY, backup_at)
             SELECT ID, ACTIVE, TIME_ADD, SID, LAST_STOCK_UPDATE_SID, ACTUAL_DATE, STOCK, STOCK_SID, NAME, NAME_SID, UNIT, QUANTITY, NOW()
             FROM stock_levels'
        );
    }

    public function failStuckJobs(int $minutes = 30): int
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE stock_import_jobs
             SET STATUS = 'failed', ERRORS = ?, finished_at = NOW()
             WHERE STATUS = 'processing' AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        $stmt->execute(['Импорт прерван: превышено время обработки', max(1, $minutes)]);

        return $stmt->rowCount();
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
