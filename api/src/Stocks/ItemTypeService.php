<?php

declare(strict_types=1);

namespace App\Stocks;

use App\Http\HttpException;
use App\Repositories\SettingsRepository;
use App\Support\ItemTypes;

/**
 * Настройка разрешённых типов номенклатуры (админка) + чтение для карточки.
 */
final class ItemTypeService
{
    public function __construct(
        private readonly SettingsRepository $settings = new SettingsRepository()
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function enabledCodes(): array
    {
        return ItemTypes::parseEnabled($this->settings->get(ItemTypes::SETTING));
    }

    /**
     * Разрешённые типы для карточки позиции (без админских прав).
     *
     * @return array<int, array<string, string>>
     */
    public function enabledList(): array
    {
        $enabled = $this->enabledCodes();
        $items = [];

        foreach (ItemTypes::all() as $type) {
            if (!in_array((string) $type['code'], $enabled, true)) {
                continue;
            }

            $items[] = ['code' => (string) $type['code'], 'title' => (string) $type['title']];
        }

        return $items;
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
     * @param  array<int, string>  $capabilities
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function save(array $capabilities, array $input): array
    {
        $this->requireSettings($capabilities);

        $requested = $input['types'] ?? null;

        if (!is_array($requested)) {
            throw new HttpException(422, 'validation_error', 'Некорректный список типов');
        }

        $enabled = [];

        foreach ($requested as $code => $on) {
            $code = (string) $code;

            if (ItemTypes::isKnown($code) && $on) {
                $enabled[] = $code;
            }
        }

        if ($enabled === []) {
            throw new HttpException(422, 'validation_error', 'Должен остаться хотя бы один тип');
        }

        $ordered = array_values(array_filter(
            ItemTypes::codes(),
            static fn (string $code): bool => in_array($code, $enabled, true)
        ));

        $this->settings->set(ItemTypes::SETTING, implode(',', $ordered));

        return $this->payload();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $enabled = $this->enabledCodes();
        $items = [];

        foreach (ItemTypes::all() as $type) {
            $items[] = [
                'code' => (string) $type['code'],
                'title' => (string) $type['title'],
                'description' => (string) $type['description'],
                'enabled' => in_array((string) $type['code'], $enabled, true),
            ];
        }

        return ['types' => $items];
    }

    /**
     * @param  array<int, string>  $capabilities
     */
    private function requireSettings(array $capabilities): void
    {
        if (!in_array('settings.manage', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для настройки типов позиций');
        }
    }
}
