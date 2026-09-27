<?php

declare(strict_types=1);

namespace App\Attachments;

use App\Core\RateLimiter;
use App\Http\HttpException;
use App\Repositories\AttachmentRepository;
use App\Repositories\RequestActivityRepository;
use App\Repositories\RequestRepository;
use App\Requests\RequestService;
use App\Support\Storage;

final class AttachmentService
{
    public const MAX_SIZE = 10485760;

    public const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'webp', 'pdf', 'xls', 'xlsx', 'doc', 'docx', 'txt', 'zip', 'rar',
    ];

    public const FORBIDDEN_MIME = [
        'text/html',
        'text/x-php',
        'application/x-php',
        'application/x-httpd-php',
        'application/x-httpd-php-source',
        'text/x-shellscript',
        'application/x-sh',
        'application/x-csh',
        'text/javascript',
        'application/javascript',
        'application/x-javascript',
        'image/svg+xml',
        'application/xml',
        'text/xml',
        'application/x-msdownload',
        'application/x-dosexec',
        'application/x-executable',
        'application/vnd.microsoft.portable-executable',
        'text/x-perl',
        'application/x-perl',
    ];

    public static function assertSafeMime(array $file): void
    {
        $path = (string) ($file['tmp_name'] ?? '');

        if ($path === '' || !is_file($path)) {
            return;
        }

        $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        if ($detected !== false && in_array(strtolower((string) $detected), self::FORBIDDEN_MIME, true)) {
            throw new HttpException(422, 'bad_type', 'Недопустимый тип файла');
        }
    }

    public function __construct(
        private readonly AttachmentRepository $attachments = new AttachmentRepository(),
        private readonly RequestRepository $requests = new RequestRepository(),
        private readonly RequestService $requestService = new RequestService(),
        private readonly RequestActivityRepository $activities = new RequestActivityRepository()
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

        RateLimiter::hit('request.upload', 'user:' . (int) $user['ID'], 60, 3600);

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

        self::assertSafeMime($file);

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

        $this->activities->add(
            $requestId,
            (int) $user['ID'],
            'file_attached',
            'Прикреплён файл',
            mb_substr($originalName, 0, 200) . ' — ' . $this->formatSize($size)
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

        $this->activities->add(
            (int) $attachment['request_id'],
            (int) $user['ID'],
            'file_removed',
            'Удалён файл',
            mb_substr((string) $attachment['file_name'], 0, 255)
        );

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

    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return rtrim(rtrim(number_format($bytes / 1048576, 1, ',', ' '), '0'), ',') . ' МБ';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' КБ';
        }

        return $bytes . ' Б';
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
