<?php

declare(strict_types=1);

namespace App\Users;

use App\Core\Database;
use App\Http\HttpException;
use App\Repositories\TokenRepository;
use App\Repositories\UserHistoryRepository;
use App\Repositories\UserRepository;
use App\Support\Validator;

final class UserAdminService
{
    private const LEVELS = [5, 10, 50, 90];

    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly UserHistoryRepository $history = new UserHistoryRepository(),
        private readonly TokenRepository $tokens = new TokenRepository()
    ) {
    }

    public function list(array $filters, int $page, int $perPage): array
    {
        $where = ['1 = 1'];
        $params = [];

        if (!empty($filters['q'])) {
            $where[] = '(u.FULL_NAME LIKE ? OR u.LOGIN LIKE ? OR u.EMAIL LIKE ? OR u.PHONE LIKE ? OR u.COMPANY LIKE ? OR u.INN LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }

        if (!empty($filters['level'])) {
            $where[] = 'u.LEVEL = ?';
            $params[] = (int) $filters['level'];
        }

        if (!empty($filters['state'])) {
            if ($filters['state'] === 'blocked') {
                $where[] = "u.ACTIVE = 'N'";
            } elseif ($filters['state'] === 'active') {
                $where[] = "u.ACTIVE = 'Y' AND u.reg_state = 'active'";
            } elseif ($filters['state'] === 'pending') {
                $where[] = "u.reg_state = 'pending'";
            }
        }

        $whereSql = implode(' AND ', $where);
        $offset = max(0, ($page - 1) * $perPage);

        $stmt = Database::pdo()->prepare(
            'SELECT u.ID AS id, u.LOGIN AS login, u.FULL_NAME AS name, u.EMAIL AS email, u.PHONE AS phone,
                    u.COMPANY AS company, u.INN AS inn, u.DOLGNOST AS position, u.LEVEL AS level,
                    u.ACTIVE AS active, u.reg_state, u.TIME_ADD AS created_at, u.TIME_ACTIVE AS last_seen_at
             FROM users u
             WHERE ' . $whereSql . '
             ORDER BY u.LEVEL DESC, u.FULL_NAME ASC
             LIMIT ' . max(1, min(100, $perPage)) . ' OFFSET ' . $offset
        );
        $stmt->execute($params);

        $items = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'login' => (string) ($row['login'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'company' => (string) ($row['company'] ?? ''),
            'inn' => (string) ($row['inn'] ?? ''),
            'position' => (string) ($row['position'] ?? ''),
            'level' => (int) $row['level'],
            'active' => ($row['active'] ?? 'N') === 'Y',
            'reg_state' => (string) ($row['reg_state'] ?? 'active'),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'last_seen_at' => $row['last_seen_at'] !== null ? (string) $row['last_seen_at'] : null,
        ], $stmt->fetchAll() ?: []);

        $countStmt = Database::pdo()->prepare('SELECT COUNT(*) FROM users u WHERE ' . $whereSql);
        $countStmt->execute($params);

        return ['items' => $items, 'total' => (int) $countStmt->fetchColumn(), 'page' => $page, 'per_page' => $perPage];
    }

    public function create(array $actor, array $capabilities, array $input): array
    {
        $this->requireManage($capabilities);

        $name = trim((string) ($input['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $phone = trim((string) ($input['phone'] ?? ''));
        $company = trim((string) ($input['company'] ?? ''));
        $inn = preg_replace('/\s+/', '', (string) ($input['inn'] ?? '')) ?? '';
        $position = trim((string) ($input['position'] ?? ''));
        $level = (int) ($input['level'] ?? 5);
        $password = (string) ($input['password'] ?? '');

        if (mb_strlen($name) < 3) {
            throw new HttpException(422, 'validation_error', 'Укажите ФИО');
        }

        if (!Validator::email($email)) {
            throw new HttpException(422, 'validation_error', 'Укажите корректный e-mail');
        }

        if (!in_array($level, self::LEVELS, true)) {
            throw new HttpException(422, 'validation_error', 'Неизвестная роль');
        }

        if ($level !== 5 && !in_array('roles.manage', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Назначать роли может только сисадмин');
        }

        if ($inn !== '' && preg_match('/^(\d{10}|\d{12})$/', $inn) !== 1) {
            throw new HttpException(422, 'validation_error', 'ИНН должен содержать 10 или 12 цифр');
        }

        if ($this->users->findAnyByEmail($email) !== null || $this->users->findAnyByLogin($email) !== null) {
            throw new HttpException(422, 'email_taken', 'Пользователь с таким e-mail уже существует');
        }

        if ($phone !== '' && $this->users->findAnyByPhone($phone) !== null) {
            throw new HttpException(422, 'phone_taken', 'Пользователь с таким телефоном уже существует');
        }

        $generated = false;

        if ($password === '') {
            $password = bin2hex(random_bytes(5)) . 'Aa1';
            $generated = true;
        } else {
            $error = Validator::password($password);

            if ($error !== null) {
                throw new HttpException(422, 'weak_password', $error);
            }
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "INSERT INTO users (ACTIVE, LOGIN, PASSWORD, LEVEL, FULL_NAME, STATUS, COMPANY, INN, PHONE, EMAIL, DOLGNOST, reg_state)
             VALUES ('Y', ?, ?, ?, ?, 'Y', ?, ?, ?, ?, ?, 'active')"
        );
        $stmt->execute([
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            $level,
            $name,
            $company !== '' ? $company : null,
            $inn !== '' ? $inn : null,
            $phone !== '' ? $phone : null,
            $email,
            $position !== '' ? $position : null,
        ]);

        $userId = (int) $pdo->lastInsertId();
        $this->history->add($userId, 'created', (int) $actor['ID'], 'Создан администратором');

        return [
            'id' => $userId,
            'login' => $email,
            'password' => $generated ? $password : null,
        ];
    }

    public function update(array $actor, array $capabilities, int $userId, array $input): array
    {
        $this->requireManage($capabilities);

        $user = $this->users->findById($userId);

        if ($user === null) {
            throw new HttpException(404, 'not_found', 'Пользователь не найден');
        }

        $name = trim((string) ($input['name'] ?? (string) $user['FULL_NAME']));
        $phone = trim((string) ($input['phone'] ?? (string) ($user['PHONE'] ?? '')));
        $company = trim((string) ($input['company'] ?? (string) ($user['COMPANY'] ?? '')));
        $inn = preg_replace('/\s+/', '', (string) ($input['inn'] ?? (string) ($user['INN'] ?? ''))) ?? '';
        $position = trim((string) ($input['position'] ?? (string) ($user['DOLGNOST'] ?? '')));
        $level = isset($input['level']) ? (int) $input['level'] : (int) $user['LEVEL'];

        if (mb_strlen($name) < 3) {
            throw new HttpException(422, 'validation_error', 'Укажите ФИО');
        }

        if ($inn !== '' && preg_match('/^(\d{10}|\d{12})$/', $inn) !== 1) {
            throw new HttpException(422, 'validation_error', 'ИНН должен содержать 10 или 12 цифр');
        }

        if ($phone !== '' && $phone !== (string) ($user['PHONE'] ?? '')) {
            $existing = $this->users->findAnyByPhone($phone);

            if ($existing !== null && (int) $existing['ID'] !== $userId) {
                throw new HttpException(422, 'phone_taken', 'Пользователь с таким телефоном уже существует');
            }
        }

        if ($level !== (int) $user['LEVEL']) {
            if (!in_array('roles.manage', $capabilities, true)) {
                throw new HttpException(403, 'forbidden', 'Менять роль может только сисадмин');
            }

            if ($userId === (int) $actor['ID']) {
                throw new HttpException(422, 'validation_error', 'Нельзя менять свою роль');
            }

            if (!in_array($level, self::LEVELS, true)) {
                throw new HttpException(422, 'validation_error', 'Неизвестная роль');
            }
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE users SET FULL_NAME = ?, PHONE = ?, COMPANY = ?, INN = ?, DOLGNOST = ?, LEVEL = ? WHERE ID = ?'
        );
        $stmt->execute([
            $name,
            $phone !== '' ? $phone : null,
            $company !== '' ? $company : null,
            $inn !== '' ? $inn : null,
            $position !== '' ? $position : null,
            $level,
            $userId,
        ]);

        $this->history->add($userId, 'updated', (int) $actor['ID'], 'Изменён администратором');

        return ['id' => $userId, 'level' => $level];
    }

    public function block(array $actor, array $capabilities, int $userId, bool $block): array
    {
        $this->requireManage($capabilities);

        $user = $this->users->findById($userId);

        if ($user === null) {
            throw new HttpException(404, 'not_found', 'Пользователь не найден');
        }

        if ($userId === (int) $actor['ID']) {
            throw new HttpException(422, 'validation_error', 'Нельзя заблокировать себя');
        }

        if ((int) $user['LEVEL'] >= (int) $actor['LEVEL'] && (int) $actor['LEVEL'] < 90) {
            throw new HttpException(403, 'forbidden', 'Нельзя блокировать пользователя с равной или большей ролью');
        }

        $stmt = Database::pdo()->prepare("UPDATE users SET ACTIVE = ? WHERE ID = ?");
        $stmt->execute([$block ? 'N' : 'Y', $userId]);

        if ($block) {
            $this->tokens->revokeAllForUser($userId);
        }

        $this->history->add(
            $userId,
            $block ? 'blocked' : 'unblocked',
            (int) $actor['ID'],
            $block ? 'Доступ заблокирован' : 'Доступ восстановлен'
        );

        return ['id' => $userId, 'active' => !$block];
    }

    public function resetPassword(array $actor, array $capabilities, int $userId): array
    {
        $this->requireManage($capabilities);

        $user = $this->users->findById($userId);

        if ($user === null) {
            throw new HttpException(404, 'not_found', 'Пользователь не найден');
        }

        $password = bin2hex(random_bytes(5)) . 'Aa1';

        $this->users->updatePassword($userId, password_hash($password, PASSWORD_DEFAULT));
        $this->tokens->revokeAllForUser($userId);
        $this->history->add($userId, 'password_reset', (int) $actor['ID'], 'Пароль сброшен администратором');

        return ['id' => $userId, 'password' => $password];
    }

    public function roles(): array
    {
        $levels = Database::pdo()->query(
            'SELECT l.level, COUNT(lc.capability_code) AS capabilities
             FROM (SELECT DISTINCT level FROM level_capabilities) l
             LEFT JOIN level_capabilities lc ON lc.level = l.level
             GROUP BY l.level
             ORDER BY l.level DESC'
        )->fetchAll() ?: [];

        $titles = [90 => 'Сисадмин', 50 => 'Администратор', 10 => 'Менеджер', 5 => 'Клиент', 1 => 'Гость'];

        $items = [];

        foreach ($levels as $row) {
            $level = (int) $row['level'];
            $stmt = Database::pdo()->prepare(
                'SELECT capability_code FROM level_capabilities WHERE level = ? ORDER BY capability_code'
            );
            $stmt->execute([$level]);

            $items[] = [
                'level' => $level,
                'title' => $titles[$level] ?? ('Уровень ' . $level),
                'capabilities' => array_map('strval', array_column($stmt->fetchAll() ?: [], 'capability_code')),
            ];
        }

        return ['items' => $items];
    }

    private function requireManage(array $capabilities): void
    {
        if (!in_array('users.manage', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для управления пользователями');
        }
    }
}
