<?php

declare(strict_types=1);

namespace App\Stocks;

use App\Audit\AuditService;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Export\TableExport;
use App\Groups\ItemGroupService;
use App\Http\FileResponse;
use App\Http\HttpException;
use App\Prices\PriceService;
use App\Repositories\SearchLogRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\StockPhotoRepository;
use App\Repositories\StocksRepository;
use App\Repositories\UserRepository;
use App\Repositories\ViewLogRepository;
use App\Support\ImageProcessor;
use App\Support\ItemTypes;
use App\Support\Storage;
use Throwable;

final class StocksService
{
    public const MAX_SIZE = 20971520;

    private const ALLOWED_EXTENSIONS = ['xls', 'xlsx', 'csv', 'txt'];

    public const MAX_PHOTOS = 10;

    public const MAX_PHOTO_SIZE = 5242880;

    private const PHOTO_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    private const MAX_PHOTO_PIXELS = 40000000;

    public const SINGLE_WAREHOUSE_SID = 'single-warehouse';

    private const SINGLE_WAREHOUSE_LEVEL = '{50},{10},{5},';

    /** Типы складов, доступные в карточке. */
    public const WAREHOUSE_TYPES = ['main', 'transit', 'returns', 'reserve', 'defect'];

    /** Роли, которым склад доступен по умолчанию (LEVEL). */
    public const WAREHOUSE_LEVELS = [90, 50, 10, 5, 1];

    public function __construct(
        private readonly StocksRepository $stocks = new StocksRepository(),
        private readonly AuditService $audit = new AuditService(),
        private readonly ViewLogRepository $views = new ViewLogRepository(),
        private readonly PriceService $prices = new PriceService(),
        private readonly UserRepository $users = new UserRepository(),
        private readonly ItemGroupService $groups = new ItemGroupService(),
        private readonly SearchLogRepository $searches = new SearchLogRepository(),
        private readonly StockPhotoRepository $photos = new StockPhotoRepository(),
        private readonly SettingsRepository $settings = new SettingsRepository(),
        private readonly ItemTypeService $itemTypes = new ItemTypeService()
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
        $bySid = $this->stocks->searchCounts($sids, $this->applyActiveFilter($capabilities, self::normalizeFilters($filters)));

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) (int) $row['ID']] = $bySid[(string) $row['SID']] ?? 0;
        }

        return ['counts' => $counts];
    }

    public function levels(
        array $user,
        array $capabilities,
        array $warehouseIds,
        array $filters,
        int $page,
        int $perPage,
        int $clientId = 0
    ): array {
        $warehouseIds = array_values(array_unique(array_filter(
            array_map('intval', $warehouseIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($warehouseIds === []) {
            throw new HttpException(422, 'validation_error', 'Не выбрано ни одного склада');
        }

        $warehouses = [];
        $bySid = [];

        foreach ($warehouseIds as $warehouseId) {
            $warehouse = $this->requireWarehouse($user, $capabilities, $warehouseId);
            $warehouses[] = $warehouse;
            $bySid[(string) $warehouse['SID']] = $warehouse;
        }

        $sids = array_map(static fn (array $row): string => (string) $row['SID'], $warehouses);

        $page = max(1, $page);
        $perPage = $perPage <= 0 ? 0 : max(10, min(100, $perPage));
        $filters = $this->applyActiveFilter($capabilities, self::normalizeFilters($filters));

        $priceTypeId = $this->priceTypeFor($user, $clientId);

        $rows = $this->stocks->levels($sids, $filters, $page, $perPage, $priceTypeId);
        $total = $this->stocks->levelsCount($sids, $filters);

        $priceMap = [];
        $allPrices = [];

        if ($priceTypeId !== null && $rows !== []) {
            $sidsByStock = [];

            foreach ($rows as $row) {
                $stockSid = (string) ($row['stock_sid'] ?? '');
                $nameSid = (string) ($row['name_sid'] ?? '');

                if ($stockSid !== '' && $nameSid !== '') {
                    $sidsByStock[$stockSid][$nameSid] = true;
                }
            }

            foreach ($sidsByStock as $stockSid => $nameSet) {
                $nameSids = array_keys($nameSet);
                $priceMap[$stockSid] = $this->prices->pricesForStockItems($stockSid, $nameSids, $priceTypeId);

                if (in_array('stocks.edit', $capabilities, true)) {
                    $allPrices[$stockSid] = $this->prices->pricesForStockItemsAll($stockSid, $nameSids);
                }
            }
        }

        $primaryId = (int) $warehouses[0]['ID'];

        if ($filters['q'] !== '') {
            $this->searches->add((int) $user['ID'], $filters['q'], $total);
        } elseif (!$this->views->recentExists('stocks', $primaryId, (int) $user['ID'], 'list', 30)) {
            $this->views->add('stocks', $primaryId, (int) $user['ID'], 'list', (string) $warehouses[0]['NAME']);
        }

        $type = $priceTypeId !== null ? $this->prices->findType($priceTypeId) : null;

        $nameSids = [];

        foreach ($rows as $row) {
            $sid = (string) ($row['name_sid'] ?? '');

            if ($sid !== '') {
                $nameSids[$sid] = true;
            }
        }

        $photosBySid = $this->photos->forNames(array_keys($nameSids));

        return [
            'items' => array_map(static function (array $row) use ($priceMap, $allPrices, $bySid, $photosBySid): array {
                $stockSid = (string) ($row['stock_sid'] ?? '');
                $sid = (string) ($row['name_sid'] ?? '');
                $warehouse = $bySid[$stockSid] ?? null;
                $item = [
                    'id' => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'unit' => (string) ($row['unit'] ?? ''),
                    'quantity' => (float) $row['quantity'],
                    'description' => (string) ($row['description'] ?? ''),
                    'actual_date' => $row['actual_date'] !== null ? (string) $row['actual_date'] : null,
                    'price' => $sid !== '' && isset($priceMap[$stockSid][$sid]) ? (float) $priceMap[$stockSid][$sid] : null,
                    'group_id' => $row['group_id'] !== null ? (int) $row['group_id'] : null,
                    'group_title' => (string) ($row['group_title'] ?? ''),
                    'warehouse_id' => $warehouse !== null ? (int) $warehouse['ID'] : 0,
                    'warehouse_name' => $warehouse !== null ? (string) $warehouse['NAME'] : (string) ($row['stock_name'] ?? ''),
                    'active' => ($row['active'] ?? 'Y') === 'Y',
                    'photos' => $photosBySid[$sid] ?? [],
                ];

                if ($allPrices !== [] && isset($allPrices[$stockSid])) {
                    $item['prices'] = $allPrices[$stockSid][$sid] ?? [];
                }

                return $item;
            }, $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'can_edit' => in_array('stocks.edit', $capabilities, true),
            'can_deactivate' => $this->canDeactivate($capabilities),
            'prices' => [
                'enabled' => $this->prices->enabled(),
                'type' => $type !== null ? ['id' => (int) $type['id'], 'title' => (string) $type['title']] : null,
            ],
            'groups' => [
                'enabled' => $this->groups->enabled(),
            ],
            'warehouse' => [
                'id' => (int) $warehouses[0]['ID'],
                'name' => (string) $warehouses[0]['NAME'],
            ],
            'warehouses' => array_map(static fn (array $row): array => [
                'id' => (int) $row['ID'],
                'name' => (string) $row['NAME'],
            ], $warehouses),
        ];
    }

    public function level(array $user, array $capabilities, int $itemId): array
    {
        $level = $this->stocks->findLevel($itemId);

        if ($level === null) {
            throw new HttpException(404, 'not_found', 'Позиция не найдена');
        }

        $warehouse = $this->stocks->findWarehouseBySid((string) $level['stock_sid']);

        if ($warehouse === null) {
            throw new HttpException(404, 'not_found', 'Склад не найден');
        }

        $this->requireWarehouse($user, $capabilities, (int) $warehouse['ID']);

        $stockSid = (string) $level['stock_sid'];
        $nameSid = (string) $level['name_sid'];
        $canEdit = in_array('stocks.edit', $capabilities, true);

        $priceTypeId = $this->priceTypeFor($user, 0);
        $price = null;

        if ($priceTypeId !== null) {
            $map = $this->prices->pricesForStockItems($stockSid, [$nameSid], $priceTypeId);
            $price = isset($map[$nameSid]) ? (float) $map[$nameSid] : null;
        }

        $groupId = $level['group_id'] !== null ? (int) $level['group_id'] : null;

        $existing = [];

        foreach ($this->stocks->levelsForName($nameSid) as $row) {
            $existing[(string) $row['stock_sid']] = $row;
        }

        $levels = [];

        foreach ($this->stocks->warehousesForUser(
            (int) $user['LEVEL'],
            (string) $user['SID'],
            in_array('stocks.manage', $capabilities, true)
        ) as $wh) {
            $whSid = (string) $wh['SID'];
            $row = $existing[$whSid] ?? null;

            $levels[] = [
                'id' => $row !== null ? (int) $row['id'] : 0,
                'warehouse_sid' => $whSid,
                'warehouse_name' => (string) $wh['NAME'],
                'quantity' => $row !== null ? (float) $row['quantity'] : 0.0,
                'unit' => $row !== null ? (string) ($row['unit'] ?? '') : (string) ($level['unit'] ?? ''),
                'actual_date' => $row !== null && $row['actual_date'] !== null ? (string) $row['actual_date'] : null,
                'current' => $row !== null && (int) $row['id'] === $itemId,
            ];
        }

        $type = $this->storedType((string) ($level['type'] ?? ''));
        $active = ($level['active'] ?? 'Y') === 'Y';
        $usage = $this->stocks->positionUsage($nameSid, (string) $level['name']);

        $deleteBlocked = $usage['in_sets'] > 0
            ? 'in_sets'
            : ($usage['in_requests'] > 0 ? 'in_requests' : null);

        $deactivateBlocked = $active && $usage['in_sets'] > 0 ? 'in_sets' : null;

        $composition = $type === ItemTypes::SET
            ? array_map(static fn (array $row): array => [
                'item_sid' => (string) $row['item_sid'],
                'name' => (string) $row['name'],
                'unit' => (string) ($row['unit'] ?? ''),
                'type' => (string) $row['type'],
                'article' => (string) ($row['article'] ?? ''),
                'quantity' => (float) $row['quantity'],
            ], $this->stocks->compositionFor($nameSid))
            : [];

        return [
            'item' => [
                'id' => (int) $level['id'],
                'name' => (string) $level['name'],
                'unit' => (string) ($level['unit'] ?? ''),
                'quantity' => (float) $level['quantity'],
                'description' => (string) ($level['description'] ?? ''),
                'actual_date' => $level['actual_date'] !== null ? (string) $level['actual_date'] : null,
                'price' => $price,
                'prices' => $canEdit ? $this->prices->pricesForStockItem($stockSid, $nameSid) : [],
                'group_id' => $groupId,
                'group_title' => $this->groupTitle($groupId),
                'warehouse_id' => (int) $warehouse['ID'],
                'warehouse_name' => (string) $warehouse['NAME'],
                'levels' => $levels,
                'article' => (string) ($level['article'] ?? ''),
                'type' => $type,
                'active' => $active,
                'composition' => $composition,
                'photos' => $this->photos->forNames([$nameSid])[$nameSid] ?? [],
            ],
            'can_edit' => $canEdit,
            'can_manage_photos' => $canEdit || in_array('stocks.import', $capabilities, true),
            'can_deactivate' => $this->canDeactivate($capabilities),
            'can_delete' => in_array('stocks.manage', $capabilities, true),
            'deactivate_blocked' => $deactivateBlocked,
            'delete_blocked' => $deleteBlocked,
            'prices_enabled' => $this->prices->enabled(),
            'groups_enabled' => $this->groups->enabled(),
            'item_types' => $this->itemTypes->enabledList(),
        ];
    }

    public function setActive(array $user, array $capabilities, int $itemId, bool $active): array
    {
        if (!$this->canDeactivate($capabilities)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для изменения активности позиции');
        }

        $level = $this->stocks->findLevel($itemId);

        if ($level === null) {
            throw new HttpException(404, 'not_found', 'Позиция не найдена');
        }

        $warehouse = $this->stocks->findWarehouseBySid((string) $level['stock_sid']);

        if ($warehouse === null) {
            throw new HttpException(404, 'not_found', 'Склад не найден');
        }

        $this->requireWarehouse($user, $capabilities, (int) $warehouse['ID']);

        $nameSid = (string) $level['name_sid'];

        if (!$active) {
            $usage = $this->stocks->positionUsage($nameSid, (string) $level['name']);

            if ($usage['in_sets'] > 0) {
                throw new HttpException(422, 'in_use_sets', 'Позиция входит в наборы — сначала уберите её из состава');
            }
        }

        $this->stocks->setNomenclatureActive($nameSid, $active);
        $this->audit->log(
            $user,
            $active ? 'stocks.item.activate' : 'stocks.item.deactivate',
            'nomenclature',
            $nameSid,
            ['name' => (string) $level['name']],
            null
        );

        return ['active' => $active];
    }

    public function deleteItem(array $user, array $capabilities, int $itemId): array
    {
        if (!in_array('stocks.manage', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для удаления позиции');
        }

        $level = $this->stocks->findLevel($itemId);

        if ($level === null) {
            throw new HttpException(404, 'not_found', 'Позиция не найдена');
        }

        $warehouse = $this->stocks->findWarehouseBySid((string) $level['stock_sid']);

        if ($warehouse === null) {
            throw new HttpException(404, 'not_found', 'Склад не найден');
        }

        $this->requireWarehouse($user, $capabilities, (int) $warehouse['ID']);

        $nameSid = (string) $level['name_sid'];
        $usage = $this->stocks->positionUsage($nameSid, (string) $level['name']);

        if ($usage['in_sets'] > 0) {
            throw new HttpException(422, 'in_use_sets', 'Позиция входит в наборы — сначала уберите её из состава');
        }

        if ($usage['in_requests'] > 0) {
            throw new HttpException(
                422,
                'in_use_requests',
                'Позиция использовалась в заявках — удалить нельзя, можно деактивировать'
            );
        }

        foreach ($this->photos->listForName($nameSid) as $photo) {
            $this->photos->delete((int) $photo['id']);

            try {
                Storage::delete((string) $photo['storage_path']);
            } catch (Throwable) {
                // файл мог быть уже удалён
            }
        }

        $this->stocks->deletePosition($nameSid);
        $this->audit->log($user, 'stocks.item.delete', 'nomenclature', $nameSid, ['name' => (string) $level['name']], null);

        return ['deleted' => true];
    }

    /**
     * Разрешённые типы номенклатуры для карточки позиции.
     *
     * @param  array<int, string>  $capabilities
     * @return array<string, mixed>
     */
    public function itemTypes(array $capabilities): array
    {
        $this->requireView($capabilities);

        return ['items' => $this->itemTypes->enabledList()];
    }

    /**
     * Поиск позиций номенклатуры (для состава набора).
     *
     * @param  array<int, string>  $capabilities
     * @return array<string, mixed>
     */
    public function nomenclature(array $capabilities, string $query, int $limit): array
    {
        $this->requireView($capabilities);

        $query = trim($query);

        if ($query === '') {
            return ['items' => []];
        }

        return [
            'items' => array_map(static fn (array $row): array => [
                'sid' => (string) $row['sid'],
                'name' => (string) $row['name'],
                'unit' => (string) ($row['unit'] ?? ''),
                'type' => (string) $row['type'],
                'article' => (string) ($row['article'] ?? ''),
            ], $this->stocks->searchNomenclature($query, $limit)),
        ];
    }

    private function requireView(array $capabilities): void
    {
        if (
            !in_array('stocks.view', $capabilities, true)
            && !in_array('stocks.edit', $capabilities, true)
            && !in_array('stocks.import', $capabilities, true)
            && !in_array('stocks.manage', $capabilities, true)
        ) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для просмотра номенклатуры');
        }
    }

    public function uploadPhoto(array $user, array $capabilities, int $levelId, array $file): array
    {
        $this->requirePhotoAccess($capabilities);

        $nameSid = $this->stocks->nameSidForLevel($levelId);

        if ($nameSid === null || $nameSid === '') {
            throw new HttpException(404, 'not_found', 'Позиция не найдена');
        }

        if ($this->photos->countForName($nameSid) >= self::MAX_PHOTOS) {
            throw new HttpException(422, 'too_many', 'Можно добавить не более ' . self::MAX_PHOTOS . ' фото');
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

        if ($size > self::MAX_PHOTO_SIZE) {
            throw new HttpException(422, 'too_large', 'Фото больше 5 МБ');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) ($finfo->file($tmp) ?: '');

        if (!in_array($mime, self::PHOTO_MIMES, true)) {
            throw new HttpException(422, 'bad_type', 'Допустимые форматы фото: jpg, png, webp');
        }

        $info = @getimagesize($tmp);

        if ($info === false) {
            throw new HttpException(422, 'bad_image', 'Не удалось прочитать изображение');
        }

        if ($info[0] * $info[1] > self::MAX_PHOTO_PIXELS) {
            throw new HttpException(422, 'too_large', 'Слишком большое изображение (свыше 40 Мпикс)');
        }

        $sizes = $this->settings->photoSizes();
        $extension = ImageProcessor::extension($mime);

        [$source] = ImageProcessor::load($tmp, $mime);

        $maxImage = ImageProcessor::fit($source, $sizes['max']);
        $maxWidth = imagesx($maxImage);
        $maxHeight = imagesy($maxImage);
        $maxPath = Storage::allocate('item-photos', $extension);
        ImageProcessor::save($maxImage, Storage::absolute($maxPath), $mime);

        $cardImage = ImageProcessor::fit($source, $sizes['card']);
        $cardPath = Storage::allocate('item-photos', $extension);
        ImageProcessor::save($cardImage, Storage::absolute($cardPath), $mime);

        $previewImage = ImageProcessor::fit($source, $sizes['preview']);
        $previewPath = Storage::allocate('item-photos', $extension);
        ImageProcessor::save($previewImage, Storage::absolute($previewPath), $mime);

        imagedestroy($source);
        imagedestroy($maxImage);
        imagedestroy($cardImage);
        imagedestroy($previewImage);

        $storedSize = (int) (@filesize(Storage::absolute($maxPath)) ?: $size);

        $this->photos->add(
            $nameSid,
            $maxPath,
            $cardPath,
            $previewPath,
            $mime,
            $storedSize,
            $maxWidth,
            $maxHeight,
            (int) $user['ID']
        );

        $this->audit->log($user, 'stocks.photo.add', 'nomenclature', null, ['name_sid' => $nameSid], null);

        return ['photos' => $this->photos->listForName($nameSid)];
    }

    public function deletePhoto(array $user, array $capabilities, int $id): array
    {
        $this->requirePhotoAccess($capabilities);

        $photo = $this->photos->find($id);

        if ($photo === null) {
            throw new HttpException(404, 'not_found', 'Фото не найдено');
        }

        $this->photos->delete($id);

        try {
            Storage::delete($photo['storage_path']);
        } catch (Throwable) {
            // файл можно удалить позже
        }

        $this->audit->log($user, 'stocks.photo.delete', 'nomenclature', null, ['name_sid' => $photo['name_sid']], null);

        return ['photos' => $this->photos->listForName($photo['name_sid'])];
    }

    public function photo(array $user, array $capabilities, int $id, string $size = 'max'): FileResponse
    {
        if (!in_array('stocks.view', $capabilities, true) && !in_array('stocks.import', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав');
        }

        $photo = $this->photos->find($id);

        if ($photo === null) {
            throw new HttpException(404, 'not_found', 'Фото не найдено');
        }

        $path = match ($size) {
            'preview' => $photo['preview_path'] !== '' ? $photo['preview_path'] : $photo['storage_path'],
            'card' => $photo['card_path'] !== '' ? $photo['card_path'] : $photo['storage_path'],
            default => $photo['storage_path'],
        };

        $extension = (string) pathinfo($path, PATHINFO_EXTENSION);

        return new FileResponse(
            Storage::absolute($path),
            'photo-' . $id . ($extension !== '' ? '.' . $extension : ''),
            $photo['mime'] !== '' ? $photo['mime'] : 'application/octet-stream'
        );
    }

    private function requirePhotoAccess(array $capabilities): void
    {
        if (!in_array('stocks.edit', $capabilities, true) && !in_array('stocks.import', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав');
        }
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

        $allowedSorts = [
            'name_asc', 'name_desc',
            'qty_asc', 'qty_desc',
            'warehouse', 'warehouse_asc', 'warehouse_desc',
            'price_asc', 'price_desc',
        ];

        $criteria = [];

        foreach (explode(',', (string) ($input['sort'] ?? '')) as $criterion) {
            $criterion = trim($criterion);

            if ($criterion !== '' && in_array($criterion, $allowedSorts, true) && !in_array($criterion, $criteria, true)) {
                $criteria[] = $criterion;
            }
        }

        $sort = $criteria === [] ? 'name_asc' : implode(',', $criteria);

        $showZero = in_array((string) ($input['show_zero'] ?? ''), ['1', 'true', 'yes'], true);

        $groupIds = [];
        $rawGroups = $input['group_ids'] ?? $input['group_id'] ?? [];

        if (is_array($rawGroups)) {
            foreach ($rawGroups as $value) {
                $id = (int) $value;

                if ($id > 0) {
                    $groupIds[] = $id;
                }
            }
        } else {
            $id = (int) $rawGroups;

            if ($id > 0) {
                $groupIds[] = $id;
            }
        }

        $groupIds = array_values(array_unique($groupIds));
        $noGroup = in_array((string) ($input['no_group'] ?? ''), ['1', 'true', 'yes'], true);

        $active = (string) ($input['active'] ?? '');

        if (!in_array($active, ['active', 'inactive'], true)) {
            $active = 'all';
        }

        return [
            'q' => trim((string) ($input['q'] ?? '')),
            'qty_op' => $qtyOp,
            'qty' => $qty,
            'sort' => $sort,
            'show_zero' => $showZero,
            'group_ids' => $groupIds,
            'no_group' => $noGroup,
            'active' => $active,
        ];
    }

    private function canDeactivate(array $capabilities): bool
    {
        return in_array('stocks.deactivate', $capabilities, true);
    }

    /**
     * Неактивные позиции видны только роли с правом; без него — принудительно только активные.
     */
    private function applyActiveFilter(array $capabilities, array $filters): array
    {
        if (!$this->canDeactivate($capabilities)) {
            $filters['active'] = 'active';
        }

        return $filters;
    }

    public function export(array $user, array $capabilities, array $warehouseIds, array $filters, string $format): array
    {
        $filters = $this->applyActiveFilter($capabilities, self::normalizeFilters($filters));
        $format = TableExport::normalizeFormat($format);

        $warehouseIds = array_values(array_unique(array_filter(
            array_map('intval', $warehouseIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($warehouseIds === []) {
            $all = in_array('stocks.manage', $capabilities, true);
            $warehouses = array_values(array_filter(
                $this->stocks->warehousesForUser((int) $user['LEVEL'], (string) $user['SID'], $all),
                static fn (array $row): bool => ($row['ACTIVE'] ?? 'Y') === 'Y'
            ));
        } else {
            $warehouses = [];

            foreach ($warehouseIds as $warehouseId) {
                $warehouses[] = $this->requireWarehouse($user, $capabilities, $warehouseId);
            }
        }

        $sids = array_map(static fn (array $row): string => (string) $row['SID'], $warehouses);
        $rows = $this->stocks->levelsAllMulti($sids, $filters, $this->priceTypeFor($user, 0));
        $total = count($rows);

        $showPrices = $this->prices->enabled();
        $showGroups = $this->groups->enabled();

        $headers = ['Склад', 'Товар', 'Ед.', 'Остаток на складе'];

        if ($showPrices) {
            $headers[] = 'Цена';
        }

        if ($showGroups) {
            $headers[] = 'Группа';
        }

        $headers[] = 'Описание';
        $headers[] = 'Актуальность';

        $section = [
            'headers' => $headers,
            'rows' => array_map(static function (array $row) use ($showPrices, $showGroups): array {
                $cells = [
                    (string) ($row['stock_name'] ?? ''),
                    (string) $row['name'],
                    (string) ($row['unit'] ?? ''),
                    number_format((float) $row['quantity'], 3, ',', ' '),
                ];

                if ($showPrices) {
                    $cells[] = $row['price'] !== null ? number_format((float) $row['price'], 2, ',', ' ') : '';
                }

                if ($showGroups) {
                    $cells[] = (string) ($row['group_title'] ?? '');
                }

                $cells[] = (string) ($row['description'] ?? '');
                $cells[] = $row['actual_date'] !== null ? (string) $row['actual_date'] : '';

                return $cells;
            }, $rows),
        ];

        $single = count($warehouses) === 1;
        $meta = [
            $single ? 'Склад: ' . (string) $warehouses[0]['NAME'] : 'Складов: ' . count($warehouses),
            'Позиций: ' . $total,
            'Фильтр: ' . $this->filterLabel($filters),
            'Сортировка: ' . $this->sortLabel($filters['sort']),
            'Сформировано: ' . date('d.m.Y H:i'),
        ];

        $baseName = $single
            ? 'Остатки ' . (string) $warehouses[0]['NAME'] . ' ' . date('Y-m-d')
            : 'Остатки по складам ' . date('Y-m-d');

        $result = TableExport::build($baseName, $format, [$section], $meta);

        $logId = $warehouseIds !== [] ? $warehouseIds[0] : 0;

        $this->views->add('stocks', $logId, (int) $user['ID'], 'export', $this->filterLabel($filters), $total);

        $this->audit->log($user, 'stocks.export', 'stocks', $logId, [
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
            $parts[] = 'остаток ' . ($filters['qty_op'] === 'lt' ? 'меньше' : 'больше') . ' ' . $filters['qty'];
        }

        if ($filters['show_zero'] ?? false) {
            $parts[] = 'включая нулевые остатки';
        }

        if (($filters['group_ids'] ?? []) !== []) {
            $parts[] = 'групп: ' . count($filters['group_ids']);
        }

        return $parts !== [] ? implode(', ', $parts) : 'без фильтра';
    }

    private function sortLabel(string $sort): string
    {
        $labels = [
            'name_asc' => 'наименование ↑',
            'name_desc' => 'наименование ↓',
            'qty_asc' => 'остаток на складе ↑',
            'qty_desc' => 'остаток на складе ↓',
            'warehouse' => 'склад ↑',
            'warehouse_asc' => 'склад ↑',
            'warehouse_desc' => 'склад ↓',
            'price_asc' => 'цена ↑',
            'price_desc' => 'цена ↓',
        ];

        $parts = [];

        foreach (explode(',', $sort) as $criterion) {
            $criterion = trim($criterion);

            if ($criterion !== '') {
                $parts[] = $labels[$criterion] ?? $criterion;
            }
        }

        return $parts !== [] ? implode(', ', $parts) : 'наименование ↑';
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
        $this->stocks->setNomenclatureArticle((string) $item['SID'], $this->normalizeArticle($input['article'] ?? null));
        $this->stocks->setNomenclatureType(
            (string) $item['SID'],
            array_key_exists('type', $input) ? $this->normalizeType($input['type']) : ItemTypes::PRODUCT
        );

        // Новая позиция создаётся деактивированной — пользователь активирует её отдельно.
        $isNewPosition = $this->stocks->levelsForName((string) $item['SID']) === [];

        if ($isNewPosition) {
            $this->stocks->setNomenclatureActive((string) $item['SID'], false);
        }

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
                'active' => !$isNewPosition,
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

        $nameSid = (string) $level['name_sid'];

        // Позиция единая: переименование правит общую nomenclature и переносится на все склады.
        if ($name !== (string) $level['name']) {
            $existing = $this->stocks->nomenclatureByName($name);

            if ($existing !== null && (string) $existing['SID'] !== $nameSid) {
                throw new HttpException(422, 'duplicate_item', 'Позиция с таким названием уже есть');
            }
        }

        $this->stocks->renameNomenclature($nameSid, $name, $unit, $description);
        $this->stocks->setNomenclatureGroup($nameSid, $groupId);

        if (array_key_exists('article', $input)) {
            $this->stocks->setNomenclatureArticle($nameSid, $this->normalizeArticle($input['article']));
        }

        $storedType = $this->storedType((string) ($level['type'] ?? ''));
        $type = $storedType;

        if (array_key_exists('type', $input)) {
            $type = $this->normalizeType($input['type'], $storedType);
            $this->stocks->setNomenclatureType($nameSid, $type);
        }

        if ($type === ItemTypes::SET) {
            if (array_key_exists('composition', $input)) {
                $this->stocks->replaceComposition($nameSid, $this->normalizeComposition($nameSid, $input['composition']));
            }
        } elseif (array_key_exists('type', $input)) {
            $this->stocks->replaceComposition($nameSid, []);
        }

        $this->stocks->updateLevelQuantity($itemId, $quantity);

        if (isset($input['levels']) && is_array($input['levels'])) {
            $this->saveLevelQuantities($user, $capabilities, $nameSid, $itemId, $name, $unit, $input['levels']);
        }

        $item = ['SID' => $nameSid, 'NAME' => $name];
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

    /**
     * Обновляет количества по другим складам (вкладка «Количество»), проверяя права на каждый склад.
     */
    private function saveLevelQuantities(
        array $user,
        array $capabilities,
        string $nameSid,
        int $currentId,
        string $name,
        string $unit,
        array $levels
    ): void {
        $existing = [];

        foreach ($this->stocks->levelsForName($nameSid) as $row) {
            $existing[(string) $row['stock_sid']] = $row;
        }

        $accessible = [];

        foreach ($this->stocks->warehousesForUser(
            (int) $user['LEVEL'],
            (string) $user['SID'],
            in_array('stocks.manage', $capabilities, true)
        ) as $wh) {
            $accessible[(string) $wh['SID']] = $wh;
        }

        foreach ($levels as $entry) {
            if (!is_array($entry) || !array_key_exists('quantity', $entry)) {
                continue;
            }

            $stockSid = (string) ($entry['warehouse_sid'] ?? '');

            if ($stockSid === '' || !isset($accessible[$stockSid])) {
                continue;
            }

            $warehouse = $accessible[$stockSid];
            $this->requireWarehouse($user, $capabilities, (int) $warehouse['ID']);

            $quantity = (float) $entry['quantity'];

            if (isset($existing[$stockSid])) {
                $row = $existing[$stockSid];

                if ((int) $row['id'] === $currentId) {
                    continue;
                }

                $this->stocks->updateLevelQuantity((int) $row['id'], $quantity);
                continue;
            }

            $this->stocks->createLevel([
                'update_sid' => null,
                'actual_date' => date('Y-m-d'),
                'stock' => (string) $warehouse['NAME'],
                'stock_sid' => $stockSid,
                'name' => $name,
                'name_sid' => $nameSid,
                'unit' => $unit,
                'quantity' => $quantity,
            ]);
        }
    }

    private function normalizeArticle(mixed $value): ?string
    {
        $article = trim((string) ($value ?? ''));

        return $article !== '' ? $article : null;
    }

    private function storedType(string $value): string
    {
        return ItemTypes::isKnown($value) ? $value : ItemTypes::PRODUCT;
    }

    private function normalizeType(mixed $value, ?string $current = null): string
    {
        $type = trim((string) ($value ?? ''));

        if (!ItemTypes::isKnown($type)) {
            throw new HttpException(422, 'validation_error', 'Неизвестный тип позиции');
        }

        if ($type !== $current && !in_array($type, $this->itemTypes->enabledCodes(), true)) {
            throw new HttpException(422, 'type_disabled', 'Тип позиции отключён администратором');
        }

        return $type;
    }

    /**
     * @return array<int, array{item_sid: string, quantity: float}>
     */
    private function normalizeComposition(string $setSid, mixed $value): array
    {
        if (!is_array($value)) {
            throw new HttpException(422, 'validation_error', 'Некорректный состав набора');
        }

        $items = [];

        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $itemSid = trim((string) ($entry['item_sid'] ?? ''));

            if ($itemSid === '') {
                continue;
            }

            if ($itemSid === $setSid) {
                throw new HttpException(422, 'validation_error', 'Набор не может содержать сам себя');
            }

            $component = $this->stocks->findNomenclature($itemSid);

            if ($component === null) {
                throw new HttpException(422, 'validation_error', 'Компонент состава не найден');
            }

            if (($component['ACTIVE'] ?? 'Y') !== 'Y') {
                throw new HttpException(422, 'item_inactive', 'Деактивированную позицию нельзя добавить в набор');
            }

            $quantity = (float) ($entry['quantity'] ?? 0);

            if (!is_finite($quantity) || $quantity <= 0) {
                throw new HttpException(422, 'validation_error', 'Количество в составе должно быть больше нуля');
            }

            $items[$itemSid] = ['item_sid' => $itemSid, 'quantity' => $quantity];
        }

        return array_values($items);
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
            throw new HttpException(422, 'validation_error', 'Укажите корректный остаток');
        }

        $quantity = (float) $raw;

        if ($quantity < 0 || $quantity > 1000000000) {
            throw new HttpException(422, 'validation_error', 'Остаток должен быть неотрицательным');
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

    private function requireWarehouseAdmin(array $capabilities): void
    {
        if (
            !in_array('stocks.edit', $capabilities, true)
            && !in_array('stocks.import', $capabilities, true)
            && !in_array('stocks.manage', $capabilities, true)
        ) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для управления складами');
        }
    }

    public function warehouseDirectory(array $user, array $capabilities): array
    {
        $this->requireWarehouseAdmin($capabilities);

        return [
            'items' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'sid' => (string) $row['sid'],
                'name' => (string) $row['name'],
                'address' => $row['address'] !== null ? (string) $row['address'] : null,
                'active' => ($row['active'] ?? 'N') === 'Y',
                'type' => (string) ($row['type'] ?? 'main'),
                'is_default' => (int) ($row['is_default'] ?? 0) === 1,
                'stock_num' => (int) ($row['stock_num'] ?? 0),
                'sort' => (int) $row['sort'],
                'responsible_name' => $row['responsible_name'] !== null ? (string) $row['responsible_name'] : null,
                'positions' => (int) $row['positions'],
                'positions_total' => (int) $row['positions_total'],
                'users' => (int) $row['users'],
                'actual_date' => $row['actual_date'] !== null ? (string) $row['actual_date'] : null,
            ], $this->stocks->warehousesAdmin()),
        ];
    }

    public function warehouseCard(array $user, array $capabilities, int $id): array
    {
        $this->requireWarehouseAdmin($capabilities);

        $warehouse = $this->stocks->findWarehouseById($id);

        if ($warehouse === null) {
            throw new HttpException(404, 'not_found', 'Склад не найден');
        }

        return ['warehouse' => $this->presentWarehouse($warehouse)];
    }

    public function updateWarehouse(array $user, array $capabilities, int $id, array $input): array
    {
        $this->requireWarehouseAdmin($capabilities);

        $warehouse = $this->stocks->findWarehouseById($id);

        if ($warehouse === null) {
            throw new HttpException(404, 'not_found', 'Склад не найден');
        }

        $fields = $this->warehouseFields($input, $warehouse);

        if (
            $fields['name'] !== (string) $warehouse['NAME']
            && $this->stocks->warehouseNameTaken($fields['name'], (string) $warehouse['SID'])
        ) {
            throw new HttpException(422, 'duplicate_warehouse', 'Склад с таким названием уже есть');
        }

        $this->stocks->updateWarehouse((string) $warehouse['SID'], $fields);

        if ($fields['is_default']) {
            $this->stocks->clearDefaultWarehouse((string) $warehouse['SID']);
        }

        $this->audit->log($user, 'stocks.warehouse.update', 'stocks', $id, [
            'name' => $fields['name'],
            'active' => $fields['active'],
            'type' => $fields['type'],
            'is_default' => $fields['is_default'],
        ], null);

        return ['warehouse' => $this->presentWarehouse($this->stocks->findWarehouseById($id) ?? $warehouse)];
    }

    public function createWarehouse(array $user, array $capabilities, array $input): array
    {
        $this->requireWarehouseAdmin($capabilities);

        $fields = $this->warehouseFields($input, null);

        if ($this->stocks->warehouseExists($fields['name'])) {
            throw new HttpException(422, 'duplicate_warehouse', 'Склад с таким названием уже есть');
        }

        $warehouse = $this->stocks->createWarehouse($fields);

        if ($warehouse === null) {
            throw new HttpException(500, 'create_failed', 'Не удалось создать склад');
        }

        if ($fields['is_default']) {
            $this->stocks->clearDefaultWarehouse((string) $warehouse['SID']);
        }

        $this->audit->log($user, 'stocks.warehouse.create', 'stocks', (int) $warehouse['ID'], ['name' => $fields['name']], null);

        return ['warehouse' => $this->presentWarehouse($warehouse)];
    }

    /**
     * Разбирает и валидирует поля склада из входных данных.
     *
     * @return array<string, mixed>
     */
    private function warehouseFields(array $input, ?array $existing): array
    {
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            throw new HttpException(422, 'validation_error', 'Укажите название склада');
        }

        if (mb_strlen($name) > 255) {
            throw new HttpException(422, 'validation_error', 'Название слишком длинное (до 255 символов)');
        }

        $name1c = trim((string) ($input['name_1c'] ?? ''));

        if ($name1c === '') {
            $name1c = $name;
        }

        if (mb_strlen($name1c) > 255) {
            throw new HttpException(422, 'validation_error', 'Название в 1С слишком длинное (до 255 символов)');
        }

        $sid = $existing !== null ? (string) $existing['SID'] : null;

        if ($this->stocks->warehouseName1cTaken($name1c, $sid)) {
            throw new HttpException(422, 'duplicate_warehouse', 'Склад с таким названием в 1С уже есть');
        }

        $email = $this->optionalText($input['email'] ?? null, 255);

        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new HttpException(422, 'validation_error', 'Некорректный e-mail');
        }

        $type = (string) ($input['type'] ?? 'main');

        if (!in_array($type, self::WAREHOUSE_TYPES, true)) {
            $type = 'main';
        }

        $levels = [];

        foreach ((array) ($input['levels'] ?? []) as $level) {
            $level = (int) $level;

            if (in_array($level, self::WAREHOUSE_LEVELS, true)) {
                $levels[$level] = true;
            }
        }

        if ($levels === []) {
            throw new HttpException(422, 'validation_error', 'Выберите хотя бы одну роль доступа');
        }

        $levelValue = '';

        foreach (array_keys($levels) as $level) {
            $levelValue .= '{' . $level . '},';
        }

        $responsibleSid = trim((string) ($input['responsible_sid'] ?? ''));

        if ($responsibleSid !== '' && $this->users->findBySid($responsibleSid) === null) {
            throw new HttpException(422, 'validation_error', 'Ответственный не найден');
        }

        return [
            'name' => $name,
            'name_1c' => $name1c,
            'address' => $this->optionalText($input['address'] ?? null, 255),
            'contact_name' => $this->optionalText($input['contact_name'] ?? null, 255),
            'phone' => $this->optionalText($input['phone'] ?? null, 64),
            'email' => $email,
            'note' => $this->optionalText($input['note'] ?? null, 4000),
            'type' => $type,
            'stock_num' => max(0, min(99, (int) ($input['stock_num'] ?? 0))),
            'sort' => max(0, min(999, (int) ($input['sort'] ?? ($existing['SORT'] ?? 500)))),
            'level' => $levelValue,
            'active' => (bool) ($input['active'] ?? true),
            'is_default' => (bool) ($input['is_default'] ?? false),
            'allow_orders' => (bool) ($input['allow_orders'] ?? true),
            'in_reports' => (bool) ($input['in_reports'] ?? true),
            'responsible_sid' => $responsibleSid === '' ? null : $responsibleSid,
        ];
    }

    private function optionalText(mixed $value, int $max): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    public function exportWarehouses(array $user, array $capabilities, string $format): array
    {
        $this->requireWarehouseAdmin($capabilities);

        $format = TableExport::normalizeFormat($format);
        $rows = $this->stocks->warehousesAdmin();

        $section = [
            'headers' => ['Название', 'Адрес', 'Позиций', 'Доступ (пользователей)', 'Остатки на', 'Статус'],
            'rows' => array_map(static fn (array $row): array => [
                (string) $row['name'],
                $row['address'] !== null ? (string) $row['address'] : '',
                (string) (int) $row['positions'],
                (string) (int) $row['users'],
                $row['actual_date'] !== null ? (string) $row['actual_date'] : '',
                ($row['active'] ?? 'N') === 'Y' ? 'активен' : 'скрыт',
            ], $rows),
        ];

        $meta = [
            'Складов: ' . count($rows),
            'Сформировано: ' . date('d.m.Y H:i'),
        ];

        $result = TableExport::build('Склады ' . date('Y-m-d'), $format, [$section], $meta);

        $this->audit->log($user, 'stocks.warehouses.export', 'stocks', null, [
            'rows' => count($rows),
            'format' => $format,
        ], null);

        return $result;
    }

    public function warehouseCandidates(array $user, array $capabilities, int $id, array $query): array
    {
        $this->requireWarehouseAdmin($capabilities);

        $warehouse = $this->stocks->findWarehouseById($id);

        if ($warehouse === null) {
            throw new HttpException(404, 'not_found', 'Склад не найден');
        }

        $roles = array_values(array_filter(array_map('intval', $query['roles'] ?? []), static fn (int $level): bool => $level > 0));
        $search = trim((string) ($query['q'] ?? ''));
        $sort = (string) ($query['sort'] ?? 'name_asc');
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($query['per_page'] ?? 20)));

        $stockSid = (string) $warehouse['SID'];

        return [
            'items' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'sid' => (string) $row['sid'],
                'login' => (string) $row['login'],
                'name' => (string) $row['name'],
                'level' => (int) $row['level'],
                'email' => (string) ($row['email'] ?? ''),
                'has_access' => (int) ($row['has_access'] ?? 0) === 1,
            ], $this->users->warehouseCandidates($stockSid, $roles, $search, $sort, $perPage, ($page - 1) * $perPage)),
            'total' => $this->users->countWarehouseCandidates($roles, $search),
        ];
    }

    public function warehouseAccess(array $user, array $capabilities, int $id): array
    {
        $this->requireWarehouseAdmin($capabilities);

        $warehouse = $this->stocks->findWarehouseById($id);

        if ($warehouse === null) {
            throw new HttpException(404, 'not_found', 'Склад не найден');
        }

        return [
            'users' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'user_sid' => (string) $row['user_sid'],
                'full_name' => (string) ($row['full_name'] ?? ''),
                'login' => (string) ($row['login'] ?? ''),
                'level' => (int) ($row['level'] ?? 0),
                'active' => ($row['active'] ?? 'N') === 'Y' && ($row['status'] ?? 'N') === 'Y',
            ], $this->stocks->warehouseUsers((string) $warehouse['SID'])),
        ];
    }

    public function addWarehouseUser(array $user, array $capabilities, int $id, array $input): array
    {
        $this->requireWarehouseAdmin($capabilities);

        $warehouse = $this->stocks->findWarehouseById($id);

        if ($warehouse === null) {
            throw new HttpException(404, 'not_found', 'Склад не найден');
        }

        $userSid = trim((string) ($input['user_sid'] ?? ''));

        if ($userSid === '') {
            throw new HttpException(422, 'validation_error', 'Выберите пользователя');
        }

        $target = $this->users->findBySid($userSid);

        if ($target === null) {
            throw new HttpException(404, 'not_found', 'Пользователь не найден');
        }

        $stockSid = (string) $warehouse['SID'];

        if (!$this->stocks->warehouseUserExists($stockSid, $userSid)) {
            $this->stocks->addWarehouseUser($stockSid, $userSid, (string) ($target['FULL_NAME'] ?? $target['LOGIN'] ?? ''));
            $this->audit->log($user, 'stocks.warehouse.user.add', 'stocks', $id, [
                'user' => (string) ($target['LOGIN'] ?? ''),
            ], null);
        }

        return $this->warehouseAccess($user, $capabilities, $id);
    }

    public function removeWarehouseUser(array $user, array $capabilities, int $id, string $userSid): array
    {
        $this->requireWarehouseAdmin($capabilities);

        $warehouse = $this->stocks->findWarehouseById($id);

        if ($warehouse === null) {
            throw new HttpException(404, 'not_found', 'Склад не найден');
        }

        $this->stocks->removeWarehouseUser((string) $warehouse['SID'], $userSid);

        $this->audit->log($user, 'stocks.warehouse.user.remove', 'stocks', $id, [
            'user_sid' => $userSid,
        ], null);

        return $this->warehouseAccess($user, $capabilities, $id);
    }

    private function presentWarehouse(array $warehouse): array
    {
        preg_match_all('/\{(\d+)\}/', (string) ($warehouse['LEVEL'] ?? ''), $matches);

        $responsible = null;
        $responsibleSid = $warehouse['RESPONSIBLE_SID'] !== null ? (string) $warehouse['RESPONSIBLE_SID'] : '';

        if ($responsibleSid !== '') {
            $owner = $this->users->findBySid($responsibleSid);

            if ($owner !== null) {
                $responsible = [
                    'sid' => $responsibleSid,
                    'name' => (string) ($owner['FULL_NAME'] ?? $owner['LOGIN'] ?? ''),
                    'login' => (string) ($owner['LOGIN'] ?? ''),
                ];
            }
        }

        return [
            'id' => (int) $warehouse['ID'],
            'sid' => (string) $warehouse['SID'],
            'name' => (string) $warehouse['NAME'],
            'name_1c' => (string) ($warehouse['NAME_1C'] ?? ''),
            'address' => $warehouse['ADDRESS'] !== null ? (string) $warehouse['ADDRESS'] : null,
            'contact_name' => $warehouse['CONTACT_NAME'] !== null ? (string) $warehouse['CONTACT_NAME'] : null,
            'phone' => $warehouse['PHONE'] !== null ? (string) $warehouse['PHONE'] : null,
            'email' => $warehouse['EMAIL'] !== null ? (string) $warehouse['EMAIL'] : null,
            'note' => $warehouse['NOTE'] !== null ? (string) $warehouse['NOTE'] : null,
            'type' => (string) ($warehouse['TYPE'] ?? 'main'),
            'stock_num' => (int) ($warehouse['STOCK_NUM'] ?? 0),
            'sort' => (int) ($warehouse['SORT'] ?? 0),
            'is_default' => (int) ($warehouse['IS_DEFAULT'] ?? 0) === 1,
            'allow_orders' => (int) ($warehouse['ALLOW_ORDERS'] ?? 1) === 1,
            'in_reports' => (int) ($warehouse['IN_REPORTS'] ?? 1) === 1,
            'responsible' => $responsible,
            'active' => ($warehouse['ACTIVE'] ?? 'N') === 'Y',
            'levels' => array_values(array_unique(array_map('intval', $matches[1] ?? []))),
        ];
    }

    /**
     * Единственный («виртуальный») склад в режиме без складов: создаётся при необходимости.
     */
    private function ensureSingleWarehouse(): array
    {
        $pdo = Database::pdo();
        $name = $this->settings->singleWarehouseName();
        $sid = self::SINGLE_WAREHOUSE_SID;
        $existing = $this->stocks->findWarehouseBySid($sid);

        if ($existing === null) {
            $pdo->prepare(
                "INSERT INTO stocks (NAME, NAME_1C, SID, SORT, LEVEL, ACTIVE, STATUS)
                 VALUES (?, ?, ?, 0, ?, 'Y', 'Y')"
            )->execute([$name, $sid, $sid, self::SINGLE_WAREHOUSE_LEVEL]);
        } else {
            $pdo->prepare('UPDATE stocks SET NAME = ?, ACTIVE = ?, STATUS = ?, LEVEL = ? WHERE SID = ?')
                ->execute([$name, 'Y', 'Y', self::SINGLE_WAREHOUSE_LEVEL, $sid]);
        }

        return $this->stocks->findWarehouseBySid($sid) ?? [];
    }

    /**
     * Схлопывает все склады в один: количество — сумма, цена по типу — максимум.
     * Остальные склады удаляются. Перед этим — снимок остатков.
     */
    public function collapseWarehouses(array $user): void
    {
        $pdo = Database::pdo();
        $name = $this->settings->singleWarehouseName();
        $sid = self::SINGLE_WAREHOUSE_SID;

        $levels = $this->stocks->mergedLevels();
        $prices = $this->stocks->mergedPrices();

        $this->stocks->snapshotLevels();

        $pdo->beginTransaction();

        try {
            $pdo->exec('DELETE FROM stock_levels');
            $pdo->exec('DELETE FROM nomenclature_prices');
            $pdo->exec('DELETE FROM user_level_stock');
            $pdo->exec('DELETE FROM stocks');

            $pdo->prepare(
                "INSERT INTO stocks (NAME, NAME_1C, SID, SORT, LEVEL, ACTIVE, STATUS)
                 VALUES (?, ?, ?, 0, ?, 'Y', 'Y')"
            )->execute([$name, $sid, $sid, self::SINGLE_WAREHOUSE_LEVEL]);

            $insertLevel = $pdo->prepare(
                'INSERT INTO stock_levels (LAST_STOCK_UPDATE_SID, ACTUAL_DATE, STOCK, STOCK_SID, NAME, NAME_SID, UNIT, QUANTITY)
                 VALUES (NULL, ?, ?, ?, ?, ?, ?, ?)'
            );

            foreach ($levels as $row) {
                $insertLevel->execute([
                    $row['actual_date'],
                    $name,
                    $sid,
                    (string) $row['name'],
                    (string) $row['name_sid'],
                    $row['unit'] !== null ? (string) $row['unit'] : null,
                    (float) $row['quantity'],
                ]);
            }

            $insertPrice = $pdo->prepare(
                'INSERT INTO nomenclature_prices (stock_sid, name_sid, price_type_id, price) VALUES (?, ?, ?, ?)'
            );

            foreach ($prices as $row) {
                $insertPrice->execute([
                    $sid,
                    (string) $row['name_sid'],
                    (int) $row['price_type_id'],
                    (float) $row['price'],
                ]);
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        $this->audit->log($user, 'stocks.warehouses.collapse', 'stocks', null, [
            'name' => $name,
            'levels' => count($levels),
            'prices' => count($prices),
        ], null);
    }

    /**
     * В режиме без складов строки файла схлопываются по названию: количество — сумма,
     * цена по каждому столбцу — максимум.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function aggregateRows(array $rows): array
    {
        $byItem = [];

        foreach ($rows as $row) {
            $key = (string) $row['item'];

            if (!isset($byItem[$key])) {
                $byItem[$key] = [
                    'stock' => '',
                    'item' => (string) $row['item'],
                    'unit' => (string) $row['unit'],
                    'quantity' => 0.0,
                    'prices' => [],
                ];
            }

            $byItem[$key]['quantity'] += (float) $row['quantity'];

            if ($byItem[$key]['unit'] === '' && (string) $row['unit'] !== '') {
                $byItem[$key]['unit'] = (string) $row['unit'];
            }

            foreach (($row['prices'] ?? []) as $source => $price) {
                $current = $byItem[$key]['prices'][$source] ?? null;

                if ($current === null || (float) $price > (float) $current) {
                    $byItem[$key]['prices'][$source] = $price;
                }
            }
        }

        return array_values($byItem);
    }

    public function import(array $user, array $capabilities, array $file, string $date, array $mapping = []): array
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

        $parsed = $this->parseFile((string) $file['tmp_name'], $extension);
        $rows = $parsed['rows'];
        $skipped = $parsed['skipped'];
        $errors = $parsed['errors'];
        $priceColumns = $parsed['price_columns'];

        if ($rows === []) {
            throw new HttpException(422, 'empty_file', 'В файле не найдено строк с остатками');
        }

        $warehousesEnabled = $this->settings->warehousesEnabled();

        if (!$warehousesEnabled) {
            $rows = $this->aggregateRows($rows);

            foreach ($rows as $index => $row) {
                $rows[$index]['stock'] = '__single__';
            }
        }

        $pricesEnabled = $this->prices->enabled();
        $resolvedPrices = [];

        if ($pricesEnabled && $priceColumns !== []) {
            if ($mapping !== []) {
                $this->prices->saveImportMappings($mapping);
            }

            $titleMap = $this->prices->typeIdsByTitle();
            $savedMappings = $this->prices->importMappings();
            $unresolved = [];

            foreach ($priceColumns as $source) {
                $normalized = $this->prices->normalizeImportName($source);

                if (isset($titleMap[$normalized])) {
                    $resolvedPrices[$source] = $titleMap[$normalized];
                    continue;
                }

                if (array_key_exists($source, $mapping)) {
                    $resolvedPrices[$source] = max(0, (int) $mapping[$source]);
                    continue;
                }

                if (array_key_exists($normalized, $mapping)) {
                    $resolvedPrices[$source] = max(0, (int) $mapping[$normalized]);
                    continue;
                }

                if (isset($savedMappings[$normalized])) {
                    $resolvedPrices[$source] = $savedMappings[$normalized];
                    continue;
                }

                $unresolved[] = $source;
            }

            if ($unresolved !== []) {
                return [
                    'status' => 'needs_mapping',
                    'columns' => array_values($unresolved),
                    'types' => array_map(
                        static fn (array $type): array => ['id' => (int) $type['id'], 'title' => (string) $type['title']],
                        $this->prices->types()
                    ),
                ];
            }
        }

        $jobId = $this->stocks->createJob($originalName, $actualDate, (int) $user['ID']);
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $updateSid = md5(uniqid('', true));
            $this->stocks->insertUpdate($updateSid, $actualDate, $originalName);

            $warehouses = [];
            $nomenclature = [];
            $newPositions = [];
            $imported = 0;
            $created = 0;
            $warehousesCreated = 0;
            $seen = [];

            $this->stocks->snapshotLevels();
            $existing = $this->stocks->levelsIndex();
            $this->stocks->zeroAllLevels();
            $insert = $this->stocks->prepareLevelInsert();

            $pricesImported = 0;

            foreach ($rows as $row) {
                $stockName = $row['stock'];
                $itemName = $row['item'];
                $unit = $row['unit'];
                $quantity = $row['quantity'];
                if (!isset($warehouses[$stockName])) {
                    if (!$warehousesEnabled) {
                        $warehouse = $this->ensureSingleWarehouse();
                        $warehouseIsNew = false;
                    } else {
                        $warehouseIsNew = !$this->stocks->warehouseExists($stockName);
                        $warehouse = $this->stocks->upsertWarehouse($stockName);
                    }

                    if ($warehouse === null || $warehouse === []) {
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
                    $isNewPosition = !$this->stocks->nomenclatureExists($itemName);
                    $item = $this->stocks->upsertNomenclature($itemName, $unit);

                    if ($item === null) {
                        $skipped++;
                        $errors[] = 'Не удалось создать товар: ' . $itemName;
                        continue;
                    }

                    $nomenclature[$itemName] = $item;

                    if ($isNewPosition) {
                        $newPositions[$itemName] = [
                            'sid' => (string) $item['SID'],
                            'unit' => $unit,
                        ];
                    }
                }

                $key = (string) $warehouses[$stockName]['SID'] . '|' . (string) $nomenclature[$itemName]['SID'];
                $seen[$key] = $quantity;

                if (isset($existing[$key])) {
                    $this->stocks->updateLevelFromImport(
                        $existing[$key]['id'],
                        $updateSid,
                        $actualDate,
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

                if ($resolvedPrices !== [] && $row['prices'] !== []) {
                    $stockSid = (string) $warehouses[$stockName]['SID'];
                    $nameSid = (string) $nomenclature[$itemName]['SID'];

                    foreach ($row['prices'] as $source => $price) {
                        $typeId = $resolvedPrices[$source] ?? 0;

                        if ($typeId <= 0) {
                            continue;
                        }

                        $this->prices->importPrice($stockSid, $nameSid, $typeId, $price);
                        $pricesImported++;
                    }
                }
            }

            // Новая позиция добавляется ко всем складам: где количество не указано в импорте — 0.
            // Существующие строки и настройки позиций (описание, группа, фото) не затрагиваются.
            if ($newPositions !== []) {
                foreach ($newPositions as $itemName => $position) {
                    foreach ($this->stocks->allWarehouses() as $warehouse) {
                        $key = $warehouse['SID'] . '|' . $position['sid'];

                        if (isset($seen[$key]) || isset($existing[$key])) {
                            continue;
                        }

                        $insert->execute([
                            $updateSid,
                            $actualDate,
                            $warehouse['NAME'],
                            $warehouse['SID'],
                            $itemName,
                            $position['sid'],
                            $position['unit'] !== '' ? $position['unit'] : null,
                            0,
                        ]);

                        $created++;
                    }
                }
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
            Logger::error('stocks.import failed', ['job' => $jobId, 'error' => $exception->getMessage()]);
            $this->stocks->finishJob($jobId, 'failed', count($rows) + $skipped, 0, $skipped, ['Импорт не выполнен. Подробности — в журнале.']);

            throw new HttpException(500, 'import_failed', 'Импорт не выполнен. Подробности — в журнале.');
        }

        $this->stocks->finishJob($jobId, 'done', count($rows) + $skipped, $imported, $skipped, $errors);

        $this->audit->log($user, 'stocks.import', 'stocks', $jobId, [
            'file' => $originalName,
            'imported' => $imported,
            'skipped' => $skipped,
            'actual_date' => $actualDate,
        ], null);

        return [
            'status' => 'done',
            'job_id' => $jobId,
            'file_name' => $originalName,
            'actual_date' => $actualDate,
            'rows_total' => count($rows) + $skipped,
            'rows_imported' => $imported,
            'rows_created' => $created,
            'rows_zeroed' => $zeroed,
            'rows_skipped' => $skipped,
            'warehouses_created' => $warehousesCreated,
            'new_positions' => count($newPositions),
            'prices_imported' => $pricesImported,
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
        $skipped = 0;
        $errors = [];

        $cells = in_array($extension, ['xls', 'xlsx'], true)
            ? $this->readSpreadsheet($path)
            : $this->readCsv($path);

        $columns = $this->detectColumns($cells);

        if ($columns !== null) {
            return $this->parseWithColumns($cells, $columns, $skipped, $errors);
        }

        return $this->parseLegacy($cells, $skipped, $errors);
    }

    /**
     * @return array{header_index: int, stock: ?int, item: int, unit: ?int, quantity: int, prices: array<int, array{index: int, name: string}>}|null
     */
    private function detectColumns(array $cells): ?array
    {
        $headerIndex = null;
        $limit = min(count($cells), 20);

        for ($index = 0; $index < $limit; $index++) {
            foreach ($cells[$index] as $cell) {
                if (preg_match('/^\s*остат/ui', trim((string) $cell)) === 1) {
                    $headerIndex = $index;
                    break 2;
                }
            }
        }

        if ($headerIndex === null) {
            return null;
        }

        $stock = null;
        $item = null;
        $unit = null;
        $quantity = null;
        $prices = [];

        foreach ($cells[$headerIndex] as $index => $cell) {
            $text = trim((string) $cell);

            if ($text === '') {
                continue;
            }

            if (preg_match('/склад/ui', $text) === 1) {
                $stock = (int) $index;
                continue;
            }

            if (preg_match('/товар|наимен|позиц/ui', $text) === 1) {
                $item = (int) $index;
                continue;
            }

            if (preg_match('/^ед|единиц/ui', $text) === 1) {
                $unit = (int) $index;
                continue;
            }

            if (preg_match('/^остат/ui', $text) === 1) {
                $quantity = (int) $index;
                continue;
            }

            if (preg_match('/^(№|no\.?|код|артикул)/ui', $text) === 1) {
                continue;
            }

            $prices[] = ['index' => (int) $index, 'name' => $text];
        }

        if ($item === null || $quantity === null) {
            return null;
        }

        return [
            'header_index' => $headerIndex,
            'stock' => $stock,
            'item' => $item,
            'unit' => $unit,
            'quantity' => $quantity,
            'prices' => $prices,
        ];
    }

    private function parseWithColumns(array $cells, array $columns, int &$skipped, array &$errors): array
    {
        $rows = [];
        $priceColumns = array_map(static fn (array $column): string => $column['name'], $columns['prices']);
        $currentWarehouse = '';

        foreach ($cells as $index => $row) {
            if ($index <= $columns['header_index']) {
                continue;
            }

            if ($this->isWarehouseHeader($row)) {
                $currentWarehouse = trim((string) ($row[0] ?? ''));
                continue;
            }

            $itemName = trim((string) ($row[$columns['item']] ?? ''));

            if ($itemName === '') {
                if (trim((string) ($row[0] ?? '')) !== '') {
                    $skipped++;
                }

                continue;
            }

            $stockName = $columns['stock'] !== null
                ? trim((string) ($row[$columns['stock']] ?? ''))
                : $currentWarehouse;

            if ($stockName === '') {
                $skipped++;

                if (count($errors) < 20) {
                    $errors[] = 'Строка ' . ($index + 1) . ': не указан склад';
                }

                continue;
            }

            $unit = $columns['unit'] !== null ? trim((string) ($row[$columns['unit']] ?? '')) : '';
            $rawQuantity = (string) ($row[$columns['quantity']] ?? '');
            $quantity = $this->parseQuantity($rawQuantity);

            if ($quantity === null) {
                $skipped++;

                if (count($errors) < 20) {
                    $errors[] = 'Строка ' . ($index + 1) . ': некорректный остаток "' . trim($rawQuantity) . '"';
                }

                continue;
            }

            $prices = [];

            foreach ($columns['prices'] as $column) {
                $value = $this->parseQuantity((string) ($row[$column['index']] ?? ''));

                if ($value !== null) {
                    $prices[$column['name']] = $value;
                }
            }

            $rows[] = [
                'stock' => $stockName,
                'item' => $itemName,
                'unit' => $unit,
                'quantity' => $quantity,
                'prices' => $prices,
            ];
        }

        return [
            'rows' => $rows,
            'skipped' => $skipped,
            'errors' => $errors,
            'price_columns' => $priceColumns,
        ];
    }

    private function parseLegacy(array $cells, int &$skipped, array &$errors): array
    {
        $rows = [];
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
                    $errors[] = 'Строка ' . ($index + 1) . ': некорректный остаток "' . trim($rawQuantity) . '"';
                }

                continue;
            }

            $rows[] = [
                'stock' => $stockName,
                'item' => $itemName,
                'unit' => $unit,
                'quantity' => $quantity,
                'prices' => [],
            ];
        }

        return [
            'rows' => $rows,
            'skipped' => $skipped,
            'errors' => $errors,
            'price_columns' => [],
        ];
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
            Logger::error('stocks.parseFile failed', ['error' => $exception->getMessage()]);
            throw new HttpException(422, 'read_failed', 'Не удалось прочитать файл. Проверьте формат.');
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
