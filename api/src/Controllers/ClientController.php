<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Clients\ClientManagerService;
use App\Clients\ClientService;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\UserHistoryRepository;
use App\Repositories\UserRepository;

final class ClientController extends ApiController
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly ClientService $service = new ClientService(),
        private readonly ClientManagerService $managers = new ClientManagerService(),
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

    public function interests(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        $sort = trim($request->queryParam('sort'));

        return Response::ok($this->service->interests($user, $capabilities, (int) ($params['id'] ?? 0), [
            'q' => trim($request->queryParam('q')),
            'sort' => in_array($sort, [
                'qty_desc', 'qty_asc', 'orders_desc', 'orders_asc',
                'last_desc', 'last_asc', 'name_asc', 'name_desc',
            ], true) ? $sort : 'qty_desc',
            'page' => (int) $request->queryParam('page', '1'),
            'per_page' => (int) $request->queryParam('per_page', '20'),
        ]));
    }

    public function block(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);
        $clientId = (int) ($params['id'] ?? 0);

        $result = $this->service->setBlocked(
            $user,
            $capabilities,
            $clientId,
            true,
            trim((string) $request->input('comment', ''))
        );

        $this->audit($request, $user, 'client.block', 'user', $clientId, []);

        return Response::ok($result);
    }

    public function unblock(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);
        $clientId = (int) ($params['id'] ?? 0);

        $result = $this->service->setBlocked(
            $user,
            $capabilities,
            $clientId,
            false,
            trim((string) $request->input('comment', ''))
        );

        $this->audit($request, $user, 'client.unblock', 'user', $clientId, []);

        return Response::ok($result);
    }

    public function update(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);
        $clientId = (int) ($params['id'] ?? 0);

        $result = $this->service->updateContacts($user, $capabilities, $clientId, $request->bodyAll());

        $this->audit($request, $user, 'client.update', 'user', $clientId, []);

        return Response::ok($result);
    }

    public function claim(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);
        $clientId = (int) ($params['id'] ?? 0);

        $result = $this->managers->claim($user, $capabilities, $clientId);

        $this->audit($request, $user, 'client.claim', 'user', $clientId, []);

        return Response::ok($result);
    }

    public function assign(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);
        $clientId = (int) ($params['id'] ?? 0);
        $managerId = $request->input('manager_id', null);
        $managerId = $managerId !== null && $managerId !== '' ? (int) $managerId : null;

        $result = $this->managers->assign(
            $user,
            $capabilities,
            $clientId,
            $managerId,
            trim((string) $request->input('comment', ''))
        );

        $this->audit($request, $user, 'client.assign', 'user', $clientId, ['manager_id' => $managerId]);

        return Response::ok($result);
    }

    public function transfer(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);
        $clientId = (int) ($params['id'] ?? 0);
        $toManager = $request->input('to_manager_id', null);
        $toManagerId = $toManager !== null && $toManager !== '' ? (int) $toManager : null;

        $result = $this->managers->transfer(
            $user,
            $capabilities,
            $clientId,
            $toManagerId,
            $request->input('date_from', null) !== null ? (string) $request->input('date_from') : null,
            $request->input('date_to', null) !== null ? (string) $request->input('date_to') : null,
            trim((string) $request->input('comment', ''))
        );

        $this->audit($request, $user, 'client.transfer', 'user', $clientId, [
            'to_manager_id' => $toManagerId,
            'date_from' => (string) $request->input('date_from', ''),
            'date_to' => (string) $request->input('date_to', ''),
        ]);

        return Response::ok($result);
    }

    public function acceptTransfer(Request $request, array $params): Response
    {
        [$user] = $this->context($request);
        $transferId = (int) ($params['id'] ?? 0);

        $result = $this->managers->accept($user, $transferId);

        $this->audit($request, $user, 'client.transfer.accept', 'user', (int) ($result['id'] ?? 0), [
            'transfer_id' => $transferId,
        ]);

        return Response::ok($result);
    }

    public function declineTransfer(Request $request, array $params): Response
    {
        [$user] = $this->context($request);
        $transferId = (int) ($params['id'] ?? 0);

        $result = $this->managers->decline($user, $transferId);

        $this->audit($request, $user, 'client.transfer.decline', 'user', (int) ($result['id'] ?? 0), [
            'transfer_id' => $transferId,
        ]);

        return Response::ok($result);
    }

    public function cancelTransfer(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);
        $transferId = (int) ($params['id'] ?? 0);

        $result = $this->managers->cancel($user, $capabilities, $transferId);

        $this->audit($request, $user, 'client.transfer.cancel', 'user', (int) ($result['id'] ?? 0), [
            'transfer_id' => $transferId,
        ]);

        return Response::ok($result);
    }

    public function index(Request $request): Response
    {
        [$user, $capabilities] = $this->context($request);

        if ((int) $user['LEVEL'] < 10) {
            throw new HttpException(403, 'forbidden', 'Нет доступа к списку клиентов');
        }

        $query = trim($request->queryParam('q'));
        $page = max(1, (int) $request->queryParam('page', '1'));
        $perPage = max(10, min(100, (int) $request->queryParam('per_page', '20')));

        $state = trim($request->queryParam('state'));
        $from = trim($request->queryParam('from'));
        $to = trim($request->queryParam('to'));
        $sort = trim($request->queryParam('sort'));
        $managerFilter = trim($request->queryParam('manager'));

        $filters = [
            'state' => in_array($state, ['active', 'pending', 'blocked'], true) ? $state : '',
            'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1 ? $from : '',
            'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1 ? $to : '',
            'manager' => in_array($managerFilter, ['mine', 'none'], true) ? $managerFilter : '',
            'manager_id' => (int) $user['ID'],
            'sort' => in_array($sort, ['name_asc', 'name_desc', 'created_desc', 'created_asc', 'requests_desc', 'requests_asc'], true)
                ? $sort
                : 'name_asc',
        ];

        $clients = $this->users->listClients($query, $perPage, ($page - 1) * $perPage, $filters);
        $total = $this->users->countClients($query, $filters);
        $incoming = $this->managers->pendingForManager((int) $user['ID']);
        $incomingMap = [];

        foreach ($incoming as $transfer) {
            $incomingMap[(int) $transfer['client_id']] = $transfer;
        }

        $items = array_map(static function (array $row) use ($incomingMap): array {
            $managerId = $row['manager_id'] !== null ? (int) $row['manager_id'] : null;
            $transfer = $incomingMap[(int) $row['id']] ?? null;

            return [
                'id' => (int) $row['id'],
                'login' => (string) $row['login'],
                'name' => (string) $row['name'],
                'email' => (string) ($row['email'] ?? ''),
                'phone' => (string) ($row['phone'] ?? ''),
                'company' => (string) ($row['company'] ?? ''),
                'inn' => (string) ($row['inn'] ?? ''),
                'position' => (string) ($row['position'] ?? ''),
                'active' => ($row['active'] ?? 'N') === 'Y',
                'reg_state' => (string) ($row['reg_state'] ?? 'active'),
                'registered_at' => (string) ($row['registered_at'] ?? ''),
                'requests_total' => (int) ($row['requests_total'] ?? 0),
                'manager' => $managerId !== null
                    ? ['id' => $managerId, 'name' => (string) ($row['manager_name'] ?? '')]
                    : null,
                'transfer_to_me' => $transfer !== null
                    ? [
                        'id' => (int) $transfer['id'],
                        'from_name' => (string) ($transfer['from'] !== null ? $transfer['from']['name'] : ''),
                        'date_from' => $transfer['date_from'],
                        'date_to' => $transfer['date_to'],
                    ]
                    : null,
            ];
        }, $clients);

        return Response::ok([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }
}
