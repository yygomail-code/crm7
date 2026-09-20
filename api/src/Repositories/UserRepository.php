<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class UserRepository
{
    public function findByLogin(string $login): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE LOGIN = ? LIMIT 1');
        $stmt->execute([$login]);

        return $stmt->fetch() ?: null;
    }

    public function findAnyByLogin(string $login): ?array
    {
        return $this->findByLogin($login);
    }

    public function findAnyByEmail(string $email): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE EMAIL = ? LIMIT 1');
        $stmt->execute([$email]);

        return $stmt->fetch() ?: null;
    }

    public function findAnyByPhone(string $phone): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE PHONE = ? LIMIT 1');
        $stmt->execute([$phone]);

        return $stmt->fetch() ?: null;
    }

    public function createPendingClient(array $data): int
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            "INSERT INTO users (ACTIVE, LOGIN, PASSWORD, LEVEL, FULL_NAME, STATUS, COMPANY, INN, PHONE, EMAIL, reg_state)
             VALUES ('N', ?, ?, 5, ?, 'Y', ?, ?, ?, ?, 'pending')"
        );

        $stmt->execute([
            $data['login'],
            $data['password'],
            $data['name'],
            $data['company'] !== '' ? $data['company'] : null,
            ($data['inn'] ?? '') !== '' ? $data['inn'] : null,
            $data['phone'] !== '' ? $data['phone'] : null,
            $data['email'],
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function listPendingClients(): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT u.ID AS id, u.FULL_NAME AS name, u.EMAIL AS email, u.PHONE AS phone,
                    u.COMPANY AS company, u.INN AS inn, u.DOLGNOST AS position, u.TIME_ADD AS created_at,
                    h.created_at AS registered_at
             FROM users u
             LEFT JOIN user_history h ON h.user_id = u.ID AND h.event = ?
             WHERE u.LEVEL = 5 AND u.reg_state = ?
             ORDER BY u.ID ASC
             LIMIT 200'
        );
        $stmt->execute(['registered', 'pending']);

        return $stmt->fetchAll() ?: [];
    }

    public function markClientState(int $id, string $state): void
    {
        if ($state === 'active') {
            $stmt = Database::pdo()->prepare(
                "UPDATE users SET reg_state = 'active', ACTIVE = 'Y', STATUS = 'Y', TIME_ACTIVE = NOW() WHERE ID = ?"
            );
        } else {
            $stmt = Database::pdo()->prepare(
                "UPDATE users SET reg_state = 'rejected', ACTIVE = 'N', STATUS = 'Y' WHERE ID = ?"
            );
        }

        $stmt->execute([$id]);
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE ID = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM users WHERE EMAIL = ? AND ACTIVE = 'Y' LIMIT 1"
        );
        $stmt->execute([$email]);

        return $stmt->fetch() ?: null;
    }

    public function updatePassword(int $id, string $hash): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET PASSWORD = ? WHERE ID = ?');
        $stmt->execute([$hash, $id]);
    }

    public function touchActivity(int $id): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET TIME_ACTIVE = NOW() WHERE ID = ?');
        $stmt->execute([$id]);
    }

    public function setConsent(int $id): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET CONSENT_AT = NOW() WHERE ID = ?');
        $stmt->execute([$id]);
    }

    public function updateName(int $id, string $name): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET FULL_NAME = ? WHERE ID = ?');
        $stmt->execute([$name, $id]);
    }

    public function updateAvatar(int $id, ?string $path): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET AVATAR_PATH = ? WHERE ID = ?');
        $stmt->execute([$path, $id]);
    }

    public function listManagers(): array
    {
        $stmt = Database::pdo()->query(
            "SELECT ID AS id, SID AS sid, LOGIN AS login, FULL_NAME AS name, LEVEL AS level, EMAIL AS email
             FROM users
             WHERE ACTIVE = 'Y' AND STATUS = 'Y' AND LEVEL IN (10, 50, 90)
             ORDER BY FULL_NAME ASC
             LIMIT 500"
        );

        return $stmt->fetchAll() ?: [];
    }

    public function listClients(string $query, ?array $managerIds, int $limit = 50, int $offset = 0): array
    {
        [$whereSql, $params] = $this->clientsWhere($query, $managerIds);

        $sql = 'SELECT u.ID AS id, u.SID AS sid, u.LOGIN AS login, u.FULL_NAME AS name,
                       u.EMAIL AS email, u.PHONE AS phone, u.COMPANY AS company, u.INN AS inn,
                       u.DOLGNOST AS position, u.LEVEL AS level
                FROM users u
                WHERE ' . $whereSql . '
                ORDER BY u.FULL_NAME ASC
                LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset);

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    public function countClients(string $query, ?array $managerIds): int
    {
        [$whereSql, $params] = $this->clientsWhere($query, $managerIds);

        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM users u WHERE ' . $whereSql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    private function clientsWhere(string $query, ?array $managerIds): array
    {
        $where = ["u.LEVEL = 5", "u.ACTIVE = 'Y'"];
        $params = [];

        if ($query !== '') {
            $where[] = '(u.FULL_NAME LIKE ? OR u.EMAIL LIKE ? OR u.PHONE LIKE ? OR u.COMPANY LIKE ? OR u.LOGIN LIKE ? OR u.INN LIKE ?)';
            $like = '%' . $query . '%';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }

        if ($managerIds !== null) {
            if ($managerIds === []) {
                $where[] = '1 = 0';
            } else {
                $placeholders = implode(',', array_fill(0, count($managerIds), '?'));
                $where[] = 'EXISTS (SELECT 1 FROM manager_clients mc WHERE mc.client_id = u.ID AND mc.manager_id IN (' . $placeholders . '))';

                foreach ($managerIds as $managerId) {
                    $params[] = (int) $managerId;
                }
            }
        }

        return [implode(' AND ', $where), $params];
    }

    public function capabilitiesForLevel(int $level): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT capability_code FROM level_capabilities WHERE level = ? ORDER BY capability_code'
        );
        $stmt->execute([$level]);

        return array_map('strval', array_column($stmt->fetchAll(), 'capability_code'));
    }

    public function toProfile(array $row): array
    {
        return [
            'id' => (int) $row['ID'],
            'sid' => (string) $row['SID'],
            'login' => (string) $row['LOGIN'],
            'name' => (string) ($row['FULL_NAME'] ?? ''),
            'level' => (int) $row['LEVEL'],
            'email' => (string) ($row['EMAIL'] ?? ''),
            'phone' => (string) ($row['PHONE'] ?? ''),
            'company' => (string) ($row['COMPANY'] ?? ''),
            'position' => (string) ($row['DOLGNOST'] ?? ''),
        ];
    }
}
