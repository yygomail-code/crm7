<?php

declare(strict_types=1);

namespace App\Core;

use App\Http\HttpException;
use PDO;
use PDOException;

final class DatabaseSettings
{
    private const FILE_NAME = 'database.json';

    public static function current(): array
    {
        $file = self::readFile();

        if ($file !== null) {
            return [
                'host' => (string) $file['host'],
                'port' => (int) $file['port'],
                'database' => (string) $file['database'],
                'user' => (string) $file['user'],
                'password' => (string) ($file['password'] ?? ''),
                'source' => 'file',
            ];
        }

        return [
            'host' => (string) Config::get('DB_HOST', '127.0.0.1'),
            'port' => (int) Config::get('DB_PORT', '3306'),
            'database' => (string) Config::get('DB_NAME', 'crm_local'),
            'user' => (string) Config::get('DB_USER', 'root'),
            'password' => (string) Config::get('DB_PASS', ''),
            'source' => 'env',
        ];
    }

    public static function publicPayload(): array
    {
        $current = self::current();

        return [
            'host' => $current['host'],
            'port' => $current['port'],
            'database' => $current['database'],
            'user' => $current['user'],
            'has_password' => $current['password'] !== '',
            'source' => $current['source'],
            'file' => self::path(),
        ];
    }

    public static function test(array $input): array
    {
        $settings = self::normalize($input);
        $pdo = self::connect($settings);

        return [
            'ok' => true,
            'server' => (string) $pdo->query('SELECT VERSION()')->fetchColumn(),
        ];
    }

    public static function save(array $input, string $actorLogin = ''): array
    {
        $settings = self::normalize($input);

        $pdo = self::connect($settings);

        self::assertHasAdmin($pdo, $settings['database']);

        $warnings = self::collectWarnings($pdo, $actorLogin);

        $path = self::path();

        if (is_file($path)) {
            @copy($path, $path . '.bak');
        }

        $payload = json_encode([
            'host' => $settings['host'],
            'port' => $settings['port'],
            'database' => $settings['database'],
            'user' => $settings['user'],
            'password' => $settings['password'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if ($payload === false || @file_put_contents($path . '.tmp', $payload) === false) {
            throw new HttpException(500, 'server_error', 'Не удалось записать настройки подключения');
        }

        @chmod($path . '.tmp', 0600);

        if (!@rename($path . '.tmp', $path)) {
            @unlink($path . '.tmp');

            throw new HttpException(500, 'server_error', 'Не удалось сохранить настройки подключения');
        }

        @chmod($path, 0600);

        return self::publicPayload() + ['warnings' => $warnings];
    }

    public static function path(): string
    {
        return dirname(__DIR__, 2) . '/config/' . self::FILE_NAME;
    }

    private static function normalize(array $input): array
    {
        $host = trim((string) ($input['host'] ?? ''));
        $port = (int) ($input['port'] ?? 0);
        $database = trim((string) ($input['database'] ?? ''));
        $user = trim((string) ($input['user'] ?? ''));
        $password = array_key_exists('password', $input) ? (string) $input['password'] : '';

        if ($host === '' || preg_match('/^[A-Za-z0-9._:-]+$/', $host) !== 1) {
            throw new HttpException(422, 'validation_error', 'Укажите корректный сервер базы данных');
        }

        if ($port < 1 || $port > 65535) {
            throw new HttpException(422, 'validation_error', 'Укажите корректный порт (1–65535)');
        }

        if ($database === '' || preg_match('/^[A-Za-z0-9_$-]+$/', $database) !== 1) {
            throw new HttpException(422, 'validation_error', 'Укажите корректное имя базы данных');
        }

        if ($user === '') {
            throw new HttpException(422, 'validation_error', 'Укажите пользователя базы данных');
        }

        if ($password === '') {
            $password = self::current()['password'];
        }

        return [
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'user' => $user,
            'password' => $password,
        ];
    }

    private static function connect(array $settings): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $settings['host'],
            $settings['port'],
            $settings['database']
        );

        try {
            return new PDO($dsn, $settings['user'], $settings['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (PDOException $exception) {
            throw new HttpException(422, 'db_connection_failed', 'Не удалось подключиться: ' . $exception->getMessage());
        }
    }

    private static function assertHasAdmin(PDO $pdo, string $database): void
    {
        $tables = $pdo->query("SHOW TABLES LIKE 'users'")->fetchAll();

        if ($tables === []) {
            throw new HttpException(
                422,
                'no_schema',
                'В базе «' . $database . '» нет таблиц CRM — сначала примените миграции (php api/bin/migrate.php)'
            );
        }

        $count = (int) $pdo->query(
            "SELECT COUNT(*) FROM users
             WHERE LEVEL >= 50
               AND ACTIVE = 'Y'
               AND STATUS = 'Y'
               AND (reg_state IS NULL OR reg_state = 'active')"
        )->fetchColumn();

        if ($count < 1) {
            throw new HttpException(
                422,
                'no_admin',
                'В базе «' . $database . '» нет активного администратора — после переключения вход будет невозможен'
            );
        }
    }

    private static function collectWarnings(PDO $pdo, string $actorLogin): array
    {
        if ($actorLogin === '') {
            return [];
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM users
             WHERE LOGIN = ?
               AND LEVEL >= 50
               AND ACTIVE = 'Y'
               AND STATUS = 'Y'
               AND (reg_state IS NULL OR reg_state = 'active')"
        );
        $stmt->execute([$actorLogin]);

        if ((int) $stmt->fetchColumn() === 0) {
            return [
                'Ваш логин «' . $actorLogin . '» не найден среди администраторов новой базы — после переключения войти сможет только администратор новой базы',
            ];
        }

        return [];
    }

    private static function readFile(): ?array
    {
        $path = self::path();

        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        if ($raw === false || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);

        if (!is_array($data) || !isset($data['host'], $data['port'], $data['database'], $data['user'])) {
            return null;
        }

        return $data;
    }
}
