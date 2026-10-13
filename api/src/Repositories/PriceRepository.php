<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use Throwable;

final class PriceRepository
{
    public function types(): array
    {
        $rows = Database::pdo()->query(
            'SELECT pt.ID AS id, pt.CODE AS code, pt.TITLE AS title, pt.SORT AS sort,
                    (SELECT COUNT(*) FROM users u WHERE u.price_type_id = pt.ID) AS users_count,
                    (SELECT COUNT(*) FROM nomenclature_prices np WHERE np.price_type_id = pt.ID) AS prices_count
             FROM price_types pt
             ORDER BY pt.SORT ASC, pt.ID ASC'
        )->fetchAll() ?: [];

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'title' => (string) $row['title'],
            'sort' => (int) $row['sort'],
            'users_count' => (int) $row['users_count'],
            'prices_count' => (int) $row['prices_count'],
        ], $rows);
    }

    public function findType(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT ID AS id, CODE AS code, TITLE AS title, SORT AS sort FROM price_types WHERE ID = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'title' => (string) $row['title'],
            'sort' => (int) $row['sort'],
        ];
    }

    public function findTypeByCode(string $code): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT ID AS id FROM price_types WHERE CODE = ? LIMIT 1');
        $stmt->execute([$code]);
        $row = $stmt->fetch();

        return $row === false ? null : ['id' => (int) $row['id']];
    }

    public function defaultTypeId(): ?int
    {
        $value = Database::pdo()->query('SELECT ID FROM price_types ORDER BY SORT ASC, ID ASC LIMIT 1')->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /**
     * Закреплённые типы цен пользователя (по умолчанию — первым).
     *
     * @return array<int, array{id:int,title:string,is_default:bool}>
     */
    public function assignedTypes(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $stmt = Database::pdo()->prepare(
            'SELECT pt.ID AS id, pt.TITLE AS title, upt.is_default AS is_default
             FROM user_price_types upt
             JOIN price_types pt ON pt.ID = upt.price_type_id
             WHERE upt.user_id = ?
             ORDER BY upt.is_default DESC, upt.sort ASC, pt.SORT ASC, pt.ID ASC'
        );
        $stmt->execute([$userId]);

        return $this->mapAssignedRows($stmt->fetchAll() ?: []);
    }

    /**
     * @param int[] $userIds
     * @return array<int, array<int, array{id:int,title:string,is_default:bool}>>
     */
    public function assignedTypesForUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(
            array_map('intval', $userIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($userIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = Database::pdo()->prepare(
            'SELECT upt.user_id AS user_id, pt.ID AS id, pt.TITLE AS title, upt.is_default AS is_default
             FROM user_price_types upt
             JOIN price_types pt ON pt.ID = upt.price_type_id
             WHERE upt.user_id IN (' . $placeholders . ')
             ORDER BY upt.is_default DESC, upt.sort ASC, pt.SORT ASC, pt.ID ASC'
        );
        $stmt->execute($userIds);

        $map = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $map[(int) $row['user_id']][] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'is_default' => (int) $row['is_default'] === 1,
            ];
        }

        return $map;
    }

    /**
     * @return int[]
     */
    public function assignedTypeIds(int $userId): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], $this->assignedTypes($userId));
    }

    /**
     * Полностью заменяет закреплённый набор типов цен пользователя.
     *
     * @param int[] $typeIds
     */
    public function replaceAssignedTypes(int $userId, array $typeIds, ?int $defaultId): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $pdo->prepare('DELETE FROM user_price_types WHERE user_id = ?')->execute([$userId]);

            $insert = $pdo->prepare(
                'INSERT INTO user_price_types (user_id, price_type_id, sort, is_default) VALUES (?, ?, ?, ?)'
            );

            $sort = 0;

            foreach ($typeIds as $typeId) {
                $insert->execute([$userId, $typeId, $sort, $typeId === $defaultId ? 1 : 0]);
                $sort += 10;
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();

            throw $exception;
        }
    }

    public function setUserDefaultType(int $userId, ?int $typeId): void
    {
        Database::pdo()->prepare('UPDATE users SET price_type_id = ? WHERE ID = ?')->execute([$typeId, $userId]);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{id:int,title:string,is_default:bool}>
     */
    private function mapAssignedRows(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'is_default' => (int) $row['is_default'] === 1,
        ], $rows);
    }

    public function nextSort(): int
    {
        $value = Database::pdo()->query('SELECT COALESCE(MAX(SORT), 0) + 10 FROM price_types')->fetchColumn();

        return (int) $value;
    }

    /**
     * @return int[]
     */
    public function allTypeIds(): array
    {
        $rows = Database::pdo()->query('SELECT ID AS id FROM price_types ORDER BY SORT ASC, ID ASC')->fetchAll() ?: [];

        return array_map('intval', array_column($rows, 'id'));
    }

    /**
     * Настроенный набор типов цен склада и признак «настройка задана».
     *
     * @return array{set:bool,ids:int[]}
     */
    public function warehousePriceTypes(string $stockSid): array
    {
        if ($stockSid === '') {
            return ['set' => false, 'ids' => []];
        }

        $pdo = Database::pdo();
        $flagStmt = $pdo->prepare('SELECT PRICE_TYPES_SET FROM stocks WHERE SID = ? LIMIT 1');
        $flagStmt->execute([$stockSid]);
        $flag = $flagStmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT price_type_id FROM stock_price_types WHERE stock_sid = ? ORDER BY sort ASC, price_type_id ASC'
        );
        $stmt->execute([$stockSid]);

        return [
            'set' => $flag !== false && (int) $flag === 1,
            'ids' => array_map('intval', array_column($stmt->fetchAll() ?: [], 'price_type_id')),
        ];
    }

    /**
     * @param string[] $stockSids
     * @return array<string, array{set:bool,ids:int[]}>
     */
    public function warehousePriceTypesForStocks(array $stockSids): array
    {
        $stockSids = array_values(array_unique(array_filter(
            $stockSids,
            static fn ($sid): bool => is_string($sid) && $sid !== ''
        )));

        if ($stockSids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($stockSids), '?'));
        $pdo = Database::pdo();
        $map = [];

        foreach ($stockSids as $sid) {
            $map[$sid] = ['set' => false, 'ids' => []];
        }

        $flagStmt = $pdo->prepare('SELECT SID, PRICE_TYPES_SET FROM stocks WHERE SID IN (' . $placeholders . ')');
        $flagStmt->execute($stockSids);

        foreach ($flagStmt->fetchAll() ?: [] as $row) {
            $map[(string) $row['SID']]['set'] = (int) $row['PRICE_TYPES_SET'] === 1;
        }

        $stmt = $pdo->prepare(
            'SELECT stock_sid, price_type_id FROM stock_price_types
             WHERE stock_sid IN (' . $placeholders . ')
             ORDER BY sort ASC, price_type_id ASC'
        );
        $stmt->execute($stockSids);

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $map[(string) $row['stock_sid']]['ids'][] = (int) $row['price_type_id'];
        }

        return $map;
    }

    /**
     * Полностью заменяет набор типов цен склада и помечает настройку заданной.
     *
     * @param int[] $typeIds
     */
    public function setWarehousePriceTypes(string $stockSid, array $typeIds): void
    {
        if ($stockSid === '') {
            return;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $pdo->prepare('DELETE FROM stock_price_types WHERE stock_sid = ?')->execute([$stockSid]);

            $insert = $pdo->prepare('INSERT INTO stock_price_types (stock_sid, price_type_id, sort) VALUES (?, ?, ?)');
            $sort = 0;

            foreach ($typeIds as $typeId) {
                $insert->execute([$stockSid, $typeId, $sort]);
                $sort += 10;
            }

            $pdo->prepare('UPDATE stocks SET PRICE_TYPES_SET = 1 WHERE SID = ?')->execute([$stockSid]);
            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();

            throw $exception;
        }
    }

    public function createType(string $code, string $title, int $sort): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('INSERT INTO price_types (CODE, TITLE, SORT) VALUES (?, ?, ?)');
        $stmt->execute([$code, $title, $sort]);

        return (int) $pdo->lastInsertId();
    }

    public function updateType(int $id, string $title): void
    {
        $stmt = Database::pdo()->prepare('UPDATE price_types SET TITLE = ? WHERE ID = ?');
        $stmt->execute([$title, $id]);
    }

    public function deleteType(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM price_types WHERE ID = ?')->execute([$id]);
    }

    public function deletePricesForType(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM nomenclature_prices WHERE price_type_id = ?')->execute([$id]);
    }

    /**
     * Цены позиций конкретного склада по одному типу: name_sid => price.
     *
     * @param array<int, string> $nameSids
     * @return array<string, float>
     */
    public function pricesForStockItems(string $stockSid, array $nameSids, int $typeId): array
    {
        $nameSids = array_values(array_unique(array_filter($nameSids, static fn (string $sid): bool => $sid !== '')));

        if ($stockSid === '' || $nameSids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($nameSids), '?'));
        $params = array_merge([$stockSid, $typeId], $nameSids);

        $stmt = Database::pdo()->prepare(
            'SELECT name_sid, price FROM nomenclature_prices
             WHERE stock_sid = ? AND price_type_id = ? AND name_sid IN (' . $placeholders . ')'
        );
        $stmt->execute($params);

        $map = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $map[(string) $row['name_sid']] = (float) $row['price'];
        }

        return $map;
    }

    /**
     * Все цены позиций конкретного склада: name_sid => (price_type_id => price).
     *
     * @param array<int, string> $nameSids
     * @return array<string, array<int, float>>
     */
    public function pricesForStockItemsAll(string $stockSid, array $nameSids): array
    {
        $nameSids = array_values(array_unique(array_filter($nameSids, static fn (string $sid): bool => $sid !== '')));

        if ($stockSid === '' || $nameSids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($nameSids), '?'));
        $params = array_merge([$stockSid], $nameSids);

        $stmt = Database::pdo()->prepare(
            'SELECT name_sid, price_type_id, price FROM nomenclature_prices
             WHERE stock_sid = ? AND name_sid IN (' . $placeholders . ')'
        );
        $stmt->execute($params);

        $map = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $map[(string) $row['name_sid']][(int) $row['price_type_id']] = (float) $row['price'];
        }

        return $map;
    }

    /**
     * Цены позиций склада по заданным типам: name_sid => (price_type_id => price).
     *
     * @param array<int, string> $nameSids
     * @param int[] $typeIds
     * @return array<string, array<int, float>>
     */
    public function pricesForStockItemsByTypes(string $stockSid, array $nameSids, array $typeIds): array
    {
        $nameSids = array_values(array_unique(array_filter($nameSids, static fn (string $sid): bool => $sid !== '')));
        $typeIds = array_values(array_unique(array_filter(
            array_map('intval', $typeIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($stockSid === '' || $nameSids === [] || $typeIds === []) {
            return [];
        }

        $namePlaceholders = implode(',', array_fill(0, count($nameSids), '?'));
        $typePlaceholders = implode(',', array_fill(0, count($typeIds), '?'));
        $params = array_merge([$stockSid], $typeIds, $nameSids);

        $stmt = Database::pdo()->prepare(
            'SELECT name_sid, price_type_id, price FROM nomenclature_prices
             WHERE stock_sid = ? AND price_type_id IN (' . $typePlaceholders . ')
               AND name_sid IN (' . $namePlaceholders . ')'
        );
        $stmt->execute($params);

        $map = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $map[(string) $row['name_sid']][(int) $row['price_type_id']] = (float) $row['price'];
        }

        return $map;
    }

    /**
     * Цены одной позиции склада: price_type_id => price.
     *
     * @return array<int, float>
     */
    public function pricesForStockItem(string $stockSid, string $nameSid): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT price_type_id, price FROM nomenclature_prices WHERE stock_sid = ? AND name_sid = ?'
        );
        $stmt->execute([$stockSid, $nameSid]);

        $map = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $map[(int) $row['price_type_id']] = (float) $row['price'];
        }

        return $map;
    }

    /**
     * Цены для набора пар «склад + позиция» по одному типу.
     *
     * @param array<int, array{0: string, 1: string}> $pairs
     * @return array<string, float> "stock_sid|name_sid" => price
     */
    public function pricesForPairs(array $pairs, int $typeId): array
    {
        $pairs = array_values(array_filter($pairs, static fn (array $pair): bool => $pair[0] !== '' && $pair[1] !== ''));

        if ($pairs === []) {
            return [];
        }

        $conditions = [];
        $params = [$typeId];

        foreach ($pairs as [$stockSid, $nameSid]) {
            $conditions[] = '(stock_sid = ? AND name_sid = ?)';
            $params[] = $stockSid;
            $params[] = $nameSid;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT stock_sid, name_sid, price FROM nomenclature_prices
             WHERE price_type_id = ? AND (' . implode(' OR ', $conditions) . ')'
        );
        $stmt->execute($params);

        $map = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $map[(string) $row['stock_sid'] . '|' . (string) $row['name_sid']] = (float) $row['price'];
        }

        return $map;
    }

    /**
     * @return array<string, string> NAME => SID
     */
    public function nameSidsByNames(array $names): array
    {
        $names = array_values(array_unique(array_filter($names, static fn (string $name): bool => $name !== '')));

        if ($names === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $stmt = Database::pdo()->prepare('SELECT SID, NAME FROM nomenclature WHERE NAME IN (' . $placeholders . ')');
        $stmt->execute($names);

        $map = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $map[(string) $row['NAME']] = (string) $row['SID'];
        }

        return $map;
    }

    /**
     * @return array<int, array{name_sid: string, stock_sid: string}> stock level ID => позиция и склад
     */
    public function nameSidsByLevelIds(array $levelIds): array
    {
        $levelIds = array_values(array_unique(array_filter(array_map('intval', $levelIds), static fn (int $id): bool => $id > 0)));

        if ($levelIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($levelIds), '?'));
        $stmt = Database::pdo()->prepare('SELECT ID, NAME_SID, STOCK_SID FROM stock_levels WHERE ID IN (' . $placeholders . ')');
        $stmt->execute($levelIds);

        $map = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $map[(int) $row['ID']] = [
                'name_sid' => (string) ($row['NAME_SID'] ?? ''),
                'stock_sid' => (string) ($row['STOCK_SID'] ?? ''),
            ];
        }

        return $map;
    }

    /**
     * @return array<int, string> stocks.ID => SID
     */
    public function warehouseSidsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::pdo()->prepare('SELECT ID, SID FROM stocks WHERE ID IN (' . $placeholders . ')');
        $stmt->execute($ids);

        $map = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $map[(int) $row['ID']] = (string) $row['SID'];
        }

        return $map;
    }

    public function upsertPrice(string $stockSid, string $nameSid, int $typeId, float $price): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO nomenclature_prices (stock_sid, name_sid, price_type_id, price) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE price = VALUES(price)'
        );
        $stmt->execute([$stockSid, $nameSid, $typeId, $price]);
    }

    public function deletePrice(string $stockSid, string $nameSid, int $typeId): void
    {
        $stmt = Database::pdo()->prepare(
            'DELETE FROM nomenclature_prices WHERE stock_sid = ? AND name_sid = ? AND price_type_id = ?'
        );
        $stmt->execute([$stockSid, $nameSid, $typeId]);
    }

    /**
     * Запомненные сопоставления колонок-цен из файла с типами цен.
     *
     * @return array<string, int> source_name (нормализованное) => price_type_id (0 = не импортировать)
     */
    public function importMappings(): array
    {
        $rows = Database::pdo()->query('SELECT source_name, price_type_id FROM price_import_mappings')->fetchAll() ?: [];

        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row['source_name']] = (int) $row['price_type_id'];
        }

        return $map;
    }

    public function saveImportMapping(string $sourceName, int $typeId): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO price_import_mappings (source_name, price_type_id) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE price_type_id = VALUES(price_type_id)'
        );
        $stmt->execute([$sourceName, max(0, $typeId)]);
    }
}
