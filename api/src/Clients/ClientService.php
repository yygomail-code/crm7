<?php

declare(strict_types=1);

namespace App\Clients;

use App\Http\HttpException;
use App\Notifications\NotificationService;
use App\Repositories\ManagerClientRepository;
use App\Repositories\RequestRepository;
use App\Repositories\SubstitutionRepository;
use App\Repositories\UserHistoryRepository;
use App\Repositories\UserRepository;
use App\Repositories\ViewLogRepository;

final class ClientService
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly UserHistoryRepository $history = new UserHistoryRepository(),
        private readonly ManagerClientRepository $managerClients = new ManagerClientRepository(),
        private readonly SubstitutionRepository $substitutions = new SubstitutionRepository(),
        private readonly RequestRepository $requests = new RequestRepository(),
        private readonly NotificationService $notifications = new NotificationService(),
        private readonly ViewLogRepository $views = new ViewLogRepository()
    ) {
    }

    public function pending(array $capabilities): array
    {
        $this->requireConfirm($capabilities);

        $items = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) ($row['email'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'company' => (string) ($row['company'] ?? ''),
            'inn' => (string) ($row['inn'] ?? ''),
            'position' => (string) ($row['position'] ?? ''),
            'registered_at' => (string) ($row['registered_at'] ?? $row['created_at'] ?? ''),
        ], $this->users->listPendingClients());

        return ['items' => $items];
    }

    public function activate(array $actor, array $capabilities, int $clientId, string $comment): array
    {
        $this->requireConfirm($capabilities);
        $client = $this->assertPendingClient($clientId);

        $this->users->markClientState($clientId, 'active');
        $this->history->add($clientId, 'activated', (int) $actor['ID'], $comment);

        $this->notifications->notify(
            $clientId,
            'user.approved',
            'Регистрация подтверждена',
            'Добро пожаловать! Теперь вы можете войти в личный кабинет и создавать заявки.'
        );

        return [
            'id' => $clientId,
            'name' => (string) ($client['FULL_NAME'] ?? ''),
            'state' => 'active',
        ];
    }

    public function reject(array $actor, array $capabilities, int $clientId, string $comment): array
    {
        $this->requireConfirm($capabilities);
        $client = $this->assertPendingClient($clientId);

        if (trim($comment) === '') {
            throw new HttpException(422, 'validation_error', 'Укажите причину отказа');
        }

        $this->users->markClientState($clientId, 'rejected');
        $this->history->add($clientId, 'rejected', (int) $actor['ID'], $comment);

        $this->notifications->notify(
            $clientId,
            'user.rejected',
            'Регистрация отклонена',
            'Причина: ' . trim($comment)
        );

        return [
            'id' => $clientId,
            'name' => (string) ($client['FULL_NAME'] ?? ''),
            'state' => 'rejected',
        ];
    }

    public function card(array $actor, array $capabilities, int $clientId): array
    {
        $client = $this->users->findById($clientId);

        if ($client === null || (int) $client['LEVEL'] !== 5) {
            throw new HttpException(404, 'not_found', 'Клиент не найден');
        }

        $this->assertCanView($actor, $capabilities, $clientId);

        if ((int) $actor['LEVEL'] >= 10
            && !$this->views->recentExists('user', $clientId, (int) $actor['ID'], 'view', 15)
        ) {
            $this->views->add('user', $clientId, (int) $actor['ID'], 'view');
        }

        $views = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'action' => (string) $row['action'],
            'user' => [
                'id' => (int) $row['user_id'],
                'name' => (string) $row['user_name'],
                'level' => (int) $row['user_level'],
            ],
            'created_at' => (string) $row['created_at'],
        ], $this->views->listForEntity('user', $clientId, 50));

        $history = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'event' => (string) $row['event'],
            'comment' => (string) ($row['comment'] ?? ''),
            'actor' => $row['actor_id'] !== null
                ? [
                    'id' => (int) $row['actor_id'],
                    'name' => (string) ($row['actor_name'] ?? ''),
                    'level' => (int) ($row['actor_level'] ?? 0),
                ]
                : null,
            'created_at' => (string) $row['created_at'],
        ], $this->history->listByUser($clientId));

        $requestRows = $this->requests->listForClient($clientId, 200);

        $requests = array_map(static fn (array $row): array => [
            'id' => (int) $row['ID'],
            'number' => (string) $row['number'],
            'subject' => (string) $row['subject'],
            'status' => [
                'code' => (string) $row['status_id'],
                'title' => (string) $row['status_title'],
                'color' => (string) $row['status_color'],
                'is_final' => (bool) $row['status_is_final'],
            ],
            'manager' => $row['manager_id'] !== null
                ? ['id' => (int) $row['manager_id'], 'name' => (string) ($row['manager_name'] ?? '')]
                : null,
            'created_at' => (string) $row['created_at'],
            'due_at' => $row['due_at'] !== null ? (string) $row['due_at'] : null,
            'is_overdue' => (bool) $row['is_overdue'],
        ], $requestRows);

        $open = 0;
        $closed = 0;
        $overdue = 0;

        foreach ($requestRows as $row) {
            if ((bool) $row['status_is_final']) {
                $closed++;
            } else {
                $open++;
            }

            if ((bool) $row['is_overdue']) {
                $overdue++;
            }
        }

        return [
            'client' => [
                'id' => $clientId,
                'login' => (string) ($client['LOGIN'] ?? ''),
                'name' => (string) ($client['FULL_NAME'] ?? ''),
                'email' => (string) ($client['EMAIL'] ?? ''),
                'phone' => (string) ($client['PHONE'] ?? ''),
                'company' => (string) ($client['COMPANY'] ?? ''),
                'inn' => (string) ($client['INN'] ?? ''),
                'position' => (string) ($client['DOLGNOST'] ?? ''),
                'active' => ($client['ACTIVE'] ?? 'N') === 'Y',
                'reg_state' => (string) ($client['reg_state'] ?? 'active'),
                'registered_at' => (string) ($client['TIME_ADD'] ?? ''),
                'last_seen_at' => $client['TIME_ACTIVE'] !== null ? (string) $client['TIME_ACTIVE'] : null,
                'manager' => $this->managerClients->forClients([$clientId])[$clientId] ?? null,
            ],
            'stats' => [
                'total' => count($requestRows),
                'open' => $open,
                'closed' => $closed,
                'overdue' => $overdue,
            ],
            'history' => $history,
            'views' => $views,
            'requests' => $requests,
        ];
    }

    private function assertPendingClient(int $clientId): array
    {
        $client = $this->users->findById($clientId);

        if ($client === null || (int) $client['LEVEL'] !== 5) {
            throw new HttpException(404, 'not_found', 'Клиент не найден');
        }

        if (($client['reg_state'] ?? 'active') !== 'pending') {
            throw new HttpException(422, 'not_pending', 'Заявка на регистрацию уже обработана');
        }

        return $client;
    }

    private function assertCanView(array $actor, array $capabilities, int $clientId): void
    {
        if (in_array('clients.view.all', $capabilities, true)) {
            return;
        }

        $managerIds = array_merge(
            [(int) $actor['ID']],
            $this->substitutions->activeSubstitutedIds((int) $actor['ID'])
        );

        foreach ($managerIds as $managerId) {
            if ($this->managerClients->isManagerOf($clientId, (int) $managerId)) {
                return;
            }
        }

        throw new HttpException(403, 'forbidden', 'Нет доступа к клиенту');
    }

    private function requireConfirm(array $capabilities): void
    {
        if (!in_array('clients.confirm', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для подтверждения регистрации');
        }
    }
}
