<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\UserRepository;
use App\Users\UserAdminService;

final class UserController extends ApiController
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly UserAdminService $admin = new UserAdminService()
    ) {
        parent::__construct();
    }

    private function denyInDemo(): void
    {
        if (Config::demoMode()) {
            throw new HttpException(403, 'demo_mode', 'Действие недоступно в демо-режиме');
        }
    }

    public function managers(Request $request): Response
    {
        $this->context($request);

        $items = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'sid' => (string) $row['sid'],
            'login' => (string) $row['login'],
            'name' => (string) $row['name'],
            'level' => (int) $row['level'],
            'email' => (string) ($row['email'] ?? ''),
        ], $this->users->listManagers());

        return Response::ok(['items' => $items]);
    }

    public function index(Request $request): Response
    {
        [, $capabilities] = $this->context($request);

        if (!in_array('users.manage', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав');
        }

        return Response::ok($this->admin->list([
            'q' => trim($request->queryParam('q')),
            'level' => (int) $request->queryParam('level', '0'),
            'state' => trim($request->queryParam('state')),
        ], max(1, (int) $request->queryParam('page', '1')), max(10, min(100, (int) $request->queryParam('per_page', '50')))));
    }

    public function create(Request $request): Response
    {
        $this->denyInDemo();

        [$user, $capabilities] = $this->context($request);

        $result = $this->admin->create($user, $capabilities, $request->bodyAll());
        $this->audit($request, $user, 'user.create', 'user', $result['id'], ['login' => $result['login']]);

        return Response::ok($result, 201);
    }

    public function update(Request $request, array $params): Response
    {
        $this->denyInDemo();

        [$user, $capabilities] = $this->context($request);

        $result = $this->admin->update($user, $capabilities, (int) ($params['id'] ?? 0), $request->bodyAll());
        $this->audit($request, $user, 'user.update', 'user', (int) ($params['id'] ?? 0), [
            'level' => $result['level'],
        ]);

        return Response::ok($result);
    }

    public function block(Request $request, array $params): Response
    {
        $this->denyInDemo();

        [$user, $capabilities] = $this->context($request);

        $result = $this->admin->block($user, $capabilities, (int) ($params['id'] ?? 0), true);
        $this->audit($request, $user, 'user.block', 'user', (int) ($params['id'] ?? 0));

        return Response::ok($result);
    }

    public function unblock(Request $request, array $params): Response
    {
        $this->denyInDemo();

        [$user, $capabilities] = $this->context($request);

        $result = $this->admin->block($user, $capabilities, (int) ($params['id'] ?? 0), false);
        $this->audit($request, $user, 'user.unblock', 'user', (int) ($params['id'] ?? 0));

        return Response::ok($result);
    }

    public function resetPassword(Request $request, array $params): Response
    {
        $this->denyInDemo();

        [$user, $capabilities] = $this->context($request);

        $result = $this->admin->resetPassword($user, $capabilities, (int) ($params['id'] ?? 0));
        $this->audit($request, $user, 'user.reset_password', 'user', (int) ($params['id'] ?? 0));

        return Response::ok($result);
    }

    public function roles(Request $request): Response
    {
        [, $capabilities] = $this->context($request);

        if (!in_array('users.manage', $capabilities, true) && !in_array('roles.manage', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав');
        }

        return Response::ok($this->admin->roles());
    }

    public function saveRole(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->admin->saveRole(
            $user,
            $capabilities,
            (int) ($params['level'] ?? 0),
            $request->bodyAll()
        ));
    }
}
