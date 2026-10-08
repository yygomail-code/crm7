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
use App\Support\Validator;

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
        $userId = (int) $user['ID'];

        $name = trim((string) $request->input('name', ''));

        if (mb_strlen($name) < 3) {
            throw new HttpException(422, 'validation_error', 'Укажите имя (минимум 3 символа)');
        }

        if ((int) $user['LEVEL'] < 10) {
            $oldName = (string) ($user['FULL_NAME'] ?? '');
            $this->users->updateName($userId, mb_substr($name, 0, 255));
            $this->history->add(
                $userId,
                'profile_updated',
                $userId,
                $oldName !== $name
                    ? 'Имя: «' . ($oldName !== '' ? $oldName : '—') . '» → «' . $name . '»'
                    : 'Изменений нет'
            );

            return Response::ok([
                'id' => $userId,
                'name' => mb_substr($name, 0, 255),
                'position' => (string) ($user['DOLGNOST'] ?? ''),
                'phone' => (string) ($user['PHONE'] ?? ''),
                'email' => (string) ($user['EMAIL'] ?? ''),
            ]);
        }

        $position = trim((string) $request->input('position', ''));
        $phone = trim((string) $request->input('phone', ''));
        $email = mb_strtolower(trim((string) $request->input('email', '')));

        if ($email !== '' && !Validator::email($email)) {
            throw new HttpException(422, 'validation_error', 'Некорректный e-mail');
        }

        if ($phone !== '' && mb_strlen($phone) < 5) {
            throw new HttpException(422, 'validation_error', 'Некорректный телефон');
        }

        if ($email !== '') {
            $existing = $this->users->findAnyByEmail($email);

            if ($existing !== null && (int) $existing['ID'] !== $userId) {
                throw new HttpException(422, 'email_taken', 'Пользователь с таким e-mail уже зарегистрирован');
            }
        }

        if ($phone !== '') {
            $existing = $this->users->findAnyByPhone($phone);

            if ($existing !== null && (int) $existing['ID'] !== $userId) {
                throw new HttpException(422, 'phone_taken', 'Пользователь с таким телефоном уже зарегистрирован');
            }
        }

        $before = [
            'Имя' => (string) ($user['FULL_NAME'] ?? ''),
            'Должность' => (string) ($user['DOLGNOST'] ?? ''),
            'Телефон' => (string) ($user['PHONE'] ?? ''),
            'E-mail' => (string) ($user['EMAIL'] ?? ''),
        ];
        $after = [
            'Имя' => mb_substr($name, 0, 255),
            'Должность' => mb_substr($position, 0, 255),
            'Телефон' => mb_substr($phone, 0, 255),
            'E-mail' => mb_substr($email, 0, 255),
        ];

        $this->users->updateProfileContacts($userId, [
            'name' => $after['Имя'],
            'position' => $after['Должность'],
            'phone' => $after['Телефон'],
            'email' => $after['E-mail'],
        ]);

        $changes = [];

        foreach ($before as $label => $oldValue) {
            $newValue = $after[$label];

            if ($oldValue !== $newValue) {
                $changes[] = $label . ': «' . ($oldValue !== '' ? $oldValue : '—') . '» → «' . ($newValue !== '' ? $newValue : '—') . '»';
            }
        }

        $this->history->add(
            $userId,
            'profile_updated',
            $userId,
            $changes !== [] ? implode('; ', $changes) : 'Изменений нет'
        );
        $this->audit($request, $user, 'profile.update', 'user', $userId, []);

        return Response::ok([
            'id' => $userId,
            'name' => mb_substr($name, 0, 255),
            'position' => mb_substr($position, 0, 255),
            'phone' => mb_substr($phone, 0, 255),
            'email' => mb_substr($email, 0, 255),
        ]);
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

        $this->history->add(
            (int) $user['ID'],
            'avatar_updated',
            (int) $user['ID'],
            $previous !== '' ? 'Фото профиля: заменено' : 'Фото профиля: добавлено'
        );

        return Response::ok(['has_avatar' => true]);
    }

    public function deleteAvatar(Request $request): Response
    {
        [$user] = $this->context($request);

        $previous = (string) ($user['AVATAR_PATH'] ?? '');

        if ($previous === '') {
            return Response::ok(['has_avatar' => false]);
        }

        $this->users->updateAvatar((int) $user['ID'], null);

        try {
            Storage::delete($previous);
        } catch (\Throwable) {
            // файл можно удалить позже
        }

        $this->history->add((int) $user['ID'], 'avatar_removed', (int) $user['ID'], 'Фото профиля: удалено');

        return Response::ok(['has_avatar' => false]);
    }

    public function avatar(Request $request, array $params): FileResponse
    {
        [$viewer] = $this->context($request);

        $targetId = (int) ($params['id'] ?? 0);
        $target = $this->users->findById($targetId);

        if ($target === null || ($target['AVATAR_PATH'] ?? null) === null) {
            throw new HttpException(404, 'not_found', 'Фото не найдено');
        }

        if ((int) $target['LEVEL'] === 5 && (int) $viewer['LEVEL'] < 10 && (int) $viewer['ID'] !== $targetId) {
            throw new HttpException(403, 'forbidden', 'Нет доступа к фото профиля');
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
            'login' => (string) ($target['LOGIN'] ?? ''),
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
