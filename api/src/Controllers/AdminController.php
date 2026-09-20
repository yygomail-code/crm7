<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Mail\MailService;
use App\Repositories\SettingsRepository;

final class AdminController extends ApiController
{
    public function __construct(
        private readonly MailService $mail = new MailService(),
        private readonly SettingsRepository $settings = new SettingsRepository()
    ) {
        parent::__construct();
    }

    public function systemSettings(Request $request): Response
    {
        $this->requireSettings($request);

        return Response::ok($this->systemSettingsPayload());
    }

    public function saveSystemSettings(Request $request): Response
    {
        $this->requireSettings($request);

        $reaction = max(1, min(168, (int) $request->input('sla_reaction_hours', 2)));
        $resolution = max(1, min(720, (int) $request->input('sla_resolution_hours', 24)));
        $spf = (bool) $request->input('spf_checklist', false);

        $this->settings->many([
            'sla.reaction_hours' => (string) $reaction,
            'sla.resolution_hours' => (string) $resolution,
            'mail.spf_checklist' => $spf ? '1' : '0',
        ]);

        return Response::ok($this->systemSettingsPayload());
    }

    private function systemSettingsPayload(): array
    {
        return [
            'sla_reaction_hours' => (int) ($this->settings->get('sla.reaction_hours') ?? 2),
            'sla_resolution_hours' => (int) ($this->settings->get('sla.resolution_hours') ?? 24),
            'spf_checklist' => $this->settings->get('mail.spf_checklist') === '1',
            'spf_steps' => [
                'SPF: добавьте в DNS TXT-запись домена с серверами отправки (v=spf1 …)',
                'DKIM: включите подпись в панели почтового провайдера и опубликуйте публичный ключ',
                'DMARC: добавьте TXT-запись _dmarc с политикой (p=none для старта)',
                'Проверьте отправку тестового письма на внешний ящик и заголовки SPF/DKIM',
            ],
        ];
    }

    public function emailSettings(Request $request): Response
    {
        $this->requireSettings($request);

        return Response::ok($this->mail->publicSettings());
    }

    public function saveEmailSettings(Request $request): Response
    {
        $this->requireSettings($request);

        return Response::ok($this->mail->saveSettings($request->bodyAll()));
    }

    public function testEmail(Request $request): Response
    {
        $this->requireSettings($request);

        $to = trim((string) $request->input('to', ''));

        if ($to !== '') {
            return Response::ok($this->mail->sendTest($to));
        }

        return Response::ok($this->mail->testConnection());
    }

    public function templates(Request $request): Response
    {
        $this->requireSettings($request);

        return Response::ok($this->mail->templates());
    }

    public function saveTemplate(Request $request, array $params): Response
    {
        $this->requireSettings($request);

        return Response::ok($this->mail->saveTemplate(
            (string) ($params['code'] ?? ''),
            (string) $request->input('subject', ''),
            (string) $request->input('body', '')
        ));
    }

    public function queue(Request $request): Response
    {
        $this->requireSettings($request);

        return Response::ok($this->mail->queueRecent((int) $request->queryParam('limit', '30')));
    }

    private function requireSettings(Request $request): void
    {
        [, $capabilities] = $this->context($request);

        if (!in_array('settings.manage', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав');
        }
    }
}
