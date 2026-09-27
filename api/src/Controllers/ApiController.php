<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Audit\AuditService;
use App\Auth\AuthService;
use App\Http\Request;

abstract class ApiController
{
    protected AuthService $auth;

    protected AuditService $audit;

    public function __construct()
    {
        $this->auth = new AuthService();
        $this->audit = new AuditService();
    }

    protected function context(Request $request): array
    {
        [$user] = $this->auth->authenticate($request->bearerToken());

        return [$user, $this->auth->capabilitiesFor($user)];
    }

    protected function audit(
        Request $request,
        array $user,
        string $action,
        ?string $entity = null,
        string|int|null $entityId = null,
        array $data = []
    ): void {
        $this->audit->log($user, $action, $entity, $entityId, $data, $request->ip);
    }
}
