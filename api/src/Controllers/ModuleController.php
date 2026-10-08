<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Modules\ModuleService;

final class ModuleController extends ApiController
{
    public function __construct(private readonly ModuleService $service = new ModuleService())
    {
        parent::__construct();
    }

    public function index(Request $request): Response
    {
        [, $capabilities] = $this->context($request);

        return Response::ok($this->service->list($capabilities));
    }

    public function save(Request $request): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->save($user, $capabilities, $request->bodyAll()));
    }
}
