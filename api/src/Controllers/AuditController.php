<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Audit\AuditService;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;

final class AuditController extends ApiController
{
    public function __construct(private readonly AuditService $service = new AuditService())
    {
        parent::__construct();
    }

    public function index(Request $request): Response
    {
        [, $capabilities] = $this->context($request);

        if (!in_array('audit.view', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для просмотра аудита');
        }

        $userId = (int) $request->queryParam('user_id', '0');

        return Response::ok($this->service->list([
            'user_id' => $userId > 0 ? $userId : null,
            'action' => trim($request->queryParam('action')),
            'entity' => trim($request->queryParam('entity')),
            'from' => trim($request->queryParam('from')),
            'to' => trim($request->queryParam('to')),
        ], max(1, (int) $request->queryParam('page', '1')), max(10, min(100, (int) $request->queryParam('per_page', '50')))));
    }

    public function actions(Request $request): Response
    {
        [, $capabilities] = $this->context($request);

        if (!in_array('audit.view', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для просмотра аудита');
        }

        return Response::ok($this->service->actions());
    }
}
