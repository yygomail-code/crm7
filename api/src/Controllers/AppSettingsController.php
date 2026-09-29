<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Groups\ItemGroupService;
use App\Http\Request;
use App\Http\Response;
use App\Prices\PriceService;
use App\Repositories\SettingsRepository;

final class AppSettingsController extends ApiController
{
    public function __construct(
        private readonly SettingsRepository $settings = new SettingsRepository(),
        private readonly PriceService $prices = new PriceService(),
        private readonly ItemGroupService $groups = new ItemGroupService()
    ) {
        parent::__construct();
    }

    public function index(Request $request): Response
    {
        $this->context($request);

        return Response::ok([
            'sales_enabled' => $this->settings->salesEnabled(),
            'email_export_enabled' => $this->settings->emailExportEnabled(),
            'stock_reserve_enabled' => $this->settings->stockReserveEnabled(),
            'stock_allow_zero' => $this->settings->allowZeroStock(),
            'prices_enabled' => $this->settings->pricesEnabled(),
            'groups_enabled' => $this->settings->groupsEnabled(),
        ]);
    }

    public function priceTypes(Request $request): Response
    {
        $this->context($request);

        $items = array_map(static fn (array $type): array => [
            'id' => (int) $type['id'],
            'code' => (string) $type['code'],
            'title' => (string) $type['title'],
            'sort' => (int) $type['sort'],
        ], $this->prices->types());

        return Response::ok(['items' => $items]);
    }

    public function itemGroups(Request $request): Response
    {
        $this->context($request);

        $items = array_map(static fn (array $group): array => [
            'id' => (int) $group['id'],
            'title' => (string) $group['title'],
            'sort' => (int) $group['sort'],
        ], $this->groups->list());

        return Response::ok(['items' => $items]);
    }
}
