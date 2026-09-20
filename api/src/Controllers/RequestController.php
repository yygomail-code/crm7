<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Repositories\SavedFilterRepository;
use App\Requests\RequestService;

final class RequestController extends ApiController
{
    public function __construct(
        private readonly RequestService $service = new RequestService(),
        private readonly SavedFilterRepository $filters = new SavedFilterRepository()
    ) {
        parent::__construct();
    }

    public function bulk(Request $request): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->bulk($user, $capabilities, $request->bodyAll()));
    }

    public function savedFilters(Request $request): Response
    {
        [$user] = $this->context($request);

        $items = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'params' => json_decode((string) $row['params_json'], true) ?? [],
            'created_at' => (string) $row['created_at'],
        ], $this->filters->listForUser((int) $user['ID']));

        return Response::ok(['items' => $items]);
    }

    public function saveFilter(Request $request): Response
    {
        [$user] = $this->context($request);

        $name = trim((string) $request->input('name', ''));
        $params = $request->input('params', []);

        if ($name === '') {
            throw new \App\Http\HttpException(422, 'validation_error', 'Укажите название фильтра');
        }

        if (!is_array($params)) {
            $params = [];
        }

        $id = $this->filters->create((int) $user['ID'], $name, $params);

        return Response::ok(['id' => $id, 'name' => $name, 'params' => $params], 201);
    }

    public function deleteFilter(Request $request, array $params): Response
    {
        [$user] = $this->context($request);

        return Response::ok([
            'deleted' => $this->filters->delete((int) ($params['id'] ?? 0), (int) $user['ID']),
        ]);
    }

    public function statuses(Request $request): Response
    {
        $this->context($request);

        return Response::ok($this->service->statuses());
    }

    public function index(Request $request): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->list($user, $capabilities, $request->queryAll()));
    }

    public function filters(Request $request): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->filters($user, $capabilities));
    }

    public function summary(Request $request): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->summary($user, $capabilities));
    }

    public function show(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->detail($user, $capabilities, (int) ($params['id'] ?? 0)));
    }

    public function create(Request $request): Response
    {
        [$user, $capabilities] = $this->context($request);

        $result = $this->service->create($user, $capabilities, $request->bodyAll());
        $this->audit($request, $user, 'request.create', 'request', $result['request']['id'] ?? null, [
            'number' => $result['request']['number'] ?? '',
        ]);

        return Response::ok($result, 201);
    }

    public function transition(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        $result = $this->service->transition($user, $capabilities, (int) ($params['id'] ?? 0), $request->bodyAll());
        $this->audit($request, $user, 'request.transition', 'request', (int) ($params['id'] ?? 0), [
            'to_status' => (string) $request->input('to_status', ''),
        ]);

        return Response::ok($result);
    }

    public function claim(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        $result = $this->service->claim($user, $capabilities, (int) ($params['id'] ?? 0));
        $this->audit($request, $user, 'request.claim', 'request', (int) ($params['id'] ?? 0));

        return Response::ok($result);
    }

    public function assign(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        $result = $this->service->assignRequest($user, $capabilities, (int) ($params['id'] ?? 0), $request->bodyAll());
        $this->audit($request, $user, 'request.assign', 'request', (int) ($params['id'] ?? 0), [
            'manager_id' => $request->input('manager_id'),
        ]);

        return Response::ok($result);
    }

    public function comment(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok(
            $this->service->addComment($user, $capabilities, (int) ($params['id'] ?? 0), $request->bodyAll()),
            201
        );
    }

    public function activityTypes(Request $request): Response
    {
        [, $capabilities] = $this->context($request);

        return Response::ok($this->service->activityTypes($capabilities));
    }

    public function activity(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok(
            $this->service->addActivity($user, $capabilities, (int) ($params['id'] ?? 0), $request->bodyAll()),
            201
        );
    }
}
