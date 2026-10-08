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

    public function mailConfigured(): bool
    {
        return $this->get('mail.enabled') === '1' && trim((string) $this->get('mail.host')) !== '';
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

    public function requestsEnabled(): bool
    {
        return $this->get('module.requests') !== '0';
    }

    public function cartEnabled(): bool
    {
        return $this->get('module.cart') !== '0';
    }

    public function substitutionsEnabled(): bool
    {
        return $this->get('module.substitutions') !== '0';
    }

    public function managerAssignEnabled(): bool
    {
        return $this->get('module.manager_assign') !== '0';
    }

    public function warehousesEnabled(): bool
    {
        return $this->get('module.warehouses') !== '0';
    }

    public function reportsEnabled(): bool
    {
        return $this->get('module.reports') !== '0';
    }

    public function singleWarehouseName(): string
    {
        $name = trim((string) $this->get('stocks.single_name'));

        return $name !== '' ? $name : 'Основной склад';
    }

    public function appTitle(): string
    {
        $title = trim((string) $this->get('branding.title'));

        return $title !== '' ? $title : 'CRM7';
    }

    public function clientLabel(): string
    {
        $label = trim((string) $this->get('branding.client_label'));

        return $label !== '' ? $label : 'Клиент';
    }

    public function photoRatio(): string
    {
        $ratio = (string) $this->get('stocks.photo_ratio');

        return in_array($ratio, ['dynamic', 'square', 'landscape', 'portrait'], true) ? $ratio : 'square';
    }

    public function photoFit(): string
    {
        return $this->get('stocks.photo_fit') === 'cover' ? 'cover' : 'contain';
    }

    /**
     * Размеры вариантов фото по длинной стороне (px), нормализованные: max >= card >= preview.
     *
     * @return array{preview: int, card: int, max: int}
     */
    public function photoSizes(): array
    {
        $preview = $this->photoSize('stocks.photo_size_preview', 160);
        $card = max($this->photoSize('stocks.photo_size_card', 600), $preview);
        $max = max($this->photoSize('stocks.photo_size_max', 1600), $card);

        return ['preview' => $preview, 'card' => $card, 'max' => $max];
    }

    private function photoSize(string $key, int $default): int
    {
        $value = (int) $this->get($key);

        return $value >= 80 && $value <= 4000 ? $value : $default;
    }
}
