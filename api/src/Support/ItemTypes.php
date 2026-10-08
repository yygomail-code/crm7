<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Реестр типов номенклатуры: товар, услуга, набор.
 *
 * Разрешённые типы хранятся в настройке `stocks.item_types` (CSV кодов).
 * Отсутствие настройки = разрешены все типы.
 */
final class ItemTypes
{
    public const PRODUCT = 'product';
    public const SERVICE = 'service';
    public const SET = 'set';

    public const SETTING = 'stocks.item_types';

    /**
     * @return array<int, array<string, string>>
     */
    public static function all(): array
    {
        return [
            [
                'code' => self::PRODUCT,
                'title' => 'Товар',
                'description' => 'Материальная позиция с остатками и ценами',
            ],
            [
                'code' => self::SERVICE,
                'title' => 'Услуга',
                'description' => 'Работа или услуга; учитывается по складам (где оказана или заказана)',
            ],
            [
                'code' => self::SET,
                'title' => 'Набор',
                'description' => 'Состав из других позиций (товаров и услуг)',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function codes(): array
    {
        return array_map(static fn (array $type): string => (string) $type['code'], self::all());
    }

    /**
     * @return array<string, string>
     */
    public static function titles(): array
    {
        $map = [];

        foreach (self::all() as $type) {
            $map[(string) $type['code']] = (string) $type['title'];
        }

        return $map;
    }

    public static function title(string $code): string
    {
        return self::titles()[$code] ?? $code;
    }

    public static function isKnown(string $code): bool
    {
        return in_array($code, self::codes(), true);
    }

    /**
     * Разрешённые типы из значения настройки.
     *
     * @return array<int, string>
     */
    public static function parseEnabled(?string $value): array
    {
        if ($value === null) {
            return self::codes();
        }

        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $code): bool => self::isKnown($code)
        )));
    }
}
