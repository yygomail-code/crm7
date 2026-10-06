<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DatabaseSettings;
use App\Groups\ItemGroupService;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Mail\MailService;
use App\Prices\PriceService;
use App\Repositories\SettingsRepository;

final class AdminController extends ApiController
{
    public function __construct(
        private readonly MailService $mail = new MailService(),
        private readonly SettingsRepository $settings = new SettingsRepository(),
        private readonly PriceService $prices = new PriceService(),
        private readonly ItemGroupService $groups = new ItemGroupService()
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
        $sales = (bool) $request->input('sales_enabled', true);
        $emailExport = (bool) $request->input('email_export_enabled', true);
        $stockReserve = (bool) $request->input('stock_reserve_enabled', false);
        $stockAllowZero = (bool) $request->input('stock_allow_zero', false);
        $pricesEnabled = (bool) $request->input('prices_enabled', false);
        $groupsEnabled = (bool) $request->input('groups_enabled', false);
        $requestsEnabled = (bool) $request->input('requests_enabled', true);
        $cartEnabled = (bool) $request->input('cart_enabled', true);
        $substitutionsEnabled = (bool) $request->input('substitutions_enabled', true);
        $managerAssignEnabled = (bool) $request->input('manager_assign_enabled', true);
        $appTitle = mb_substr(trim((string) $request->input('app_title', '')), 0, 60);
        $clientLabel = mb_substr(trim((string) $request->input('client_label', '')), 0, 40);

        $this->settings->many([
            'sla.reaction_hours' => (string) $reaction,
            'sla.resolution_hours' => (string) $resolution,
            'mail.spf_checklist' => $spf ? '1' : '0',
            'sales.enabled' => $sales ? '1' : '0',
            'mail.export_enabled' => $emailExport ? '1' : '0',
            'stock.reserve_enabled' => $stockReserve ? '1' : '0',
            'stock.allow_zero' => $stockAllowZero ? '1' : '0',
            'prices.enabled' => $pricesEnabled ? '1' : '0',
            'groups.enabled' => $groupsEnabled ? '1' : '0',
            'module.requests' => $requestsEnabled ? '1' : '0',
            'module.cart' => $cartEnabled ? '1' : '0',
            'module.substitutions' => $substitutionsEnabled ? '1' : '0',
            'module.manager_assign' => $managerAssignEnabled ? '1' : '0',
            'branding.title' => $appTitle,
            'branding.client_label' => $clientLabel,
        ]);

        return Response::ok($this->systemSettingsPayload());
    }

    private function systemSettingsPayload(): array
    {
        return [
            'sla_reaction_hours' => (int) ($this->settings->get('sla.reaction_hours') ?? 2),
            'sla_resolution_hours' => (int) ($this->settings->get('sla.resolution_hours') ?? 24),
            'spf_checklist' => $this->settings->get('mail.spf_checklist') === '1',
            'sales_enabled' => $this->settings->salesEnabled(),
            'email_export_enabled' => $this->settings->emailExportEnabled(),
            'stock_reserve_enabled' => $this->settings->stockReserveEnabled(),
            'stock_allow_zero' => $this->settings->allowZeroStock(),
            'prices_enabled' => $this->settings->pricesEnabled(),
            'groups_enabled' => $this->settings->groupsEnabled(),
            'requests_enabled' => $this->settings->requestsEnabled(),
            'cart_enabled' => $this->settings->cartEnabled(),
            'substitutions_enabled' => $this->settings->substitutionsEnabled(),
            'manager_assign_enabled' => $this->settings->managerAssignEnabled(),
            'app_title' => $this->settings->appTitle(),
            'client_label' => $this->settings->clientLabel(),
            'spf_steps' => [
                'SPF: добавьте в DNS TXT-запись домена с серверами отправки (v=spf1 …)',
                'DKIM: включите подпись в панели почтового провайдера и опубликуйте публичный ключ',
                'DMARC: добавьте TXT-запись _dmarc с политикой (p=none для старта)',
                'Проверьте отправку тестового письма на внешний ящик и заголовки SPF/DKIM',
            ],
        ];
    }

    public function priceTypes(Request $request): Response
    {
        $this->requireSettings($request);

        return Response::ok(['items' => $this->prices->types()]);
    }

    public function createPriceType(Request $request): Response
    {
        $user = $this->requireSettings($request);

        $type = $this->prices->createType((string) $request->input('title', ''));
        $this->audit($request, $user, 'prices.type.create', 'price_type', $type['id'] ?? null, [
            'title' => $type['title'] ?? '',
        ]);

        return Response::ok(['type' => $type], 201);
    }

    public function updatePriceType(Request $request, array $params): Response
    {
        $user = $this->requireSettings($request);

        $id = (int) ($params['id'] ?? 0);
        $type = $this->prices->updateType($id, (string) $request->input('title', ''));
        $this->audit($request, $user, 'prices.type.update', 'price_type', $id, [
            'title' => $type['title'] ?? '',
        ]);

        return Response::ok(['type' => $type]);
    }

    public function deletePriceType(Request $request, array $params): Response
    {
        $user = $this->requireSettings($request);

        $id = (int) ($params['id'] ?? 0);
        $this->prices->deleteType($id);
        $this->audit($request, $user, 'prices.type.delete', 'price_type', $id);

        return Response::ok(['id' => $id]);
    }

    public function itemGroups(Request $request): Response
    {
        $this->requireSettings($request);

        return Response::ok(['items' => $this->groups->list()]);
    }

    public function createItemGroup(Request $request): Response
    {
        $user = $this->requireSettings($request);

        $group = $this->groups->create((string) $request->input('title', ''));
        $this->audit($request, $user, 'groups.create', 'item_group', $group['id'] ?? null, [
            'title' => $group['title'] ?? '',
        ]);

        return Response::ok(['group' => $group], 201);
    }

    public function updateItemGroup(Request $request, array $params): Response
    {
        $user = $this->requireSettings($request);

        $id = (int) ($params['id'] ?? 0);
        $group = $this->groups->update($id, (string) $request->input('title', ''));
        $this->audit($request, $user, 'groups.update', 'item_group', $id, [
            'title' => $group['title'] ?? '',
        ]);

        return Response::ok(['group' => $group]);
    }

    public function deleteItemGroup(Request $request, array $params): Response
    {
        $user = $this->requireSettings($request);

        $id = (int) ($params['id'] ?? 0);
        $this->groups->delete($id);
        $this->audit($request, $user, 'groups.delete', 'item_group', $id);

        return Response::ok(['id' => $id]);
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

    public function databaseSettings(Request $request): Response
    {
        $this->requireSettings($request);

        return Response::ok(DatabaseSettings::publicPayload());
    }

    public function testDatabaseSettings(Request $request): Response
    {
        $this->requireSettings($request);

        return Response::ok(DatabaseSettings::test($request->bodyAll()));
    }

    public function saveDatabaseSettings(Request $request): Response
    {
        $user = $this->requireSettings($request);

        $payload = DatabaseSettings::save($request->bodyAll(), (string) ($user['LOGIN'] ?? ''));

        $this->audit($request, $user, 'settings.database', 'settings', null, [
            'host' => $payload['host'],
            'database' => $payload['database'],
            'user' => $payload['user'],
        ]);

        return Response::ok($payload);
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

    private function requireSettings(Request $request): array
    {
        [$user, $capabilities] = $this->context($request);

        if (!in_array('settings.manage', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав');
        }

        return $user;
    }
}
