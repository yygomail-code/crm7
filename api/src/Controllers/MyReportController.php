<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Export\ExportMailer;
use App\Http\DownloadResponse;
use App\Http\Request;
use App\Http\Response;
use App\Reports\MyReportService;

final class MyReportController extends ApiController
{
    public function __construct(private readonly MyReportService $service = new MyReportService())
    {
        parent::__construct();
    }

    public function summary(Request $request): Response
    {
        [$user] = $this->context($request);

        return Response::ok($this->service->summary($user, $this->filters($request)));
    }

    public function export(Request $request): Response|DownloadResponse
    {
        [$user] = $this->context($request);

        $result = $this->service->export(
            $user,
            $this->filters($request),
            (string) $request->queryParam('format', 'csv')
        );

        if ($request->queryParam('email', '') === '1') {
            return Response::ok(ExportMailer::send($user, $result));
        }

        return new DownloadResponse($result['content'], $result['file_name'], $result['mime']);
    }

    private function filters(Request $request): array
    {
        return [
            'from' => $request->queryParam('from'),
            'to' => $request->queryParam('to'),
            'status' => $request->queryParam('status'),
            'priority' => $request->queryParam('priority', ''),
            'warehouse_id' => $request->queryParam('warehouse_id', ''),
            'item' => $request->queryParam('item'),
            'detail' => $request->queryParam('detail', 'requests'),
        ];
    }
}
