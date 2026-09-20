<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class ChatRepository
{
    public function findByClient(int $clientId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM chat_threads WHERE client_id = ? LIMIT 1');
        $stmt->execute([$clientId]);

        return $stmt->fetch() ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM chat_threads WHERE ID = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function create(int $clientId, ?int $managerId): int
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare('INSERT INTO chat_threads (client_id, manager_id) VALUES (?, ?)');
        $stmt->execute([$clientId, $managerId]);

        return (int) $pdo->lastInsertId();
    }

    public function syncManager(int $clientId, ?int $managerId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE chat_threads SET manager_id = ? WHERE client_id = ? AND (manager_id IS NULL OR manager_id <> ?)'
        );
        $stmt->execute([$managerId, $clientId, $managerId]);
    }

    public function listForManager(array $managerIds): array
    {
        if ($managerIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($managerIds), '?'));
        $params = array_map('intval', $managerIds);

        $stmt = Database::pdo()->prepare(
            'SELECT t.*, c.FULL_NAME AS client_name, m.FULL_NAME AS manager_name
             FROM chat_threads t
             INNER JOIN users c ON c.ID = t.client_id
             LEFT JOIN users m ON m.ID = t.manager_id
             WHERE t.manager_id IN (' . $placeholders . ')
                OR EXISTS (
                    SELECT 1 FROM manager_clients mc
                    WHERE mc.client_id = t.client_id AND mc.manager_id IN (' . $placeholders . ')
                )
             ORDER BY COALESCE(t.last_message_at, t.created_at) DESC
             LIMIT 200'
        );
        $stmt->execute(array_merge($params, $params));

        return $stmt->fetchAll() ?: [];
    }

    public function listAll(): array
    {
        $stmt = Database::pdo()->query(
            'SELECT t.*, c.FULL_NAME AS client_name, m.FULL_NAME AS manager_name
             FROM chat_threads t
             INNER JOIN users c ON c.ID = t.client_id
             LEFT JOIN users m ON m.ID = t.manager_id
             ORDER BY COALESCE(t.last_message_at, t.created_at) DESC
             LIMIT 500'
        );

        return $stmt->fetchAll() ?: [];
    }

    public function lastMessages(array $threadIds): array
    {
        if ($threadIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($threadIds), '?'));

        $stmt = Database::pdo()->prepare(
            'SELECT m.thread_id, m.body, m.created_at, m.user_id, u.FULL_NAME AS user_name
             FROM chat_messages m
             INNER JOIN users u ON u.ID = m.user_id
             INNER JOIN (
                 SELECT thread_id, MAX(ID) AS max_id
                 FROM chat_messages
                 WHERE thread_id IN (' . $placeholders . ')
                 GROUP BY thread_id
             ) last ON last.max_id = m.ID'
        );
        $stmt->execute(array_map('intval', $threadIds));

        $result = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $result[(int) $row['thread_id']] = $row;
        }

        return $result;
    }

    public function unreadCounts(int $userId, array $threadIds): array
    {
        if ($threadIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($threadIds), '?'));

        $stmt = Database::pdo()->prepare(
            'SELECT m.thread_id, COUNT(*) AS unread
             FROM chat_messages m
             LEFT JOIN chat_reads r ON r.thread_id = m.thread_id AND r.user_id = ?
             WHERE m.thread_id IN (' . $placeholders . ') AND m.user_id <> ?
               AND m.ID > COALESCE(r.last_read_id, 0)
             GROUP BY m.thread_id'
        );
        $stmt->execute(array_merge([$userId], array_map('intval', $threadIds), [$userId]));

        $result = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $result[(int) $row['thread_id']] = (int) $row['unread'];
        }

        return $result;
    }

    public function addMessage(int $threadId, int $userId, string $body): int
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare('INSERT INTO chat_messages (thread_id, user_id, body) VALUES (?, ?, ?)');
        $stmt->execute([$threadId, $userId, $body]);

        $messageId = (int) $pdo->lastInsertId();

        $pdo->prepare('UPDATE chat_threads SET last_message_at = NOW() WHERE ID = ?')->execute([$threadId]);

        return $messageId;
    }

    public function messages(int $threadId, int $afterId = 0, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));

        $stmt = Database::pdo()->prepare(
            'SELECT m.ID AS id, m.body, m.created_at, m.user_id,
                    u.FULL_NAME AS user_name, u.LEVEL AS user_level
             FROM chat_messages m
             INNER JOIN users u ON u.ID = m.user_id
             WHERE m.thread_id = ? AND m.ID > ?
             ORDER BY m.ID ASC
             LIMIT ' . $limit
        );
        $stmt->execute([$threadId, $afterId]);

        return $stmt->fetchAll() ?: [];
    }

    public function createAttachment(int $threadId, int $userId, string $fileName, string $path, string $mime, int $size): int
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'INSERT INTO chat_attachments (thread_id, user_id, file_name, storage_path, mime, size)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$threadId, $userId, $fileName, $path, $mime, $size]);

        return (int) $pdo->lastInsertId();
    }

    public function attachToMessage(int $messageId, array $attachmentIds, int $threadId, int $userId): int
    {
        $ids = array_values(array_filter(array_map('intval', $attachmentIds), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = Database::pdo()->prepare(
            'UPDATE chat_attachments SET message_id = ?
             WHERE ID IN (' . $placeholders . ') AND thread_id = ? AND user_id = ? AND message_id IS NULL'
        );
        $stmt->execute(array_merge([$messageId], $ids, [$threadId, $userId]));

        return $stmt->rowCount();
    }

    public function attachmentsForMessages(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));

        $stmt = Database::pdo()->prepare(
            'SELECT ID AS id, message_id, file_name, mime, size
             FROM chat_attachments
             WHERE message_id IN (' . $placeholders . ')
             ORDER BY ID ASC'
        );
        $stmt->execute(array_map('intval', $messageIds));

        $result = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $result[(int) $row['message_id']][] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['file_name'],
                'mime' => (string) ($row['mime'] ?? ''),
                'size' => (int) $row['size'],
            ];
        }

        return $result;
    }

    public function findAttachment(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM chat_attachments WHERE ID = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function maxMessageId(int $threadId): int
    {
        $stmt = Database::pdo()->prepare('SELECT COALESCE(MAX(ID), 0) FROM chat_messages WHERE thread_id = ?');
        $stmt->execute([$threadId]);

        return (int) $stmt->fetchColumn();
    }

    public function markRead(int $threadId, int $userId, int $lastReadId): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO chat_reads (thread_id, user_id, last_read_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id))'
        );
        $stmt->execute([$threadId, $userId, $lastReadId]);
    }

    public function quickReplies(): array
    {
        $stmt = Database::pdo()->query(
            'SELECT ID AS id, body FROM chat_quick_replies WHERE is_active = 1 ORDER BY sort ASC, ID ASC LIMIT 50'
        );

        return $stmt->fetchAll() ?: [];
    }
}
