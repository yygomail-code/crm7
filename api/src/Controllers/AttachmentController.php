<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Attachments\AttachmentService;
use App\Http\FileResponse;
use App\Http\Request;
use App\Http\Response;

final class AttachmentController extends ApiController
{
    public function __construct(private readonly AttachmentService $service = new AttachmentService())
    {
        parent::__construct();
    }

    public function index(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->list($user, $capabilities, (int) ($params['id'] ?? 0)));
    }

    public function upload(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok(
            $this->service->upload($user, $capabilities, (int) ($params['id'] ?? 0), $_FILES['file'] ?? []),
            201
        );
    }

    public function download(Request $request, array $params): FileResponse
    {
        [$user, $capabilities] = $this->context($request);

        $file = $this->service->download($user, $capabilities, (int) ($params['id'] ?? 0));

        return new FileResponse($file['absolute'], $file['name'], $file['mime']);
    }

    public function remove(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->remove($user, $capabilities, (int) ($params['id'] ?? 0)));
    }
}
