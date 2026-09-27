<?php

declare(strict_types=1);

namespace App\Chat;

use App\Attachments\AttachmentService;
use App\Clients\ClientManagerService;
use App\Core\RateLimiter;
use App\Http\HttpException;
use App\Notifications\NotificationService;
use App\Repositories\ChatRepository;
use App\Repositories\RequestRepository;
use App\Repositories\SubstitutionRepository;
use App\Repositories\UserRepository;
use App\Support\Storage;

final class ChatService
{
    private const MAX_BODY = 4000;

    public function __construct(
        private readonly ChatRepository $chat = new ChatRepository(),
        private readonly UserRepository $users = new UserRepository(),
        private readonly ClientManagerService $clientManagers = new ClientManagerService(),
        private readonly SubstitutionRepository $substitutions = new SubstitutionRepository(),
        private readonly NotificationService $notifications = new NotificationService(),
        private readonly RequestRepository $requests = new RequestRepository()
    ) {
    }

    public function threads(array $user, array $capabilities): array
    {
        if ($this->isClient($user)) {
            $thread = $this->ensureThread((int) $user['ID']);

            return $this->present([$thread], $user, $capabilities);
        }

        if (in_array('clients.view.all', $capabilities, true)) {
            return $this->present($this->chat->listAll(), $user, $capabilities);
        }

        return $this->present($this->chat->listForManager($this->managerIds($user)), $user, $capabilities);
    }

    public function threadForClient(array $user, array $capabilities, int $clientId, int $requestId = 0): array
    {
        if ($clientId <= 0) {
            throw new HttpException(422, 'validation_error', 'Не указан клиент');
        }

        if ($this->isClient($user) && (int) $user['ID'] !== $clientId) {
            throw new HttpException(403, 'forbidden', 'Доступен только свой клиент');
        }

        $thread = $this->ensureThread($clientId);
        $threadId = (int) ($thread['ID'] ?? 0);
        $unread = 0;

        if ($threadId > 0) {
            $unread = $requestId > 0
                ? $this->chat->unreadCountForRequest((int) $user['ID'], $threadId, $requestId)
                : ($this->chat->unreadCounts((int) $user['ID'], [$threadId])[$threadId] ?? 0);
        }

        return [
            'thread_id' => $threadId,
            'can_post' => $thread !== [] && $this->canPostForThread($user, $capabilities, $thread),
            'unread' => (int) $unread,
        ];
    }

    public function messages(array $user, array $capabilities, int $threadId, int $afterId = 0, ?int $requestId = null): array
    {
        $thread = $this->requireThread($threadId);
        $this->assertCanView($user, $capabilities, $thread);

        $rows = $this->chat->messages($threadId, $afterId, 200, $requestId);
        $attachments = $this->chat->attachmentsForMessages(array_map(
            static fn (array $row): int => (int) $row['id'],
            $rows
        ));

        $items = array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'body' => (string) $row['body'],
            'created_at' => (string) $row['created_at'],
            'user' => [
                'id' => (int) $row['user_id'],
                'name' => (string) $row['user_name'],
                'level' => (int) $row['user_level'],
            ],
            'request' => ($row['request_id'] ?? null) !== null
                ? [
                    'id' => (int) $row['request_id'],
                    'number' => (string) ($row['request_number'] ?? ''),
                ]
                : null,
            'is_mine' => (int) $row['user_id'] === (int) $user['ID'],
            'attachments' => $attachments[(int) $row['id']] ?? [],
        ], $rows);

        return ['items' => $items];
    }

    public function uploadAttachment(array $user, array $capabilities, int $threadId, array $file): array
    {
        $thread = $this->requireThread($threadId);
        $this->assertCanView($user, $capabilities, $thread);

        if (!$this->canPostForThread($user, $capabilities, $thread)) {
            throw new HttpException(403, 'forbidden', 'Отвечать может только менеджер клиента или замещающий');
        }

        RateLimiter::hit('chat.upload', 'user:' . (int) $user['ID'], 60, 3600);

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new HttpException(422, 'too_large', 'Файл превышает лимит сервера загрузки');
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new HttpException(422, 'upload_failed', 'Файл не загружен');
        }

        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0) {
            throw new HttpException(422, 'upload_failed', 'Пустой файл');
        }

        if ($size > AttachmentService::MAX_SIZE) {
            throw new HttpException(422, 'too_large', 'Файл больше 10 МБ');
        }

        $originalName = (string) ($file['name'] ?? 'file');
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, AttachmentService::ALLOWED_EXTENSIONS, true)) {
            throw new HttpException(422, 'bad_type', 'Недопустимый тип файла');
        }

        AttachmentService::assertSafeMime($file);

        $stored = Storage::save($file);
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) ($finfo->file($stored['absolute']) ?: 'application/octet-stream');

        $attachmentId = $this->chat->createAttachment(
            $threadId,
            (int) $user['ID'],
            mb_substr($originalName, 0, 255),
            $stored['relative'],
            mb_substr($mime, 0, 100),
            $size
        );

        return [
            'id' => $attachmentId,
            'name' => mb_substr($originalName, 0, 255),
            'mime' => mb_substr($mime, 0, 100),
            'size' => $size,
        ];
    }

    public function downloadAttachment(array $user, array $capabilities, int $attachmentId): array
    {
        $attachment = $this->chat->findAttachment($attachmentId);

        if ($attachment === null) {
            throw new HttpException(404, 'not_found', 'Файл не найден');
        }

        $thread = $this->requireThread((int) $attachment['thread_id']);
        $this->assertCanView($user, $capabilities, $thread);

        return [
            'absolute' => Storage::absolute((string) $attachment['storage_path']),
            'name' => (string) $attachment['file_name'],
            'mime' => (string) ($attachment['mime'] ?? 'application/octet-stream'),
        ];
    }

    public function post(
        array $user,
        array $capabilities,
        int $threadId,
        string $body,
        array $attachmentIds = [],
        ?int $requestId = null
    ): array {
        $thread = $this->requireThread($threadId);
        $this->assertCanView($user, $capabilities, $thread);

        if (!$this->canPostForThread($user, $capabilities, $thread)) {
            throw new HttpException(403, 'forbidden', 'Отвечать может только менеджер клиента или замещающий');
        }

        $request = null;

        if ($requestId !== null && $requestId > 0) {
            $request = $this->requests->findById($requestId);

            if ($request === null) {
                throw new HttpException(404, 'not_found', 'Заявка не найдена');
            }

            if ($thread['client_id'] === null || (int) $request['client_id'] !== (int) $thread['client_id']) {
                throw new HttpException(422, 'validation_error', 'Заявка не относится к этому клиенту');
            }
        }

        RateLimiter::hit('chat.send', 'user:' . (int) $user['ID'], 240, 3600);

        $body = trim($body);

        if ($body === '' && $attachmentIds === []) {
            throw new HttpException(422, 'validation_error', 'Введите текст сообщения');
        }

        if (mb_strlen($body) > self::MAX_BODY) {
            throw new HttpException(422, 'validation_error', 'Сообщение слишком длинное (до ' . self::MAX_BODY . ' символов)');
        }

        $messageId = $this->chat->addMessage(
            $threadId,
            (int) $user['ID'],
            $body,
            $request !== null ? (int) $request['ID'] : null
        );

        if ($attachmentIds !== []) {
            $this->chat->attachToMessage($messageId, $attachmentIds, $threadId, (int) $user['ID']);
        }

        if ($this->isClient($user)) {
            $recipientId = $thread['manager_id'] !== null ? (int) $thread['manager_id'] : null;
        } elseif ($thread['client_id'] !== null) {
            $recipientId = (int) $thread['client_id'];
        } else {
            $recipientId = (int) $user['ID'] === (int) ($thread['manager_id'] ?? 0)
                ? ($thread['peer_id'] !== null ? (int) $thread['peer_id'] : null)
                : (int) ($thread['manager_id'] ?? 0);
        }

        if ($recipientId !== null && $recipientId !== (int) $user['ID']) {
            $title = $request !== null
                ? 'Новое сообщение по заявке ' . (string) $request['number']
                : 'Новое сообщение от ' . (string) ($user['FULL_NAME'] ?? '');

            $this->notifications->notify(
                $recipientId,
                'chat.message',
                $title,
                $body !== '' ? mb_substr($body, 0, 200) : 'Прикреплён файл',
                $request !== null ? (int) $request['ID'] : null
            );
        }

        return [
            'id' => $messageId,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    public function markRead(array $user, array $capabilities, int $threadId, ?int $upTo = null): array
    {
        $thread = $this->requireThread($threadId);
        $this->assertCanView($user, $capabilities, $thread);

        $lastId = $this->chat->maxMessageId($threadId);

        if ($upTo !== null && $upTo > 0) {
            $lastId = min($lastId, $upTo);
        }

        $this->chat->markRead($threadId, (int) $user['ID'], $lastId);

        return ['read_up_to' => $lastId];
    }

    public function unread(array $user, array $capabilities): array
    {
        $threads = $this->threads($user, $capabilities);

        return ['unread' => $threads['unread']];
    }

    public function quickReplies(array $user): array
    {
        if ($this->isClient($user)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав');
        }

        return ['items' => array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'body' => (string) $row['body'],
        ], $this->chat->quickReplies())];
    }

    private function present(array $rows, array $user, array $capabilities): array
    {
        $userId = (int) $user['ID'];
        $threadIds = array_map(static fn (array $row): int => (int) $row['ID'], $rows);
        $lastMessages = $this->chat->lastMessages($threadIds);
        $unreadCounts = $this->chat->unreadCounts($userId, $threadIds);
        $isStaff = !$this->isClient($user);
        $managerIds = $isStaff ? $this->managerIds($user) : [];
        $unreadTotal = 0;

        $items = array_map(function (array $row) use (
            $lastMessages,
            $unreadCounts,
            &$unreadTotal,
            $userId,
            $isStaff,
            $managerIds
        ): array {
            $threadId = (int) $row['ID'];
            $last = $lastMessages[$threadId] ?? null;
            $unread = $unreadCounts[$threadId] ?? 0;
            $unreadTotal += $unread;

            $clientId = $row['client_id'] !== null ? (int) $row['client_id'] : null;
            $managerId = $row['manager_id'] !== null ? (int) $row['manager_id'] : null;
            $peerId = $row['peer_id'] !== null ? (int) $row['peer_id'] : null;

            if ($clientId !== null) {
                $peer = $userId === $clientId
                    ? ($managerId !== null
                        ? ['id' => $managerId, 'name' => (string) ($row['manager_name'] ?? '')]
                        : null)
                    : ['id' => $clientId, 'name' => (string) ($row['client_name'] ?? '')];
            } else {
                $peer = $userId === $managerId
                    ? ($peerId !== null ? ['id' => $peerId, 'name' => (string) ($row['peer_name'] ?? '')] : null)
                    : ($managerId !== null ? ['id' => $managerId, 'name' => (string) ($row['manager_name'] ?? '')] : null);
            }

            if (!$isStaff) {
                $canPost = $clientId !== null && $clientId === $userId;
            } elseif ($clientId === null) {
                $canPost = ($managerId !== null && in_array($managerId, $managerIds, true))
                    || ($peerId !== null && in_array($peerId, $managerIds, true));
            } else {
                // В клиентском чате может отвечать любой сотрудник.
                $canPost = true;
            }

            return [
                'id' => $threadId,
                'kind' => $clientId !== null ? 'client' : 'staff',
                'can_post' => $canPost,
                'client' => [
                    'id' => $clientId ?? 0,
                    'name' => (string) ($row['client_name'] ?? ''),
                ],
                'manager' => $managerId !== null
                    ? ['id' => $managerId, 'name' => (string) ($row['manager_name'] ?? '')]
                    : null,
                'peer' => $peer,
                'last_message' => $last !== null
                    ? [
                        'body' => mb_substr((string) $last['body'], 0, 120),
                        'created_at' => (string) $last['created_at'],
                        'user' => ['id' => (int) $last['user_id'], 'name' => (string) $last['user_name']],
                    ]
                    : null,
                'last_message_at' => $row['last_message_at'] !== null
                    ? (string) $row['last_message_at']
                    : (string) $row['created_at'],
                'unread' => $unread,
            ];
        }, $rows);

        return ['items' => $items, 'unread' => $unreadTotal];
    }

    private function ensureThread(int $clientId): array
    {
        $thread = $this->chat->findByClient($clientId);
        $managerId = $this->clientManagers->effectiveManagerId($clientId);

        if ($thread === null) {
            $this->chat->create($clientId, $managerId);

            $thread = $this->chat->findByClient($clientId);
        } elseif ($managerId !== null && (int) ($thread['manager_id'] ?? 0) !== $managerId) {
            $this->chat->syncManager($clientId, $managerId);

            $thread = $this->chat->findByClient($clientId);
        }

        return $thread ?? [];
    }

    private function requireThread(int $threadId): array
    {
        $thread = $this->chat->findById($threadId);

        if ($thread === null) {
            throw new HttpException(404, 'not_found', 'Диалог не найден');
        }

        return $thread;
    }

    private function assertCanView(array $user, array $capabilities, array $thread): void
    {
        if ($this->isClient($user)) {
            if ($thread['client_id'] === null || (int) $thread['client_id'] !== (int) $user['ID']) {
                throw new HttpException(403, 'forbidden', 'Нет доступа к диалогу');
            }

            return;
        }

        if (in_array('clients.view.all', $capabilities, true)) {
            return;
        }

        if ($thread['client_id'] !== null) {
            return;
        }

        $managerIds = $this->managerIds($user);

        if (
            ($thread['manager_id'] !== null && in_array((int) $thread['manager_id'], $managerIds, true))
            || ($thread['peer_id'] !== null && in_array((int) $thread['peer_id'], $managerIds, true))
        ) {
            return;
        }

        throw new HttpException(403, 'forbidden', 'Нет доступа к диалогу');
    }

    public function start(array $user, array $capabilities, int $peerId): array
    {
        if ($this->isClient($user)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав');
        }

        $userId = (int) $user['ID'];

        if ($peerId <= 0 || $peerId === $userId) {
            throw new HttpException(422, 'validation_error', 'Выберите собеседника');
        }

        $peer = $this->users->findById($peerId);

        if ($peer === null || ($peer['ACTIVE'] ?? 'N') !== 'Y') {
            throw new HttpException(422, 'validation_error', 'Собеседник не найден');
        }

        if ((int) $peer['LEVEL'] === 5) {
            $thread = $this->ensureThread($peerId);

            return ['id' => (int) ($thread['ID'] ?? 0)];
        }

        $thread = $this->chat->findStaffThread($userId, $peerId);

        if ($thread === null) {
            $this->chat->create(0, $userId, $peerId);
            $thread = $this->chat->findStaffThread($userId, $peerId);
        }

        return ['id' => (int) ($thread['ID'] ?? 0)];
    }

    private function canPostForThread(array $user, array $capabilities, array $thread): bool
    {
        if ($this->isClient($user)) {
            return $thread['client_id'] !== null && (int) $thread['client_id'] === (int) $user['ID'];
        }

        if ($thread['client_id'] === null) {
            $managerIds = $this->managerIds($user);

            return ($thread['manager_id'] !== null && in_array((int) $thread['manager_id'], $managerIds, true))
                || ($thread['peer_id'] !== null && in_array((int) $thread['peer_id'], $managerIds, true));
        }

        // В клиентском чате может отвечать любой сотрудник: заявки клиента
        // может вести менеджер, отличный от привязанного.
        return true;
    }

    private function managerIds(array $user): array
    {
        return array_values(array_unique(array_merge(
            [(int) $user['ID']],
            $this->substitutions->activeSubstitutedIds((int) $user['ID'])
        )));
    }

    private function isClient(array $user): bool
    {
        return (int) $user['LEVEL'] === 5;
    }
}
