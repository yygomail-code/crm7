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

    public function add(string $nameSid, string $storagePath, string $mime, int $size, int $userId): int
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort), 0) + 10 FROM nomenclature_photos WHERE name_sid = ?');
        $stmt->execute([$nameSid]);
        $sort = (int) $stmt->fetchColumn();

        $insert = $pdo->prepare(
            'INSERT INTO nomenclature_photos (name_sid, storage_path, mime, size, sort, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([$nameSid, $storagePath, $mime, $size, $sort, $userId]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @return array{id: int, name_sid: string, storage_path: string, mime: string, size: int}|null
     */
    public function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, name_sid, storage_path, mime, size FROM nomenclature_photos WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : [
            'id' => (int) $row['id'],
            'name_sid' => (string) $row['name_sid'],
            'storage_path' => (string) $row['storage_path'],
            'mime' => (string) $row['mime'],
            'size' => (int) $row['size'],
        ];
    }

    public function delete(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM nomenclature_photos WHERE id = ?')->execute([$id]);
    }
}
