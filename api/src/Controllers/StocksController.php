<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Export\ExportMailer;
use App\Http\DownloadResponse;
use App\Http\FileResponse;
use App\Http\Request;
use App\Http\Response;
use App\Stocks\StocksService;

final class StocksController extends ApiController
{
    public function __construct(private readonly StocksService $service = new StocksService())
    {
        parent::__construct();
    }

    public function warehouses(Request $request): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->warehouses($user, $capabilities));
    }

    public function searchCounts(Request $request): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->searchCounts(
            $user,
            $capabilities,
            $this->filters($request)
        ));
    }

    public function levels(Request $request): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->levels(
            $user,
            $capabilities,
            $this->warehouseIds($request),
            $this->filters($request),
            (int) $request->queryParam('page', '1'),
            (int) $request->queryParam('per_page', '50'),
            (int) $request->queryParam('client_id', '0')
        ));
    }

    public function level(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->level(
            $user,
            $capabilities,
            (int) ($params['itemId'] ?? 0)
        ));
    }

    public function export(Request $request): Response|DownloadResponse
    {
        [$user, $capabilities] = $this->context($request);

        $result = $this->service->export(
            $user,
            $capabilities,
            $this->warehouseIds($request),
            $this->filters($request),
            (string) $request->queryParam('format', 'csv')
        );

        if ($request->queryParam('email', '') === '1') {
            return Response::ok(ExportMailer::send($user, $result));
        }

        return new DownloadResponse($result['content'], $result['file_name'], $result['mime']);
    }

    public function import(Request $request): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->import(
            $user,
            $capabilities,
            $_FILES['file'] ?? [],
            (string) $request->input('actual_date', ''),
            $this->importMapping($request)
        ), 201);
    }

    public function history(Request $request): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->history($user, $capabilities));
    }

    public function createItem(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->createItem(
            $user,
            $capabilities,
            (int) ($params['id'] ?? 0),
            $request->bodyAll()
        ), 201);
    }

    public function updateItem(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->updateItem(
            $user,
            $capabilities,
            (int) ($params['itemId'] ?? 0),
            $request->bodyAll()
        ));
    }

    public function rename(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->renameWarehouse(
            $user,
            $capabilities,
            (int) ($params['id'] ?? 0),
            $request->bodyAll()
        ));
    }

    public function uploadPhoto(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->uploadPhoto(
            $user,
            $capabilities,
            (int) ($params['itemId'] ?? 0),
            $_FILES['file'] ?? []
        ));
    }

    public function deletePhoto(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->deletePhoto($user, $capabilities, (int) ($params['id'] ?? 0)));
    }

    public function photo(Request $request, array $params): FileResponse
    {
        [$user, $capabilities] = $this->context($request);

        return $this->service->photo(
            $user,
            $capabilities,
            (int) ($params['id'] ?? 0),
            $request->queryParam('size', 'max')
        );
    }

    private function importMapping(Request $request): array
    {
        $raw = trim((string) $request->input('mapping', ''));

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<int, int>
     */
    private function warehouseIds(Request $request): array
    {
        $raw = trim((string) $request->queryParam('warehouse_ids', ''));

        if ($raw === '') {
            $single = (int) $request->queryParam('warehouse_id', '0');

            return $single > 0 ? [$single] : [];
        }

        $ids = [];

        foreach (explode(',', $raw) as $part) {
            $id = (int) trim($part);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function filters(Request $request): array
    {
        return [
            'q' => trim($request->queryParam('q')),
            'qty_op' => (string) $request->queryParam('qty_op', ''),
            'qty' => $request->queryParam('qty', ''),
            'sort' => (string) $request->queryParam('sort', ''),
            'show_zero' => (string) $request->queryParam('show_zero', ''),
            'group_ids' => $this->groupIds($request),
            'no_group' => (string) $request->queryParam('no_group', ''),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function groupIds(Request $request): array
    {
        $raw = trim((string) $request->queryParam('group_ids', ''));

        if ($raw === '') {
            $single = (int) $request->queryParam('group_id', '0');

            return $single > 0 ? [$single] : [];
        }

        $ids = [];

        foreach (explode(',', $raw) as $part) {
            $id = (int) trim($part);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
