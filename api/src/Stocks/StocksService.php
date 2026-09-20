<?php

declare(strict_types=1);

namespace App\Stocks;

use App\Audit\AuditService;
use App\Core\Config;
use App\Core\Database;
use App\Export\TableExport;
use App\Http\HttpException;
use App\Repositories\StocksRepository;
use App\Repositories\ViewLogRepository;
use Throwable;

final class StocksService
{
    public const MAX_SIZE = 20971520;

    private const ALLOWED_EXTENSIONS = ['xls', 'xlsx', 'csv', 'txt'];

    public function __construct(
        private readonly StocksRepository $stocks = new StocksRepository(),
        private readonly AuditService $audit = new AuditService(),
        private readonly ViewLogRepository $views = new ViewLogRepository()
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
        int $perPage
    ): array {
        $warehouse = $this->requireWarehouse($user, $capabilities, $warehouseId);

        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        $filters = self::normalizeFilters($filters);

        $rows = $this->stocks->levels((string) $warehouse['SID'], $filters, $page, $perPage);
        $total = $this->stocks->levelsCount((string) $warehouse['SID'], $filters);

        if ($filters['q'] !== '') {
            $this->views->add('stocks', $warehouseId, (int) $user['ID'], 'search', $filters['q'], $total);
        } elseif (!$this->views->recentExists('stocks', $warehouseId, (int) $user['ID'], 'list', 30)) {
            $this->views->add('stocks', $warehouseId, (int) $user['ID'], 'list', (string) $warehouse['NAME']);
        }

        return [
            'items' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'unit' => (string) ($row['unit'] ?? ''),
                'quantity' => (float) $row['quantity'],
                'actual_date' => $row['actual_date'] !== null ? (string) $row['actual_date'] : null,
            ], $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'warehouse' => [
                'id' => (int) $warehouse['ID'],
                'name' => (string) $warehouse['NAME'],
            ],
        ];
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

        return [
            'q' => trim((string) ($input['q'] ?? '')),
            'qty_op' => $qtyOp,
            'qty' => $qty,
            'sort' => $sort,
        ];
    }

    public function export(array $user, array $capabilities, int $warehouseId, array $filters, string $format): array
    {
        $warehouse = $this->requireWarehouse($user, $capabilities, $warehouseId);
        $filters = self::normalizeFilters($filters);
        $rows = $this->stocks->levelsAll((string) $warehouse['SID'], $filters);
        $format = TableExport::normalizeFormat($format);

        $sections = [[
            'headers' => ['Остатки', 'Ед.', 'Количество', 'Актуальность'],
            'rows' => array_map(static function (array $row): array {
                return [
                    (string) $row['name'],
                    (string) ($row['unit'] ?? ''),
                    number_format((float) $row['quantity'], 3, ',', ' '),
                    $row['actual_date'] !== null ? (string) $row['actual_date'] : '',
                ];
            }, $rows),
        ]];

        $meta = [
            'Склад: ' . (string) $warehouse['NAME'],
            'Позиций: ' . count($rows),
            'Фильтр: ' . $this->filterLabel($filters),
            'Сформировано: ' . date('d.m.Y H:i'),
        ];

        $result = TableExport::build(
            'Остатки ' . (string) $warehouse['NAME'] . ' ' . date('Y-m-d'),
            $format,
            $sections,
            $meta
        );

        $this->views->add('stocks', $warehouseId, (int) $user['ID'], 'export', $this->filterLabel($filters), count($rows));

        $this->audit->log($user, 'stocks.export', 'stocks', (int) $warehouse['ID'], [
            'rows' => count($rows),
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

        return $parts !== [] ? implode(', ', $parts) : 'без фильтра';
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

            $this->stocks->deleteAllLevels();
            $insert = $this->stocks->prepareLevelInsert();

            foreach ($rows as [$stockName, $itemName, $unit, $quantity]) {
                if (!isset($warehouses[$stockName])) {
                    $warehouse = $this->stocks->upsertWarehouse($stockName);

                    if ($warehouse === null) {
                        $skipped++;
                        $errors[] = 'Не удалось создать склад: ' . $stockName;
                        continue;
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

                $imported++;
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
            'rows_skipped' => $skipped,
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
