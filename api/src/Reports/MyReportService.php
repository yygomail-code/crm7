<?php

declare(strict_types=1);

namespace App\Reports;

use App\Export\TableExport;
use App\Http\HttpException;
use App\Repositories\MyReportRepository;
use App\Repositories\RequestStatusRepository;
use DateTimeImmutable;

final class MyReportService
{
    private const PRIORITY_LABELS = [1 => 'Низкий', 2 => 'Обычный', 3 => 'Высокий'];

    public function __construct(
        private readonly MyReportRepository $reports = new MyReportRepository(),
        private readonly RequestStatusRepository $statuses = new RequestStatusRepository()
    ) {
    }

    public function summary(array $user, array $filters): array
    {
        $userId = (int) $user['ID'];
        [$fromDate, $toDate] = $this->period($filters);
        $filters = $this->normalizeFilters($filters);
        $summary = $this->reports->summary($userId, $fromDate, $toDate, $filters);

        return [
            'period' => ['from' => $fromDate, 'to' => $toDate],
            'requests' => $summary['requests'],
            'items' => $summary['items'],
            'dynamics' => $summary['dynamics'],
            'warehouses' => $this->reports->warehouses($userId, $fromDate, $toDate, $filters),
            'statuses' => $this->statuses(),
            'priorities' => array_map(static fn (int $value, string $label): array => [
                'value' => $value,
                'title' => $label,
            ], array_keys(self::PRIORITY_LABELS), array_values(self::PRIORITY_LABELS)),
        ];
    }

    public function export(array $user, array $filters, string $format): array
    {
        $userId = (int) $user['ID'];
        [$fromDate, $toDate] = $this->period($filters);
        $filters = $this->normalizeFilters($filters);
        $detail = ($filters['detail'] ?? '') === 'items' ? 'items' : 'requests';
        $title = $detail === 'items' ? 'Позиции заявок' : 'Заявки';

        if ($detail === 'items') {
            $rows = array_map(static fn (array $row): array => [
                (string) $row['number'],
                (string) $row['created_at'],
                (string) ($row['warehouse_name'] ?? ''),
                (string) $row['name'],
                (string) ($row['unit'] ?? ''),
                number_format((float) $row['quantity'], 3, ',', ' '),
                (string) $row['status_title'],
                self::PRIORITY_LABELS[(int) $row['priority']] ?? 'Обычный',
            ], $this->reports->exportItems($userId, $fromDate, $toDate, $filters));

            $headers = ['Заявка', 'Создана', 'Склад', 'Позиция', 'Ед.', 'Количество', 'Статус', 'Приоритет'];
        } else {
            $rows = array_map(static fn (array $row): array => [
                (string) $row['number'],
                (string) $row['created_at'],
                (string) $row['status_title'],
                self::PRIORITY_LABELS[(int) $row['priority']] ?? 'Обычный',
                (string) $row['subject'],
                (string) $row['items_count'],
                (int) $row['is_overdue'] === 1 ? 'да' : 'нет',
            ], $this->reports->exportRequests($userId, $fromDate, $toDate, $filters));

            $headers = ['Номер', 'Создана', 'Статус', 'Приоритет', 'Тема', 'Позиций', 'Просрочена'];
        }

        if ($rows === []) {
            throw new HttpException(422, 'empty_report', 'За выбранный период и фильтры нет данных для отчёта');
        }

        $sections = [[
            'title' => $title,
            'headers' => $headers,
            'rows' => $rows,
        ]];

        $meta = [
            'Период: ' . $fromDate . ' — ' . $toDate,
            'Фильтр: ' . $this->filterLabel($filters),
            'Сформировано: ' . date('d.m.Y H:i'),
        ];

        return TableExport::build(
            $title . ' ' . $fromDate . ' — ' . $toDate,
            $format,
            $sections,
            $meta
        );
    }

    public static function normalizeFilters(array $input): array
    {
        $priority = (int) ($input['priority'] ?? 0);

        return [
            'status' => trim((string) ($input['status'] ?? '')),
            'priority' => in_array($priority, [1, 2, 3], true) ? $priority : 0,
            'warehouse_id' => (int) ($input['warehouse_id'] ?? 0),
            'item' => trim((string) ($input['item'] ?? '')),
            'detail' => (string) ($input['detail'] ?? '') === 'items' ? 'items' : 'requests',
        ];
    }

    private function period(array $filters): array
    {
        $from = trim((string) ($filters['from'] ?? ''));
        $to = trim((string) ($filters['to'] ?? ''));

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1) {
            $from = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1) {
            $to = (new DateTimeImmutable('today'))->format('Y-m-d');
        }

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    private function statuses(): array
    {
        return array_map(static fn (array $row): array => [
            'code' => (string) $row['code'],
            'title' => (string) $row['title'],
        ], $this->statuses->all());
    }

    private function filterLabel(array $filters): string
    {
        $parts = [];

        if ($filters['status'] !== '') {
            $parts[] = 'статус: ' . $filters['status'];
        }

        if ($filters['priority'] > 0) {
            $parts[] = 'приоритет: ' . (self::PRIORITY_LABELS[$filters['priority']] ?? $filters['priority']);
        }

        if ($filters['warehouse_id'] > 0) {
            $parts[] = 'склад #' . $filters['warehouse_id'];
        }

        if ($filters['item'] !== '') {
            $parts[] = 'позиция: ' . $filters['item'];
        }

        return $parts !== [] ? implode(', ', $parts) : 'без фильтра';
    }
}
