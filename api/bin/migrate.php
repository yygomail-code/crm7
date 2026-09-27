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

    foreach (splitStatements($sql) as $statement) {
        $pdo->exec($statement);
    }

    $stmt = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)');
    $stmt->execute([$name]);

    echo "applied $name\n";
}

echo "migrations done\n";

/**
 * Разбирает SQL-файл на отдельные запросы, не трогая точки с запятой внутри
 * строковых литералов и комментариев (тексты документов и шаблонов).
 *
 * @return array<int, string>
 */
function splitStatements(string $sql): array
{
    $statements = [];
    $current = '';
    $inString = false;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];

        if ($inString) {
            $current .= $char;

            if ($char === '\\' && $i + 1 < $length) {
                $current .= $sql[$i + 1];
                $i++;
            } elseif ($char === "'") {
                if ($i + 1 < $length && $sql[$i + 1] === "'") {
                    $current .= $sql[$i + 1];
                    $i++;
                } else {
                    $inString = false;
                }
            }

            continue;
        }

        if ($char === "'") {
            $inString = true;
            $current .= $char;
            continue;
        }

        if ($char === '-' && $i + 1 < $length && $sql[$i + 1] === '-') {
            while ($i < $length && $sql[$i] !== "\n") {
                $i++;
            }

            $current .= "\n";
            continue;
        }

        if ($char === ';') {
            $statement = trim($current);

            if ($statement !== '') {
                $statements[] = $statement;
            }

            $current = '';
            continue;
        }

        $current .= $char;
    }

    $statement = trim($current);

    if ($statement !== '') {
        $statements[] = $statement;
    }

    return $statements;
}
