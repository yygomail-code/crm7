<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Database;
use App\Http\HttpException;
use App\Repositories\SettingsRepository;
use App\Support\Modules;
use App\Users\UserAdminService;

/**
 * Настройка модулей: вкл/выкл + доступ ролей (через права в level_capabilities).
 */
final class ModuleService
{
    private const LEVELS = [90, 50, 10, 5, 1];

    private const ROLES = [
        ['level' => 90, 'title' => 'Сисадмин'],
        ['level' => 50, 'title' => 'Администратор'],
        ['level' => 10, 'title' => 'Менеджер'],
        ['level' => 5, 'title' => 'Клиент'],
        ['level' => 1, 'title' => 'Гость'],
    ];

    public function __construct(
        private readonly SettingsRepository $settings = new SettingsRepository(),
        private readonly UserAdminService $users = new UserAdminService()
    ) {
    }

    /**
     * @param  array<int, string>  $capabilities
     * @return array<string, mixed>
     */
    public function list(array $capabilities): array
    {
        $this->requireSettings($capabilities);

        return $this->payload();
    }

    /**
     * @param  array<string, mixed>  $actor
     * @param  array<int, string>  $capabilities
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function save(array $actor, array $capabilities, array $input): array
    {
        $this->requireSettings($capabilities);

        $requested = $input['modules'] ?? null;

        if (!is_array($requested)) {
            throw new HttpException(422, 'validation_error', 'Некорректный список модулей');
        }

        $settings = [];
        $access = [];

        foreach (Modules::all() as $module) {
            $code = (string) $module['code'];
            $row = $requested[$code] ?? null;

            if (!is_array($row)) {
                throw new HttpException(422, 'validation_error', 'Некорректные данные модуля: ' . $code);
            }

            $settings[(string) $module['setting']] = ($row['enabled'] ?? true) ? '1' : '0';

            $levels = array_map('intval', (array) ($row['levels'] ?? []));
            $access[$code] = array_values(array_unique(array_filter(
                $levels,
                static fn (int $level): bool => in_array($level, self::LEVELS, true)
            )));
        }

        $this->settings->many($settings);

        if (in_array('roles.manage', $capabilities, true)) {
            $this->applyAccess($actor, $capabilities, $access);
        }

        return $this->payload();
    }

    /**
     * @param  array<string, mixed>  $actor
     * @param  array<int, string>  $capabilities
     * @param  array<string, array<int, int>>  $access
     */
    private function applyAccess(array $actor, array $capabilities, array $access): void
    {
        $catalog = Modules::all();
        $ownedBy = [];
        $accessCodes = [];

        foreach ($catalog as $module) {
            $code = (string) $module['code'];
            $accessCodes[$code] = array_values((array) $module['access']);

            foreach ((array) $module['capabilities'] as $cap) {
                $ownedBy[(string) $cap][] = $code;
            }
        }

        $current = $this->levelCapabilities();

        foreach (self::LEVELS as $level) {
            $before = $current[$level] ?? [];
            $add = [];
            $remove = [];

            foreach ($catalog as $module) {
                $code = (string) $module['code'];
                $was = count(array_intersect($accessCodes[$code], $before)) > 0;
                $want = in_array($level, $access[$code] ?? [], true);

                if ($was === $want) {
                    continue;
                }

                if ($want) {
                    foreach ((array) $module['grant'] as $cap) {
                        $add[(string) $cap] = true;
                    }
                } else {
                    foreach ((array) $module['capabilities'] as $cap) {
                        $remove[(string) $cap] = true;
                    }
                }
            }

            if ($add === [] && $remove === []) {
                continue;
            }

            foreach (array_keys($remove) as $cap) {
                foreach ($ownedBy[$cap] ?? [] as $code) {
                    if (in_array($level, $access[$code] ?? [], true)) {
                        unset($remove[$cap]);
                        break;
                    }
                }
            }

            $after = array_values(array_unique(array_merge(
                array_values(array_diff($before, array_keys($remove))),
                array_keys($add)
            )));
            sort($after);

            $sortedBefore = $before;
            sort($sortedBefore);

            if ($after === $sortedBefore) {
                continue;
            }

            $this->users->saveRole($actor, $capabilities, $level, ['capabilities' => $after]);
            $current[$level] = $after;
        }
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function levelCapabilities(): array
    {
        $map = [];

        foreach (Database::pdo()->query('SELECT level, capability_code FROM level_capabilities') as $row) {
            $map[(int) $row['level']][] = (string) $row['capability_code'];
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $current = $this->levelCapabilities();
        $items = [];

        foreach (Modules::all() as $module) {
            $access = array_values((array) $module['access']);
            $levels = [];

            foreach (self::LEVELS as $level) {
                if (count(array_intersect($access, $current[$level] ?? [])) > 0) {
                    $levels[] = $level;
                }
            }

            $items[] = [
                'code' => (string) $module['code'],
                'title' => (string) $module['title'],
                'description' => (string) $module['description'],
                'enabled' => $this->settings->get((string) $module['setting']) !== '0',
                'levels' => $levels,
                'capabilities' => array_values((array) $module['capabilities']),
            ];
        }

        return ['modules' => $items, 'roles' => self::ROLES];
    }

    /**
     * @param  array<int, string>  $capabilities
     */
    private function requireSettings(array $capabilities): void
    {
        if (!in_array('settings.manage', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для настройки модулей');
        }
    }
}
