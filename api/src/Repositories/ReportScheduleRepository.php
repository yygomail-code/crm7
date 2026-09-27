<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class ReportScheduleRepository
{
    public function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM report_schedules';

        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }

        $sql .= ' ORDER BY ID ASC';

        return Database::pdo()->query($sql)->fetchAll() ?: [];
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM report_schedules WHERE ID = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'INSERT INTO report_schedules
               (title, report_type, frequency, time_of_day, day_of_week, day_of_month, recipients, extra_emails, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $data['title'],
            $data['report_type'],
            $data['frequency'],
            $data['time_of_day'],
            $data['day_of_week'],
            $data['day_of_month'],
            $data['recipients'],
            $data['extra_emails'],
            $data['is_active'],
            $data['created_by'],
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE report_schedules
             SET title = ?, report_type = ?, frequency = ?, time_of_day = ?, day_of_week = ?,
                 day_of_month = ?, recipients = ?, extra_emails = ?, is_active = ?
             WHERE ID = ?'
        );

        $stmt->execute([
            $data['title'],
            $data['report_type'],
            $data['frequency'],
            $data['time_of_day'],
            $data['day_of_week'],
            $data['day_of_month'],
            $data['recipients'],
            $data['extra_emails'],
            $data['is_active'],
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM report_schedules WHERE ID = ?');
        $stmt->execute([$id]);
    }

    public function markSent(int $id): void
    {
        $stmt = Database::pdo()->prepare('UPDATE report_schedules SET last_sent_at = NOW() WHERE ID = ?');
        $stmt->execute([$id]);
    }

    public function claim(int $id, string $today): bool
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE report_schedules SET last_sent_at = NOW()
             WHERE ID = ? AND (last_sent_at IS NULL OR DATE(last_sent_at) < ?)'
        );
        $stmt->execute([$id, $today]);

        return $stmt->rowCount() > 0;
    }
}
