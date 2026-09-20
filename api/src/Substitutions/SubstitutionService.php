<?php

declare(strict_types=1);

namespace App\Substitutions;

use App\Http\HttpException;
use App\Notifications\NotificationService;
use App\Repositories\RequestAssignmentRepository;
use App\Repositories\RequestHistoryRepository;
use App\Repositories\RequestRepository;
use App\Repositories\SubstitutionRepository;
use App\Repositories\UserRepository;

final class SubstitutionService
{
    public function __construct(
        private readonly SubstitutionRepository $substitutions = new SubstitutionRepository(),
        private readonly RequestRepository $requests = new RequestRepository(),
        private readonly RequestAssignmentRepository $assignments = new RequestAssignmentRepository(),
        private readonly RequestHistoryRepository $history = new RequestHistoryRepository(),
        private readonly UserRepository $users = new UserRepository(),
        private readonly NotificationService $notifications = new NotificationService()
    ) {
    }

    public function list(array $user, array $capabilities): array
    {
        $rows = in_array('clients.assign', $capabilities, true)
            ? $this->substitutions->all()
            : $this->substitutions->forParticipant((int) $user['ID']);

        return array_map(fn (array $row): array => $this->present($row), $rows);
    }

    private function present(array $row): array
    {
        return [
            'id' => (int) $row['ID'],
            'manager' => ['id' => (int) $row['manager_id'], 'name' => (string) $row['manager_name']],
            'substitute' => ['id' => (int) $row['substitute_id'], 'name' => (string) $row['substitute_name']],
            'date_from' => (string) $row['date_from'],
            'date_to' => (string) $row['date_to'],
            'reason' => (string) ($row['reason'] ?? ''),
            'is_active' => (bool) $row['is_active'],
            'requests_count' => $this->substitutions->requestsCount((int) $row['ID']),
            'created_at' => (string) $row['created_at'],
        ];
    }

    public function create(array $user, array $capabilities, array $input): array
    {
        $this->requireAssign($capabilities);

        $managerId = (int) ($input['manager_id'] ?? 0);
        $substituteId = (int) ($input['substitute_id'] ?? 0);
        $from = trim((string) ($input['date_from'] ?? ''));
        $to = trim((string) ($input['date_to'] ?? ''));
        $reason = trim((string) ($input['reason'] ?? ''));

        if ($managerId === $substituteId) {
            throw new HttpException(422, 'validation_error', 'Заместитель должен отличаться от менеджера');
        }

        $this->assertManager($managerId, 'Менеджер не найден');
        $this->assertManager($substituteId, 'Заместитель не найден');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1) {
            throw new HttpException(422, 'validation_error', 'Укажите даты в формате ГГГГ-ММ-ДД');
        }

        if ($from > $to) {
            throw new HttpException(422, 'validation_error', 'Дата начала позже даты окончания');
        }

        if ($this->substitutions->hasOverlap($managerId, $from, $to)) {
            throw new HttpException(409, 'substitution_overlap', 'У этого менеджера уже есть замещение на пересекающийся период');
        }

        $id = $this->substitutions->create([
            'manager_id' => $managerId,
            'substitute_id' => $substituteId,
            'date_from' => $from,
            'date_to' => $to,
            'reason' => $reason !== '' ? mb_substr($reason, 0, 500) : null,
            'created_by' => (int) $user['ID'],
        ]);

        $moved = $from <= date('Y-m-d') ? $this->moveOpenRequests($id, $managerId, $substituteId) : 0;

        $manager = $this->users->findById($managerId);
        $substitute = $this->users->findById($substituteId);
        $managerName = (string) ($manager['FULL_NAME'] ?? '');
        $substituteName = (string) ($substitute['FULL_NAME'] ?? '');

        $this->notifications->notify(
            $substituteId,
            'substitution.created',
            'Замещение: ' . $managerName . ' с ' . $from . ' по ' . $to,
            $reason,
            null,
            ['from' => $from, 'to' => $to]
        );

        $this->notifications->notify(
            $managerId,
            'substitution.created',
            'Ваши заявки замещает ' . $substituteName . ' с ' . $from . ' по ' . $to,
            $reason,
            null,
            ['from' => $from, 'to' => $to]
        );

        return [
            'item' => $this->present($this->substitutions->findById($id) ?? []),
            'moved' => $moved,
        ];
    }

    public function end(array $user, array $capabilities, int $id): array
    {
        $this->requireAssign($capabilities);

        $row = $this->substitutions->findById($id);

        if ($row === null) {
            throw new HttpException(404, 'not_found', 'Замещение не найдено');
        }

        if ((string) $row['date_from'] > date('Y-m-d')) {
            $this->substitutions->delete($id);

            return ['deleted' => true, 'returned' => 0, 'item' => null];
        }

        $returned = $this->returnRequests($row);
        $this->substitutions->deactivate($id);

        return [
            'ended' => true,
            'returned' => $returned,
            'item' => $this->present($this->substitutions->findById($id) ?? []),
        ];
    }

    public function processEnded(): array
    {
        $processed = [];

        foreach ($this->substitutions->ended() as $row) {
            $returned = $this->returnRequests($row);
            $this->substitutions->deactivate((int) $row['ID']);

            $processed[] = ['id' => (int) $row['ID'], 'returned' => $returned];
        }

        return $processed;
    }

    private function moveOpenRequests(int $substitutionId, int $managerId, int $substituteId): int
    {
        $manager = $this->users->findById($managerId);
        $managerName = (string) ($manager['FULL_NAME'] ?? '');

        $moved = 0;

        foreach ($this->requests->openByManager($managerId) as $request) {
            $requestId = (int) $request['ID'];

            $this->requests->applySubstitution($requestId, $substituteId, $substitutionId);
            $this->assignments->add($requestId, $managerId, $substituteId, $managerId, 'Замещение: ' . $managerName . ' в отпуске');
            $this->history->add(
                $requestId,
                $managerId,
                (string) $request['status_id'],
                (string) $request['status_id'],
                'Заявка передана заместителю на время отпуска'
            );

            $moved++;
        }

        if ($moved > 0) {
            $this->notifications->notify(
                $substituteId,
                'substitution.created',
                'Вам переданы заявки (' . $moved . ') на время замещения',
                $managerName,
                null
            );
        }

        return $moved;
    }

    private function returnRequests(array $substitution): int
    {
        $substitutionId = (int) $substitution['ID'];
        $managerId = (int) $substitution['manager_id'];
        $substituteId = (int) $substitution['substitute_id'];

        $manager = $this->users->findById($managerId);
        $managerName = (string) ($manager['FULL_NAME'] ?? '');

        $returned = 0;
        $clientCounts = [];

        foreach ($this->requests->findBySubstitution($substitutionId) as $request) {
            $requestId = (int) $request['ID'];

            $this->requests->applySubstitution($requestId, $managerId, null);
            $this->assignments->add($requestId, $substituteId, $managerId, $managerId, 'Возврат после замещения');
            $this->history->add(
                $requestId,
                $managerId,
                (string) $request['status_id'],
                (string) $request['status_id'],
                'Заявка возвращена после замещения'
            );

            $clientId = (int) $request['client_id'];
            $clientCounts[$clientId] = ($clientCounts[$clientId] ?? 0) + 1;
            $returned++;
        }

        foreach ($clientCounts as $clientId => $count) {
            $this->notifications->notify(
                $clientId,
                'substitution.ended',
                'Заявки возвращены вашему менеджеру (' . $count . ')',
                'Менеджер: ' . $managerName,
                null
            );
        }

        if ($returned > 0) {
            $this->notifications->notify(
                $substituteId,
                'substitution.ended',
                'Замещение завершено, заявки возвращены',
                'Менеджер: ' . $managerName . '. Возвращено заявок: ' . $returned,
                null
            );

            $this->notifications->notify(
                $managerId,
                'substitution.ended',
                'Вам возвращены заявки после замещения: ' . $returned,
                null,
                null
            );
        }

        return $returned;
    }

    private function requireAssign(array $capabilities): void
    {
        if (!in_array('clients.assign', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для управления замещениями');
        }
    }

    private function assertManager(int $userId, string $message): void
    {
        $user = $this->users->findById($userId);

        if (
            $user === null
            || ($user['ACTIVE'] ?? 'N') !== 'Y'
            || !in_array((int) $user['LEVEL'], [10, 50, 90], true)
        ) {
            throw new HttpException(422, 'validation_error', $message);
        }
    }
}
