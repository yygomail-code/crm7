<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;

require __DIR__ . '/../src/autoload.php';

Config::load(__DIR__ . '/../config/.env');

$pdo = Database::pdo();

$users = [
    ['admin', 90, 'Сисадмин', 'admin@example.test'],
    ['administrator', 50, 'Администратор', 'administrator@example.test'],
    ['manager', 10, 'Менеджер', 'manager@example.test'],
    ['manager2', 10, 'Менеджер Второй', 'manager2@example.test'],
    ['client', 5, 'Клиент', 'client@example.test'],
];

$password = null;

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--password=')) {
        $password = substr($argument, 11);
    }
}

if ($password === null || $password === '') {
    $password = bin2hex(random_bytes(6)) . 'Aa1';
}

$hash = password_hash($password, PASSWORD_DEFAULT);

foreach ($users as [$login, $level, $name, $email]) {
    $stmt = $pdo->prepare('SELECT ID FROM users WHERE LOGIN = ?');
    $stmt->execute([$login]);

    if ($stmt->fetch() !== false) {
        echo "exists: $login\n";
        continue;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO users (LOGIN, PASSWORD, LEVEL, FULL_NAME, STATUS, ACTIVE, EMAIL)
         VALUES (?, ?, ?, ?, 'Y', 'Y', ?)"
    );
    $stmt->execute([$login, $hash, $level, $name, $email]);

    echo "created: $login (level $level, email $email)\n";
}

echo "dev password: $password\n";
