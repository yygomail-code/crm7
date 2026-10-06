<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;
use App\Demo\DemoAccounts;

require __DIR__ . '/../src/autoload.php';

Config::load(__DIR__ . '/../config/.env');

$pdo = Database::pdo();
$hash = password_hash(DemoAccounts::PASSWORD, PASSWORD_DEFAULT);

$find = $pdo->prepare('SELECT ID FROM users WHERE LOGIN = ?');
$insert = $pdo->prepare(
    "INSERT INTO users (LOGIN, PASSWORD, LEVEL, FULL_NAME, COMPANY, EMAIL, STATUS, ACTIVE, reg_state)
     VALUES (?, ?, ?, ?, ?, ?, 'Y', 'Y', 'active')"
);
$update = $pdo->prepare(
    "UPDATE users SET PASSWORD = ?, LEVEL = ?, FULL_NAME = ?, COMPANY = ?, EMAIL = ?,
            STATUS = 'Y', ACTIVE = 'Y', reg_state = 'active'
     WHERE ID = ?"
);

foreach (DemoAccounts::all() as $account) {
    $find->execute([$account['login']]);
    $id = $find->fetchColumn();

    if ($id !== false) {
        $update->execute([$hash, $account['level'], $account['name'], $account['company'], $account['email'], $id]);
        echo "updated: {$account['login']}\n";
        continue;
    }

    $insert->execute([$account['login'], $hash, $account['level'], $account['name'], $account['company'], $account['email']]);
    echo "created: {$account['login']}\n";
}

$clientId = $pdo->query("SELECT ID FROM users WHERE LOGIN = 'demo_client'")->fetchColumn();
$managerId = $pdo->query("SELECT ID FROM users WHERE LOGIN = 'demo_manager'")->fetchColumn();

if ($clientId !== false && $managerId !== false) {
    $pdo->prepare('DELETE FROM client_managers WHERE client_id = ?')->execute([$clientId]);
    $pdo->prepare('INSERT INTO client_managers (client_id, manager_id, assigned_by) VALUES (?, ?, ?)')
        ->execute([$clientId, $managerId, $managerId]);
    echo "bound: demo_client -> demo_manager\n";
}

echo 'demo password: ' . DemoAccounts::PASSWORD . "\n";
