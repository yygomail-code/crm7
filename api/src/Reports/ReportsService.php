<?php

declare(strict_types=1);

namespace App\Reports;

use App\Export\TableExport;
use App\Http\HttpException;
use App\Mail\MailService;
use App\Repositories\ReportRepository;
use App\Repositories\ReportScheduleRepository;
use App\Repositories\UserRepository;
use DateTimeImmutable;
use Throwable;

final class ReportsService
{
    private const PRIORITY_LABELS = [1 => 'Низкий', 2 => 'Обычный', 3 => 'Высокий'];

    public function __construct(
        private readonly ReportRepository $reports = new ReportRepository(),
        private readonly ReportScheduleRepository $schedules = new ReportScheduleRepository(),
        private readonly UserRepository $users = new UserRepository(),
        private readonly MailService $mail = new MailService()
    ) {
    }

    public static function scopeFor(array $user, array $capabilities): array
    {
        if (in_array('reports.view.all', $capabilities, true)) {
            return ['all' => true];
        }

        return ['manager_id' => (int) $user['ID']];
    }

    public function summary(array $scope, string $from, string $to): array
    {
        [$fromDate, $toDate] = $this->normalizePeriod($from, $to);
        [$scopeSql, $scopeParams] = $this->scopeSql($scope);

        $totals = $this->reports->totals($scopeSql, $scopeParams, $fromDate, $toDate);
        $totals['unassigned'] = $this->reports->unassigned();

        $byStatus = array_map(static fn (array $row): array => [
            'code' => (string) $row['status_id'],
            'title' => (string) $row['title'],
            'color' => (string) ($row['color'] ?? ''),
            'is_final' => (bool) $row['is_final'],
            'count' => (int) $row['cnt'],
        ], $this->reports->byStatus($scopeSql, $scopeParams, $fromDate, $toDate));

        $byManager = array_map(static fn (array $row): array => [
            'manager_id' => (int) $row['manager_id'],
            'manager_name' => (string) $row['manager_name'],
            'created_in_period' => (int) $row['created_in_period'],
            'open_now' => (int) $row['open_now'],
            'closed_in_period' => (int) $row['closed_in_period'],
            'overdue_now' => (int) $row['overdue_now'],
            'avg_response_minutes' => $row['avg_response_minutes'] !== null ? (int) $row['avg_response_minutes'] : null,
            'avg_resolution_minutes' => $row['avg_resolution_minutes'] !== null ? (int) $row['avg_resolution_minutes'] : null,
        ], $this->reports->byManager($scopeSql, $scopeParams, $fromDate, $toDate));

        $byClient = array_map(static fn (array $row): array => [
            'client_id' => (int) $row['client_id'],
            'client_name' => (string) $row['client_name'],
            'company' => (string) ($row['company'] ?? ''),
            'created_in_period' => (int) $row['created_in_period'],
            'open_now' => (int) $row['open_now'],
            'closed_in_period' => (int) $row['closed_in_period'],
            'overdue_now' => (int) $row['overdue_now'],
            'last_request_at' => $row['last_request_at'] !== null ? (string) $row['last_request_at'] : null,
        ], $this->reports->byClient($scopeSql, $scopeParams, $fromDate, $toDate));

        return [
            'period' => [
                'from' => $fromDate,
                'to' => (new DateTimeImmutable($toDate))->modify('-1 day')->format('Y-m-d'),
            ],
            'totals' => $totals,
            'by_status' => $byStatus,
            'by_manager' => $byManager,
            'by_client' => $byClient,
            'dynamics' => $this->reports->dynamics($scopeSql, $scopeParams, $fromDate, $toDate),
        ];
    }

    public function salesLeads(array $scope, string $from, string $to): array
    {
        [$fromDate, $toDate] = $this->normalizePeriod($from, $to);
        $managerIds = isset($scope['manager_id']) ? [(int) $scope['manager_id']] : null;

        $searches = array_map(static fn (array $row): array => [
            'query' => (string) $row['meta'],
            'count' => (int) $row['cnt'],
            'zero_results' => (int) $row['zero_results'],
        ], $this->reports->stockSearches($fromDate, $toDate, $managerIds));

        $zeroResults = array_values(array_filter($searches, static fn (array $row): bool => $row['zero_results'] > 0));
        usort($zeroResults, static fn (array $a, array $b): int => $b['zero_results'] <=> $a['zero_results']);

        return [
            'period' => [
                'from' => $fromDate,
                'to' => (new DateTimeImmutable($toDate))->modify('-1 day')->format('Y-m-d'),
            ],
            'top_queries' => $searches,
            'zero_result_queries' => array_slice($zeroResults, 0, 10),
            'warehouses' => array_map(static fn (array $row): array => [
                'warehouse_id' => (int) $row['warehouse_id'],
                'warehouse_name' => (string) ($row['warehouse_name'] ?? ''),
                'views' => (int) $row['views'],
                'searches' => (int) $row['searches'],
                'exports' => (int) $row['exports'],
            ], $this->reports->stockActivityByWarehouse($fromDate, $toDate, $managerIds)),
            'clients' => array_map(static fn (array $row): array => [
                'client_id' => (int) $row['client_id'],
                'client_name' => (string) $row['client_name'],
                'company' => (string) ($row['company'] ?? ''),
                'views' => (int) $row['views'],
                'searches' => (int) $row['searches'],
                'exports' => (int) $row['exports'],
                'last_activity_at' => (string) $row['last_activity_at'],
            ], $this->reports->stockActivityByClient($fromDate, $toDate, $managerIds)),
            'recent' => array_map(static fn (array $row): array => [
                'action' => (string) $row['action'],
                'query' => (string) ($row['meta'] ?? ''),
                'result_count' => $row['result_count'] !== null ? (int) $row['result_count'] : null,
                'user_name' => (string) $row['user_name'],
                'user_level' => (int) $row['user_level'],
                'warehouse_name' => (string) ($row['warehouse_name'] ?? ''),
                'created_at' => (string) $row['created_at'],
            ], $this->reports->stockRecentActivity($fromDate, $toDate, $managerIds)),
        ];
    }

    public function salesLeadsExport(array $scope, string $from, string $to, string $format): array
    {
        $report = $this->salesLeads($scope, $from, $to);

        $sections = [
            [
                'title' => 'Запросы (спрос)',
                'headers' => ['Запрос', 'Количество', 'Без результатов'],
                'rows' => array_map(static fn (array $row): array => [
                    (string) $row['query'],
                    (string) $row['count'],
                    (string) $row['zero_results'],
                ], $report['top_queries']),
            ],
            [
                'title' => 'Клиенты',
                'headers' => ['Клиент', 'Компания', 'Просмотры', 'Поиски', 'Выгрузки', 'Последняя активность'],
                'rows' => array_map(static fn (array $row): array => [
                    (string) $row['client_name'],
                    (string) $row['company'],
                    (string) $row['views'],
                    (string) $row['searches'],
                    (string) $row['exports'],
                    (string) $row['last_activity_at'],
                ], $report['clients']),
            ],
        ];

        $meta = [
            'Период: ' . $report['period']['from'] . ' — ' . $report['period']['to'],
            'Сформировано: ' . date('d.m.Y H:i'),
        ];

        return TableExport::build(
            'Целевые продажи ' . $report['period']['from'] . ' — ' . $report['period']['to'],
            $format,
            $sections,
            $meta
        );
    }

    public function warehouses(array $scope, string $from, string $to): array
    {
        [$fromDate, $toDate] = $this->normalizePeriod($from, $to);
        [$scopeSql, $scopeParams] = $this->scopeSql($scope);

        $rows = [];

        foreach ($this->reports->warehouseStock() as $row) {
            $rows[(int) $row['warehouse_id']] = [
                'warehouse_id' => (int) $row['warehouse_id'],
                'warehouse_name' => (string) $row['warehouse_name'],
                'positions' => (int) $row['positions'],
                'zero_positions' => (int) $row['zero_positions'],
                'stock_quantity' => (float) $row['stock_quantity'],
                'requests_count' => 0,
                'clients_count' => 0,
                'requested_quantity' => 0.0,
            ];
        }

        foreach ($this->reports->warehouseRequests($scopeSql, $scopeParams, $fromDate, $toDate) as $row) {
            $id = $row['warehouse_id'] !== null ? (int) $row['warehouse_id'] : 0;

            if (!isset($rows[$id])) {
                $rows[$id] = [
                    'warehouse_id' => $id,
                    'warehouse_name' => (string) ($row['warehouse_name'] ?? '') !== ''
                        ? (string) $row['warehouse_name']
                        : 'Без склада',
                    'positions' => 0,
                    'zero_positions' => 0,
                    'stock_quantity' => 0.0,
                    'requests_count' => 0,
                    'clients_count' => 0,
                    'requested_quantity' => 0.0,
                ];
            }

            $rows[$id]['requests_count'] = (int) $row['requests_count'];
            $rows[$id]['clients_count'] = (int) $row['clients_count'];
            $rows[$id]['requested_quantity'] = (float) $row['requested_quantity'];
        }

        $topItems = array_map(static fn (array $row): array => [
            'name' => (string) $row['name'],
            'total_quantity' => (float) $row['total_quantity'],
            'requests_count' => (int) $row['requests_count'],
            'by_warehouse' => [],
        ], $this->reports->warehouseTopItems($scopeSql, $scopeParams, $fromDate, $toDate));

        if ($topItems !== []) {
            $index = [];

            foreach ($topItems as $position => $item) {
                $index[$item['name']] = $position;
            }

            $breakdown = $this->reports->warehouseTopItemsBreakdown(
                array_keys($index),
                $scopeSql,
                $scopeParams,
                $fromDate,
                $toDate
            );

            foreach ($breakdown as $row) {
                $position = $index[(string) $row['name']] ?? null;

                if ($position === null) {
                    continue;
                }

                $topItems[$position]['by_warehouse'][] = [
                    'warehouse_id' => $row['warehouse_id'] !== null ? (int) $row['warehouse_id'] : 0,
                    'warehouse_name' => (string) ($row['warehouse_name'] ?? ''),
                    'quantity' => (float) $row['quantity'],
                ];
            }
        }

        return [
            'period' => [
                'from' => $fromDate,
                'to' => (new DateTimeImmutable($toDate))->modify('-1 day')->format('Y-m-d'),
            ],
            'warehouses' => array_values($rows),
            'top_items' => $topItems,
        ];
    }

    public function warehousesExport(array $scope, string $from, string $to, string $format): array
    {
        $report = $this->warehouses($scope, $from, $to);

        $sections = [
            [
                'title' => 'Склады',
                'headers' => ['Склад', 'Позиций', 'Нулевых', 'Остаток', 'Заявок', 'Клиентов', 'Запрошено'],
                'rows' => array_map(static fn (array $row): array => [
                    (string) $row['warehouse_name'],
                    (string) $row['positions'],
                    (string) $row['zero_positions'],
                    self::numberText((float) $row['stock_quantity']),
                    (string) $row['requests_count'],
                    (string) $row['clients_count'],
                    self::numberText((float) $row['requested_quantity']),
                ], $report['warehouses']),
            ],
        ];

        if ($report['top_items'] !== []) {
            $sections[] = [
                'title' => 'Топ позиций по заявкам',
                'headers' => ['Позиция', 'Запрошено', 'Заявок', 'По складам'],
                'rows' => array_map(static fn (array $row): array => [
                    (string) $row['name'],
                    self::numberText((float) $row['total_quantity']),
                    (string) $row['requests_count'],
                    implode('; ', array_map(
                        static fn (array $item): string => $item['warehouse_name'] . ': '
                            . self::numberText((float) $item['quantity']),
                        $row['by_warehouse']
                    )),
                ], $report['top_items']),
            ];
        }

        $meta = [
            'Период: ' . $report['period']['from'] . ' - ' . $report['period']['to'],
            'Сформирован: ' . date('d.m.Y H:i'),
        ];

        return TableExport::build('Склады ' . $report['period']['from'], $format, $sections, $meta);
    }

    private static function numberText(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', ' '), '0'), ',');
    }

    public function export(array $scope, string $from, string $to, string $format): array
    {
        [$fromDate, $toDate] = $this->normalizePeriod($from, $to);
        [$scopeSql, $scopeParams] = $this->scopeSql($scope);

        $rows = $this->reports->exportRows($scopeSql, $scopeParams, $fromDate, $toDate);

        $sections = [[
            'title' => 'Заявки',
            'headers' => [
                'Номер', 'Создана', 'Статус', 'Приоритет', 'Клиент', 'Менеджер',
                'Тема', 'Срок', 'Первый ответ', 'Решена', 'Закрыта', 'Просрочена',
            ],
            'rows' => array_map(static fn (array $row): array => [
                (string) $row['number'],
                (string) $row['created_at'],
                (string) $row['status_title'],
                self::PRIORITY_LABELS[(int) $row['priority']] ?? 'Обычный',
                (string) ($row['client_name'] ?? ''),
                (string) ($row['manager_name'] ?? ''),
                (string) $row['subject'],
                (string) ($row['due_at'] ?? ''),
                (string) ($row['first_response_at'] ?? ''),
                (string) ($row['resolved_at'] ?? ''),
                (string) ($row['closed_at'] ?? ''),
                (int) $row['is_overdue'] === 1 ? 'да' : 'нет',
            ], $rows),
        ]];

        $meta = [
            'Период: ' . $fromDate . ' — ' . $toDate,
            'Сформировано: ' . date('d.m.Y H:i'),
        ];

        return TableExport::build('Заявки ' . $fromDate, $format, $sections, $meta);
    }

    public function buildTextReport(string $type, string $from, string $to): string
    {
        $summary = $this->summary(['all' => true], $from, $to);

        $lines = [];
        $lines[] = 'Период: ' . $summary['period']['from'] . ' — ' . $summary['period']['to'];
        $lines[] = '';
        $lines[] = 'Создано заявок: ' . $summary['totals']['created_in_period'];
        $lines[] = 'Закрыто заявок: ' . $summary['totals']['closed_in_period'];
        $lines[] = 'Открыто сейчас: ' . $summary['totals']['open_now'];
        $lines[] = 'Просрочено: ' . $summary['totals']['overdue_now'];
        $lines[] = 'Без менеджера: ' . $summary['totals']['unassigned'];
        $lines[] = '';
        $lines[] = 'По статусам:';

        foreach ($summary['by_status'] as $status) {
            $lines[] = '  ' . $status['title'] . ': ' . $status['count'];
        }

        if ($type === 'managers') {
            $lines[] = '';
            $lines[] = 'По менеджерам:';

            foreach ($summary['by_manager'] as $manager) {
                $lines[] = sprintf(
                    '  %s: создано %d, открыто %d, закрыто %d, просрочено %d, реакция %s',
                    $manager['manager_name'],
                    $manager['created_in_period'],
                    $manager['open_now'],
                    $manager['closed_in_period'],
                    $manager['overdue_now'],
                    $this->formatMinutes($manager['avg_response_minutes'])
                );
            }
        }

        return implode("\n", $lines);
    }

    public function listSchedules(): array
    {
        return array_map(function (array $row): array {
            $recipientIds = json_decode((string) $row['recipients'], true);
            $recipients = [];

            foreach (is_array($recipientIds) ? $recipientIds : [] as $userId) {
                $user = $this->users->findById((int) $userId);

                if ($user !== null) {
                    $recipients[] = [
                        'id' => (int) $user['ID'],
                        'name' => (string) ($user['FULL_NAME'] ?? ''),
                        'email' => (string) ($user['EMAIL'] ?? ''),
                    ];
                }
            }

            return [
                'id' => (int) $row['ID'],
                'title' => (string) $row['title'],
                'report_type' => (string) $row['report_type'],
                'frequency' => (string) $row['frequency'],
                'time_of_day' => (string) $row['time_of_day'],
                'day_of_week' => $row['day_of_week'] !== null ? (int) $row['day_of_week'] : null,
                'day_of_month' => $row['day_of_month'] !== null ? (int) $row['day_of_month'] : null,
                'recipients' => $recipients,
                'extra_emails' => json_decode((string) ($row['extra_emails'] ?? '[]'), true) ?: [],
                'is_active' => (bool) $row['is_active'],
                'last_sent_at' => $row['last_sent_at'],
                'created_at' => (string) $row['created_at'],
            ];
        }, $this->schedules->all());
    }

    public function saveSchedule(?int $id, array $input, int $createdBy): array
    {
        $data = $this->validateSchedule($input, $createdBy);

        if ($id !== null && $id > 0) {
            $existing = $this->schedules->findById($id);

            if ($existing === null) {
                throw new HttpException(404, 'not_found', 'Расписание не найдено');
            }

            $this->schedules->update($id, $data);
        } else {
            $this->schedules->create($data);
        }

        return $this->listSchedules();
    }

    public function deleteSchedule(int $id): void
    {
        if ($this->schedules->findById($id) === null) {
            throw new HttpException(404, 'not_found', 'Расписание не найдено');
        }

        $this->schedules->delete($id);
    }

    public function sendScheduleNow(int $id): array
    {
        $schedule = $this->schedules->findById($id);

        if ($schedule === null) {
            throw new HttpException(404, 'not_found', 'Расписание не найдено');
        }

        $sent = $this->sendSchedule($schedule, null, true);

        return ['sent' => $sent];
    }

    public function processDueSchedules(): array
    {
        $now = new DateTimeImmutable('now');
        $processed = [];

        foreach ($this->schedules->all(true) as $schedule) {
            if (!$this->isDue($schedule, $now)) {
                continue;
            }

            try {
                $sent = $this->sendSchedule($schedule, $now);
                $processed[] = ['id' => (int) $schedule['ID'], 'title' => (string) $schedule['title'], 'sent' => $sent];
            } catch (Throwable $exception) {
                $processed[] = [
                    'id' => (int) $schedule['ID'],
                    'title' => (string) $schedule['title'],
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return $processed;
    }

    private function sendSchedule(array $schedule, ?DateTimeImmutable $now = null, bool $force = false): int
    {
        $now ??= new DateTimeImmutable('now');

        if (!$force && !$this->schedules->claim((int) $schedule['ID'], $now->format('Y-m-d'))) {
            return 0;
        }

        [$from, $to] = $this->periodFor($schedule, $now);

        $text = $this->buildTextReport((string) $schedule['report_type'], $from, $to);
        $rendered = $this->mail->render('report_scheduled', [
            'title' => (string) $schedule['title'],
            'period' => $from . ' — ' . $to,
            'report' => $text,
        ]);

        $sent = 0;

        $recipientIds = json_decode((string) $schedule['recipients'], true);

        foreach (is_array($recipientIds) ? $recipientIds : [] as $userId) {
            $user = $this->users->findById((int) $userId);
            $email = (string) ($user['EMAIL'] ?? '');

            if ($email !== '') {
                $this->mail->enqueue($email, $rendered['subject'], $rendered['body']);
                $sent++;
            }
        }

        $extra = json_decode((string) ($schedule['extra_emails'] ?? '[]'), true);

        foreach (is_array($extra) ? $extra : [] as $email) {
            if (is_string($email) && $email !== '') {
                $this->mail->enqueue($email, $rendered['subject'], $rendered['body']);
                $sent++;
            }
        }

        if ($force) {
            $this->schedules->markSent((int) $schedule['ID']);
        }

        return $sent;
    }

    private function isDue(array $schedule, DateTimeImmutable $now): bool
    {
        $time = substr((string) $schedule['time_of_day'], 0, 5);

        if ($time > $now->format('H:i')) {
            return false;
        }

        if (
            $schedule['last_sent_at'] !== null
            && substr((string) $schedule['last_sent_at'], 0, 10) === $now->format('Y-m-d')
        ) {
            return false;
        }

        return match ((string) $schedule['frequency']) {
            'daily' => true,
            'weekly' => (int) $now->format('N') === (int) $schedule['day_of_week'],
            'monthly' => (int) $now->format('j') === (int) $schedule['day_of_month'],
            default => false,
        };
    }

    private function periodFor(array $schedule, DateTimeImmutable $now): array
    {
        return match ((string) $schedule['frequency']) {
            'weekly' => [
                $now->modify('-6 days')->format('Y-m-d'),
                $now->format('Y-m-d'),
            ],
            'monthly' => [
                $now->modify('first day of last month')->format('Y-m-d'),
                $now->modify('last day of last month')->format('Y-m-d'),
            ],
            default => [
                $now->modify('-1 day')->format('Y-m-d'),
                $now->modify('-1 day')->format('Y-m-d'),
            ],
        };
    }

    private function validateSchedule(array $input, int $createdBy): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $reportType = (string) ($input['report_type'] ?? 'summary');
        $frequency = (string) ($input['frequency'] ?? 'daily');
        $timeOfDay = trim((string) ($input['time_of_day'] ?? '09:00'));

        if ($title === '') {
            throw new HttpException(422, 'validation_error', 'Укажите название отчёта');
        }

        if (!in_array($reportType, ['summary', 'managers'], true)) {
            throw new HttpException(422, 'validation_error', 'Некорректный тип отчёта');
        }

        if (!in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
            throw new HttpException(422, 'validation_error', 'Некорректная периодичность');
        }

        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $timeOfDay) !== 1) {
            throw new HttpException(422, 'validation_error', 'Время указывается в формате ЧЧ:ММ (00:00–23:59)');
        }

        $dayOfWeek = null;
        $dayOfMonth = null;

        if ($frequency === 'weekly') {
            $dayOfWeek = (int) ($input['day_of_week'] ?? 1);

            if ($dayOfWeek < 1 || $dayOfWeek > 7) {
                throw new HttpException(422, 'validation_error', 'День недели: от 1 (пн) до 7 (вс)');
            }
        }

        if ($frequency === 'monthly') {
            $dayOfMonth = (int) ($input['day_of_month'] ?? 1);

            if ($dayOfMonth < 1 || $dayOfMonth > 28) {
                throw new HttpException(422, 'validation_error', 'День месяца: от 1 до 28');
            }
        }

        $recipients = [];
        $rawRecipients = $input['recipients'] ?? [];

        foreach (is_array($rawRecipients) ? $rawRecipients : [] as $userId) {
            $user = $this->users->findById((int) $userId);

            if ($user === null || (string) ($user['EMAIL'] ?? '') === '') {
                throw new HttpException(422, 'validation_error', 'У получателя не заполнен e-mail');
            }

            $recipients[] = (int) $user['ID'];
        }

        $extraEmails = [];
        $rawExtra = $input['extra_emails'] ?? [];

        foreach (is_array($rawExtra) ? $rawExtra : [] as $email) {
            $email = trim((string) $email);

            if ($email === '') {
                continue;
            }

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new HttpException(422, 'validation_error', 'Некорректный дополнительный e-mail: ' . $email);
            }

            $extraEmails[] = $email;
        }

        if ($recipients === [] && $extraEmails === []) {
            throw new HttpException(422, 'validation_error', 'Укажите хотя бы одного получателя');
        }

        return [
            'title' => mb_substr($title, 0, 255),
            'report_type' => $reportType,
            'frequency' => $frequency,
            'time_of_day' => $timeOfDay,
            'day_of_week' => $dayOfWeek,
            'day_of_month' => $dayOfMonth,
            'recipients' => json_encode($recipients, JSON_UNESCAPED_UNICODE),
            'extra_emails' => json_encode($extraEmails, JSON_UNESCAPED_UNICODE),
            'is_active' => !empty($input['is_active']) ? 1 : 0,
            'created_by' => $createdBy,
        ];
    }

    private function scopeSql(array $scope): array
    {
        if (!empty($scope['all'])) {
            return ['', []];
        }

        return [' AND r.manager_id = ?', [(int) ($scope['manager_id'] ?? 0)]];
    }

    private function normalizePeriod(string $from, string $to): array
    {
        $today = new DateTimeImmutable('today');
        $fromDate = $this->parseDate($from) ?? $today->modify('first day of this month');
        $toDate = $this->parseDate($to) ?? $today;

        if ($toDate < $fromDate) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        return [$fromDate->format('Y-m-d'), $toDate->modify('+1 day')->format('Y-m-d')];
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date instanceof DateTimeImmutable ? $date : null;
    }

    private function formatMinutes(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        if ($minutes < 60) {
            return $minutes . ' мин';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest > 0 ? $hours . ' ч ' . $rest . ' мин' : $hours . ' ч';
    }

}
