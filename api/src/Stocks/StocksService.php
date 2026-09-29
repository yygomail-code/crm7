<?php

declare(strict_types=1);

namespace App\Stocks;

use App\Audit\AuditService;
use App\Core\Config;
use App\Core\Database;
use App\Export\TableExport;
use App\Groups\ItemGroupService;
use App\Http\HttpException;
use App\Prices\PriceService;
use App\Repositories\StocksRepository;
use App\Repositories\UserRepository;
use App\Repositories\ViewLogRepository;
use Throwable;

final class StocksService
{
    public const MAX_SIZE = 20971520;

    private const ALLOWED_EXTENSIONS = ['xls', 'xlsx', 'csv', 'txt'];

    public function __construct(
        private readonly StocksRepository $stocks = new StocksRepository(),
        private readonly AuditService $audit = new AuditService(),
        private readonly ViewLogRepository $views = new ViewLogRepository(),
        private readonly PriceService $prices = new PriceService(),
        private readonly UserRepository $users = new UserRepository(),
        private readonly ItemGroupService $groups = new ItemGroupService()
    ) {
    }

    public function warehouses(array $user, array $capabilities): array
    {
        $all = in_array('stocks.manage', $capabilities, true);
        $rows = $this->stocks->warehousesForUser((int) $user['LEVEL'], (string) $user['SID'], $all);

        return [
            'items' => array_map(static fn (array $row): array => [
                'id' => (int) $row['ID'],
                'name' => (string) ($row['NAME'] ?? ''),
                'sort' => (int) ($row['SORT'] ?? 0),
                'positions' => (int) ($row['positions'] ?? 0),
                'actual_date' => $row['actual_date'] !== null ? (string) $row['actual_date'] : null,
                'is_personal' => (bool) ($row['personal'] ?? false),
            ], $rows),
            'can_import' => in_array('stocks.import', $capabilities, true),
            'can_edit' => in_array('stocks.edit', $capabilities, true),
        ];
    }

    public function searchCounts(array $user, array $capabilities, array $filters): array
    {
        $all = in_array('stocks.manage', $capabilities, true);
        $rows = $this->stocks->warehousesForUser((int) $user['LEVEL'], (string) $user['SID'], $all);
        $sids = array_map(static fn (array $row): string => (string) $row['SID'], $rows);
        $bySid = $this->stocks->searchCounts($sids, self::normalizeFilters($filters));

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) (int) $row['ID']] = $bySid[(string) $row['SID']] ?? 0;
        }

        return ['counts' => $counts];
    }

    public function levels(
        array $user,
        array $capabilities,
        int $warehouseId,
        array $filters,
        int $page,
        int $perPage,
        int $clientId = 0
    ): array {
        $warehouse = $this->requireWarehouse($user, $capabilities, $warehouseId);

        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        $filters = self::normalizeFilters($filters);

        $rows = $this->stocks->levels((string) $warehouse['SID'], $filters, $page, $perPage);
        $total = $this->stocks->levelsCount((string) $warehouse['SID'], $filters);

        $priceTypeId = $this->priceTypeFor($user, $clientId);
        $priceMap = [];
        $allPrices = [];
        $stockSid = (string) $warehouse['SID'];

        if ($priceTypeId !== null && $rows !== []) {
            $sids = array_map(
                static fn (array $row): string => (string) ($row['name_sid'] ?? ''),
                $rows
            );
            $priceMap = $this->prices->pricesForStockItems($stockSid, $sids, $priceTypeId);

            if (in_array('stocks.edit', $capabilities, true)) {
                $allPrices = $this->prices->pricesForStockItemsAll($stockSid, $sids);
            }
        }

        if ($filters['q'] !== '') {
            $this->views->add('stocks', $warehouseId, (int) $user['ID'], 'search', $filters['q'], $total);
        } elseif (!$this->views->recentExists('stocks', $warehouseId, (int) $user['ID'], 'list', 30)) {
            $this->views->add('stocks', $warehouseId, (int) $user['ID'], 'list', (string) $warehouse['NAME']);
        }

        $type = $priceTypeId !== null ? $this->prices->findType($priceTypeId) : null;

        return [
            'items' => array_map(static function (array $row) use ($priceMap, $allPrices): array {
                $sid = (string) ($row['name_sid'] ?? '');
                $item = [
                    'id' => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'unit' => (string) ($row['unit'] ?? ''),
                    'quantity' => (float) $row['quantity'],
                    'description' => (string) ($row['description'] ?? ''),
                    'actual_date' => $row['actual_date'] !== null ? (string) $row['actual_date'] : null,
                    'price' => $sid !== '' && isset($priceMap[$sid]) ? (float) $priceMap[$sid] : null,
                    'group_id' => $row['group_id'] !== null ? (int) $row['group_id'] : null,
                    'group_title' => (string) ($row['group_title'] ?? ''),
                ];

                if ($allPrices !== []) {
                    $item['prices'] = $allPrices[$sid] ?? [];
                }

                return $item;
            }, $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'can_edit' => in_array('stocks.edit', $capabilities, true),
            'prices' => [
                'enabled' => $this->prices->enabled(),
                'type' => $type !== null ? ['id' => (int) $type['id'], 'title' => (string) $type['title']] : null,
            ],
            'groups' => [
                'enabled' => $this->groups->enabled(),
            ],
            'warehouse' => [
                'id' => (int) $warehouse['ID'],
                'name' => (string) $warehouse['NAME'],
            ],
        ];
    }

    /**
     * Тип цен для пользователя: для сотрудника можно запросить тип выбранного клиента.
     */
    private function priceTypeFor(array $user, int $clientId): ?int
    {
        if (!$this->prices->enabled()) {
            return null;
        }

        $target = $user;

        if ($clientId > 0 && $clientId !== (int) $user['ID'] && (int) $user['LEVEL'] >= 10) {
            $client = $this->users->findById($clientId);

            if ($client !== null && (int) $client['LEVEL'] === 5) {
                $target = $client;
            }
        }

        return $this->prices->effectiveTypeIdForUser($target);
    }

    public static function normalizeFilters(array $input): array
    {
        $qtyOp = in_array($input['qty_op'] ?? '', ['gt', 'lt'], true) ? (string) $input['qty_op'] : '';
        $qty = isset($input['qty']) && is_numeric($input['qty']) ? (float) $input['qty'] : null;

        if ($qtyOp === '' || $qty === null) {
            $qtyOp = '';
            $qty = null;
        }

        $sort = (string) ($input['sort'] ?? '');

        if (!in_array($sort, ['name_asc', 'name_desc', 'qty_asc', 'qty_desc'], true)) {
            $sort = 'name_asc';
        }

        $showZero = in_array((string) ($input['show_zero'] ?? ''), ['1', 'true', 'yes'], true);
        $groupId = (int) ($input['group_id'] ?? 0);

        return [
            'q' => trim((string) ($input['q'] ?? '')),
            'qty_op' => $qtyOp,
            'qty' => $qty,
            'sort' => $sort,
            'show_zero' => $showZero,
            'group_id' => $groupId > 0 ? $groupId : null,
        ];
    }

    public function export(array $user, array $capabilities, int $warehouseId, array $filters, string $format): array
    {
        $filters = self::normalizeFilters($filters);
        $format = TableExport::normalizeFormat($format);

        if ($warehouseId > 0) {
            $warehouses = [$this->requireWarehouse($user, $capabilities, $warehouseId)];
        } else {
            $all = in_array('stocks.manage', $capabilities, true);
            $warehouses = array_values(array_filter(
                $this->stocks->warehousesForUser((int) $user['LEVEL'], (string) $user['SID'], $all),
                static fn (array $row): bool => ($row['ACTIVE'] ?? 'Y') === 'Y'
            ));
        }

        $sections = [];
        $total = 0;

        foreach ($warehouses as $warehouse) {
            $rows = $this->stocks->levelsAll((string) $warehouse['SID'], $filters);
            $total += count($rows);

            $sections[] = [
                'title' => (string) $warehouse['NAME'],
                'headers' => ['Остатки', 'Ед.', 'Количество', 'Описание', 'Актуальность'],
                'rows' => array_map(static function (array $row): array {
                    return [
                        (string) $row['name'],
                        (string) ($row['unit'] ?? ''),
                        number_format((float) $row['quantity'], 3, ',', ' '),
                        (string) ($row['description'] ?? ''),
                        $row['actual_date'] !== null ? (string) $row['actual_date'] : '',
                    ];
                }, $rows),
            ];
        }

        $single = $warehouseId > 0 && $warehouses !== [];
        $meta = [
            $single ? 'Склад: ' . (string) $warehouses[0]['NAME'] : 'Складов: ' . count($warehouses),
            'Позиций: ' . $total,
            'Фильтр: ' . $this->filterLabel($filters),
            'Сформировано: ' . date('d.m.Y H:i'),
        ];

        $baseName = $single
            ? 'Остатки ' . (string) $warehouses[0]['NAME'] . ' ' . date('Y-m-d')
            : 'Остатки по складам ' . date('Y-m-d');

        $result = TableExport::buildPerSheet($baseName, $format, $sections, $meta);

        $this->views->add('stocks', $warehouseId, (int) $user['ID'], 'export', $this->filterLabel($filters), $total);

        $this->audit->log($user, 'stocks.export', 'stocks', $warehouseId, [
            'rows' => $total,
            'warehouses' => count($warehouses),
            'format' => $format,
            'filters' => $filters,
        ], null);

        return $result;
    }

    private function filterLabel(array $filters): string
    {
        $parts = [];

        if ($filters['q'] !== '') {
            $parts[] = 'поиск: ' . $filters['q'];
        }

        if ($filters['qty_op'] !== '' && $filters['qty'] !== null) {
            $parts[] = 'количество ' . ($filters['qty_op'] === 'lt' ? 'меньше' : 'больше') . ' ' . $filters['qty'];
        }

        if ($filters['show_zero'] ?? false) {
            $parts[] = 'включая нулевые остатки';
        }

        return $parts !== [] ? implode(', ', $parts) : 'без фильтра';
    }

    public function createItem(array $user, array $capabilities, int $warehouseId, array $input): array
    {
        $this->requireEdit($capabilities);
        $warehouse = $this->requireWarehouse($user, $capabilities, $warehouseId);

        [$name, $unit, $quantity, $description] = $this->normalizeItemInput($input);
        $groupId = $this->normalizeGroupId($input['group_id'] ?? null);

        $item = $this->stocks->upsertNomenclature($name, $unit, $description);

        if ($item === null) {
            throw new HttpException(500, 'server_error', 'Не удалось создать позицию');
        }

        if ($this->stocks->levelExists((string) $warehouse['SID'], (string) $item['SID'])) {
            throw new HttpException(422, 'duplicate_item', 'Позиция с таким названием уже есть на складе — отредактируйте её');
        }

        $this->stocks->setNomenclatureGroup((string) $item['SID'], $groupId);

        $actualDate = date('Y-m-d');

        $itemId = $this->stocks->createLevel([
            'update_sid' => null,
            'actual_date' => $actualDate,
            'stock' => (string) $warehouse['NAME'],
            'stock_sid' => (string) $warehouse['SID'],
            'name' => $name,
            'name_sid' => (string) $item['SID'],
            'unit' => $unit,
            'quantity' => $quantity,
        ]);

        $this->savePrices($warehouse, $item, $input);

        $this->audit->log($user, 'stocks.item.create', 'stocks', $warehouseId, [
            'name' => $name,
            'quantity' => $quantity,
        ], null);

        return [
            'item' => [
                'id' => $itemId,
                'name' => $name,
                'unit' => $unit,
                'quantity' => $quantity,
                'description' => $description ?? '',
                'actual_date' => $actualDate,
                'prices' => $this->prices->pricesForStockItem((string) $warehouse['SID'], (string) $item['SID']),
                'group_id' => $groupId,
                'group_title' => $this->groupTitle($groupId),
            ],
        ];
    }

    public function updateItem(array $user, array $capabilities, int $itemId, array $input): array
    {
        $this->requireEdit($capabilities);

        $level = $this->stocks->findLevel($itemId);

        if ($level === null) {
            throw new HttpException(404, 'not_found', 'Позиция не найдена');
        }

        $warehouse = $this->stocks->findWarehouseBySid((string) $level['stock_sid']);

        if ($warehouse === null) {
            throw new HttpException(404, 'not_found', 'Склад не найден');
        }

        $this->requireWarehouse($user, $capabilities, (int) $warehouse['ID']);

        [$name, $unit, $quantity, $description] = $this->normalizeItemInput([
            'name' => array_key_exists('name', $input) ? $input['name'] : $level['name'],
            'unit' => array_key_exists('unit', $input) ? $input['unit'] : ($level['unit'] ?? ''),
            'description' => array_key_exists('description', $input) ? $input['description'] : ($level['description'] ?? ''),
            'quantity' => array_key_exists('quantity', $input) ? $input['quantity'] : (float) $level['quantity'],
        ]);
        $groupId = array_key_exists('group_id', $input)
            ? $this->normalizeGroupId($input['group_id'])
            : ($level['group_id'] !== null ? (int) $level['group_id'] : null);

        $item = $this->stocks->upsertNomenclature($name, $unit, $description);

        if ($item === null) {
            throw new HttpException(500, 'server_error', 'Не удалось сохранить позицию');
        }

        if ((string) $item['SID'] !== (string) $level['name_sid']
            && $this->stocks->levelExists((string) $level['stock_sid'], (string) $item['SID'], $itemId)
        ) {
            throw new HttpException(422, 'duplicate_item', 'Позиция с таким названием уже есть на складе');
        }

        $this->stocks->setNomenclatureGroup((string) $item['SID'], $groupId);
        $this->stocks->updateLevel($itemId, $name, (string) $item['SID'], $unit, $quantity);
        $this->savePrices($warehouse, $item, $input);

        $this->audit->log($user, 'stocks.item.update', 'stocks', (int) $warehouse['ID'], [
            'id' => $itemId,
            'name' => $name,
            'quantity' => $quantity,
        ], null);

        return [
            'item' => [
                'id' => $itemId,
                'name' => $name,
                'unit' => $unit,
                'quantity' => $quantity,
                'description' => $description ?? '',
                'actual_date' => date('Y-m-d'),
                'prices' => $this->prices->pricesForStockItem((string) $warehouse['SID'], (string) $item['SID']),
                'group_id' => $groupId,
                'group_title' => $this->groupTitle($groupId),
            ],
        ];
    }

    private function savePrices(array $warehouse, array $item, array $input): void
    {
        if (!$this->prices->enabled() || !isset($input['prices']) || !is_array($input['prices'])) {
            return;
        }

        $this->prices->saveItemPrices(
            (string) ($warehouse['SID'] ?? ''),
            (string) ($item['SID'] ?? ''),
            $input['prices']
        );
    }

    private function normalizeGroupId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        $id = (int) $value;

        if ($this->groups->find($id) === null) {
            throw new HttpException(422, 'validation_error', 'Неизвестная группа позиций');
        }

        return $id;
    }

    private function groupTitle(?int $groupId): string
    {
        if ($groupId === null) {
            return '';
        }

        $group = $this->groups->find($groupId);

        return $group !== null ? (string) $group['title'] : '';
    }

    private function requireEdit(array $capabilities): void
    {
        if (!in_array('stocks.edit', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для добавления и правки позиций');
        }
    }

    /**
     * @return array{0: string, 1: string, 2: float, 3: ?string}
     */
    private function normalizeItemInput(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $unit = trim((string) ($input['unit'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));

        if ($name === '') {
            throw new HttpException(422, 'validation_error', 'Укажите название позиции');
        }

        if (mb_strlen($name) > 255) {
            throw new HttpException(422, 'validation_error', 'Название слишком длинное (до 255 символов)');
        }

        if (mb_strlen($unit) > 50) {
            throw new HttpException(422, 'validation_error', 'Единица измерения слишком длинная');
        }

        if (mb_strlen($description) > 500) {
            throw new HttpException(422, 'validation_error', 'Описание слишком длинное (до 500 символов)');
        }

        $raw = $input['quantity'] ?? 0;

        if (!is_numeric($raw)) {
            throw new HttpException(422, 'validation_error', 'Укажите корректное количество');
        }

        $quantity = (float) $raw;

        if ($quantity < 0 || $quantity > 1000000000) {
            throw new HttpException(422, 'validation_error', 'Количество должно быть неотрицательным');
        }

        return [$name, $unit, $quantity, $description !== '' ? $description : null];
    }

    public function renameWarehouse(array $user, array $capabilities, int $warehouseId, array $input): array
    {
        $this->requireEdit($capabilities);

        $warehouse = $this->stocks->findWarehouseById($warehouseId);

        if ($warehouse === null) {
            throw new HttpException(404, 'not_found', 'Склад не найден');
        }

        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            throw new HttpException(422, 'validation_error', 'Укажите название склада');
        }

        if (mb_strlen($name) > 255) {
            throw new HttpException(422, 'validation_error', 'Название слишком длинное (до 255 символов)');
        }

        if ($name === (string) $warehouse['NAME']) {
            return ['warehouse' => ['id' => $warehouseId, 'name' => $name]];
        }

        if ($this->stocks->warehouseNameTaken($name, (string) $warehouse['SID'])) {
            throw new HttpException(422, 'duplicate_warehouse', 'Склад с таким названием уже есть');
        }

        $this->stocks->renameWarehouse((string) $warehouse['SID'], $name);

        $this->audit->log($user, 'stocks.warehouse.rename', 'stocks', $warehouseId, [
            'from' => (string) $warehouse['NAME'],
            'to' => $name,
        ], null);

        return ['warehouse' => ['id' => $warehouseId, 'name' => $name]];
    }

    public function import(array $user, array $capabilities, array $file, string $date): array
    {
        if (!in_array('stocks.import', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для импорта остатков');
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new HttpException(422, 'too_large', 'Файл превышает лимит сервера загрузки');
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new HttpException(422, 'upload_failed', 'Файл не загружен');
        }

        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0) {
            throw new HttpException(422, 'upload_failed', 'Пустой файл');
        }

        if ($size > self::MAX_SIZE) {
            throw new HttpException(422, 'too_large', 'Файл больше 20 МБ');
        }

        $originalName = (string) ($file['name'] ?? 'file');
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new HttpException(422, 'bad_type', 'Допустимые форматы: xls, xlsx, csv');
        }

        $actualDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : date('Y-m-d');

        [$rows, $skipped, $errors] = $this->parseFile((string) $file['tmp_name'], $extension);

        if ($rows === []) {
            throw new HttpException(422, 'empty_file', 'В файле не найдено строк с остатками');
        }

        $jobId = $this->stocks->createJob($originalName, $actualDate, (int) $user['ID']);
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $updateSid = md5(uniqid('', true));
            $this->stocks->insertUpdate($updateSid, $actualDate, $originalName);

            $warehouses = [];
            $nomenclature = [];
            $imported = 0;
            $created = 0;
            $warehousesCreated = 0;
            $seen = [];

            $this->stocks->snapshotLevels();
            $existing = $this->stocks->levelsIndex();
            $this->stocks->zeroAllLevels();
            $insert = $this->stocks->prepareLevelInsert();

            foreach ($rows as [$stockName, $itemName, $unit, $quantity]) {
                if (!isset($warehouses[$stockName])) {
                    $warehouseIsNew = !$this->stocks->warehouseExists($stockName);
                    $warehouse = $this->stocks->upsertWarehouse($stockName);

                    if ($warehouse === null) {
                        $skipped++;
                        $errors[] = 'Не удалось создать склад: ' . $stockName;
                        continue;
                    }

                    if ($warehouseIsNew) {
                        $warehousesCreated++;
                    }

                    $warehouses[$stockName] = $warehouse;
                    $this->stocks->touchWarehouse((string) $warehouse['SID'], $updateSid);
                }

                if (!isset($nomenclature[$itemName])) {
                    $item = $this->stocks->upsertNomenclature($itemName, $unit);

                    if ($item === null) {
                        $skipped++;
                        $errors[] = 'Не удалось создать товар: ' . $itemName;
                        continue;
                    }

                    $nomenclature[$itemName] = $item;
                }

                $key = (string) $warehouses[$stockName]['SID'] . '|' . (string) $nomenclature[$itemName]['SID'];
                $seen[$key] = $quantity;

                if (isset($existing[$key])) {
                    $this->stocks->updateLevelFromImport(
                        $existing[$key]['id'],
                        $updateSid,
                        $actualDate,
                        $unit,
                        $quantity
                    );
                } else {
                    $insert->execute([
                        $updateSid,
                        $actualDate,
                        $stockName,
                        (string) $warehouses[$stockName]['SID'],
                        $itemName,
                        (string) $nomenclature[$itemName]['SID'],
                        $unit !== '' ? $unit : null,
                        $quantity,
                    ]);

                    $created++;
                }

                $imported++;
            }

            $zeroed = 0;

            foreach ($existing as $key => $row) {
                $now = $seen[$key] ?? 0.0;

                if ($row['quantity'] > 0 && $now <= 0) {
                    $zeroed++;
                }
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            $this->stocks->finishJob($jobId, 'failed', count($rows) + $skipped, 0, $skipped, [$exception->getMessage()]);

            throw new HttpException(500, 'import_failed', 'Импорт не выполнен: ' . $exception->getMessage());
        }

        $this->stocks->finishJob($jobId, 'done', count($rows) + $skipped, $imported, $skipped, $errors);

        $this->audit->log($user, 'stocks.import', 'stocks', $jobId, [
            'file' => $originalName,
            'imported' => $imported,
            'skipped' => $skipped,
            'actual_date' => $actualDate,
        ], null);

        return [
            'job_id' => $jobId,
            'file_name' => $originalName,
            'actual_date' => $actualDate,
            'rows_total' => count($rows) + $skipped,
            'rows_imported' => $imported,
            'rows_created' => $created,
            'rows_zeroed' => $zeroed,
            'rows_skipped' => $skipped,
            'warehouses_created' => $warehousesCreated,
            'errors' => array_slice($errors, 0, 20),
        ];
    }

    public function history(array $user, array $capabilities): array
    {
        if (!in_array('stocks.view', $capabilities, true) && !in_array('stocks.import', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав');
        }

        return [
            'jobs' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'file_name' => (string) $row['file_name'],
                'actual_date' => (string) $row['actual_date'],
                'status' => (string) $row['status'],
                'rows_total' => (int) $row['rows_total'],
                'rows_imported' => (int) $row['rows_imported'],
                'rows_skipped' => (int) $row['rows_skipped'],
                'errors' => (string) ($row['errors'] ?? ''),
                'user' => (string) ($row['user_name'] ?? ''),
                'created_at' => (string) $row['created_at'],
                'finished_at' => $row['finished_at'] !== null ? (string) $row['finished_at'] : null,
            ], $this->stocks->jobs()),
            'updates' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'actual_date' => $row['actual_date'] !== null ? (string) $row['actual_date'] : '',
                'file' => (string) ($row['file'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'rows_count' => (int) ($row['rows_count'] ?? 0),
                'created_at' => (string) $row['created_at'],
            ], $this->stocks->updates(50)),
        ];
    }

    private function requireWarehouse(array $user, array $capabilities, int $warehouseId): array
    {
        $warehouse = $this->stocks->find($warehouseId);

        if ($warehouse === null || ($warehouse['ACTIVE'] ?? 'N') !== 'Y') {
            throw new HttpException(404, 'not_found', 'Склад не найден');
        }

        if (in_array('stocks.manage', $capabilities, true)) {
            return $warehouse;
        }

        $level = (int) $user['LEVEL'];
        $byLevel = str_contains((string) $warehouse['LEVEL'], '{' . $level . '}');

        if (!$byLevel) {
            $allowed = $this->stocks->warehousesForUser($level, (string) $user['SID'], false);

            foreach ($allowed as $row) {
                if ((int) $row['ID'] === $warehouseId) {
                    return $warehouse;
                }
            }

            throw new HttpException(403, 'forbidden', 'Нет доступа к складу');
        }

        return $warehouse;
    }

    /**
     * @return array{0: array<int, array{0: string, 1: string, 2: string, 3: float}>, 1: int, 2: array<int, string>}
     */
    private function parseFile(string $path, string $extension): array
    {
        $rows = [];
        $skipped = 0;
        $errors = [];

        $cells = in_array($extension, ['xls', 'xlsx'], true)
            ? $this->readSpreadsheet($path)
            : $this->readCsv($path);

        $headerMode = false;

        foreach (array_slice($cells, 0, 50) as $row) {
            if ($this->isWarehouseHeader($row)) {
                $headerMode = true;
                break;
            }
        }

        $currentWarehouse = '';

        foreach ($cells as $index => $row) {
            $first = trim((string) ($row[0] ?? ''));

            if ($first === '') {
                continue;
            }

            if ($this->isWarehouseHeader($row)) {
                $currentWarehouse = $first;
                continue;
            }

            if ($headerMode) {
                if ($currentWarehouse === '') {
                    $skipped++;

                    if (count($errors) < 20) {
                        $errors[] = 'Строка ' . ($index + 1) . ': не указан склад';
                    }

                    continue;
                }

                $itemName = $first;
                $unit = trim((string) ($row[1] ?? ''));
                $rawQuantity = (string) ($row[2] ?? '');
                $stockName = $currentWarehouse;
            } else {
                $stockName = $first;
                $itemName = trim((string) ($row[1] ?? ''));
                $unit = trim((string) ($row[2] ?? ''));
                $rawQuantity = (string) ($row[3] ?? '');

                if ($itemName === '') {
                    $skipped++;
                    continue;
                }
            }

            $quantity = $this->parseQuantity($rawQuantity);

            if ($quantity === null) {
                $skipped++;

                if (count($errors) < 20) {
                    $errors[] = 'Строка ' . ($index + 1) . ': некорректное количество "' . trim($rawQuantity) . '"';
                }

                continue;
            }

            $rows[] = [$stockName, $itemName, $unit, $quantity];
        }

        return [$rows, $skipped, $errors];
    }

    private function isWarehouseHeader(array $row): bool
    {
        $first = trim((string) ($row[0] ?? ''));

        if (preg_match('/^\s*склад\s/ui', $first) !== 1) {
            return false;
        }

        foreach ([1, 2, 3] as $index) {
            if (trim((string) ($row[$index] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    private function parseQuantity(string $value): ?float
    {
        $clean = preg_replace('/[^0-9,.\-]/u', '', $value) ?? '';
        $clean = str_replace(',', '.', $clean);

        if ($clean === '' || $clean === '-' || !is_numeric($clean)) {
            return null;
        }

        return (float) $clean;
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new HttpException(422, 'read_failed', 'Не удалось прочитать файл');
        }

        $firstLine = (string) fgets($handle);
        rewind($handle);

        $delimiters = [';' => substr_count($firstLine, ';'), ',' => substr_count($firstLine, ','), "\t" => substr_count($firstLine, "\t")];
        arsort($delimiters);
        $delimiter = (string) array_key_first($delimiters);

        if (($delimiters[$delimiter] ?? 0) === 0) {
            $delimiter = ';';
        }

        $rows = [];

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows[] = array_map(static function ($cell): string {
                $cell = (string) $cell;

                return preg_replace('/^\xEF\xBB\xBF/', '', $cell) ?? $cell;
            }, $row);
        }

        fclose($handle);

        return $rows;
    }

    private function readSpreadsheet(string $path): array
    {
        $vendor = (string) Config::get('LEGACY_VENDOR', dirname(__DIR__, 3) . '/site-old/back/vendor/autoload.php');

        if (is_file($vendor)) {
            require_once $vendor;
        }

        if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
            throw new HttpException(422, 'no_reader', 'Для чтения Excel не найдена библиотека. Сохраните файл в CSV.');
        }

        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
            $sheet = $spreadsheet->getActiveSheet();
        } catch (Throwable $exception) {
            throw new HttpException(422, 'read_failed', 'Не удалось прочитать Excel: ' . $exception->getMessage());
        }

        $rows = [];

        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];
            $iterator = $row->getCellIterator();
            $iterator->setIterateOnlyExistingCells(false);

            foreach ($iterator as $cell) {
                $value = $cell->getValue();
                $cells[] = $value !== null ? trim((string) $value) : '';
            }

            $rows[] = $cells;
        }

        return $rows;
    }
}
