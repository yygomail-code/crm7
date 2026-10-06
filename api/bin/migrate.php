<?php

declare(strict_types=1);

/**
 * CRM7 — применение миграций БД.
 *
 * Правила (важно, чтобы не потерять данные):
 *   1. Каждое изменение схемы — НОВЫЙ файл api/db/migrations/NNN_*.sql.
 *      Уже применённые файлы НЕ редактируются (учитываются по имени).
 *   2. Сначала аддитивно (CREATE/ADD COLUMN), удаление — отдельным поздним
 *      релизом, когда код уже не использует объект.
 *   3. Один файл — одно логическое изменение; запросы делайте идемпотентными
 *      (IF NOT EXISTS / IF EXISTS). Транзакций и down-миграций нет.
 *   4. Перед применением на k/prod — БЭКАП БД.
 *   5. Сиды (seed-demo.php --force) запускаются ТОЛЬКО на demo.
 *
 * Использование:
 *   php api/bin/migrate.php                     применить ожидающие
 *   php api/bin/migrate.php --status            показать применённые/ожидающие
 *   php api/bin/migrate.php --allow-destructive разрешить DROP/TRUNCATE/DELETE
 */

use App\Core\Config;
use App\Core\Database;

require __DIR__ . '/../src/autoload.php';

Config::load(__DIR__ . '/../config/.env');

$statusOnly = in_array('--status', $argv, true);
$allowDestructive = in_array('--allow-destructive', $argv, true);

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

$all = array_map('basename', $files);
$pending = array_values(array_diff($all, $applied));

echo "CRM7 migrations\n";
echo 'applied: ' . count($applied) . ', pending: ' . count($pending) . "\n";

if ($statusOnly) {
    if ($applied !== []) {
        echo "\napplied:\n";
        foreach ($applied as $name) {
            echo "  ok   $name\n";
        }
    }

    if ($pending !== []) {
        echo "\npending:\n";
        foreach ($pending as $name) {
            echo "  new  $name\n";
        }
    } else {
        echo "\nnothing to apply\n";
    }

    exit(0);
}

if ($pending === []) {
    echo "nothing to apply\n";
    exit(0);
}

$destructive = [];
foreach ($pending as $name) {
    $sql = (string) file_get_contents(__DIR__ . '/../db/migrations/' . $name);

    if (preg_match('/\b(DROP\s+(TABLE|COLUMN|DATABASE)|TRUNCATE|DELETE\s+FROM)\b/i', $sql) === 1) {
        $destructive[] = $name;
    }
}

echo "\npending:\n";
foreach ($pending as $name) {
    $mark = in_array($name, $destructive, true) ? ' [destructive]' : '';
    echo "  new  $name$mark\n";
}

if ($destructive !== [] && !$allowDestructive) {
    fwrite(STDERR, "\nОТКАЗ: ожидающие миграции содержат деструктивные операции (DROP/TRUNCATE/DELETE):\n");
    foreach ($destructive as $name) {
        fwrite(STDERR, "  $name\n");
    }
    fwrite(STDERR, "\nСделайте БЭКАП БД и запустите с --allow-destructive, если удаление осознанно.\n");
    exit(1);
}

fwrite(STDERR, "\nВНИМАНИЕ: перед применением на k/prod убедитесь, что есть бэкап БД.\n");

foreach ($pending as $name) {
    $sql = (string) file_get_contents(__DIR__ . '/../db/migrations/' . $name);

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
