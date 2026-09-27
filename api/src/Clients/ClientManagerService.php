<?php

declare(strict_types=1);

namespace App\Clients;

use App\Core\Database;
use App\Http\HttpException;
use App\Notifications\NotificationService;
use App\Repositories\ChatRepository;
use App\Repositories\ClientManagerRepository;
use App\Repositories\RequestAssignmentRepository;
use App\Repositories\RequestHistoryRepository;
use App\Repositories\RequestRepository;
use App\Repositories\SubstitutionRepository;
use App\Repositories\UserHistoryRepository;
use App\Repositories\UserRepository;
use Throwable;

final class ClientManagerService
{
    public function __construct(
        private readonly ClientManagerRepository $managers = new ClientManagerRepository(),
        private readonly UserRepository $users = new UserRepository(),
        private readonly SubstitutionRepository $substitutions = new SubstitutionRepository(),
        private readonly RequestRepository $requests = new RequestRepository(),
        private readonly RequestAssignmentRepository $assignments = new RequestAssignmentRepository(),
        private readonly RequestHistoryRepository $requestHistory = new RequestHistoryRepository(),
        private readonly UserHistoryRepository $history = new UserHistoryRepository(),
        private readonly ChatRepository $chat = new ChatRepository(),
        private readonly NotificationService $notifications = new NotificationService()
    ) {
    }

    public function managerFor(int $clientId): ?array
    {
        return $this->managers->findByClient($clientId);
    }

    public function managerIdFor(int $clientId): ?int
    {
        return $this->managers->managerIdFor($clientId);
    }

    public function forClients(array $clientIds): array
    {
        return $this->managers->forClients($clientIds);
    }

    public function effectiveManagerId(int $clientId): ?int
    {
        $managerId = $this->managerIdFor($clientId);

        return $managerId !== null ? $this->effectiveForManager($managerId)['id'] : null;
    }

    public function effectiveForManager(int $managerId): array
    {
        $substitution = $this->substitutions->activeForManager($managerId);

        if ($substitution !== null) {
            return [
                'id' => (int) $substitution['substitute_id'],
                'substitution_id' => (int) $substitution['ID'],
                'substituted' => true,
            ];
        }

        return ['id' => $managerId, 'substitution_id' => null, 'substituted' => false];
    }

    public function canManage(array $actor, array $capabilities, int $clientId): bool
    {
        foreach ($this->managerIds($actor) as $managerId) {
            if ($this->managers->isManagerOf($clientId, $managerId)) {
                return true;
            }
        }

        return false;
    }

    public function assertCanManage(array $actor, array $capabilities, int $clientId): void
    {
        if (!$this->canManage($actor, $capabilities, $clientId)) {
            throw new HttpException(403, 'forbidden', 'Управлять профилем клиента может его менеджер или замещающий');
        }
    }

    public function claim(array $actor, array $capabilities, int $clientId): array
    {
        $client = $this->assertClient($clientId);

        if ((int) $actor['LEVEL'] < 10) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав');
        }

        if (($client['ACTIVE'] ?? 'N') !== 'Y') {
            throw new HttpException(422, 'not_active', 'Клиент недоступен');
        }

        $actorId = (int) $actor['ID'];
        $actorName = (string) ($actor['FULL_NAME'] ?? '');
        $clientName = (string) ($client['FULL_NAME'] ?? '');

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $this->managers->lockClient($clientId);

            if ($this->managerIdFor($clientId) !== null) {
                throw new HttpException(409, 'already_bound', 'Клиент уже закреплён за менеджером');
            }

            $this->applyManager($clientId, $actorId, $actorId, $actorId);
            $this->history->add($clientId, 'manager_claimed', $actorId, 'Менеджер: «' . $actorName . '» (взял клиента)');

            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();

            throw $exception;
        }

        $this->notifications->notify(
            $clientId,
            'client.manager',
            'Ваш менеджер: ' . $actorName,
            'Теперь ваши запросы ведёт ' . $actorName . '.',
            null
        );

        return ['id' => $clientId, 'manager' => $this->presentManager($this->managerFor($clientId))];
    }

    public function assign(array $actor, array $capabilities, int $clientId, ?int $managerId, string $comment): array
    {
        $this->requireCapability($capabilities, 'clients.assign');
        $client = $this->assertClient($clientId);
        $comment = trim($comment);

        if ($managerId !== null) {
            $this->assertManager($managerId, 'Менеджер не найден');
        }

        $clientName = (string) ($client['FULL_NAME'] ?? '');
        $actorId = (int) $actor['ID'];
        $actorName = (string) ($actor['FULL_NAME'] ?? '');
        $current = $this->managerIdFor($clientId);

        if ($current === $managerId) {
            throw new HttpException(422, 'same_manager', $managerId === null
                ? 'Клиент и так без менеджера'
                : 'Клиент уже закреплён за этим менеджером');
        }

        $oldName = $current !== null ? $this->userName($current) : '—';
        $newName = $managerId !== null ? $this->userName($managerId) : '—';

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $this->managers->lockClient($clientId);
            $current = $this->managerIdFor($clientId);

            $cancelled = $this->managers->cancelOpen($clientId, 0, $actorId);
            $this->applyManager($clientId, $managerId, $actorId, $actorId);

            $commentText = 'Менеджер: «' . $oldName . '» → «' . $newName . '» (назначил ' . $actorName . ')';
            $this->history->add(
                $clientId,
                $managerId === null ? 'manager_unassigned' : 'manager_assigned',
                $actorId,
                $comment !== '' ? $commentText . '. ' . $comment : $commentText
            );

            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();

            throw $exception;
        }

        foreach ($cancelled as $row) {
            if ($row['to_manager_id'] === null) {
                continue;
            }

            $this->notifications->notify(
                (int) $row['to_manager_id'],
                'client.transfer',
                'Передача клиента отменена: ' . $clientName,
                'Назначен новый менеджер.',
                null
            );
        }

        $this->notifyManagerChange($clientId, $clientName, $current, $managerId, $actorName);

        return ['id' => $clientId, 'manager' => $this->presentManager($this->managerFor($clientId))];
    }

    public function transfer(
        array $actor,
        array $capabilities,
        int $clientId,
        ?int $toManagerId,
        ?string $dateFrom,
        ?string $dateTo,
        string $comment
    ): array {
        $client = $this->assertClient($clientId);
        $actorId = (int) $actor['ID'];
        $actorName = (string) ($actor['FULL_NAME'] ?? '');
        $current = $this->managerIdFor($clientId);
        $isOwnManager = $this->canManage($actor, $capabilities, $clientId);
        $canAssign = in_array('clients.assign', $capabilities, true);

        if (!$isOwnManager && !$canAssign) {
            throw new HttpException(403, 'forbidden', 'Передать клиента может его менеджер, замещающий или РОП');
        }

        if ($current === null) {
            throw new HttpException(422, 'not_bound', 'Клиент не закреплён — назначьте менеджера');
        }

        if ($toManagerId !== null) {
            if ($toManagerId === $current) {
                throw new HttpException(422, 'same_manager', 'Клиент уже закреплён за этим сотрудником');
            }

            $this->assertManager($toManagerId, 'Сотрудник не найден');
        }

        $from = $dateFrom !== null && trim($dateFrom) !== '' ? trim($dateFrom) : date('Y-m-d');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1) {
            throw new HttpException(422, 'validation_error', 'Дата передачи в формате ГГГГ-ММ-ДД');
        }

        if ($from < date('Y-m-d')) {
            throw new HttpException(422, 'validation_error', 'Дата передачи уже прошла');
        }

        $to = null;

        if ($dateTo !== null && trim($dateTo) !== '') {
            $to = trim($dateTo);

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1) {
                throw new HttpException(422, 'validation_error', 'Дата окончания в формате ГГГГ-ММ-ДД');
            }

            if ($to < $from) {
                throw new HttpException(422, 'validation_error', 'Дата окончания раньше даты передачи');
            }

            if ($toManagerId === null) {
                throw new HttpException(422, 'validation_error', 'В пул без менеджера — только без периода');
            }
        }

        $needsAcceptance = $toManagerId !== null && !$canAssign;
        $startsNow = $from <= date('Y-m-d');

        $clientName = (string) ($client['FULL_NAME'] ?? '');
        $fromName = $this->userName($current);
        $toName = $toManagerId !== null ? $this->userName($toManagerId) : null;
        $toLabel = $toName !== null && $toName !== '' ? $toName : 'пул (без менеджера)';

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $this->managers->lockClient($clientId);

            if ($this->managerIdFor($clientId) !== $current) {
                throw new HttpException(409, 'transfer_stale', 'Привязка клиента изменилась, повторите действие');
            }

            $cancelled = $this->managers->cancelOpen($clientId, 0, $actorId);
            $status = $needsAcceptance ? 'pending' : ($startsNow ? 'active' : 'scheduled');

            $transferId = $this->managers->createTransfer([
                'client_id' => $clientId,
                'from_manager_id' => $current,
                'to_manager_id' => $toManagerId,
                'date_from' => $from,
                'date_to' => $to,
                'status' => $status,
                'comment' => $comment,
                'created_by' => $actorId,
                'started_at' => $status === 'active' ? date('Y-m-d H:i:s') : null,
            ]);

            $periodText = $to !== null
                ? 'с ' . $this->formatDate($from) . ' до ' . $this->formatDate($to)
                : 'с ' . $this->formatDate($from);

            if ($toManagerId === null) {
                $event = $startsNow ? 'manager_released' : 'manager_transfer_requested';
                $commentText = 'Передача: «' . $fromName . '» → «в пул (без менеджера)» (' . $periodText . '), инициатор ' . $actorName;
            } elseif ($needsAcceptance || !$startsNow) {
                $event = 'manager_transfer_requested';
                $commentText = 'Передача: «' . $fromName . '» → «' . $toLabel . '» (' . $periodText . '), инициатор ' . $actorName;
            } else {
                $event = 'manager_assigned';
                $commentText = 'Менеджер: «' . $fromName . '» → «' . $toLabel . '» (назначил ' . $actorName . ', ' . $periodText . ')';
            }

            $this->history->add(
                $clientId,
                $event,
                $actorId,
                $comment !== '' ? $commentText . '. ' . trim($comment) : $commentText
            );

            if (!$needsAcceptance && $startsNow) {
                $this->applyManager($clientId, $toManagerId, $actorId, $actorId);
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();

            throw $exception;
        }

        foreach ($cancelled as $row) {
            if ($row['to_manager_id'] === null) {
                continue;
            }

            $this->notifications->notify(
                (int) $row['to_manager_id'],
                'client.transfer',
                'Передача клиента отменена: ' . $clientName,
                'Инициатор создал новую передачу.',
                null
            );
        }

        $periodNotify = $to !== null
            ? 'На период с ' . $this->formatDate($from) . ' до ' . $this->formatDate($to)
            : 'Навсегда с ' . $this->formatDate($from);

        if ($needsAcceptance) {
            $this->notifications->notify(
                $toManagerId,
                'client.transfer',
                'Вам передают клиента: ' . $clientName,
                'От: ' . $fromName . '. ' . $periodNotify . ($comment !== '' ? '. ' . trim($comment) : ''),
                null
            );
        } elseif ($startsNow) {
            $this->notifyManagerChange($clientId, $clientName, $current, $toManagerId, $actorName);
        } else {
            if ($toManagerId !== null) {
                $this->notifications->notify(
                    $toManagerId,
                    'client.transfer',
                    'С ' . $this->formatDate($from) . ' вам передают клиента: ' . $clientName,
                    'От: ' . $fromName . '. ' . $periodNotify . ($comment !== '' ? '. ' . trim($comment) : ''),
                    null
                );
            }

            $this->notifications->notify(
                $current,
                'client.transfer',
                'Запланирована передача клиента: ' . $clientName,
                $toLabel . '. ' . $periodNotify,
                null
            );
        }

        return [
            'transfer' => $this->presentTransfer($this->managers->findTransfer($transferId) ?? []),
            'id' => $clientId,
        ];
    }

    public function accept(array $actor, int $transferId): array
    {
        $actorId = (int) $actor['ID'];
        $transfer = $this->managers->findTransfer($transferId);

        if ($transfer === null || (string) $transfer['status'] !== 'pending') {
            throw new HttpException(404, 'not_found', 'Передача не найдена');
        }

        if ((int) $transfer['to_manager_id'] !== $actorId) {
            throw new HttpException(403, 'forbidden', 'Передача адресована другому сотруднику');
        }

        $clientId = (int) $transfer['client_id'];
        $fromId = $transfer['from_manager_id'] !== null ? (int) $transfer['from_manager_id'] : null;
        $client = $this->assertClient($clientId);
        $clientName = (string) ($client['FULL_NAME'] ?? '');
        $actorName = (string) ($actor['FULL_NAME'] ?? '');
        $fromName = $fromId !== null ? $this->userName($fromId) : '—';
        $from = (string) $transfer['date_from'];
        $to = $transfer['date_to'] !== null ? (string) $transfer['date_to'] : null;
        $startsNow = $from <= date('Y-m-d');

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $this->managers->lockClient($clientId);
            $locked = $this->managers->findTransferForUpdate($transferId);

            if ($locked === null || (string) $locked['status'] !== 'pending') {
                throw new HttpException(409, 'transfer_stale', 'Передача уже обработана');
            }

            if ($this->managerIdFor($clientId) !== $fromId) {
                throw new HttpException(409, 'transfer_stale', 'Привязка клиента изменилась, передача неактуальна');
            }

            $this->managers->setTransferStatus($transferId, $startsNow ? 'active' : 'scheduled', $actorId);

            if ($startsNow) {
                $this->managers->markStarted($transferId);
                $this->applyManager($clientId, $actorId, $actorId, $actorId);
            }

            $periodText = $to !== null
                ? ' с ' . $this->formatDate($from) . ' до ' . $this->formatDate($to)
                : ' с ' . $this->formatDate($from);
            $this->history->add(
                $clientId,
                'manager_transfer_accepted',
                $actorId,
                'Передача принята: «' . $fromName . '» → «' . $actorName . '»' . $periodText
                    . ($startsNow ? '' : ' (вступит в силу с ' . $this->formatDate($from) . ')')
            );

            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();

            throw $exception;
        }

        if ($transfer['created_by'] !== null && (int) $transfer['created_by'] !== $actorId) {
            $this->notifications->notify(
                (int) $transfer['created_by'],
                'client.transfer',
                'Передача клиента принята: ' . $clientName,
                'Новый менеджер: ' . $actorName . ($startsNow ? '' : '. Вступит в силу с ' . $this->formatDate($from)),
                null
            );
        }

        if ($startsNow) {
            $this->notifyManagerChange($clientId, $clientName, $fromId, $actorId, $actorName);
        }

        return ['id' => $clientId, 'manager' => $this->presentManager($this->managerFor($clientId))];
    }

    public function decline(array $actor, int $transferId): array
    {
        $actorId = (int) $actor['ID'];
        $transfer = $this->managers->findTransfer($transferId);

        if ($transfer === null || (string) $transfer['status'] !== 'pending') {
            throw new HttpException(404, 'not_found', 'Передача не найдена');
        }

        if ((int) $transfer['to_manager_id'] !== $actorId) {
            throw new HttpException(403, 'forbidden', 'Передача адресована другому сотруднику');
        }

        $clientId = (int) $transfer['client_id'];
        $clientName = (string) $transfer['client_name'];
        $actorName = (string) ($actor['FULL_NAME'] ?? '');

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $this->managers->lockClient($clientId);
            $locked = $this->managers->findTransferForUpdate($transferId);

            if ($locked === null || (string) $locked['status'] !== 'pending') {
                throw new HttpException(409, 'transfer_stale', 'Передача уже обработана');
            }

            $this->managers->setTransferStatus($transferId, 'declined', $actorId);
            $this->history->add($clientId, 'manager_transfer_declined', $actorId, 'Передача отклонена: ' . $actorName);

            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();

            throw $exception;
        }

        if ($transfer['created_by'] !== null) {
            $this->notifications->notify(
                (int) $transfer['created_by'],
                'client.transfer',
                'Передача клиента отклонена: ' . $clientName,
                $actorName . ' отказался принять клиента.',
                null
            );
        }

        return ['id' => $clientId, 'declined' => true];
    }

    public function cancel(array $actor, array $capabilities, int $transferId): array
    {
        $actorId = (int) $actor['ID'];
        $transfer = $this->managers->findTransfer($transferId);

        if ($transfer === null || !in_array((string) $transfer['status'], ['pending', 'scheduled'], true)) {
            throw new HttpException(404, 'not_found', 'Передача не найдена');
        }

        $canAssign = in_array('clients.assign', $capabilities, true);

        if ((int) $transfer['created_by'] !== $actorId && !$canAssign) {
            throw new HttpException(403, 'forbidden', 'Отменить передачу может инициатор или РОП');
        }

        $clientId = (int) $transfer['client_id'];
        $clientName = (string) $transfer['client_name'];
        $actorName = (string) ($actor['FULL_NAME'] ?? '');
        $wasScheduled = (string) $transfer['status'] === 'scheduled';

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $this->managers->lockClient($clientId);
            $locked = $this->managers->findTransferForUpdate($transferId);

            if ($locked === null || !in_array((string) $locked['status'], ['pending', 'scheduled'], true)) {
                throw new HttpException(409, 'transfer_stale', 'Передача уже обработана');
            }

            $this->managers->setTransferStatus($transferId, 'cancelled', $actorId);
            $this->history->add(
                $clientId,
                'manager_transfer_cancelled',
                $actorId,
                'Передача отменена: ' . $actorName . ($wasScheduled ? ' (запланированная)' : '')
            );

            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();

            throw $exception;
        }

        if ($transfer['to_manager_id'] !== null) {
            $this->notifications->notify(
                (int) $transfer['to_manager_id'],
                'client.transfer',
                'Передача клиента отменена: ' . $clientName,
                $actorName . ' отменил передачу.',
                null
            );
        }

        return ['id' => $clientId, 'cancelled' => true];
    }

    public function processDue(): array
    {
        $result = ['started' => [], 'expired' => []];

        foreach ($this->managers->scheduledDue() as $transfer) {
            $transferId = (int) $transfer['ID'];
            $clientId = (int) $transfer['client_id'];
            $toId = $transfer['to_manager_id'] !== null ? (int) $transfer['to_manager_id'] : null;
            $fromId = $transfer['from_manager_id'] !== null ? (int) $transfer['from_manager_id'] : null;
            $createdBy = $transfer['created_by'] !== null ? (int) $transfer['created_by'] : null;

            $pdo = Database::pdo();
            $pdo->beginTransaction();

            try {
                $this->managers->lockClient($clientId);
                $locked = $this->managers->findTransferForUpdate($transferId);

                if ($locked === null || (string) $locked['status'] !== 'scheduled') {
                    $pdo->rollBack();
                    continue;
                }

                if ($toId !== null) {
                    $target = $this->users->findById($toId);

                    if ($target === null || ($target['ACTIVE'] ?? 'N') !== 'Y') {
                        $this->managers->setTransferStatus($transferId, 'cancelled', null);
                        $this->history->add(
                            $clientId,
                            'manager_transfer_cancelled',
                            null,
                            'Передача отменена: получатель недоступен'
                        );
                        $pdo->commit();
                        continue;
                    }
                }

                if ($this->managerIdFor($clientId) !== $fromId) {
                    $this->managers->setTransferStatus($transferId, 'cancelled', null);
                    $pdo->commit();
                    continue;
                }

                $this->applyManager($clientId, $toId, $createdBy, $toId ?? $fromId ?? 0);
                $this->managers->markStarted($transferId);

                $fromName = $fromId !== null ? $this->userName($fromId) : '—';
                $toName = $toId !== null ? $this->userName($toId) : 'пул (без менеджера)';
                $this->history->add(
                    $clientId,
                    'manager_transfer_started',
                    null,
                    'Передача вступила в силу: «' . $fromName . '» → «' . $toName . '»'
                );

                $pdo->commit();
            } catch (Throwable $exception) {
                $pdo->rollBack();

                throw $exception;
            }

            $client = $this->users->findById($clientId);
            $clientName = (string) ($client['FULL_NAME'] ?? '');

            $this->notifyManagerChange($clientId, $clientName, $fromId, $toId, 'система');

            $result['started'][] = ['id' => $transferId, 'client_id' => $clientId, 'manager_id' => $toId];
        }

        foreach ($this->managers->expiredTemporary() as $transfer) {
            $transferId = (int) $transfer['ID'];
            $clientId = (int) $transfer['client_id'];
            $toId = $transfer['to_manager_id'] !== null ? (int) $transfer['to_manager_id'] : null;
            $fromId = $transfer['from_manager_id'] !== null ? (int) $transfer['from_manager_id'] : null;

            if ($toId === null || $this->managerIdFor($clientId) !== $toId) {
                $this->managers->setTransferStatus($transferId, 'expired', null);
                continue;
            }

            $client = $this->users->findById($clientId);
            $clientName = (string) ($client['FULL_NAME'] ?? '');
            $fromName = $fromId !== null ? $this->userName($fromId) : 'пул (без менеджера)';

            $pdo = Database::pdo();
            $pdo->beginTransaction();

            try {
                $this->managers->lockClient($clientId);
                $locked = $this->managers->findTransferForUpdate($transferId);

                if ($locked === null || (string) $locked['status'] !== 'active') {
                    $pdo->rollBack();
                    continue;
                }

                $this->applyManager($clientId, $fromId, null, $fromId ?? 0);
                $this->managers->setTransferStatus($transferId, 'expired', null);
                $this->history->add(
                    $clientId,
                    'manager_transfer_expired',
                    null,
                    'Временная передача завершена, возврат к «' . $fromName . '»'
                );

                $pdo->commit();
            } catch (Throwable $exception) {
                $pdo->rollBack();

                throw $exception;
            }

            $result['expired'][] = ['id' => $transferId, 'client_id' => $clientId, 'returned_to' => $fromId];

            $this->notifications->notify(
                $toId,
                'client.transfer',
                'Временная передача завершена: ' . $clientName,
                'Клиент возвращён: ' . $fromName,
                null
            );

            if ($fromId !== null) {
                $this->notifications->notify(
                    $fromId,
                    'client.transfer',
                    'Клиент возвращён: ' . $clientName,
                    'Временная передача завершена.',
                    null
                );
            }

            $this->notifications->notify(
                $clientId,
                'client.manager',
                'Ваш менеджер: ' . $fromName,
                'Временная передача завершена.',
                null
            );
        }

        return $result;
    }

    public function pendingForManager(int $managerId): array
    {
        return array_map(fn (array $row): array => $this->presentTransfer($row), $this->managers->pendingForManager($managerId));
    }

    public function currentForClient(int $clientId): ?array
    {
        $row = $this->managers->currentForClient($clientId);

        return $row !== null ? $this->presentTransfer($row) : null;
    }

    public function activeTemporary(int $clientId): ?array
    {
        return $this->managers->activeTemporaryForClient($clientId);
    }

    public function presentManager(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['manager_id'],
            'name' => (string) ($row['manager_name'] ?? ''),
            'level' => (int) ($row['manager_level'] ?? 0),
            'assigned_at' => (string) ($row['assigned_at'] ?? ''),
            'assigned_by' => $row['assigned_by'] !== null
                ? ['id' => (int) $row['assigned_by'], 'name' => (string) ($row['assigned_by_name'] ?? '')]
                : null,
        ];
    }

    public function presentTransfer(array $row): array
    {
        return [
            'id' => (int) $row['ID'],
            'client_id' => (int) $row['client_id'],
            'client_name' => (string) ($row['client_name'] ?? ''),
            'from' => $row['from_manager_id'] !== null
                ? ['id' => (int) $row['from_manager_id'], 'name' => (string) ($row['from_name'] ?? '')]
                : null,
            'to' => $row['to_manager_id'] !== null
                ? ['id' => (int) $row['to_manager_id'], 'name' => (string) ($row['to_name'] ?? '')]
                : null,
            'date_from' => $row['date_from'] !== null ? (string) $row['date_from'] : null,
            'date_to' => $row['date_to'] !== null ? (string) $row['date_to'] : null,
            'started_at' => $row['started_at'] !== null ? (string) $row['started_at'] : null,
            'status' => (string) $row['status'],
            'comment' => (string) ($row['comment'] ?? ''),
            'created_by' => ['id' => (int) $row['created_by'], 'name' => (string) ($row['created_by_name'] ?? '')],
            'created_at' => (string) $row['created_at'],
        ];
    }

    private function applyManager(int $clientId, ?int $newManagerId, ?int $assignedBy, int $actorId): void
    {
        $oldManagerId = $this->managerIdFor($clientId);

        if ($newManagerId === null) {
            $this->managers->unbind($clientId);
        } else {
            $this->managers->assign($clientId, $newManagerId, $assignedBy);
        }

        $this->moveOpenRequests($clientId, $oldManagerId, $newManagerId, $actorId);
        $this->syncChat($clientId, $newManagerId);
    }

    public function syncChat(int $clientId, ?int $managerId): void
    {
        $effective = $managerId !== null ? $this->effectiveForManager($managerId)['id'] : null;

        $this->chat->syncManager($clientId, $effective);
    }

    private function moveOpenRequests(int $clientId, ?int $oldManagerId, ?int $newManagerId, int $actorId): int
    {
        $effective = $newManagerId !== null
            ? $this->effectiveForManager($newManagerId)
            : ['id' => null, 'substitution_id' => null];
        $moved = 0;

        foreach ($this->requests->openByClient($clientId) as $request) {
            $requestId = (int) $request['ID'];
            $current = $request['manager_id'] !== null ? (int) $request['manager_id'] : null;

            if ($current !== $oldManagerId || $current === $effective['id']) {
                continue;
            }

            $this->requests->applySubstitution($requestId, $effective['id'], $effective['substitution_id']);
            $this->assignments->add(
                $requestId,
                $current,
                $effective['id'],
                $actorId > 0 ? $actorId : ($effective['id'] ?? $oldManagerId ?? 0),
                'Смена менеджера клиента'
            );
            $this->requestHistory->add(
                $requestId,
                $actorId > 0 ? $actorId : null,
                (string) $request['status_id'],
                (string) $request['status_id'],
                'Заявка переведена к менеджеру клиента'
            );

            $moved++;
        }

        return $moved;
    }

    private function notifyManagerChange(
        int $clientId,
        string $clientName,
        ?int $oldManagerId,
        ?int $newManagerId,
        string $actorName
    ): void {
        if ($newManagerId !== null) {
            $this->notifications->notify(
                $newManagerId,
                'client.assigned',
                'Вам закреплён клиент: ' . $clientName,
                'Назначил: ' . $actorName,
                null
            );
        }

        if ($oldManagerId !== null && $oldManagerId !== $newManagerId) {
            $this->notifications->notify(
                $oldManagerId,
                'client.unassigned',
                'Клиент закреплён за другим менеджером: ' . $clientName,
                $newManagerId !== null ? 'Новый менеджер: ' . $this->userName($newManagerId) : 'Менеджер снят.',
                null
            );
        }

        $this->notifications->notify(
            $clientId,
            'client.manager',
            $newManagerId !== null ? 'Ваш менеджер: ' . $this->userName($newManagerId) : 'Менеджер изменён',
            $newManagerId !== null
                ? 'Теперь ваши запросы ведёт ' . $this->userName($newManagerId) . '.'
                : 'Новый менеджер будет назначен в ближайшее время.',
            null
        );
    }

    private function managerIds(array $actor): array
    {
        $userId = (int) $actor['ID'];

        return array_values(array_unique(array_merge(
            [$userId],
            $this->substitutions->activeSubstitutedIds($userId)
        )));
    }

    private function assertClient(int $clientId): array
    {
        $client = $this->users->findById($clientId);

        if ($client === null || (int) $client['LEVEL'] !== 5) {
            throw new HttpException(404, 'not_found', 'Клиент не найден');
        }

        return $client;
    }

    private function assertManager(int $managerId, string $message): void
    {
        $manager = $this->users->findById($managerId);

        if (
            $manager === null
            || ($manager['ACTIVE'] ?? 'N') !== 'Y'
            || !in_array((int) $manager['LEVEL'], [10, 50, 90], true)
        ) {
            throw new HttpException(422, 'validation_error', $message);
        }
    }

    private function userName(int $userId): string
    {
        $user = $this->users->findById($userId);

        return (string) ($user['FULL_NAME'] ?? '');
    }

    private function formatDate(string $date): string
    {
        $timestamp = strtotime($date);

        return $timestamp !== false ? date('d.m.Y', $timestamp) : $date;
    }

    private function requireCapability(array $capabilities, string $capability): void
    {
        if (!in_array($capability, $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав');
        }
    }
}
