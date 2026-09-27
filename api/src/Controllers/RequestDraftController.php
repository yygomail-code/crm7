<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Requests\RequestDraftService;

final class RequestDraftController extends ApiController
{
    public function __construct(private readonly RequestDraftService $service = new RequestDraftService())
    {
        parent::__construct();
    }

    public function index(Request $request): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->list($user, $capabilities));
    }

    public function show(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->get($user, $capabilities, (int) ($params['id'] ?? 0)));
    }

    public function create(Request $request): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->save($user, $capabilities, $request->bodyAll()), 201);
    }

    public function update(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok(
            $this->service->save($user, $capabilities, $request->bodyAll(), (int) ($params['id'] ?? 0))
        );
    }

    public function remove(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->delete($user, $capabilities, (int) ($params['id'] ?? 0)));
    }
}
