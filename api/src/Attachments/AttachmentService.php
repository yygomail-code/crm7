<?php

declare(strict_types=1);

namespace App\Attachments;

use App\Http\HttpException;
use App\Repositories\AttachmentRepository;
use App\Repositories\RequestRepository;
use App\Requests\RequestService;
use App\Support\Storage;

final class AttachmentService
{
    public const MAX_SIZE = 10485760;

    public const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'webp', 'pdf', 'xls', 'xlsx', 'doc', 'docx', 'txt', 'zip', 'rar',
    ];

    public function __construct(
        private readonly AttachmentRepository $attachments = new AttachmentRepository(),
        private readonly RequestRepository $requests = new RequestRepository(),
        private readonly RequestService $requestService = new RequestService()
    ) {
    }

    public function list(array $user, array $capabilities, int $requestId): array
    {
        $request = $this->requireRequest($user, $capabilities, $requestId);

        return [
            'items' => array_map([$this, 'mapAttachment'], $this->attachments->listByRequest($requestId)),
            'can_upload' => true,
            'max_size' => self::MAX_SIZE,
            'request_id' => (int) $request['ID'],
        ];
    }

    public function upload(array $user, array $capabilities, int $requestId, array $file): array
    {
        $this->requireRequest($user, $capabilities, $requestId);

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

        if ($size > self::MAX_SIZE) {
            throw new HttpException(422, 'too_large', 'Файл больше 10 МБ');
        }

        $originalName = (string) ($file['name'] ?? 'file');
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new HttpException(422, 'bad_type', 'Недопустимый тип файла');
        }

        $stored = Storage::save($file);

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) ($finfo->file($stored['absolute']) ?: 'application/octet-stream');

        $this->attachments->add(
            $requestId,
            (int) $user['ID'],
            mb_substr($originalName, 0, 255),
            $stored['relative'],
            mb_substr($mime, 0, 100),
            $size
        );

        return $this->list($user, $capabilities, $requestId);
    }

    public function download(array $user, array $capabilities, int $attachmentId): array
    {
        $attachment = $this->attachments->findById($attachmentId);

        if ($attachment === null) {
            throw new HttpException(404, 'not_found', 'Файл не найден');
        }

        $this->requireRequest($user, $capabilities, (int) $attachment['request_id']);

        return [
            'absolute' => Storage::absolute((string) $attachment['storage_path']),
            'name' => (string) $attachment['file_name'],
            'mime' => (string) ($attachment['mime'] ?? 'application/octet-stream'),
        ];
    }

    public function remove(array $user, array $capabilities, int $attachmentId): array
    {
        $attachment = $this->attachments->findById($attachmentId);

        if ($attachment === null) {
            throw new HttpException(404, 'not_found', 'Файл не найден');
        }

        $request = $this->requireRequest($user, $capabilities, (int) $attachment['request_id']);

        $isAuthor = (int) $attachment['user_id'] === (int) $user['ID'];
        $canManage = $this->requestService->canManageRequest($user, $capabilities, $request);

        if (!$isAuthor && !$canManage) {
            throw new HttpException(403, 'forbidden', 'Удалить файл может автор или менеджер заявки');
        }

        Storage::delete((string) $attachment['storage_path']);
        $this->attachments->delete($attachmentId);

        return ['deleted' => true];
    }

    private function requireRequest(array $user, array $capabilities, int $requestId): array
    {
        $request = $this->requests->detail($requestId);

        if ($request === null) {
            throw new HttpException(404, 'not_found', 'Заявка не найдена');
        }

        $this->requestService->assertAccess($user, $capabilities, $request);

        return $request;
    }

    private function mapAttachment(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['file_name'],
            'mime' => (string) ($row['mime'] ?? ''),
            'size' => (int) $row['size'],
            'user' => [
                'id' => (int) $row['user_id'],
                'name' => (string) $row['user_name'],
            ],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
