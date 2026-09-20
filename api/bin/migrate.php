<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;

require __DIR__ . '/../src/autoload.php';

Config::load(__DIR__ . '/../config/.env');

$pdo = Database::pdo();

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        id INT NOT NULL AUTO_INCREMENT,
        filename VARCHAR(255) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_filename (filename)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$applied = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$files = glob(__DIR__ . '/../db/migrations/*.sql') ?: [];
sort($files);

foreach ($files as $file) {
    $name = basename($file);

    if (in_array($name, $applied, true)) {
        echo "skip $name\n";
        continue;
    }

    $sql = (string) file_get_contents($file);
    $statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql) ?: []));

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    $stmt = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)');
    $stmt->execute([$name]);

    echo "applied $name\n";
}

echo "migrations done\n";
