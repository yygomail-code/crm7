<?php

declare(strict_types=1);

namespace App\Stocks;

use App\Http\HttpException;
use App\Repositories\SettingsRepository;
use App\Repositories\StockReservationRepository;

final class StockReservationService
{
    private const EPSILON = 0.00001;

    public function __construct(
        private readonly StockReservationRepository $reservations = new StockReservationRepository(),
        private readonly SettingsRepository $settings = new SettingsRepository()
    ) {
    }

    /**
     * Приводит резервы заявки в соответствие с её составом: проверяет доступность,
     * уменьшает/восстанавливает остатки и сохраняет актуальные резервы.
     */
    public function apply(int $requestId, array $items): void
    {
        $desired = $this->aggregate($items);
        $current = $this->reservations->forRequest($requestId);

        if (!$this->settings->allowZeroStock()) {
            $this->assertAvailable($desired, $current);
        }

        if (!$this->settings->stockReserveEnabled()) {
            return;
        }

        foreach ($this->levelIds($desired, $current) as $levelId) {
            $delta = ($desired[$levelId] ?? 0.0) - ($current[$levelId] ?? 0.0);
            $this->reservations->adjustLevel($levelId, -$delta);
        }

        $this->reservations->replace($requestId, $desired);
    }

    /**
     * Возвращает остатки по всем активным резервам заявки.
     */
    public function release(int $requestId): void
    {
        $current = $this->reservations->forRequest($requestId);

        if ($current === []) {
            return;
        }

        foreach ($current as $levelId => $quantity) {
            $this->reservations->adjustLevel($levelId, $quantity);
        }

        $this->reservations->deleteForRequest($requestId);
    }

    /**
     * @return array<int, float> stock_level_id => quantity
     */
    private function aggregate(array $items): array
    {
        $map = [];

        foreach ($items as $item) {
            $levelId = (int) ($item['stock_level_id'] ?? 0);

            if ($levelId <= 0) {
                continue;
            }

            $map[$levelId] = ($map[$levelId] ?? 0.0) + (float) $item['quantity'];
        }

        return $map;
    }

    /**
     * @param array<int, float> $desired
     * @param array<int, float> $current
     */
    private function assertAvailable(array $desired, array $current): void
    {
        foreach ($desired as $levelId => $want) {
            $available = $this->reservations->levelQuantity($levelId) + ($current[$levelId] ?? 0.0);

            if ($want > $available + self::EPSILON) {
                throw new HttpException(
                    422,
                    'insufficient_stock',
                    'Недостаточно остатка «' . $this->reservations->levelLabel($levelId) . '»: доступно '
                        . $this->formatQuantity($available) . ', запрошено ' . $this->formatQuantity($want)
                );
            }
        }
    }

    /**
     * @param array<int, float> $desired
     * @param array<int, float> $current
     * @return array<int, int>
     */
    private function levelIds(array $desired, array $current): array
    {
        return array_values(array_unique(array_map(
            'intval',
            array_merge(array_keys($desired), array_keys($current))
        )));
    }

    private function formatQuantity(float $value): string
    {
        return number_format($value, 3, ',', ' ');
    }
}
