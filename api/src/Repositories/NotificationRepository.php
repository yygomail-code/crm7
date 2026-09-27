<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class NotificationRepository
{
    public function settingsFor(int $userId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT event_code, in_app, email FROM notification_settings WHERE user_id = ?'
        );
        $stmt->execute([$userId]);

        $result = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $result[(string) $row['event_code']] = [
                'in_app' => (bool) $row['in_app'],
                'email' => (bool) $row['email'],
            ];
        }

        return $result;
    }

    public function saveSettings(int $userId, array $items): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO notification_settings (user_id, event_code, in_app, email)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE in_app = VALUES(in_app), email = VALUES(email)'
        );

        foreach ($items as $item) {
            $stmt->execute([
                $userId,
                (string) $item['event'],
                !empty($item['in_app']) ? 1 : 0,
                !empty($item['email']) ? 1 : 0,
            ]);
        }
    }

    public function add(int $userId, string $type, string $title, ?string $body, ?int $requestId): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO notifications (user_id, type, title, body, request_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $type, $title, $body, $requestId]);
    }

    public function listForUser(int $userId, bool $unreadOnly, int $limit, int $offset = 0): array
    {
        $sql = 'SELECT ID AS id, type, title, body, request_id, read_at, created_at
                FROM notifications
                WHERE user_id = ?';

        if ($unreadOnly) {
            $sql .= ' AND read_at IS NULL';
        }

        $sql .= ' ORDER BY created_at DESC, ID DESC LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset);

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([$userId]);

        return $stmt->fetchAll() ?: [];
    }

    public function countForUser(int $userId, bool $unreadOnly): int
    {
        $sql = 'SELECT COUNT(*) FROM notifications WHERE user_id = ?';

        if ($unreadOnly) {
            $sql .= ' AND read_at IS NULL';
        }

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    public function unreadCount(int $userId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL'
        );
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    public function markRead(int $userId, array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$userId], $ids);

        $stmt = Database::pdo()->prepare(
            'UPDATE notifications SET read_at = NOW()
             WHERE user_id = ? AND read_at IS NULL AND ID IN (' . $placeholders . ')'
        );
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    public function markAllRead(int $userId): int
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL'
        );
        $stmt->execute([$userId]);

        return $stmt->rowCount();
    }
}
