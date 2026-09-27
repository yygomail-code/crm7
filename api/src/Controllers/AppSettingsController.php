<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Repositories\SettingsRepository;

final class AppSettingsController extends ApiController
{
    public function __construct(
        private readonly SettingsRepository $settings = new SettingsRepository()
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
        ]);
    }
}
