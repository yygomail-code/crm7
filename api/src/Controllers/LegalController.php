<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\LegalRepository;

final class LegalController extends ApiController
{
    public function __construct(private readonly LegalRepository $documents = new LegalRepository())
    {
        parent::__construct();
    }

    public function show(Request $request, array $params): Response
    {
        $document = $this->documents->find((string) ($params['code'] ?? ''));

        if ($document === null) {
            throw new HttpException(404, 'not_found', 'Документ не найден');
        }

        return Response::ok([
            'code' => (string) $document['code'],
            'title' => (string) $document['title'],
            'body' => (string) $document['body'],
            'updated_at' => (string) $document['updated_at'],
        ]);
    }

    public function index(Request $request): Response
    {
        $this->requireSettings($request);

        return Response::ok(['items' => array_map(static fn (array $row): array => [
            'code' => (string) $row['code'],
            'title' => (string) $row['title'],
            'body' => (string) $row['body'],
            'updated_at' => (string) $row['updated_at'],
        ], $this->documents->all())]);
    }

    public function save(Request $request, array $params): Response
    {
        [$user] = $this->requireSettings($request);

        $code = (string) ($params['code'] ?? '');
        $title = trim((string) $request->input('title', ''));
        $body = (string) $request->input('body', '');

        if ($code === '' || $title === '' || trim($body) === '') {
            throw new HttpException(422, 'validation_error', 'Заполните заголовок и текст документа');
        }

        $this->documents->save($code, $title, $body, (int) $user['ID']);
        $this->audit($request, $user, 'legal.save', 'legal', $code);

        return Response::ok($this->index($request));
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
