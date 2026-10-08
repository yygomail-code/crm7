<?php

declare(strict_types=1);

namespace App\Requests;

use App\Clients\ClientManagerService;
use App\Chat\ChatService;
use App\Core\Config;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Http\HttpException;
use App\Repositories\ActivityTypeRepository;
use App\Repositories\RequestActivityRepository;
use App\Repositories\RequestAssignmentRepository;
use App\Repositories\RequestCommentRepository;
use App\Repositories\RequestHistoryRepository;
use App\Repositories\RequestRepository;
use App\Repositories\RequestStatusRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\SubstitutionRepository;
use App\Repositories\UserRepository;
use App\Repositories\ViewLogRepository;
use App\Notifications\NotificationService;
use App\Prices\PriceService;
use App\Stocks\StockReservationService;
use Throwable;

final class RequestService
{
    private const PRIORITIES = [1, 2, 3];

    private const ITEMS_BRIEF_LIMIT = 15;

    public function __construct(
        private readonly RequestRepository $requests = new RequestRepository(),
        private readonly RequestStatusRepository $statuses = new RequestStatusRepository(),
        private readonly RequestHistoryRepository $history = new RequestHistoryRepository(),
        private readonly RequestCommentRepository $comments = new RequestCommentRepository(),
        private readonly ClientManagerService $clientManagers = new ClientManagerService(),
        private readonly UserRepository $users = new UserRepository(),
        private readonly RequestAssignmentRepository $assignments = new RequestAssignmentRepository(),
        private readonly ActivityTypeRepository $activityTypes = new ActivityTypeRepository(),
        private readonly RequestActivityRepository $activities = new RequestActivityRepository(),
        private readonly NotificationService $notifications = new NotificationService(),
        private readonly SubstitutionRepository $substitutions = new SubstitutionRepository(),
        private readonly SettingsRepository $settings = new SettingsRepository(),
        private readonly ViewLogRepository $views = new ViewLogRepository(),
        private readonly ChatService $chat = new ChatService(),
        private readonly StockReservationService $reservations = new StockReservationService(),
        private readonly PriceService $prices = new PriceService()
    ) {
    }

    private ?array $substitutedIdsCache = null;

    private int $substitutedIdsUser = 0;

    public function assertAccess(array $user, array $capabilities, array $request): void
    {
        $this->assertCanView($user, $capabilities, $request);
    }

    public function canManageRequest(array $user, array $capabilities, array $request): bool
    {
        return $this->canManage($user, $capabilities, $request);
    }

    public function filters(array $user, array $capabilities): array
    {
        $warehouses = array_map(static fn (array $row): array => [
            'id' => (int) $row['warehouse_id'],
            'name' => (string) ($row['warehouse_name'] ?? ''),
        ], $this->requests->warehousesForScope($this->scopeFor($user, $capabilities)));

        return [
            'statuses' => $this->statuses(),
            'warehouses' => $warehouses,
            'sorts' => [
                ['code' => 'created_desc', 'title' => 'Сначала новые'],
                ['code' => 'created_asc', 'title' => 'Сначала старые'],
                ['code' => 'due_asc', 'title' => 'По сроку'],
                ['code' => 'priority_desc', 'title' => 'По приоритету'],
                ['code' => 'status', 'title' => 'По статусу'],
            ],
        ];
    }

    private function normalizeItems(mixed $raw): array
    {
        return RequestItems::normalize($raw);
    }

    private function itemsText(array $items): string
    {
        $lines = array_map(fn (array $item): string => $this->itemLine($item), $items);

        return "Позиции со склада:\n" . implode("\n", $lines);
    }

    private function itemLine(array $item): string
    {
        $unit = (string) ($item['unit'] ?? '') !== '' ? ' ' . $item['unit'] : '';
        $warehouse = (string) ($item['warehouse_name'] ?? '') !== '' ? ' (' . $item['warehouse_name'] . ')' : '';

        return '— ' . $item['name'] . ': ' . $this->quantityText((float) $item['quantity']) . $unit . $warehouse;
    }

    private function quantityText(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, ',', ' '), '0'), ',');
    }

    private function itemsCountText(int $count): string
    {
        $mod100 = $count % 100;
        $mod10 = $count % 10;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return $count . ' позиций';
        }

        return match ($mod10) {
            1 => $count . ' позиция',
            2, 3, 4 => $count . ' позиции',
            default => $count . ' позиций',
        };
    }

    private function defaultSubject(array $items): string
    {
        $date = date('d.m.Y');

        if ($items === []) {
            return 'Заявка от ' . $date;
        }

        return 'Заявка на ' . $this->itemsCountText(count($items)) . ' от ' . $date;
    }

    private function itemsBrief(array $items): string
    {
        $limit = self::ITEMS_BRIEF_LIMIT;
        $lines = [];

        foreach (array_slice($items, 0, $limit) as $item) {
            $lines[] = $this->itemLine($item);
        }

        if (count($items) > $limit) {
            $lines[] = '— и ещё ' . (count($items) - $limit) . ' поз.';
        }

        return implode("\n", $lines);
    }

    private function itemsMap(array $items): array
    {
        $map = [];

        foreach ($items as $item) {
            $key = ((int) ($item['warehouse_id'] ?? 0)) . '|' . mb_strtolower(trim((string) $item['name']));
            $map[$key] = $item;
        }

        return $map;
    }

    private function itemsAddedText(array $items): string
    {
        return 'Добавлено ' . $this->itemsCountText(count($items)) . ":\n" . $this->itemsBrief($items);
    }

    private function itemsDiffText(array $before, array $after): string
    {
        $beforeMap = $this->itemsMap($before);
        $afterMap = $this->itemsMap($after);

        $added = [];
        $changed = [];
        $removed = [];

        foreach ($afterMap as $key => $item) {
            if (!isset($beforeMap[$key])) {
                $added[] = $item;

                continue;
            }

            if (abs((float) $beforeMap[$key]['quantity'] - (float) $item['quantity']) > 0.00001) {
                $changed[] = ['before' => $beforeMap[$key], 'after' => $item];
            }
        }

        foreach ($beforeMap as $key => $item) {
            if (!isset($afterMap[$key])) {
                $removed[] = $item;
            }
        }

        if ($added === [] && $changed === [] && $removed === []) {
            return '';
        }

        $parts = [];

        if ($added !== []) {
            $parts[] = "Добавлено:\n" . $this->itemsBrief($added);
        }

        if ($removed !== []) {
            $parts[] = "Убрано:\n" . $this->itemsBrief($removed);
        }

        if ($changed !== []) {
            $lines = [];

            foreach (array_slice($changed, 0, self::ITEMS_BRIEF_LIMIT) as $pair) {
                $unit = (string) ($pair['after']['unit'] ?? '') !== '' ? ' ' . $pair['after']['unit'] : '';
                $warehouse = (string) ($pair['after']['warehouse_name'] ?? '') !== '' ? ' (' . $pair['after']['warehouse_name'] . ')' : '';

                $lines[] = '— ' . $pair['after']['name'] . ': '
                    . $this->quantityText((float) $pair['before']['quantity']) . ' → '
                    . $this->quantityText((float) $pair['after']['quantity']) . $unit . $warehouse;
            }

            if (count($changed) > self::ITEMS_BRIEF_LIMIT) {
                $lines[] = '— и ещё ' . (count($changed) - self::ITEMS_BRIEF_LIMIT) . ' поз.';
            }

            $parts[] = "Изменено:\n" . implode("\n", $lines);
        }

        $parts[] = $after === []
            ? 'Осталось: позиций нет'
            : 'Осталось ' . $this->itemsCountText(count($after)) . ":\n" . $this->itemsBrief($after);

        return $this->limitText(implode("\n\n", $parts));
    }

    public function statuses(): array
    {
        return array_map(static fn (array $row): array => [
            'code' => (string) $row['code'],
            'title' => (string) $row['title'],
            'sort' => (int) $row['sort'],
            'color' => (string) ($row['color'] ?? ''),
            'is_final' => (bool) $row['is_final'],
        ], $this->statuses->all());
    }

    public function list(array $user, array $capabilities, array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(5, (int) ($filters['per_page'] ?? 20)));

        $managerScope = (string) ($filters['manager_scope'] ?? '');
        $state = (string) ($filters['state'] ?? '');

        $result = $this->requests->list([
            'status' => trim((string) ($filters['status'] ?? '')),
            'q' => trim((string) ($filters['q'] ?? '')),
            'manager_scope' => in_array($managerScope, ['all', 'none', 'mine', 'others'], true) ? $managerScope : '',
            'manager_scope_user' => (int) $user['ID'],
            'manager_id' => $filters['manager_id'] ?? null,
            'client_id' => $filters['client_id'] ?? null,
            'state' => in_array($state, ['open', 'closed', 'overdue'], true) ? $state : '',
            'warehouse_id' => $filters['warehouse_id'] ?? null,
            'from' => trim((string) ($filters['from'] ?? '')),
            'to' => trim((string) ($filters['to'] ?? '')),
            'sort' => trim((string) ($filters['sort'] ?? '')),
        ], $this->scopeFor($user, $capabilities), $page, $perPage);

        return [
            'items' => array_map([$this, 'mapRequest'], $result['items']),
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    public function summary(array $user, array $capabilities): array
    {
        $summary = $this->requests->summary($this->scopeFor($user, $capabilities));
        $summary['statuses'] = $this->statuses();

        return $summary;
    }

    public function detail(array $user, array $capabilities, int $id): array
    {
        $request = $this->requests->detail($id);

        if ($request === null) {
            throw new HttpException(404, 'not_found', 'Заявка не найдена');
        }

        $this->assertCanView($user, $capabilities, $request);

        $isStaff = (int) $user['LEVEL'] >= 10;

        if ($isStaff && !$this->views->recentExists('request', $id, (int) $user['ID'], 'view', 15)) {
            $this->views->add('request', $id, (int) $user['ID'], 'view');
        }

        $isManager = $this->canManage($user, $capabilities, $request);
        $status = $this->statuses->map()[(string) $request['status_id']] ?? null;
        $isFinal = $status !== null && (bool) $status['is_final'];
        $managerId = $request['manager_id'] !== null ? (int) $request['manager_id'] : null;
        $previousManager = $managerId !== null ? $this->assignments->previousManager($id, $managerId) : null;
        $chat = $this->chat->threadForClient($user, $capabilities, (int) $request['client_id'], $id);

        $decoratedItems = $this->prices->decorateItems(
            $this->requests->itemsForRequests([$id])[$id] ?? [],
            $this->users->findById((int) $request['client_id'])
        );

        return [
            'request' => $this->mapRequest($request),
            'items' => $decoratedItems['items'],
            'prices' => $decoratedItems['prices'],
            'history' => array_map([$this, 'mapHistory'], $this->history->listByRequest($id)),
            'comments' => $isStaff
                ? array_map([$this, 'mapComment'], $this->comments->listByRequest($id, true))
                : [],
            'assignments' => array_map([$this, 'mapAssignment'], $this->assignments->listByRequest($id)),
            'activities' => array_map([$this, 'mapActivity'], array_values(array_filter(
                $this->activities->listByRequest($id),
                static fn (array $row): bool => $isStaff || (string) ($row['audience'] ?? 'all') !== 'manager'
            ))),
            'views' => $isStaff
                ? array_map(static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'action' => (string) $row['action'],
                    'user' => [
                        'id' => (int) $row['user_id'],
                        'name' => (string) $row['user_name'],
                        'level' => (int) $row['user_level'],
                    ],
                    'created_at' => (string) $row['created_at'],
                ], $this->views->listForEntity('request', $id, 30))
                : [],
            'previous_manager' => $previousManager !== null
                ? ['id' => (int) $previousManager['id'], 'name' => (string) $previousManager['name']]
                : null,
            'can' => [
                'transition' => $isStaff && $this->canTransition($user, $capabilities, $request),
                'transition_to' => $this->transitionTargets($user, $capabilities, $request, $isManager),
                'cancel' => !$isStaff && $this->canTransition($user, $capabilities, $request),
                'claim' => $this->canClaim($user, $capabilities, $request),
                'assign' => $isManager && !$isFinal,
                'comment' => $isStaff,
                'comment_internal' => $isManager,
                'activity' => true,
                'edit' => $this->canEdit($user, $capabilities, $request),
                'priority' => $isStaff && $this->canEdit($user, $capabilities, $request),
                'meta' => $isStaff && !$isFinal && $this->canTransition($user, $capabilities, $request),
                'edit_text' => $isStaff && $this->canTransition($user, $capabilities, $request),
                'edit_items' => $this->canEditItems($user, $capabilities, $request),
            ],
            'chat' => $chat,
        ];
    }

    public function create(array $user, array $capabilities, array $input): array
    {
        $this->requireCapability($capabilities, 'requests.create');

        if (!$this->settings->salesEnabled()) {
            throw new HttpException(403, 'sales_disabled', 'Продажи отключены: оформление заявок недоступно');
        }

        RateLimiter::hit('request.create', 'user:' . (int) $user['ID'], 60, 3600);

        $body = trim((string) ($input['body'] ?? ''));
        $items = $this->normalizeItems($input['items'] ?? []);
        $subject = trim((string) ($input['subject'] ?? ''));

        if ($subject === '') {
            $subject = $this->defaultSubject($items);
        }

        if (mb_strlen($subject) > 255) {
            $subject = mb_substr($subject, 0, 255);
        }

        if ($items !== []) {
            $body = trim($body === '' ? $this->itemsText($items) : $body . "\n\n" . $this->itemsText($items));
        }

        if (mb_strlen($body) > 5000) {
            $body = mb_substr($body, 0, 5000);
        }

        $priority = (int) ($input['priority'] ?? 2);

        if (!in_array($priority, self::PRIORITIES, true)) {
            $priority = 2;
        }

        $clientId = (int) $user['ID'];
        $requestedClientId = (int) ($input['client_id'] ?? 0);

        if ($requestedClientId > 0 && $requestedClientId !== $clientId) {
            if ((int) $user['LEVEL'] < 10) {
                throw new HttpException(403, 'forbidden', 'Можно создавать заявки только от своего имени');
            }

            $client = $this->users->findById($requestedClientId);

            if ($client === null || ($client['ACTIVE'] ?? 'N') !== 'Y' || (int) $client['LEVEL'] !== 5) {
                throw new HttpException(422, 'validation_error', 'Клиент не найден');
            }

            $clientId = $requestedClientId;
        } elseif ((int) $user['LEVEL'] >= 10) {
            throw new HttpException(422, 'client_required', 'Выберите клиента, к которому привязать заявку');
        }

        $managerId = (int) $user['LEVEL'] >= 10 ? (int) $user['ID'] : null;
        $substitutionId = null;

        if ($managerId !== null) {
            $substitution = $this->substitutions->activeForManager($managerId);

            if ($substitution !== null) {
                $substitutionId = (int) $substitution['ID'];
                $managerId = (int) $substitution['substitute_id'];
            }
        } else {
            $boundManagerId = $this->clientManagers->managerIdFor($clientId);

            if ($boundManagerId !== null) {
                $effective = $this->clientManagers->effectiveForManager($boundManagerId);
                $managerId = $effective['id'];
                $substitutionId = $effective['substitution_id'];
            }
        }

        $slaHours = max(1, (int) ($this->settings->get('sla.resolution_hours') ?? Config::int('REQUEST_SLA_HOURS', 24)));

        $items = $this->prices->attachPrices(
            $items,
            $this->prices->effectiveTypeIdForUser($this->users->findById($clientId))
        );

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $id = $this->requests->create([
                'subject' => $subject,
                'body' => $body,
                'client_id' => $clientId,
                'manager_id' => $managerId,
                'status_id' => 'new',
                'priority' => $priority,
                'source' => 'cabinet',
                'sla_hours' => $slaHours,
                'substitution_id' => $substitutionId,
            ]);

            $number = $this->requests->setNumber($id);
            $this->requests->createItems($id, $items);
            $this->reservations->apply($id, $items);
            $this->history->add($id, (int) $user['ID'], null, 'new', 'Заявка создана');

            if ($items !== []) {
                $this->activities->add(
                    $id,
                    (int) $user['ID'],
                    'items',
                    'Состав заявки',
                    $this->itemsAddedText($items)
                );
            }

            if ($managerId !== null) {
                $this->assignments->add(
                    $id,
                    null,
                    $managerId,
                    (int) $user['ID'],
                    $substitutionId !== null ? 'Замещение: менеджер в отпуске' : 'Заявка создана менеджером'
                );
                $this->notifications->notify(
                    $managerId,
                    'request.new',
                    'Новая заявка ' . $number,
                    $subject,
                    $id,
                    ['request_number' => $number]
                );
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        return $this->detail($user, $capabilities, $id);
    }

    /**
     * Предварительный расчёт цен по составу заявки для текущего клиента
     * или выбранного клиента (сотрудником). Ничего не сохраняет.
     */
    public function preview(array $user, array $input): array
    {
        $items = $this->normalizeItems($input['items'] ?? []);
        $clientId = (int) ($input['client_id'] ?? 0);
        $target = $user;

        if ($clientId > 0 && $clientId !== (int) $user['ID']) {
            if ((int) $user['LEVEL'] < 10) {
                throw new HttpException(403, 'forbidden', 'Можно смотреть цены только для себя');
            }

            $client = $this->users->findById($clientId);

            if ($client === null || (int) $client['LEVEL'] !== 5) {
                throw new HttpException(422, 'validation_error', 'Клиент не найден');
            }

            $target = $client;
        }

        if (!$this->prices->enabled()) {
            return ['enabled' => false, 'type' => null, 'items' => [], 'total' => null];
        }

        $typeId = $this->prices->effectiveTypeIdForUser($target);
        $type = $typeId !== null ? $this->prices->findType($typeId) : null;
        $rows = $this->prices->previewRows($this->prices->attachPrices($items, $typeId));

        return [
            'enabled' => true,
            'type' => $type !== null ? ['id' => (int) $type['id'], 'title' => (string) $type['title']] : null,
            'items' => $rows,
            'total' => $this->prices->total($rows),
        ];
    }

    public function updateItems(array $user, array $capabilities, int $id, array $input): array
    {
        $version = isset($input['version']) ? (int) $input['version'] : null;
        $items = $this->normalizeItems($input['items'] ?? []);
        $comment = trim((string) ($input['comment'] ?? ''));

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $request = $this->requests->findByIdForUpdate($id);

            if ($request === null) {
                throw new HttpException(404, 'not_found', 'Заявка не найдена');
            }

            $this->assertCanView($user, $capabilities, $request);

            if (!$this->canEditItems($user, $capabilities, $request)) {
                throw new HttpException(403, 'forbidden', 'Изменять состав можно только у активной заявки');
            }

            $this->assertVersion($version, $request);

            $items = $this->prices->attachPrices(
                $items,
                $this->prices->effectiveTypeIdForUser($this->users->findById((int) $request['client_id']))
            );

            $before = $this->requests->itemsForRequests([$id])[$id] ?? [];

            $this->requests->replaceItems($id, $items);
            $this->reservations->apply($id, $items);

            $diff = $this->itemsDiffText($before, $items);

            if ($comment !== '') {
                $comment = mb_substr($comment, 0, 500);
                $diff = $diff !== '' ? $diff . "\n" . $comment : $comment;
            }

            if ($diff !== '') {
                $this->activities->add($id, (int) $user['ID'], 'items', 'Состав заявки изменён', $diff);
            }

            $this->notifications->notifyParticipants(
                $request,
                (int) $user['ID'],
                'request.items',
                'Заявка ' . (string) $request['number'] . ': изменён состав',
                $items === []
                    ? 'Позиции убраны из заявки'
                    : 'В заявке ' . $this->itemsCountText(count($items)) . '. Подробности — в истории заявки',
                ['request_number' => (string) $request['number']]
            );

            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        return $this->detail($user, $capabilities, $id);
    }

    private function canEdit(array $user, array $capabilities, array $request): bool
    {
        if ((string) $request['status_id'] !== 'new') {
            return false;
        }

        if ((int) $request['client_id'] === (int) $user['ID']) {
            return true;
        }

        return $this->canManage($user, $capabilities, $request);
    }

    private function canEditItems(array $user, array $capabilities, array $request): bool
    {
        if ($this->canEdit($user, $capabilities, $request)) {
            return true;
        }

        if ((int) ($user['LEVEL'] ?? 0) < 10) {
            return false;
        }

        $status = $this->statuses->map()[(string) $request['status_id']] ?? null;

        if ($status !== null && (bool) $status['is_final']) {
            return false;
        }

        return $this->canTransition($user, $capabilities, $request);
    }

    public function updateRequest(array $user, array $capabilities, int $id, array $input): array
    {
        $version = isset($input['version']) ? (int) $input['version'] : null;
        $subject = trim((string) ($input['subject'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));
        $items = $this->normalizeItems($input['items'] ?? []);

        if (mb_strlen($subject) > 255) {
            $subject = mb_substr($subject, 0, 255);
        }

        if (mb_strlen($body) > 5000) {
            $body = mb_substr($body, 0, 5000);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $request = $this->requests->findByIdForUpdate($id);

            if ($request === null) {
                throw new HttpException(404, 'not_found', 'Заявка не найдена');
            }

            $this->assertCanView($user, $capabilities, $request);

            if (!$this->canEdit($user, $capabilities, $request)) {
                throw new HttpException(403, 'forbidden', 'Изменить заявку можно только в статусе «Новая»');
            }

            $this->assertVersion($version, $request);

            $beforeItems = $this->requests->itemsForRequests([$id])[$id] ?? [];

            if ($subject === '') {
                $subject = $this->defaultSubject($items !== [] ? $items : $beforeItems);
            }

            $oldSubject = (string) $request['subject'];
            $oldBody = (string) ($request['body'] ?? '');

            $subjectChanged = $subject !== $oldSubject;
            $bodyChanged = $body !== $oldBody;
            $itemsChanged = $this->itemsChanged($beforeItems, $items);

            $newClientId = isset($input['client_id']) ? (int) $input['client_id'] : 0;

            if ($newClientId > 0 && $newClientId !== (int) $request['client_id']) {
                throw new HttpException(422, 'client_locked', 'Клиента заявки нельзя изменить после создания');
            }

            $priority = null;

            if (array_key_exists('priority', $input) && (int) $user['LEVEL'] >= 10) {
                $candidate = (int) $input['priority'];

                if (in_array($candidate, self::PRIORITIES, true) && $candidate !== (int) $request['priority']) {
                    $priority = $candidate;
                }
            }

            if ($subjectChanged || $bodyChanged) {
                $this->requests->updateDetails($id, $subject, $body);
            }

            if ($itemsChanged) {
                if (!$this->settings->salesEnabled()) {
                    throw new HttpException(403, 'sales_disabled', 'Продажи отключены: изменение состава заявки недоступно');
                }

                $items = $this->prices->attachPrices(
                    $items,
                    $this->prices->effectiveTypeIdForUser($this->users->findById((int) $request['client_id']))
                );

                $this->requests->replaceItems($id, $items);
                $this->reservations->apply($id, $items);
            }

            if ($priority !== null) {
                $this->requests->updatePriority($id, $priority);

                $this->activities->add(
                    $id,
                    (int) $user['ID'],
                    'priority',
                    'Приоритет изменён',
                    'Было: ' . $this->priorityText((int) $request['priority']) . ' → Стало: ' . $this->priorityText($priority)
                );

                $this->notifications->notifyParticipants(
                    $request,
                    (int) $user['ID'],
                    'request.priority',
                    'Заявка ' . (string) $request['number'] . ': приоритет изменён',
                    'Новый приоритет: ' . $this->priorityText($priority),
                    ['request_number' => (string) $request['number']]
                );
            }

            if ($subjectChanged || $bodyChanged) {
                $lines = [];

                if ($subjectChanged) {
                    $lines[] = 'Тема: «' . $oldSubject . '» → «' . $subject . '»';
                }

                if ($bodyChanged) {
                    $lines[] = $oldBody === ''
                        ? "Добавлено описание:\n" . $body
                        : "Описание (было):\n" . $oldBody;
                }

                $this->activities->add(
                    $id,
                    (int) $user['ID'],
                    'edit',
                    'Заявка изменена',
                    $this->limitText(implode("\n\n", $lines))
                );
            }

            if ($itemsChanged) {
                $diff = $this->itemsDiffText($beforeItems, $items);

                if ($diff !== '') {
                    $this->activities->add($id, (int) $user['ID'], 'items', 'Состав заявки изменён', $diff);
                }
            }

            if ($subjectChanged || $bodyChanged || $itemsChanged) {
                $this->notifications->notifyParticipants(
                    $request,
                    (int) $user['ID'],
                    'request.edit',
                    'Заявка ' . (string) $request['number'] . ': изменена',
                    'Тема, описание или состав заявки обновлены. Подробности — в истории заявки',
                    ['request_number' => (string) $request['number']]
                );
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        return $this->detail($user, $capabilities, $id);
    }

    public function updateMeta(array $user, array $capabilities, int $id, array $input): array
    {
        $version = isset($input['version']) ? (int) $input['version'] : null;

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $request = $this->requests->findByIdForUpdate($id);

            if ($request === null) {
                throw new HttpException(404, 'not_found', 'Заявка не найдена');
            }

            $this->assertCanView($user, $capabilities, $request);

            if (!$this->canTransition($user, $capabilities, $request)) {
                throw new HttpException(403, 'forbidden', 'Недостаточно прав для изменения заявки');
            }

            $isStaff = (int) ($user['LEVEL'] ?? 0) >= 10;

            if (!$isStaff && (array_key_exists('subject', $input) || array_key_exists('body', $input))) {
                throw new HttpException(403, 'forbidden', 'Редактирование текста заявки доступно сотрудникам');
            }

            if (!$isStaff && (array_key_exists('priority', $input) || array_key_exists('due_at', $input))) {
                throw new HttpException(403, 'forbidden', 'Изменение приоритета и срока доступно сотрудникам');
            }

            $status = $this->statuses->map()[(string) $request['status_id']] ?? null;
            $isFinal = $status !== null && (bool) $status['is_final'];

            if ($isFinal && (array_key_exists('priority', $input) || array_key_exists('due_at', $input))) {
                throw new HttpException(422, 'final_status', 'Заявка завершена — изменения недоступны');
            }

            $this->assertVersion($version, $request);

            $newSubject = array_key_exists('subject', $input)
                ? trim((string) $input['subject'])
                : (string) $request['subject'];
            $newBody = array_key_exists('body', $input)
                ? trim((string) $input['body'])
                : (string) ($request['body'] ?? '');

            if (array_key_exists('subject', $input) && $newSubject === '') {
                throw new HttpException(422, 'validation_error', 'Тема заявки не может быть пустой');
            }

            if (mb_strlen($newSubject) > 255) {
                $newSubject = mb_substr($newSubject, 0, 255);
            }

            if (mb_strlen($newBody) > 5000) {
                $newBody = mb_substr($newBody, 0, 5000);
            }

            $subjectChanged = $newSubject !== (string) $request['subject'];
            $bodyChanged = $newBody !== (string) ($request['body'] ?? '');

            if ($subjectChanged || $bodyChanged) {
                $this->requests->updateDetails($id, $newSubject, $newBody);

                if ($subjectChanged) {
                    $this->activities->add(
                        $id,
                        (int) $user['ID'],
                        'subject',
                        'Тема изменена',
                        'Было: «' . (string) $request['subject'] . '» → Стало: «' . $newSubject . '»'
                    );
                }

                if ($bodyChanged) {
                    $this->activities->add(
                        $id,
                        (int) $user['ID'],
                        'body',
                        'Описание изменено',
                        $newBody !== '' ? 'Новое описание: ' . $newBody : 'Описание очищено'
                    );
                }
            }

            if (array_key_exists('priority', $input)) {
                $candidate = (int) $input['priority'];

                if (!in_array($candidate, self::PRIORITIES, true)) {
                    throw new HttpException(422, 'validation_error', 'Неизвестный приоритет');
                }

                if ($candidate !== (int) $request['priority']) {
                    $this->requests->updatePriority($id, $candidate);

                    $this->activities->add(
                        $id,
                        (int) $user['ID'],
                        'priority',
                        'Приоритет изменён',
                        'Было: ' . $this->priorityText((int) $request['priority']) . ' → Стало: ' . $this->priorityText($candidate)
                    );

                    $this->notifications->notifyParticipants(
                        $request,
                        (int) $user['ID'],
                        'request.priority',
                        'Заявка ' . (string) $request['number'] . ': приоритет изменён',
                        'Новый приоритет: ' . $this->priorityText($candidate),
                        ['request_number' => (string) $request['number']]
                    );
                }
            }

            if (array_key_exists('due_at', $input)) {
                $raw = trim((string) ($input['due_at'] ?? ''));

                if ($raw === '') {
                    throw new HttpException(422, 'due_required', 'Срок заявки обязателен');
                }

                $timestamp = strtotime($raw);

                if ($timestamp === false) {
                    throw new HttpException(422, 'validation_error', 'Некорректная дата срока');
                }

                $dueAt = date('Y-m-d H:i:s', $timestamp);

                $current = $request['due_at'] !== null && (string) $request['due_at'] !== ''
                    ? (string) $request['due_at']
                    : null;

                if ($dueAt !== $current) {
                    $comment = trim((string) ($input['comment'] ?? ''));

                    if (mb_strlen($comment) < 3) {
                        throw new HttpException(422, 'comment_required', 'Комментарий к изменению срока обязателен');
                    }

                    $this->requests->updateDueAt($id, $dueAt);

                    $this->activities->add(
                        $id,
                        (int) $user['ID'],
                        'due',
                        'Срок изменён',
                        ($current !== null ? 'Было: ' . $current : 'Срок не был задан')
                            . ' → Стало: ' . $dueAt
                            . '. ' . $comment
                    );

                    $this->notifications->notifyParticipants(
                        $request,
                        (int) $user['ID'],
                        'request.due',
                        'Заявка ' . (string) $request['number'] . ': изменён срок',
                        'Новый срок: ' . $dueAt . '. ' . $comment,
                        ['request_number' => (string) $request['number']]
                    );
                }
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        return $this->detail($user, $capabilities, $id);
    }

    private function priorityText(int $priority): string
    {
        return match ($priority) {
            1 => 'Высокий',
            3 => 'Низкий',
            default => 'Обычный',
        };
    }

    private function itemsChanged(array $before, array $after): bool
    {
        if (count($before) !== count($after)) {
            return true;
        }

        $beforeMap = $this->itemsMap($before);
        $afterMap = $this->itemsMap($after);

        if (count($beforeMap) !== count($afterMap)) {
            return true;
        }

        foreach ($afterMap as $key => $item) {
            if (!isset($beforeMap[$key])) {
                return true;
            }

            if (abs((float) $beforeMap[$key]['quantity'] - (float) $item['quantity']) > 0.00001) {
                return true;
            }
        }

        return false;
    }

    private function limitText(string $text): string
    {
        if (mb_strlen($text) > 2000) {
            return mb_substr($text, 0, 1990) . '…';
        }

        return $text;
    }

    public function transition(array $user, array $capabilities, int $id, array $input, bool $requireVersion = true): array
    {
        $toStatus = trim((string) ($input['to_status'] ?? ''));
        $comment = trim((string) ($input['comment'] ?? ''));
        $version = isset($input['version']) ? (int) $input['version'] : null;

        if (!$this->statuses->exists($toStatus)) {
            throw new HttpException(422, 'validation_error', 'Неизвестный статус');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $request = $this->requests->findByIdForUpdate($id);

            if ($request === null) {
                throw new HttpException(404, 'not_found', 'Заявка не найдена');
            }

            $this->assertCanView($user, $capabilities, $request);

            if (!$this->canTransition($user, $capabilities, $request)) {
                throw new HttpException(403, 'forbidden', 'Недостаточно прав для смены статуса');
            }

            $isManager = $this->canManage($user, $capabilities, $request);

            if (!$isManager && $toStatus !== 'canceled') {
                throw new HttpException(403, 'forbidden', 'Клиент может только отменить заявку');
            }

            if ($requireVersion) {
                $this->assertVersion($version, $request);
            }

            $fromStatus = (string) $request['status_id'];

            if ($fromStatus === $toStatus) {
                throw new HttpException(422, 'validation_error', 'Заявка уже в этом статусе');
            }

            $allowedTargets = $isManager ? $this->allowedTargets($fromStatus) : ['canceled'];

            if (!in_array($toStatus, $allowedTargets, true)) {
                $fromTitle = (string) ($this->statuses->map()[$fromStatus]['title'] ?? $fromStatus);

                throw new HttpException(
                    422,
                    'invalid_transition',
                    'Из статуса «' . $fromTitle . '» нельзя перейти в этот статус'
                );
            }

            $target = $this->statuses->map()[$toStatus] ?? null;

            if ($target === null) {
                throw new HttpException(422, 'validation_error', 'Неизвестный статус');
            }

            $timestampOps = [];

            if (!(bool) $target['is_final']) {
                $timestampOps['resolved_at'] = 'clear';
                $timestampOps['closed_at'] = 'clear';
            }

            if ($fromStatus === 'new' && $toStatus !== 'canceled' && empty($request['first_response_at'])) {
                $timestampOps['first_response_at'] = 'set';
            }

            if ($toStatus === 'resolved') {
                $timestampOps['resolved_at'] = 'set';
            }

            if (in_array($toStatus, ['closed', 'canceled'], true)) {
                $timestampOps['closed_at'] = 'set';
            }

            $fromTitle = (string) ($this->statuses->map()[$fromStatus]['title'] ?? $fromStatus);
            $historyComment = 'Было: ' . $fromTitle . ' → Стало: ' . (string) $target['title'];

            if ($comment !== '') {
                $historyComment .= "\n" . $comment;
            }

            $this->requests->applyTransition($id, $toStatus, $timestampOps);

            if ($toStatus === 'canceled') {
                $this->reservations->release($id);
            }

            $this->history->add($id, (int) $user['ID'], $fromStatus, $toStatus, $historyComment);

            $this->notifications->notifyParticipants(
                $request,
                (int) $user['ID'],
                'request.status',
                'Заявка ' . (string) $request['number'] . ': ' . $fromTitle . ' → ' . (string) $target['title'],
                $comment,
                ['request_number' => (string) $request['number']]
            );

            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        return $this->detail($user, $capabilities, $id);
    }

    public function claim(array $user, array $capabilities, int $id): array
    {
        $this->requireCapability($capabilities, 'requests.transition');

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $request = $this->requests->findByIdForUpdate($id);

            if ($request === null) {
                throw new HttpException(404, 'not_found', 'Заявка не найдена');
            }

            if (!$this->canClaim($user, $capabilities, $request)) {
                throw new HttpException(409, 'already_assigned', 'Заявка уже назначена другому менеджеру');
            }

            $currentManagerId = $request['manager_id'] !== null ? (int) $request['manager_id'] : null;

            $this->requests->assignManager($id, (int) $user['ID']);
            $this->assignments->add($id, $currentManagerId, (int) $user['ID'], (int) $user['ID'], 'Взял заявку в работу');
            $this->history->add(
                $id,
                (int) $user['ID'],
                (string) $request['status_id'],
                (string) $request['status_id'],
                'Взял заявку в работу'
            );

            $this->notifications->notify(
                (int) $request['client_id'],
                'request.claim',
                'Заявка ' . (string) $request['number'] . ' взята в работу',
                (string) ($user['FULL_NAME'] ?? ''),
                $id,
                ['request_number' => (string) $request['number']]
            );

            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        return $this->detail($user, $capabilities, $id);
    }

    public function assignRequest(array $user, array $capabilities, int $id, array $input, bool $requireVersion = true): array
    {
        $comment = trim((string) ($input['comment'] ?? ''));

        if (mb_strlen($comment) < 3) {
            throw new HttpException(422, 'comment_required', 'Передача заявки требует пояснения');
        }

        $hasManager = array_key_exists('manager_id', $input)
            && $input['manager_id'] !== null
            && $input['manager_id'] !== '';
        $managerId = $hasManager ? (int) $input['manager_id'] : null;
        $version = isset($input['version']) ? (int) $input['version'] : null;

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $request = $this->requests->findByIdForUpdate($id);

            if ($request === null) {
                throw new HttpException(404, 'not_found', 'Заявка не найдена');
            }

            $this->assertCanView($user, $capabilities, $request);

            if (!$this->canManage($user, $capabilities, $request)) {
                throw new HttpException(403, 'forbidden', 'Передавать заявку может её менеджер или РОП');
            }

                if ($requireVersion) {
                    $this->assertVersion($version, $request);
                }

            $status = $this->statuses->map()[(string) $request['status_id']] ?? null;

            if ($status !== null && (bool) $status['is_final']) {
                throw new HttpException(422, 'final_status', 'Заявка закрыта — сначала верните её в работу');
            }

            $currentManagerId = $request['manager_id'] !== null ? (int) $request['manager_id'] : null;

            if ($managerId !== null) {
                if ($managerId === $currentManagerId) {
                    throw new HttpException(422, 'same_manager', 'Заявка уже назначена этому менеджеру');
                }

                $manager = $this->users->findById($managerId);

                if (
                    $manager === null
                    || ($manager['ACTIVE'] ?? 'N') !== 'Y'
                    || !in_array((int) $manager['LEVEL'], [10, 50, 90], true)
                ) {
                    throw new HttpException(422, 'validation_error', 'Менеджер не найден');
                }
            }

            $this->requests->assignManager($id, $managerId);
            $this->assignments->add($id, $currentManagerId, $managerId, (int) $user['ID'], $comment);

            $historyComment = $managerId === null
                ? 'Передал в поиск менеджера: ' . $comment
                : 'Передал заявку: ' . $comment;

            $this->history->add(
                $id,
                (int) $user['ID'],
                (string) $request['status_id'],
                (string) $request['status_id'],
                $historyComment
            );

            $number = (string) $request['number'];

            if ($managerId !== null && $managerId !== (int) $user['ID']) {
                $this->notifications->notify(
                    $managerId,
                    'request.assigned',
                    'Вам передана заявка ' . $number,
                    $comment,
                    $id,
                    ['request_number' => $number]
                );
            }

            if (
                $currentManagerId !== null
                && $currentManagerId !== (int) $user['ID']
                && $currentManagerId !== $managerId
            ) {
                $this->notifications->notify(
                    $currentManagerId,
                    'request.transferred',
                    'Заявка ' . $number . ' передана',
                    $comment,
                    $id,
                    ['request_number' => $number]
                );
            }

            if ((int) $request['client_id'] !== (int) $user['ID']) {
                $managerName = '';

                if ($managerId !== null) {
                    $managerRow = $this->users->findById($managerId);
                    $managerName = (string) ($managerRow['FULL_NAME'] ?? '');
                }

                $this->notifications->notify(
                    (int) $request['client_id'],
                    'request.assign',
                    $managerId !== null
                        ? 'Заявка ' . $number . ': менеджер ' . $managerName
                        : 'Заявка ' . $number . ': поиск менеджера',
                    $comment,
                    $id,
                    ['request_number' => $number]
                );
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        return $this->detail($user, $capabilities, $id);
    }

    public function activityTypes(array $capabilities): array
    {
        $isManagerRole = $this->isManagerRole($capabilities);

        $items = array_map(static fn (array $row): array => [
            'code' => (string) $row['code'],
            'title' => (string) $row['title'],
            'audience' => (string) $row['audience'],
            'sort' => (int) $row['sort'],
        ], $this->activityTypes->all());

        return array_values(array_filter($items, static function (array $item) use ($isManagerRole): bool {
            if ($item['audience'] === 'all') {
                return true;
            }

            return $item['audience'] === 'manager' ? $isManagerRole : !$isManagerRole;
        }));
    }

    public function addActivity(array $user, array $capabilities, int $id, array $input): array
    {
        $typeCode = trim((string) ($input['type'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));

        $request = $this->requests->detail($id);

        if ($request === null) {
            throw new HttpException(404, 'not_found', 'Заявка не найдена');
        }

        $this->assertCanView($user, $capabilities, $request);

        $type = $this->activityTypes->map()[$typeCode] ?? null;

        if ($type === null || !(bool) $type['is_active']) {
            throw new HttpException(422, 'validation_error', 'Выберите активность из списка');
        }

        $isManagerRole = $this->isManagerRole($capabilities);
        $audience = (string) $type['audience'];
        $allowed = $audience === 'all'
            || ($audience === 'manager' && $isManagerRole)
            || ($audience === 'client' && !$isManagerRole);

        if (!$allowed) {
            throw new HttpException(403, 'forbidden', 'Эта активность недоступна для вашей роли');
        }

        if (str_starts_with($typeCode, 'other') && mb_strlen($body) < 3) {
            throw new HttpException(422, 'validation_error', 'Для «Другое» опишите активность');
        }

        if (mb_strlen($body) > 2000) {
            $body = mb_substr($body, 0, 2000);
        }

        $occurredAt = null;
        $occurredRaw = trim((string) ($input['occurred_at'] ?? ''));

        if ($occurredRaw !== '') {
            $timestamp = strtotime($occurredRaw);

            if ($timestamp === false) {
                throw new HttpException(422, 'validation_error', 'Некорректная дата действия');
            }

            $occurredAt = date('Y-m-d H:i:s', $timestamp);
        }

        $this->activities->add(
            $id,
            (int) $user['ID'],
            $typeCode,
            (string) $type['title'],
            $body !== '' ? $body : null,
            $occurredAt
        );

        $this->notifications->notifyParticipants(
            $request,
            (int) $user['ID'],
            'request.activity',
            'Активность по заявке ' . (string) $request['number'] . ': ' . (string) $type['title'],
            $body !== '' ? $body : null
        );

        return $this->detail($user, $capabilities, $id);
    }

    public function addComment(array $user, array $capabilities, int $id, array $input): array
    {
        if ((int) $user['LEVEL'] < 10) {
            throw new HttpException(403, 'forbidden', 'Комментарии доступны только сотрудникам');
        }

        RateLimiter::hit('request.comment', 'user:' . (int) $user['ID'], 120, 3600);

        $body = trim((string) ($input['body'] ?? ''));

        if ($body === '') {
            throw new HttpException(422, 'validation_error', 'Введите текст комментария');
        }

        if (mb_strlen($body) > 5000) {
            $body = mb_substr($body, 0, 5000);
        }

        $request = $this->requests->detail($id);

        if ($request === null) {
            throw new HttpException(404, 'not_found', 'Заявка не найдена');
        }

        $this->assertCanView($user, $capabilities, $request);

        $this->comments->add($id, (int) $user['ID'], $body, true);

        return $this->detail($user, $capabilities, $id);
    }

    private function scopeFor(array $user, array $capabilities): array
    {
        if ((int) $user['LEVEL'] >= 10) {
            return [];
        }

        return [
            'participant_id' => (int) $user['ID'],
            'include_unassigned' => in_array('requests.transition', $capabilities, true),
            'substituted_ids' => $this->substitutedIds($user),
        ];
    }

    private function substitutedIds(array $user): array
    {
        $userId = (int) $user['ID'];

        if ($this->substitutedIdsCache === null || $this->substitutedIdsUser !== $userId) {
            $this->substitutedIdsUser = $userId;
            $this->substitutedIdsCache = $this->substitutions->activeSubstitutedIds($userId);
        }

        return $this->substitutedIdsCache;
    }

    private function assertCanView(array $user, array $capabilities, array $request): void
    {
        if ((int) $user['LEVEL'] >= 10) {
            return;
        }

        $isClient = (int) $request['client_id'] === (int) $user['ID'];
        $isManager = (int) ($request['manager_id'] ?? 0) === (int) $user['ID'];
        $isSubstitute = in_array((int) ($request['manager_id'] ?? 0), $this->substitutedIds($user), true);
        $isUnassignedForManager = $request['manager_id'] === null
            && in_array('requests.transition', $capabilities, true);
        $requestId = (int) ($request['ID'] ?? 0);
        $isParticipant = $requestId > 0 && $this->assignments->isParticipant($requestId, (int) $user['ID']);

        if (!$isClient && !$isManager && !$isSubstitute && !$isUnassignedForManager && !$isParticipant) {
            throw new HttpException(403, 'forbidden', 'Нет доступа к заявке');
        }
    }

    private function canManage(array $user, array $capabilities, array $request): bool
    {
        if (in_array('requests.view.all', $capabilities, true)) {
            return true;
        }

        $managerId = (int) ($request['manager_id'] ?? 0);

        if ($managerId === (int) $user['ID']) {
            return true;
        }

        return in_array($managerId, $this->substitutedIds($user), true);
    }

    private function canTransition(array $user, array $capabilities, array $request): bool
    {
        if ($this->canManage($user, $capabilities, $request)) {
            return true;
        }

        if ((int) $request['client_id'] !== (int) $user['ID']) {
            return false;
        }

        $status = $this->statuses->map()[(string) $request['status_id']] ?? null;

        return $status !== null && !(bool) $status['is_final'];
    }

    private function canClaim(array $user, array $capabilities, array $request): bool
    {
        if (!in_array('requests.transition', $capabilities, true)) {
            return false;
        }

        return $request['manager_id'] === null || (int) $request['manager_id'] === 0;
    }

    private const TRANSITIONS = [
        'new' => ['accepted', 'in_progress', 'waiting', 'closed', 'canceled'],
        'accepted' => ['in_progress', 'waiting', 'resolved', 'closed', 'canceled'],
        'in_progress' => ['waiting', 'resolved', 'closed', 'canceled'],
        'waiting' => ['in_progress', 'resolved', 'closed', 'canceled'],
        'resolved' => ['in_progress', 'closed', 'canceled'],
        'closed' => [],
        'canceled' => [],
    ];

    private function allowedTargets(string $from): array
    {
        $targets = self::TRANSITIONS[$from] ?? [];
        $statuses = $this->statuses->map();

        return array_values(array_filter(
            $targets,
            static fn (string $code): bool => isset($statuses[$code]) && (bool) $statuses[$code]['is_active']
        ));
    }

    private function transitionTargets(array $user, array $capabilities, array $request, bool $isManager): array
    {
        if (!$this->canTransition($user, $capabilities, $request)) {
            return [];
        }

        if (!$isManager) {
            return ['canceled'];
        }

        return $this->allowedTargets((string) $request['status_id']);
    }

    private function requireCapability(array $capabilities, string $capability): void
    {
        if (!in_array($capability, $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав');
        }
    }

    private function assertVersion(?int $version, array $request): void
    {
        if ($version === null) {
            throw new HttpException(422, 'version_required', 'Не указана версия заявки — обновите страницу и повторите');
        }

        if ($version !== (int) $request['version']) {
            throw new HttpException(409, 'version_conflict', 'Заявка изменена другим пользователем, обновите страницу');
        }
    }

    private function mapRequest(array $row): array
    {
        return [
            'id' => (int) $row['ID'],
            'number' => (string) ($row['number'] ?? ''),
            'subject' => (string) $row['subject'],
            'body' => (string) ($row['body'] ?? ''),
            'status' => [
                'code' => (string) $row['status_id'],
                'title' => (string) ($row['status_title'] ?? ''),
                'color' => (string) ($row['status_color'] ?? ''),
                'is_final' => (bool) ($row['status_is_final'] ?? false),
            ],
            'priority' => (int) $row['priority'],
            'client' => [
                'id' => (int) $row['client_id'],
                'name' => (string) ($row['client_name'] ?? ''),
                'email' => (string) ($row['client_email'] ?? ''),
                'phone' => (string) ($row['client_phone'] ?? ''),
                'inn' => (string) ($row['client_inn'] ?? ''),
            ],
            'manager' => $row['manager_id'] !== null
                ? ['id' => (int) $row['manager_id'], 'name' => (string) ($row['manager_name'] ?? '')]
                : null,
            'items_count' => (int) ($row['items_count'] ?? 0),
            'due_at' => $row['due_at'],
            'is_overdue' => (bool) ($row['is_overdue'] ?? false),
            'first_response_at' => $row['first_response_at'],
            'resolved_at' => $row['resolved_at'],
            'closed_at' => $row['closed_at'],
            'version' => (int) $row['version'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    private function mapHistory(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'user' => [
                'id' => (int) $row['user_id'],
                'name' => (string) $row['user_name'],
                'level' => (int) $row['user_level'],
                'position' => (string) ($row['user_position'] ?? ''),
            ],
            'from' => $row['from_status_id'] !== null
                ? ['code' => (string) $row['from_status_id'], 'title' => (string) ($row['from_title'] ?? '')]
                : null,
            'to' => $row['to_status_id'] !== null
                ? ['code' => (string) $row['to_status_id'], 'title' => (string) ($row['to_title'] ?? '')]
                : null,
            'comment' => (string) ($row['comment'] ?? ''),
            'created_at' => (string) $row['created_at'],
        ];
    }

    private function mapComment(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'user' => [
                'id' => (int) $row['user_id'],
                'name' => (string) $row['user_name'],
                'level' => (int) $row['user_level'],
            ],
            'body' => (string) $row['body'],
            'is_internal' => (bool) $row['is_internal'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    private function isManagerRole(array $capabilities): bool
    {
        return in_array('requests.transition', $capabilities, true)
            || in_array('requests.view.all', $capabilities, true);
    }

    private function mapActivity(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'type' => [
                'code' => (string) $row['type_code'],
                'title' => (string) $row['title'],
            ],
            'user' => [
                'id' => (int) $row['user_id'],
                'name' => (string) $row['user_name'],
                'level' => (int) $row['user_level'],
                'position' => (string) ($row['user_position'] ?? ''),
            ],
            'body' => (string) ($row['body'] ?? ''),
            'created_at' => (string) $row['created_at'],
        ];
    }

    private function mapAssignment(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'from' => $row['from_manager_id'] !== null
                ? ['id' => (int) $row['from_manager_id'], 'name' => (string) ($row['from_name'] ?? '')]
                : null,
            'to' => $row['to_manager_id'] !== null
                ? ['id' => (int) $row['to_manager_id'], 'name' => (string) ($row['to_name'] ?? '')]
                : null,
            'user' => [
                'id' => (int) $row['user_id'],
                'name' => (string) $row['user_name'],
            ],
            'comment' => (string) ($row['comment'] ?? ''),
            'created_at' => (string) $row['created_at'],
        ];
    }
}
