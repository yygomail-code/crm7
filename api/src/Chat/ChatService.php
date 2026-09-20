<?php

declare(strict_types=1);

namespace App\Chat;

use App\Attachments\AttachmentService;
use App\Http\HttpException;
use App\Notifications\NotificationService;
use App\Repositories\ChatRepository;
use App\Repositories\ManagerClientRepository;
use App\Repositories\SubstitutionRepository;
use App\Repositories\UserRepository;
use App\Support\Storage;

final class ChatService
{
    private const MAX_BODY = 4000;

    public function __construct(
        private readonly ChatRepository $chat = new ChatRepository(),
        private readonly UserRepository $users = new UserRepository(),
        private readonly ManagerClientRepository $managerClients = new ManagerClientRepository(),
        private readonly SubstitutionRepository $substitutions = new SubstitutionRepository(),
        private readonly NotificationService $notifications = new NotificationService()
    ) {
    }

    public function threads(array $user, array $capabilities): array
    {
        if ($this->isClient($user)) {
            $thread = $this->ensureThread((int) $user['ID']);

            return $this->present([$thread], (int) $user['ID']);
        }

        if (in_array('clients.view.all', $capabilities, true)) {
            return $this->present($this->chat->listAll(), (int) $user['ID']);
        }

        $managerIds = $this->managerIds($user);

        foreach ($managerIds as $managerId) {
            $this->ensureThreadsForManager($managerId);
        }

        return $this->present($this->chat->listForManager($managerIds), (int) $user['ID']);
    }

    public function messages(array $user, array $capabilities, int $threadId, int $afterId = 0): array
    {
        $thread = $this->requireThread($threadId);
        $this->assertCanView($user, $capabilities, $thread);

        $rows = $this->chat->messages($threadId, $afterId);
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
            'is_mine' => (int) $row['user_id'] === (int) $user['ID'],
            'attachments' => $attachments[(int) $row['id']] ?? [],
        ], $rows);

        return ['items' => $items];
    }

    public function uploadAttachment(array $user, array $capabilities, int $threadId, array $file): array
    {
        $thread = $this->requireThread($threadId);
        $this->assertCanView($user, $capabilities, $thread);

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

    public function post(array $user, array $capabilities, int $threadId, string $body, array $attachmentIds = []): array
    {
        $thread = $this->requireThread($threadId);
        $this->assertCanView($user, $capabilities, $thread);

        $body = trim($body);

        if ($body === '' && $attachmentIds === []) {
            throw new HttpException(422, 'validation_error', 'Введите текст сообщения');
        }

        if (mb_strlen($body) > self::MAX_BODY) {
            throw new HttpException(422, 'validation_error', 'Сообщение слишком длинное (до ' . self::MAX_BODY . ' символов)');
        }

        $messageId = $this->chat->addMessage($threadId, (int) $user['ID'], $body);

        if ($attachmentIds !== []) {
            $this->chat->attachToMessage($messageId, $attachmentIds, $threadId, (int) $user['ID']);
        }

        $recipientId = $this->isClient($user)
            ? ($thread['manager_id'] !== null ? (int) $thread['manager_id'] : null)
            : (int) $thread['client_id'];

        if ($recipientId !== null && $recipientId !== (int) $user['ID']) {
            $this->notifications->notify(
                $recipientId,
                'chat.message',
                'Новое сообщение от ' . (string) ($user['FULL_NAME'] ?? ''),
                $body !== '' ? mb_substr($body, 0, 200) : 'Прикреплён файл',
                null
            );
        }

        return [
            'id' => $messageId,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    public function markRead(array $user, array $capabilities, int $threadId): array
    {
        $thread = $this->requireThread($threadId);
        $this->assertCanView($user, $capabilities, $thread);

        $lastId = $this->chat->maxMessageId($threadId);
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

    private function present(array $rows, int $userId): array
    {
        $threadIds = array_map(static fn (array $row): int => (int) $row['ID'], $rows);
        $lastMessages = $this->chat->lastMessages($threadIds);
        $unreadCounts = $this->chat->unreadCounts($userId, $threadIds);

        $unreadTotal = 0;

        $items = array_map(function (array $row) use ($lastMessages, $unreadCounts, &$unreadTotal): array {
            $threadId = (int) $row['ID'];
            $last = $lastMessages[$threadId] ?? null;
            $unread = $unreadCounts[$threadId] ?? 0;
            $unreadTotal += $unread;

            return [
                'id' => $threadId,
                'client' => [
                    'id' => (int) $row['client_id'],
                    'name' => (string) ($row['client_name'] ?? ''),
                ],
                'manager' => $row['manager_id'] !== null
                    ? ['id' => (int) $row['manager_id'], 'name' => (string) ($row['manager_name'] ?? '')]
                    : null,
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
        $managerId = $this->managerClients->primaryManagerForClient($clientId);

        if ($thread === null) {
            $this->chat->create($clientId, $managerId);

            $thread = $this->chat->findByClient($clientId);
        } elseif ($thread['manager_id'] !== $managerId) {
            $this->chat->syncManager($clientId, $managerId);

            $thread = $this->chat->findByClient($clientId);
        }

        return $thread ?? [];
    }

    private function ensureThreadsForManager(int $managerId): void
    {
        foreach ($this->managerClients->clientIdsForManager($managerId) as $clientId) {
            $this->ensureThread($clientId);
        }
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
            if ((int) $thread['client_id'] !== (int) $user['ID']) {
                throw new HttpException(403, 'forbidden', 'Нет доступа к диалогу');
            }

            return;
        }

        if (in_array('clients.view.all', $capabilities, true)) {
            return;
        }

        $managerIds = $this->managerIds($user);

        if ($thread['manager_id'] !== null && in_array((int) $thread['manager_id'], $managerIds, true)) {
            return;
        }

        foreach ($managerIds as $managerId) {
            if ($this->managerClients->isManagerOf((int) $thread['client_id'], $managerId)) {
                return;
            }
        }

        throw new HttpException(403, 'forbidden', 'Нет доступа к диалогу');
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
