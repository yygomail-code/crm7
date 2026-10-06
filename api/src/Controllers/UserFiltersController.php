<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\UserFilterRepository;

final class UserFiltersController extends ApiController
{
    public function __construct(private readonly UserFilterRepository $filters = new UserFilterRepository())
    {
        parent::__construct();
    }

    public function index(Request $request): Response
    {
        [$user] = $this->context($request);

        return Response::ok(['filters' => $this->filters->all((int) $user['ID'])]);
    }

    public function save(Request $request, array $params): Response
    {
        [$user] = $this->context($request);

        $this->filters->save((int) $user['ID'], $this->normalizeKey((string) ($params['key'] ?? '')), $request->bodyAll());

        return Response::ok(['saved' => true]);
    }

    public function remove(Request $request, array $params): Response
    {
        [$user] = $this->context($request);

        $this->filters->delete((int) $user['ID'], $this->normalizeKey((string) ($params['key'] ?? '')));

        return Response::ok(['deleted' => true]);
    }

    private function normalizeKey(string $key): string
    {
        $key = trim($key);

        if ($key === '' || preg_match('/^[a-z0-9._-]{1,64}$/i', $key) !== 1) {
            throw new HttpException(422, 'validation_error', 'Некорректный ключ фильтра');
        }

        return $key;
    }
}
