<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class StockPhotoRepository
{
    /**
     * @param array<int, string> $nameSids
     * @return array<string, array<int, array{id: int}>>
     */
    public function forNames(array $nameSids): array
    {
        $nameSids = array_values(array_unique(array_filter(
            $nameSids,
            static fn (string $sid): bool => $sid !== ''
        )));

        if ($nameSids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($nameSids), '?'));
        $stmt = Database::pdo()->prepare(
            'SELECT id, name_sid FROM nomenclature_photos
             WHERE name_sid IN (' . $placeholders . ')
             ORDER BY sort ASC, id ASC'
        );
        $stmt->execute($nameSids);

        $map = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $map[(string) $row['name_sid']][] = ['id' => (int) $row['id']];
        }

        return $map;
    }

    /**
     * @return array<int, array{id: int}>
     */
    public function listForName(string $nameSid): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id FROM nomenclature_photos WHERE name_sid = ? ORDER BY sort ASC, id ASC'
        );
        $stmt->execute([$nameSid]);

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id']],
            $stmt->fetchAll() ?: []
        );
    }

    public function countForName(string $nameSid): int
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM nomenclature_photos WHERE name_sid = ?');
        $stmt->execute([$nameSid]);

        return (int) $stmt->fetchColumn();
    }

    public function add(
        string $nameSid,
        string $storagePath,
        string $cardPath,
        string $previewPath,
        string $mime,
        int $size,
        int $width,
        int $height,
        int $userId
    ): int {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort), 0) + 10 FROM nomenclature_photos WHERE name_sid = ?');
        $stmt->execute([$nameSid]);
        $sort = (int) $stmt->fetchColumn();

        $insert = $pdo->prepare(
            'INSERT INTO nomenclature_photos
                (name_sid, storage_path, card_path, preview_path, mime, size, width, height, sort, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([$nameSid, $storagePath, $cardPath, $previewPath, $mime, $size, $width, $height, $sort, $userId]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @return array{
     *   id: int, name_sid: string, storage_path: string, card_path: string,
     *   preview_path: string, mime: string, size: int, width: int, height: int
     * }|null
     */
    public function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, name_sid, storage_path, card_path, preview_path, mime, size, width, height
             FROM nomenclature_photos WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : [
            'id' => (int) $row['id'],
            'name_sid' => (string) $row['name_sid'],
            'storage_path' => (string) $row['storage_path'],
            'card_path' => (string) $row['card_path'],
            'preview_path' => (string) $row['preview_path'],
            'mime' => (string) $row['mime'],
            'size' => (int) $row['size'],
            'width' => (int) $row['width'],
            'height' => (int) $row['height'],
        ];
    }

    public function updateVariants(
        int $id,
        string $cardPath,
        string $previewPath,
        int $width,
        int $height,
        int $size
    ): void {
        Database::pdo()->prepare(
            'UPDATE nomenclature_photos
             SET card_path = ?, preview_path = ?, width = ?, height = ?, size = ?
             WHERE id = ?'
        )->execute([$cardPath, $previewPath, $width, $height, $size, $id]);
    }

    /**
     * Фото без производных вариантов (для пересчёта скриптом).
     *
     * @return array<int, array{id: int, storage_path: string, mime: string}>
     */
    public function pendingVariants(int $limit = 500): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT id, storage_path, mime FROM nomenclature_photos
             WHERE card_path = '' OR preview_path = '' OR width = 0
             ORDER BY id ASC LIMIT ?"
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'storage_path' => (string) $row['storage_path'],
            'mime' => (string) $row['mime'],
        ], $stmt->fetchAll() ?: []);
    }

    public function delete(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM nomenclature_photos WHERE id = ?')->execute([$id]);
    }
}
