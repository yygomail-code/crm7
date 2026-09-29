<?php

declare(strict_types=1);

namespace App\Groups;

use App\Core\Database;
use App\Http\HttpException;
use App\Repositories\ItemGroupRepository;
use App\Repositories\SettingsRepository;
use Throwable;

final class ItemGroupService
{
    public function __construct(
        private readonly ItemGroupRepository $groups = new ItemGroupRepository(),
        private readonly SettingsRepository $settings = new SettingsRepository()
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->groupsEnabled();
    }

    public function list(): array
    {
        return $this->groups->all();
    }

    public function find(int $id): ?array
    {
        return $this->groups->find($id);
    }

    public function create(string $title): array
    {
        $title = $this->normalizeTitle($title);

        if ($this->groups->titleTaken($title)) {
            throw new HttpException(422, 'duplicate_group', 'Группа с таким названием уже есть');
        }

        $id = $this->groups->create($title, $this->groups->nextSort());

        return $this->groups->find($id) ?? [];
    }

    public function update(int $id, string $title): array
    {
        if ($this->groups->find($id) === null) {
            throw new HttpException(404, 'not_found', 'Группа не найдена');
        }

        $title = $this->normalizeTitle($title);

        if ($this->groups->titleTaken($title, $id)) {
            throw new HttpException(422, 'duplicate_group', 'Группа с таким названием уже есть');
        }

        $this->groups->update($id, $title);

        return $this->groups->find($id) ?? [];
    }

    public function delete(int $id): void
    {
        if ($this->groups->find($id) === null) {
            throw new HttpException(404, 'not_found', 'Группа не найдена');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $this->groups->clearAssignments($id);
            $this->groups->delete($id);
            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    private function normalizeTitle(string $title): string
    {
        $title = trim($title);

        if ($title === '') {
            throw new HttpException(422, 'validation_error', 'Укажите название группы');
        }

        if (mb_strlen($title) > 255) {
            throw new HttpException(422, 'validation_error', 'Название слишком длинное (до 255 символов)');
        }

        return $title;
    }
}
