<?php

declare(strict_types=1);

namespace App\Requests;

use App\Http\HttpException;
use App\Repositories\RequestDraftRepository;
use App\Repositories\SettingsRepository;

final class RequestDraftService
{
    public function __construct(
        private readonly RequestDraftRepository $drafts = new RequestDraftRepository(),
        private readonly SettingsRepository $settings = new SettingsRepository()
    ) {
    }

    public function list(array $user, array $capabilities): array
    {
        $this->requireCreate($capabilities);

        $items = array_map(static function (array $row): array {
            $items = json_decode((string) ($row['items'] ?? ''), true);

            return [
                'id' => (int) $row['id'],
                'subject' => (string) $row['subject'],
                'priority' => (int) $row['priority'],
                'items_count' => is_array($items) ? count($items) : 0,
                'created_at' => (string) $row['created_at'],
                'updated_at' => (string) $row['updated_at'],
            ];
        }, $this->drafts->listForUser((int) $user['ID']));

        return ['items' => $items];
    }

    public function get(array $user, array $capabilities, int $id): array
    {
        $this->requireCreate($capabilities);

        $row = $this->drafts->findForUser($id, (int) $user['ID']);

        if ($row === null) {
            throw new HttpException(404, 'not_found', 'Черновик не найден');
        }

        return $this->mapDraft($row);
    }

    public function save(array $user, array $capabilities, array $input, ?int $id = null): array
    {
        $this->requireCreate($capabilities);

        if (!$this->settings->salesEnabled()) {
            throw new HttpException(403, 'sales_disabled', 'Продажи отключены: черновики недоступны');
        }

        $subject = trim((string) ($input['subject'] ?? ''));

        if (mb_strlen($subject) > 255) {
            $subject = mb_substr($subject, 0, 255);
        }

        $body = trim((string) ($input['body'] ?? ''));

        if (mb_strlen($body) > 5000) {
            $body = mb_substr($body, 0, 5000);
        }

        $priority = (int) ($input['priority'] ?? 2);

        if (!in_array($priority, [1, 2, 3], true)) {
            $priority = 2;
        }

        $clientId = (int) ($input['client_id'] ?? 0);

        if ($clientId > 0 && $clientId !== (int) $user['ID']) {
            if ((int) $user['LEVEL'] < 10) {
                throw new HttpException(403, 'forbidden', 'Можно сохранять черновики только от своего имени');
            }
        } else {
            $clientId = 0;
        }

        $items = RequestItems::normalize($input['items'] ?? []);

        $data = [
            'client_id' => $clientId > 0 ? $clientId : null,
            'subject' => $subject,
            'body' => $body,
            'priority' => $priority,
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
        ];

        if ($id === null) {
            $id = $this->drafts->create((int) $user['ID'], $data);
        } else {
            if ($this->drafts->findForUser($id, (int) $user['ID']) === null) {
                throw new HttpException(404, 'not_found', 'Черновик не найден');
            }

            $this->drafts->update($id, $data);
        }

        return $this->get($user, $capabilities, $id);
    }

    public function delete(array $user, array $capabilities, int $id): array
    {
        $this->requireCreate($capabilities);

        if ($this->drafts->findForUser($id, (int) $user['ID']) === null) {
            throw new HttpException(404, 'not_found', 'Черновик не найден');
        }

        $this->drafts->delete($id);

        return ['deleted' => true];
    }

    private function requireCreate(array $capabilities): void
    {
        if (!in_array('requests.create', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав');
        }
    }

    private function mapDraft(array $row): array
    {
        $items = json_decode((string) ($row['items'] ?? ''), true);

        return [
            'id' => (int) $row['id'],
            'subject' => (string) $row['subject'],
            'body' => (string) ($row['body'] ?? ''),
            'priority' => (int) $row['priority'],
            'client_id' => $row['client_id'] !== null ? (int) $row['client_id'] : 0,
            'items' => is_array($items) ? $items : [],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
