<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Reports\ReportsService;

final class ReportScheduleController extends ApiController
{
    public function __construct(private readonly ReportsService $service = new ReportsService())
    {
        parent::__construct();
    }

    public function index(Request $request): Response
    {
        $this->requireSettings($request);

        return Response::ok($this->service->listSchedules());
    }

    public function create(Request $request): Response
    {
        [$user] = $this->requireSettings($request);

        return Response::ok($this->service->saveSchedule(null, $request->bodyAll(), (int) $user['ID']), 201);
    }

    public function update(Request $request, array $params): Response
    {
        [$user] = $this->requireSettings($request);

        return Response::ok($this->service->saveSchedule(
            (int) ($params['id'] ?? 0),
            $request->bodyAll(),
            (int) $user['ID']
        ));
    }

    public function remove(Request $request, array $params): Response
    {
        $this->requireSettings($request);
        $this->service->deleteSchedule((int) ($params['id'] ?? 0));

        return Response::ok(['deleted' => true]);
    }

    public function send(Request $request, array $params): Response
    {
        $this->requireSettings($request);

        return Response::ok($this->service->sendScheduleNow((int) ($params['id'] ?? 0)));
    }

    private function requireSettings(Request $request): array
    {
        [$user, $capabilities] = $this->context($request);

        if (!in_array('settings.manage', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав');
        }

        return [$user, $capabilities];
    }
}
