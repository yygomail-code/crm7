<?php

declare(strict_types=1);

namespace App\Mail;

use App\Http\HttpException;
use App\Repositories\EmailQueueRepository;
use App\Repositories\EmailTemplateRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use App\Support\Crypto;
use App\Support\SmtpMailer;
use Throwable;

final class MailService
{
    public const PRESETS = [
        ['code' => 'yandex', 'title' => 'Яндекс.Почта', 'host' => 'smtp.yandex.ru', 'port' => 465, 'encryption' => 'ssl'],
        ['code' => 'mailru', 'title' => 'Mail.ru', 'host' => 'smtp.mail.ru', 'port' => 465, 'encryption' => 'ssl'],
        ['code' => 'custom', 'title' => 'Другой SMTP', 'host' => '', 'port' => 587, 'encryption' => 'tls'],
    ];

    private const KEYS = [
        'enabled' => 'mail.enabled',
        'host' => 'mail.host',
        'port' => 'mail.port',
        'encryption' => 'mail.encryption',
        'username' => 'mail.username',
        'password' => 'mail.password',
        'from' => 'mail.from',
        'from_name' => 'mail.from_name',
    ];

    public function __construct(
        private readonly SettingsRepository $settings = new SettingsRepository(),
        private readonly EmailTemplateRepository $templates = new EmailTemplateRepository(),
        private readonly EmailQueueRepository $queue = new EmailQueueRepository(),
        private readonly UserRepository $users = new UserRepository()
    ) {
    }

    public function config(): array
    {
        $storedPassword = (string) ($this->settings->get(self::KEYS['password']) ?? '');
        $password = $storedPassword !== '' ? (string) (Crypto::decrypt($storedPassword) ?? '') : '';

        return [
            'enabled' => $this->settings->get(self::KEYS['enabled']) === '1',
            'host' => (string) ($this->settings->get(self::KEYS['host']) ?? ''),
            'port' => (int) ($this->settings->get(self::KEYS['port']) ?? 465),
            'encryption' => (string) ($this->settings->get(self::KEYS['encryption']) ?? 'ssl'),
            'username' => (string) ($this->settings->get(self::KEYS['username']) ?? ''),
            'password' => $password,
            'from' => (string) ($this->settings->get(self::KEYS['from']) ?: $this->settings->get(self::KEYS['username']) ?? ''),
            'from_name' => (string) ($this->settings->get(self::KEYS['from_name']) ?: 'CRM'),
        ];
    }

    public function publicSettings(): array
    {
        $config = $this->config();

        return [
            'enabled' => $config['enabled'],
            'host' => $config['host'],
            'port' => $config['port'],
            'encryption' => $config['encryption'],
            'username' => $config['username'],
            'from' => $config['from'],
            'from_name' => $config['from_name'],
            'has_password' => $config['password'] !== '',
            'presets' => self::PRESETS,
        ];
    }

    public function saveSettings(array $input): array
    {
        $host = trim((string) ($input['host'] ?? ''));
        $port = (int) ($input['port'] ?? 0);
        $encryption = strtolower(trim((string) ($input['encryption'] ?? 'ssl')));
        $from = trim((string) ($input['from'] ?? ''));

        if ($host === '') {
            throw new HttpException(422, 'validation_error', 'Укажите SMTP-хост');
        }

        if ($port < 1 || $port > 65535) {
            throw new HttpException(422, 'validation_error', 'Некорректный порт');
        }

        if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
            throw new HttpException(422, 'validation_error', 'Некорректный тип шифрования');
        }

        if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            throw new HttpException(422, 'validation_error', 'Некорректный адрес отправителя');
        }

        $pairs = [
            self::KEYS['enabled'] => !empty($input['enabled']) ? '1' : '0',
            self::KEYS['host'] => $host,
            self::KEYS['port'] => (string) $port,
            self::KEYS['encryption'] => $encryption,
            self::KEYS['username'] => trim((string) ($input['username'] ?? '')),
            self::KEYS['from'] => $from,
            self::KEYS['from_name'] => trim((string) ($input['from_name'] ?? '')),
        ];

        $password = (string) ($input['password'] ?? '');

        if ($password !== '') {
            $pairs[self::KEYS['password']] = Crypto::encrypt($password);
        }

        $this->settings->many($pairs);

        return $this->publicSettings();
    }

    public function testConnection(): array
    {
        try {
            (new SmtpMailer($this->config()))->testConnection();
        } catch (Throwable $exception) {
            throw new HttpException(422, 'smtp_error', $exception->getMessage());
        }

        return ['ok' => true];
    }

    public function sendTest(string $to): array
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new HttpException(422, 'validation_error', 'Укажите корректный e-mail');
        }

        $config = $this->config();

        if (!$config['enabled']) {
            throw new HttpException(422, 'mail_disabled', 'Почта выключена — включите отправку');
        }

        try {
            (new SmtpMailer($config))->send(
                $config['from'],
                $config['from_name'],
                $to,
                'Тестовое письмо CRM',
                "Это тестовое письмо.\nЕсли вы его получили, SMTP настроен корректно."
            );
        } catch (Throwable $exception) {
            throw new HttpException(422, 'smtp_error', $exception->getMessage());
        }

        return ['sent' => true];
    }

    public function enqueue(string $to, string $subject, string $body): void
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $this->queue->add($to, $subject, $body);
    }

    public function notifyEmail(?int $userId, ?string $templateCode, array $vars): void
    {
        if ($userId === null || $templateCode === null) {
            return;
        }

        if (!$this->config()['enabled']) {
            return;
        }

        $user = $this->users->findById($userId);
        $email = (string) ($user['EMAIL'] ?? '');

        if ($email === '') {
            return;
        }

        $rendered = $this->render($templateCode, $vars);
        $this->enqueue($email, $rendered['subject'], $rendered['body']);
    }

    public function render(string $code, array $vars): array
    {
        $template = $this->templates->findByCode($code);

        $subject = (string) ($template['subject'] ?? 'Уведомление CRM');
        $body = (string) ($template['body'] ?? "{title}\n\n{body}\n\n{link}");

        foreach ($vars as $key => $value) {
            $subject = str_replace('{' . $key . '}', (string) $value, $subject);
            $body = str_replace('{' . $key . '}', (string) $value, $body);
        }

        return ['subject' => $subject, 'body' => $body];
    }

    public function templates(): array
    {
        return array_map(static fn (array $row): array => [
            'code' => (string) $row['code'],
            'subject' => (string) $row['subject'],
            'body' => (string) $row['body'],
            'updated_at' => (string) $row['updated_at'],
        ], $this->templates->all());
    }

    public function saveTemplate(string $code, string $subject, string $body): array
    {
        $subject = trim($subject);
        $body = trim($body);

        if ($code === '') {
            throw new HttpException(422, 'validation_error', 'Не указан код шаблона');
        }

        if ($subject === '') {
            throw new HttpException(422, 'validation_error', 'Укажите тему письма');
        }

        if ($body === '') {
            throw new HttpException(422, 'validation_error', 'Укажите текст письма');
        }

        $this->templates->save($code, mb_substr($subject, 0, 255), mb_substr($body, 0, 10000));

        return $this->templates();
    }

    public function processQueue(int $limit = 20): array
    {
        $config = $this->config();

        if (!$config['enabled']) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => true, 'reason' => 'Почта выключена'];
        }

        $sent = 0;
        $failed = 0;
        $errors = [];

        foreach ($this->queue->pending($limit) as $item) {
            try {
                (new SmtpMailer($config))->send(
                    $config['from'],
                    $config['from_name'],
                    (string) $item['to_email'],
                    (string) $item['subject'],
                    (string) $item['body']
                );
                $this->queue->markSent((int) $item['id']);
                $sent++;
            } catch (Throwable $exception) {
                $this->queue->markFailed((int) $item['id'], $exception->getMessage());
                $failed++;
                $errors[] = ['id' => (int) $item['id'], 'error' => $exception->getMessage()];
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'errors' => $errors];
    }

    public function sendAttachment(
        string $to,
        string $subject,
        string $body,
        string $fileName,
        string $content,
        string $mime
    ): void {
        $config = $this->config();

        if (!$config['enabled']) {
            throw new \RuntimeException('Почта отключена в настройках');
        }

        (new SmtpMailer($config))->send(
            (string) $config['from'],
            (string) $config['from_name'],
            $to,
            $subject,
            $body,
            ['file_name' => $fileName, 'content' => $content, 'mime' => $mime]
        );
    }

    public function queueRecent(int $limit = 30): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'to' => (string) $row['to_email'],
            'subject' => (string) $row['subject'],
            'status' => (string) $row['status'],
            'attempts' => (int) $row['attempts'],
            'last_error' => (string) ($row['last_error'] ?? ''),
            'created_at' => (string) $row['created_at'],
            'sent_at' => $row['sent_at'],
        ], $this->queue->recent($limit));
    }
}
