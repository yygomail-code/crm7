<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class SettingsRepository
{
    public function get(string $key): ?string
    {
        $stmt = Database::pdo()->prepare('SELECT value FROM settings WHERE `key` = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function set(string $key, ?string $value): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO settings (`key`, value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        $stmt->execute([$key, $value]);
    }

    public function many(array $pairs): void
    {
        foreach ($pairs as $key => $value) {
            $this->set((string) $key, $value === null ? null : (string) $value);
        }
    }

    public function salesEnabled(): bool
    {
        return $this->get('sales.enabled') !== '0';
    }

    public function emailExportEnabled(): bool
    {
        return $this->get('mail.export_enabled') !== '0';
    }

    public function stockReserveEnabled(): bool
    {
        return $this->get('stock.reserve_enabled') === '1';
    }

    public function pricesEnabled(): bool
    {
        return $this->get('prices.enabled') === '1';
    }

    public function groupsEnabled(): bool
    {
        return $this->get('groups.enabled') === '1';
    }

    public function allowZeroStock(): bool
    {
        return $this->get('stock.allow_zero') === '1';
    }
}
