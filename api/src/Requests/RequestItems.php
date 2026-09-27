<?php

declare(strict_types=1);

namespace App\Requests;

final class RequestItems
{
    public static function normalize(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $items = [];

        foreach (array_slice($raw, 0, 50) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $quantity = (float) ($row['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            $warehouseId = (int) ($row['warehouse_id'] ?? 0);
            $stockLevelId = (int) ($row['stock_level_id'] ?? 0);

            $items[] = [
                'warehouse_id' => $warehouseId > 0 ? $warehouseId : null,
                'warehouse_name' => mb_substr(trim((string) ($row['warehouse_name'] ?? '')), 0, 255),
                'stock_level_id' => $stockLevelId > 0 ? $stockLevelId : null,
                'name' => mb_substr($name, 0, 255),
                'unit' => mb_substr(trim((string) ($row['unit'] ?? '')), 0, 50),
                'description' => mb_substr(trim((string) ($row['description'] ?? '')), 0, 500),
                'quantity' => $quantity,
            ];
        }

        return $items;
    }
}
