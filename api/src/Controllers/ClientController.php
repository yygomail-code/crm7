<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Clients\ClientService;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\ManagerClientRepository;
use App\Repositories\SubstitutionRepository;
use App\Repositories\UserHistoryRepository;
use App\Repositories\UserRepository;

final class ClientController extends ApiController
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly ManagerClientRepository $managerClients = new ManagerClientRepository(),
        private readonly SubstitutionRepository $substitutions = new SubstitutionRepository(),
        private readonly ClientService $service = new ClientService(),
        private readonly UserHistoryRepository $history = new UserHistoryRepository()
    ) {
        parent::__construct();
    }

    public function pending(Request $request): Response
    {
        [, $capabilities] = $this->context($request);

        return Response::ok($this->service->pending($capabilities));
    }

    public function activate(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        $result = $this->service->activate(
            $user,
            $capabilities,
            (int) ($params['id'] ?? 0),
            (string) $request->input('comment', '')
        );

        $this->audit($request, $user, 'client.activate', 'user', (int) ($params['id'] ?? 0), [
            'name' => $result['name'] ?? '',
        ]);

        return Response::ok($result);
    }

    public function reject(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        $result = $this->service->reject(
            $user,
            $capabilities,
            (int) ($params['id'] ?? 0),
            (string) $request->input('comment', '')
        );

        $this->audit($request, $user, 'client.reject', 'user', (int) ($params['id'] ?? 0), [
            'name' => $result['name'] ?? '',
            'comment' => (string) $request->input('comment', ''),
        ]);

        return Response::ok($result);
    }

    public function show(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->card($user, $capabilities, (int) ($params['id'] ?? 0)));
    }

    public function index(Request $request): Response
    {
        [$user, $capabilities] = $this->context($request);

        $managerFilter = in_array('clients.view.all', $capabilities, true)
            ? null
            : array_values(array_unique(array_merge(
                [(int) $user['ID']],
                $this->substitutions->activeSubstitutedIds((int) $user['ID'])
            )));

        $query = trim($request->queryParam('q'));
        $page = max(1, (int) $request->queryParam('page', '1'));
        $perPage = max(10, min(100, (int) $request->queryParam('per_page', '20')));

        $clients = $this->users->listClients($query, $managerFilter, $perPage, ($page - 1) * $perPage);
        $total = $this->users->countClients($query, $managerFilter);
        $managers = $this->managerClients->forClients(array_map('intval', array_column($clients, 'id')));

        $items = array_map(static function (array $row) use ($managers): array {
            $clientId = (int) $row['id'];

            return [
                'id' => $clientId,
                'login' => (string) $row['login'],
                'name' => (string) $row['name'],
                'email' => (string) ($row['email'] ?? ''),
                'phone' => (string) ($row['phone'] ?? ''),
                'company' => (string) ($row['company'] ?? ''),
                'inn' => (string) ($row['inn'] ?? ''),
                'position' => (string) ($row['position'] ?? ''),
                'manager' => $managers[$clientId] ?? null,
            ];
        }, $clients);

        return Response::ok([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    public function assign(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        if (!in_array('clients.assign', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для назначения менеджера');
        }

        $clientId = (int) ($params['id'] ?? 0);
        $managerId = (int) ($request->input('manager_id') ?? 0);

        $client = $this->users->findById($clientId);

        if ($client === null || ($client['ACTIVE'] ?? 'N') !== 'Y' || (int) $client['LEVEL'] !== 5) {
            throw new HttpException(422, 'validation_error', 'Клиент не найден');
        }

        $manager = $this->users->findById($managerId);

        if (
            $manager === null
            || ($manager['ACTIVE'] ?? 'N') !== 'Y'
            || !in_array((int) $manager['LEVEL'], [10, 50, 90], true)
        ) {
            throw new HttpException(422, 'validation_error', 'Менеджер не найден');
        }

        $this->managerClients->assign($clientId, $managerId, (int) $user['ID']);
        $this->history->add(
            $clientId,
            'manager_assigned',
            (int) $user['ID'],
            'Назначен менеджер: ' . (string) ($manager['FULL_NAME'] ?? '')
        );
        $this->audit($request, $user, 'client.assign', 'user', $clientId, ['manager_id' => $managerId]);

        return Response::ok([
            'client_id' => $clientId,
            'manager' => [
                'id' => $managerId,
                'name' => (string) ($manager['FULL_NAME'] ?? ''),
            ],
        ]);
    }
}
