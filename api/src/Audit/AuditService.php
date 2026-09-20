<?php

declare(strict_types=1);

namespace App\Audit;

use App\Repositories\AuditRepository;
use Throwable;

final class AuditService
{
    public function __construct(private readonly AuditRepository $repo = new AuditRepository())
    {
    }

    public function log(
        ?array $user,
        string $action,
        ?string $entity = null,
        string|int|null $entityId = null,
        array $data = [],
        ?string $ip = null
    ): void {
        try {
            $this->repo->add(
                $user !== null ? (int) $user['ID'] : null,
                $action,
                $entity,
                $entityId !== null ? (string) $entityId : null,
                $data,
                $ip
            );
        } catch (Throwable) {
            // аудит не должен ломать основную операцию
        }
    }

    public function list(array $filters, int $page, int $perPage): array
    {
        $result = $this->repo->list($filters, $page, $perPage);

        $items = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'user' => $row['user_id'] !== null
                ? ['id' => (int) $row['user_id'], 'name' => (string) ($row['user_name'] ?? '')]
                : null,
            'action' => (string) $row['action'],
            'entity' => (string) ($row['entity'] ?? ''),
            'entity_id' => (string) ($row['entity_id'] ?? ''),
            'data' => $row['data_json'] !== null ? (json_decode((string) $row['data_json'], true) ?? []) : [],
            'ip' => (string) ($row['ip'] ?? ''),
            'created_at' => (string) $row['created_at'],
        ], $result['items']);

        return [
            'items' => $items,
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    public function actions(): array
    {
        return ['items' => $this->repo->actions()];
    }
}
