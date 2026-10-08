<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Stocks\ItemTypeService;

final class ItemTypeController extends ApiController
{
    public function __construct(private readonly ItemTypeService $service = new ItemTypeService())
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
        [, $capabilities] = $this->context($request);

        return Response::ok($this->service->save($capabilities, $request->bodyAll()));
    }
}
