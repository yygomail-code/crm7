<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Chat\ChatService;
use App\Http\FileResponse;
use App\Http\Request;
use App\Http\Response;

final class ChatController extends ApiController
{
    public function __construct(private readonly ChatService $service = new ChatService())
    {
        parent::__construct();
    }

    public function index(Request $request): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->threads($user, $capabilities));
    }

    public function messages(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->messages(
            $user,
            $capabilities,
            (int) ($params['id'] ?? 0),
            (int) $request->queryParam('after_id', '0')
        ));
    }

    public function post(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        $attachmentIds = $request->input('attachment_ids', []);

        return Response::ok($this->service->post(
            $user,
            $capabilities,
            (int) ($params['id'] ?? 0),
            (string) $request->input('body', ''),
            is_array($attachmentIds) ? array_map('intval', $attachmentIds) : []
        ), 201);
    }

    public function uploadAttachment(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok(
            $this->service->uploadAttachment(
                $user,
                $capabilities,
                (int) ($params['id'] ?? 0),
                $_FILES['file'] ?? []
            ),
            201
        );
    }

    public function downloadAttachment(Request $request, array $params): FileResponse
    {
        [$user, $capabilities] = $this->context($request);

        $file = $this->service->downloadAttachment($user, $capabilities, (int) ($params['id'] ?? 0));

        return new FileResponse($file['absolute'], $file['name'], $file['mime']);
    }

    public function read(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->markRead($user, $capabilities, (int) ($params['id'] ?? 0)));
    }

    public function unread(Request $request): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->unread($user, $capabilities));
    }

    public function quickReplies(Request $request): Response
    {
        [$user] = $this->context($request);

        return Response::ok($this->service->quickReplies($user));
    }
}
