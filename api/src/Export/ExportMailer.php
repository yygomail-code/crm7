<?php

declare(strict_types=1);

namespace App\Export;

use App\Http\HttpException;
use App\Mail\MailService;
use App\Repositories\SettingsRepository;
use Throwable;

final class ExportMailer
{
    /**
     * @param array{file_name: string, content: string, mime: string} $file
     * @return array{sent: bool, email: string}
     */
    public static function send(array $user, array $file): array
    {
        if (!(new SettingsRepository())->emailExportEnabled()) {
            throw new HttpException(
                403,
                'email_export_disabled',
                'Отправка отчётов на e-mail отключена администратором'
            );
        }

        $to = trim((string) ($user['EMAIL'] ?? ''));

        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new HttpException(
                422,
                'no_email',
                'В профиле не указан корректный e-mail — добавьте его в разделе «Профиль»'
            );
        }

        $title = (string) pathinfo($file['file_name'], PATHINFO_FILENAME);

        try {
            (new MailService())->sendAttachment(
                $to,
                'CRM7: ' . $title,
                "Во вложении — «{$title}».\n\nФайл: {$file['file_name']}\n\nЭто автоматическое сообщение CRM7.",
                $file['file_name'],
                $file['content'],
                $file['mime']
            );
        } catch (Throwable $exception) {
            throw new HttpException(502, 'mail_failed', 'Не удалось отправить письмо: ' . $exception->getMessage());
        }

        return ['sent' => true, 'email' => $to];
    }
}
