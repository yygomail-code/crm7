<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Substitutions\SubstitutionService;

final class SubstitutionController extends ApiController
{
    public function __construct(private readonly SubstitutionService $service = new SubstitutionService())
    {
        parent::__construct();
    }

    public function index(Request $request): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->service->list($user, $capabilities));
    }

    public function create(Request $request): Response
    {
        [$user, $capabilities] = $this->context($request);

        $result = $this->service->create($user, $capabilities, $request->bodyAll());
        $this->audit($request, $user, 'substitution.create', 'substitution', $result['item']['id'] ?? null, [
            'manager_id' => $request->input('manager_id'),
            'substitute_id' => $request->input('substitute_id'),
            'from' => (string) $request->input('date_from', ''),
            'to' => (string) $request->input('date_to', ''),
        ]);

        return Response::ok($result, 201);
    }

    public function end(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        $result = $this->service->end($user, $capabilities, (int) ($params['id'] ?? 0));
        $this->audit($request, $user, 'substitution.end', 'substitution', (int) ($params['id'] ?? 0), [
            'returned' => $result['returned'] ?? 0,
        ]);

        return Response::ok($result);
    }
}
