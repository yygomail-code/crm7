<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Реестр модулей — единый источник для админки, серверных гейтов и меню.
 *
 * Модуль описывает:
 *  - setting      — ключ в settings (module.<code>), вкл/выкл;
 *  - capabilities — все права, которыми «владеет» модуль;
 *  - access       — права, наличие любого из которых означает «роль имеет доступ»;
 *  - grant        — права, которые выдаются роли при включении доступа;
 *  - guards       — регулярные выражения путей API, блокируемые при выключенном модуле.
 */
final class Modules
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            [
                'code' => 'requests',
                'title' => 'Заявки',
                'description' => 'Создание и ведение заявок, комментарии, смена статуса',
                'setting' => 'module.requests',
                'capabilities' => ['requests.view.all', 'requests.view.own', 'requests.create', 'requests.assign', 'requests.transition', 'requests.comment'],
                'access' => ['requests.view.all', 'requests.view.own'],
                'grant' => ['requests.view.own', 'requests.create', 'requests.transition', 'requests.comment'],
                'guards' => ['#^/requests(/|$)#', '#^/request-drafts(/|$)#'],
            ],
            [
                'code' => 'cart',
                'title' => 'Корзина',
                'description' => 'Добавление позиций и оформление заявки',
                'setting' => 'module.cart',
                'capabilities' => ['requests.create'],
                'access' => ['requests.create'],
                'grant' => ['requests.create'],
                'guards' => [],
            ],
            [
                'code' => 'manager_assign',
                'title' => 'Назначение менеджера',
                'description' => 'Закрепление и передача клиентов между менеджерами',
                'setting' => 'module.manager_assign',
                'capabilities' => ['clients.assign'],
                'access' => ['clients.assign'],
                'grant' => ['clients.assign'],
                'guards' => ['#^/clients/\d+/(claim|assign|transfer)$#', '#^/client-transfers/#', '#^/requests/\d+/(claim|assign)$#'],
            ],
            [
                'code' => 'substitutions',
                'title' => 'Замещения',
                'description' => 'Замещения менеджеров',
                'setting' => 'module.substitutions',
                'capabilities' => ['clients.assign'],
                'access' => ['clients.assign'],
                'grant' => ['clients.assign'],
                'guards' => ['#^/substitutions(/|$)#'],
            ],
            [
                'code' => 'warehouses',
                'title' => 'Склады',
                'description' => 'Несколько складов, страница и карточка склада',
                'setting' => 'module.warehouses',
                'capabilities' => ['stocks.view', 'stocks.edit', 'stocks.import', 'stocks.manage', 'stocks.deactivate'],
                'access' => ['stocks.edit', 'stocks.import', 'stocks.manage'],
                'grant' => ['stocks.view', 'stocks.edit', 'stocks.import', 'stocks.manage'],
                'guards' => ['#^/stocks/warehouses(/|$)#', '#^/stocks/\d+$#', '#^/stocks/\d+/items$#'],
            ],
            [
                'code' => 'reports',
                'title' => 'Отчёты',
                'description' => 'Страница отчётов, выгрузки и расписание',
                'setting' => 'module.reports',
                'capabilities' => ['reports.view.all', 'reports.view.own', 'reports.export'],
                'access' => ['reports.view.all', 'reports.view.own'],
                'grant' => ['reports.view.own'],
                'guards' => ['#^/reports(/|$)#', '#^/admin/report-schedules(/|$)#'],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function byCode(): array
    {
        $map = [];

        foreach (self::all() as $module) {
            $map[(string) $module['code']] = $module;
        }

        return $map;
    }

    /**
     * Все пути API, которые блокируются выключенными модулями.
     *
     * @param  callable(string): bool  $enabled  модуль включён?
     * @return array<int, string>
     */
    public static function guards(callable $enabled): array
    {
        $guards = [];

        foreach (self::all() as $module) {
            if ($enabled((string) $module['setting'])) {
                continue;
            }

            foreach ((array) $module['guards'] as $guard) {
                $guards[] = (string) $guard;
            }
        }

        return $guards;
    }
}
