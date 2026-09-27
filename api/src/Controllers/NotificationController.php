<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Notifications\NotificationService;

final class NotificationController extends ApiController
{
    public function __construct(private readonly NotificationService $service = new NotificationService())
    {
        parent::__construct();
    }

    public function index(Request $request): Response
    {
        [$user] = $this->context($request);

        return Response::ok($this->service->list(
            (int) $user['ID'],
            $request->queryParam('unread') === '1',
            (int) $request->queryParam('per_page', $request->queryParam('limit', '20')),
            max(1, (int) $request->queryParam('page', '1'))
        ));
    }

    public function count(Request $request): Response
    {
        [$user] = $this->context($request);

        return Response::ok($this->service->unread((int) $user['ID']));
    }

    public function read(Request $request): Response
    {
        [$user] = $this->context($request);

        $ids = $request->input('ids', []);
        $all = (bool) $request->input('all', false);

        return Response::ok($this->service->markRead(
            (int) $user['ID'],
            is_array($ids) ? $ids : [],
            $all
        ));
    }

    public function settings(Request $request): Response
    {
        [$user] = $this->context($request);

        return Response::ok($this->service->settings((int) $user['ID']));
    }

    public function saveSettings(Request $request): Response
    {
        [$user] = $this->context($request);

        $items = $request->input('items', []);

        return Response::ok($this->service->saveSettings((int) $user['ID'], is_array($items) ? $items : []));
    }
}
