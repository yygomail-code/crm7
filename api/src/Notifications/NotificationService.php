<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Core\Config;
use App\Mail\MailService;
use App\Repositories\NotificationRepository;

final class NotificationService
{
    public const EVENTS = [
        'request.new' => 'Новая заявка',
        'request.claim' => 'Заявка взята в работу',
        'request.status' => 'Смена статуса заявки',
        'request.comment' => 'Комментарий к заявке',
        'request.assigned' => 'Назначение исполнителя',
        'request.transferred' => 'Передача заявки',
        'chat.message' => 'Сообщение в чате',
        'substitution.created' => 'Замещение: назначено',
        'substitution.ended' => 'Замещение: завершено',
        'user.pending' => 'Новая регистрация клиента',
        'user.approved' => 'Регистрация подтверждена',
        'user.rejected' => 'Регистрация отклонена',
    ];

    private const EMAIL_TEMPLATES = [
        'request.new' => 'request_new',
        'request.claim' => 'request_claim',
        'request.status' => 'request_status',
        'request.comment' => 'request_comment',
        'request.assigned' => 'request_assigned',
        'request.transferred' => 'request_transferred',
        'request.assign' => 'request_assign',
        'substitution.created' => 'substitution_created',
        'substitution.ended' => 'substitution_ended',
        'user.approved' => 'user_approved',
        'user.rejected' => 'user_rejected',
    ];

    public function __construct(
        private readonly NotificationRepository $repo = new NotificationRepository(),
        private readonly MailService $mail = new MailService()
    ) {
    }

    public function notify(
        int $userId,
        string $type,
        string $title,
        ?string $body = null,
        ?int $requestId = null,
        array $emailVars = []
    ): void {
        if ($userId <= 0) {
            return;
        }

        $settings = $this->repo->settingsFor($userId);
        $inApp = $settings[$type]['in_app'] ?? true;
        $email = $settings[$type]['email'] ?? true;

        if ($inApp) {
            $this->repo->add(
                $userId,
                $type,
                mb_substr($title, 0, 255),
                $body !== null && $body !== '' ? mb_substr($body, 0, 1000) : null,
                $requestId
            );
        }

        if (!$email) {
            return;
        }

        $template = self::EMAIL_TEMPLATES[$type] ?? null;

        if ($template === null) {
            return;
        }

        $link = $requestId !== null
            ? rtrim((string) Config::get('APP_URL', ''), '/') . '/#/requests/' . $requestId
            : '';

        $this->mail->notifyEmail($userId, $template, array_merge([
            'title' => $title,
            'body' => (string) ($body ?? ''),
            'link' => $link,
        ], $emailVars));
    }

    public function notifyParticipants(
        array $request,
        int $actorId,
        string $type,
        string $title,
        ?string $body = null,
        array $emailVars = []
    ): void {
        $requestId = (int) ($request['ID'] ?? 0);
        $clientId = (int) ($request['client_id'] ?? 0);
        $managerId = isset($request['manager_id']) && $request['manager_id'] !== null
            ? (int) $request['manager_id']
            : null;

        if ($clientId > 0 && $clientId !== $actorId) {
            $this->notify($clientId, $type, $title, $body, $requestId, $emailVars);
        }

        if ($managerId !== null && $managerId !== $actorId) {
            $this->notify($managerId, $type, $title, $body, $requestId, $emailVars);
        }
    }

    public function list(int $userId, bool $unreadOnly, int $limit, int $page = 1): array
    {
        $limit = max(1, min(100, $limit));
        $page = max(1, $page);

        $items = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'type' => (string) $row['type'],
            'title' => (string) $row['title'],
            'body' => (string) ($row['body'] ?? ''),
            'request_id' => $row['request_id'] !== null ? (int) $row['request_id'] : null,
            'is_read' => $row['read_at'] !== null,
            'created_at' => (string) $row['created_at'],
        ], $this->repo->listForUser($userId, $unreadOnly, $limit, ($page - 1) * $limit));

        return [
            'items' => $items,
            'unread' => $this->repo->unreadCount($userId),
            'total' => $this->repo->countForUser($userId, $unreadOnly),
            'page' => $page,
            'per_page' => $limit,
        ];
    }

    public function unread(int $userId): array
    {
        return ['unread' => $this->repo->unreadCount($userId)];
    }

    public function settings(int $userId): array
    {
        $stored = $this->repo->settingsFor($userId);

        $items = [];

        foreach (self::EVENTS as $code => $title) {
            $items[] = [
                'event' => $code,
                'title' => $title,
                'in_app' => $stored[$code]['in_app'] ?? true,
                'email' => $stored[$code]['email'] ?? true,
            ];
        }

        return ['items' => $items];
    }

    public function saveSettings(int $userId, array $items): array
    {
        $allowed = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $event = (string) ($item['event'] ?? '');

            if (!isset(self::EVENTS[$event])) {
                continue;
            }

            $allowed[] = [
                'event' => $event,
                'in_app' => (bool) ($item['in_app'] ?? true),
                'email' => (bool) ($item['email'] ?? true),
            ];
        }

        $this->repo->saveSettings($userId, $allowed);

        return $this->settings($userId);
    }

    public function markRead(int $userId, array $ids, bool $all): array
    {
        $marked = $all ? $this->repo->markAllRead($userId) : $this->repo->markRead($userId, $ids);

        return ['marked' => $marked, 'unread' => $this->repo->unreadCount($userId)];
    }
}
