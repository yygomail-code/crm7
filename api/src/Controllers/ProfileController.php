<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\FileResponse;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\UserHistoryRepository;
use App\Repositories\UserRepository;
use App\Support\Storage;

final class ProfileController extends ApiController
{
    public const MAX_AVATAR_SIZE = 5242880;

    private const AVATAR_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly UserHistoryRepository $history = new UserHistoryRepository()
    ) {
        parent::__construct();
    }

    public function update(Request $request): Response
    {
        [$user] = $this->context($request);

        $name = trim((string) $request->input('name', ''));

        if (mb_strlen($name) < 3) {
            throw new HttpException(422, 'validation_error', 'Укажите имя (минимум 3 символа)');
        }

        $this->users->updateName((int) $user['ID'], mb_substr($name, 0, 255));
        $this->history->add((int) $user['ID'], 'profile_updated', (int) $user['ID'], 'Профиль обновлён пользователем');

        return Response::ok(['id' => (int) $user['ID'], 'name' => mb_substr($name, 0, 255)]);
    }

    public function uploadAvatar(Request $request): Response
    {
        [$user] = $this->context($request);

        $file = $_FILES['file'] ?? [];
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

        if ($size > self::MAX_AVATAR_SIZE) {
            throw new HttpException(422, 'too_large', 'Фото больше 5 МБ');
        }

        $originalName = (string) ($file['name'] ?? 'avatar');
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, self::AVATAR_EXTENSIONS, true)) {
            throw new HttpException(422, 'bad_type', 'Допустимые форматы фото: jpg, png, webp');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) ($finfo->file((string) ($file['tmp_name'] ?? '')) ?: '');

        if (!str_starts_with($mime, 'image/')) {
            throw new HttpException(422, 'bad_type', 'Файл не является изображением');
        }

        $stored = Storage::save($file);
        $previous = (string) ($user['AVATAR_PATH'] ?? '');

        $this->users->updateAvatar((int) $user['ID'], $stored['relative']);

        if ($previous !== '' && $previous !== $stored['relative']) {
            try {
                Storage::delete($previous);
            } catch (\Throwable) {
                // старый файл можно удалить позже
            }
        }

        $this->history->add((int) $user['ID'], 'avatar_updated', (int) $user['ID'], 'Обновлено фото профиля');

        return Response::ok(['has_avatar' => true]);
    }

    public function avatar(Request $request, array $params): FileResponse
    {
        $this->context($request);

        $targetId = (int) ($params['id'] ?? 0);
        $target = $this->users->findById($targetId);

        if ($target === null || ($target['AVATAR_PATH'] ?? null) === null) {
            throw new HttpException(404, 'not_found', 'Фото не найдено');
        }

        return new FileResponse(
            Storage::absolute((string) $target['AVATAR_PATH']),
            'avatar-' . $targetId . '.' . pathinfo((string) $target['AVATAR_PATH'], PATHINFO_EXTENSION),
            (string) ($this->detectMime((string) $target['AVATAR_PATH']))
        );
    }

    public function show(Request $request, array $params): Response
    {
        [$viewer] = $this->context($request);

        $target = $this->users->findById((int) ($params['id'] ?? 0));

        if ($target === null || ($target['ACTIVE'] ?? 'N') !== 'Y') {
            throw new HttpException(404, 'not_found', 'Пользователь не найден');
        }

        $targetLevel = (int) $target['LEVEL'];
        $viewerLevel = (int) $viewer['LEVEL'];

        if ($targetLevel === 5 && $viewerLevel < 10) {
            throw new HttpException(403, 'forbidden', 'Нет доступа к профилю');
        }

        return Response::ok([
            'id' => (int) $target['ID'],
            'name' => (string) ($target['FULL_NAME'] ?? ''),
            'level' => $targetLevel,
            'is_staff' => $targetLevel >= 10,
            'position' => (string) ($target['DOLGNOST'] ?? ''),
            'company' => (string) ($target['COMPANY'] ?? ''),
            'email' => (string) ($target['EMAIL'] ?? ''),
            'phone' => (string) ($target['PHONE'] ?? ''),
            'has_avatar' => ($target['AVATAR_PATH'] ?? null) !== null,
        ]);
    }

    private function detectMime(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $absolute = Storage::absolute($path);

        return is_file($absolute) ? (string) ($finfo->file($absolute) ?: 'application/octet-stream') : 'application/octet-stream';
    }
}
