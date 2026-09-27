<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Export\ExportMailer;
use App\Http\DownloadResponse;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Reports\ReportsService;

final class ReportController extends ApiController
{
    public function __construct(private readonly ReportsService $service = new ReportsService())
    {
        parent::__construct();
    }

    public function summary(Request $request): Response
    {
        [$user, $capabilities] = $this->context($request);
        $this->requireReports($capabilities);

        return Response::ok($this->service->summary(
            ReportsService::scopeFor($user, $capabilities),
            $request->queryParam('from'),
            $request->queryParam('to')
        ));
    }

    public function salesLeads(Request $request): Response
    {
        [$user, $capabilities] = $this->context($request);
        $this->requireReports($capabilities);

        return Response::ok($this->service->salesLeads(
            ReportsService::scopeFor($user, $capabilities),
            $request->queryParam('from'),
            $request->queryParam('to')
        ));
    }

    public function salesLeadsExport(Request $request): Response|DownloadResponse
    {
        [$user, $capabilities] = $this->context($request);
        $this->requireReports($capabilities);

        $result = $this->service->salesLeadsExport(
            ReportsService::scopeFor($user, $capabilities),
            $request->queryParam('from'),
            $request->queryParam('to'),
            (string) $request->queryParam('format', 'csv')
        );

        if ($request->queryParam('email', '') === '1') {
            return Response::ok(ExportMailer::send($user, $result));
        }

        return new DownloadResponse($result['content'], $result['file_name'], $result['mime']);
    }

    public function warehouses(Request $request): Response
    {
        [$user, $capabilities] = $this->context($request);
        $this->requireReports($capabilities);

        return Response::ok($this->service->warehouses(
            ReportsService::scopeFor($user, $capabilities),
            $request->queryParam('from'),
            $request->queryParam('to')
        ));
    }

    public function warehousesExport(Request $request): Response|DownloadResponse
    {
        [$user, $capabilities] = $this->context($request);
        $this->requireReports($capabilities);

        $result = $this->service->warehousesExport(
            ReportsService::scopeFor($user, $capabilities),
            $request->queryParam('from'),
            $request->queryParam('to'),
            (string) $request->queryParam('format', 'csv')
        );

        if ($request->queryParam('email', '') === '1') {
            return Response::ok(ExportMailer::send($user, $result));
        }

        return new DownloadResponse($result['content'], $result['file_name'], $result['mime']);
    }

    public function export(Request $request): Response|DownloadResponse
    {
        [$user, $capabilities] = $this->context($request);
        $this->requireReports($capabilities);

        $result = $this->service->export(
            ReportsService::scopeFor($user, $capabilities),
            $request->queryParam('from'),
            $request->queryParam('to'),
            (string) $request->queryParam('format', 'csv')
        );

        if ($request->queryParam('email', '') === '1') {
            return Response::ok(ExportMailer::send($user, $result));
        }

        return new DownloadResponse($result['content'], $result['file_name'], $result['mime']);
    }

    private function requireReports(array $capabilities): void
    {
        if (
            !in_array('reports.view.all', $capabilities, true)
            && !in_array('reports.view.own', $capabilities, true)
        ) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для отчётов');
        }
    }
}
