<?php

declare(strict_types=1);

namespace App\Requests;

use App\Core\Config;
use App\Core\Database;
use App\Http\HttpException;
use App\Repositories\ActivityTypeRepository;
use App\Repositories\ManagerClientRepository;
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
use Throwable;

final class RequestService
{
    private const PRIORITIES = [1, 2, 3];

    public function __construct(
        private readonly RequestRepository $requests = new RequestRepository(),
        private readonly RequestStatusRepository $statuses = new RequestStatusRepository(),
        private readonly RequestHistoryRepository $history = new RequestHistoryRepository(),
        private readonly RequestCommentRepository $comments = new RequestCommentRepository(),
        private readonly ManagerClientRepository $managerClients = new ManagerClientRepository(),
        private readonly UserRepository $users = new UserRepository(),
        private readonly RequestAssignmentRepository $assignments = new RequestAssignmentRepository(),
        private readonly ActivityTypeRepository $activityTypes = new ActivityTypeRepository(),
        private readonly RequestActivityRepository $activities = new RequestActivityRepository(),
        private readonly NotificationService $notifications = new NotificationService(),
        private readonly SubstitutionRepository $substitutions = new SubstitutionRepository(),
        private readonly SettingsRepository $settings = new SettingsRepository(),
        private readonly ViewLogRepository $views = new ViewLogRepository()
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
        if (!is_array($raw)) {
            return [];
        }

        $items = [];

        foreach (array_slice($raw, 0, 50) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $quantity = (float) ($row['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            $warehouseId = (int) ($row['warehouse_id'] ?? 0);

            $items[] = [
                'warehouse_id' => $warehouseId > 0 ? $warehouseId : null,
                'warehouse_name' => mb_substr(trim((string) ($row['warehouse_name'] ?? '')), 0, 255),
                'name' => mb_substr($name, 0, 255),
                'unit' => mb_substr(trim((string) ($row['unit'] ?? '')), 0, 50),
                'quantity' => $quantity,
            ];
        }

        return $items;
    }

    private function itemsText(array $items): string
    {
        $lines = array_map(static function (array $item): string {
            $quantity = rtrim(rtrim(number_format($item['quantity'], 3, ',', ' '), '0'), ',');
            $unit = $item['unit'] !== '' ? ' ' . $item['unit'] : '';
            $warehouse = $item['warehouse_name'] !== '' ? ' (' . $item['warehouse_name'] . ')' : '';

            return '— ' . $item['name'] . ': ' . $quantity . $unit . $warehouse;
        }, $items);

        return "Позиции со склада:\n" . implode("\n", $lines);
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

        $result = $this->requests->list([
            'status' => trim((string) ($filters['status'] ?? '')),
            'q' => trim((string) ($filters['q'] ?? '')),
            'manager_id' => $filters['manager_id'] ?? null,
            'client_id' => $filters['client_id'] ?? null,
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

        return [
            'request' => $this->mapRequest($request),
            'items' => $this->requests->itemsForRequests([$id])[$id] ?? [],
            'history' => array_map([$this, 'mapHistory'], $this->history->listByRequest($id)),
            'comments' => $isStaff
                ? array_map([$this, 'mapComment'], $this->comments->listByRequest($id, true))
                : [],
            'assignments' => array_map([$this, 'mapAssignment'], $this->assignments->listByRequest($id)),
            'activities' => array_map([$this, 'mapActivity'], $this->activities->listByRequest($id)),
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
                'cancel' => !$isStaff && $this->canTransition($user, $capabilities, $request),
                'claim' => $this->canClaim($user, $capabilities, $request),
                'assign' => $isManager && !$isFinal,
                'comment' => $isStaff,
                'comment_internal' => $isManager,
                'activity' => true,
            ],
        ];
    }

    public function create(array $user, array $capabilities, array $input): array
    {
        $this->requireCapability($capabilities, 'requests.create');

        $subject = trim((string) ($input['subject'] ?? ''));

        if (mb_strlen($subject) < 3) {
            throw new HttpException(422, 'validation_error', 'Укажите тему заявки (минимум 3 символа)');
        }

        if (mb_strlen($subject) > 255) {
            $subject = mb_substr($subject, 0, 255);
        }

        $body = trim((string) ($input['body'] ?? ''));
        $items = $this->normalizeItems($input['items'] ?? []);

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
            $allowed = in_array('requests.view.all', $capabilities, true)
                || $this->managerClients->isManagerOf($requestedClientId, (int) $user['ID']);

            if (!$allowed) {
                throw new HttpException(403, 'forbidden', 'Можно создавать заявки только для своих клиентов');
            }

            $client = $this->users->findById($requestedClientId);

            if ($client === null || ($client['ACTIVE'] ?? 'N') !== 'Y') {
                throw new HttpException(422, 'validation_error', 'Клиент не найден');
            }

            $clientId = $requestedClientId;
        }

        $managerId = $this->managerClients->primaryManagerForClient($clientId);
        $substitutionId = null;

        if ($managerId !== null) {
            $substitution = $this->substitutions->activeForManager($managerId);

            if ($substitution !== null) {
                $substitutionId = (int) $substitution['ID'];
                $managerId = (int) $substitution['substitute_id'];
            }
        }

        $slaHours = max(1, (int) ($this->settings->get('sla.resolution_hours') ?? Config::int('REQUEST_SLA_HOURS', 24)));

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
            $this->history->add($id, (int) $user['ID'], null, 'new', 'Заявка создана');

            if ($managerId !== null) {
                $this->assignments->add(
                    $id,
                    null,
                    $managerId,
                    (int) $user['ID'],
                    $substitutionId !== null ? 'Замещение: менеджер в отпуске' : 'Автоназначение по клиенту'
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

    public function transition(array $user, array $capabilities, int $id, array $input): array
    {
        $toStatus = trim((string) ($input['to_status'] ?? ''));
        $comment = trim((string) ($input['comment'] ?? ''));
        $version = isset($input['version']) ? (int) $input['version'] : null;

        if (!$this->statuses->exists($toStatus)) {
            throw new HttpException(422, 'validation_error', 'Неизвестный статус');
        }

        if (mb_strlen($comment) < 3) {
            throw new HttpException(422, 'comment_required', 'Смена статуса требует комментария');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $request = $this->requests->findByIdForUpdate($id);

            if ($request === null) {
                throw new HttpException(404, 'not_found', 'Заявка не найдена');
            }

            $this->assertCanView($user, $capabilities, $request);

            if ($version !== null && $version !== (int) $request['version']) {
                throw new HttpException(409, 'version_conflict', 'Заявка изменена другим пользователем, обновите страницу');
            }

            if (!$this->canTransition($user, $capabilities, $request)) {
                throw new HttpException(403, 'forbidden', 'Недостаточно прав для смены статуса');
            }

            $isManager = $this->canManage($user, $capabilities, $request);

            if (!$isManager && $toStatus !== 'canceled') {
                throw new HttpException(403, 'forbidden', 'Клиент может только отменить заявку');
            }

            $fromStatus = (string) $request['status_id'];

            if ($fromStatus === $toStatus) {
                throw new HttpException(422, 'validation_error', 'Заявка уже в этом статусе');
            }

            $target = $this->statuses->map()[$toStatus] ?? null;

            if ($target === null) {
                throw new HttpException(422, 'validation_error', 'Неизвестный статус');
            }

            $timestampOps = [];

            if ($fromStatus === 'new' && $toStatus !== 'canceled' && empty($request['first_response_at'])) {
                $timestampOps['first_response_at'] = 'set';
            }

            if ($toStatus === 'resolved') {
                $timestampOps['resolved_at'] = 'set';
            }

            if (in_array($toStatus, ['closed', 'canceled'], true)) {
                $timestampOps['closed_at'] = 'set';
            }

            if (!(bool) $target['is_final']) {
                $timestampOps['resolved_at'] = 'clear';
                $timestampOps['closed_at'] = 'clear';
            }

            $this->requests->applyTransition($id, $toStatus, $timestampOps);
            $this->history->add($id, (int) $user['ID'], $fromStatus, $toStatus, $comment);

            $fromTitle = (string) ($this->statuses->map()[$fromStatus]['title'] ?? $fromStatus);

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

    public function bulk(array $user, array $capabilities, array $input): array
    {
        $rawIds = is_array($input['ids'] ?? null) ? $input['ids'] : [];
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $rawIds),
            static fn (int $id): bool => $id > 0
        )));

        $action = (string) ($input['action'] ?? '');

        if ($ids === []) {
            throw new HttpException(422, 'validation_error', 'Выберите заявки');
        }

        if (count($ids) > 100) {
            throw new HttpException(422, 'validation_error', 'За раз можно обработать до 100 заявок');
        }

        if (!in_array($action, ['assign', 'transition'], true)) {
            throw new HttpException(422, 'validation_error', 'Неизвестное массовое действие');
        }

        $updated = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                if ($action === 'assign') {
                    $this->assignRequest($user, $capabilities, $id, $input);
                } else {
                    $this->transition($user, $capabilities, $id, $input);
                }

                $updated++;
            } catch (HttpException $exception) {
                $errors[] = ['id' => $id, 'message' => $exception->getMessage()];
            }
        }

        return [
            'updated' => $updated,
            'failed' => count($errors),
            'errors' => array_slice($errors, 0, 20),
        ];
    }

    public function claim(array $user, array $capabilities, int $id): array
    {
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

    public function assignRequest(array $user, array $capabilities, int $id, array $input): array
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

            if ($version !== null && $version !== (int) $request['version']) {
                throw new HttpException(409, 'version_conflict', 'Заявка изменена другим пользователем, обновите страницу');
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

        $this->activities->add(
            $id,
            (int) $user['ID'],
            $typeCode,
            (string) $type['title'],
            $body !== '' ? $body : null
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

        $isInternal = (bool) ($input['is_internal'] ?? false)
            && $this->canManage($user, $capabilities, $request);

        $this->comments->add($id, (int) $user['ID'], $body, $isInternal);

        if (!$isInternal) {
            $this->notifications->notifyParticipants(
                $request,
                (int) $user['ID'],
                'request.comment',
                'Новый комментарий по заявке ' . (string) $request['number'],
                $body,
                ['request_number' => (string) $request['number']]
            );
        }

        return $this->detail($user, $capabilities, $id);
    }

    private function scopeFor(array $user, array $capabilities): array
    {
        if (in_array('requests.view.all', $capabilities, true)) {
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
        if (in_array('requests.view.all', $capabilities, true)) {
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

    private function requireCapability(array $capabilities, string $capability): void
    {
        if (!in_array($capability, $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав');
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
            ],
            'manager' => $row['manager_id'] !== null
                ? ['id' => (int) $row['manager_id'], 'name' => (string) ($row['manager_name'] ?? '')]
                : null,
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
