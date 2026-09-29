<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Requests\RequestService;

final class RequestController extends ApiController
{
    public function __construct(
        private readonly RequestService $service = new RequestService()
    ) {
        parent::__construct();
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

    public function preview(Request $request): Response
    {
        [$user] = $this->context($request);

        return Response::ok($this->service->preview($user, $request->bodyAll()));
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

    public function edit(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        $result = $this->service->updateRequest($user, $capabilities, (int) ($params['id'] ?? 0), $request->bodyAll());
        $this->audit($request, $user, 'request.edit', 'request', (int) ($params['id'] ?? 0));

        return Response::ok($result);
    }

    public function meta(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        $result = $this->service->updateMeta($user, $capabilities, (int) ($params['id'] ?? 0), $request->bodyAll());
        $this->audit($request, $user, 'request.meta', 'request', (int) ($params['id'] ?? 0), [
            'priority' => $request->input('priority'),
            'due_at' => $request->input('due_at'),
        ]);

        return Response::ok($result);
    }

    public function items(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        $result = $this->service->updateItems($user, $capabilities, (int) ($params['id'] ?? 0), $request->bodyAll());
        $this->audit($request, $user, 'request.items', 'request', (int) ($params['id'] ?? 0));

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
