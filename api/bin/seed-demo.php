<?php

declare(strict_types=1);

/**
 * Тестовые данные для демонстрации CRM.
 *
 * ВНИМАНИЕ: скрипт полностью удаляет текущие данные — заявки, чаты,
 * пользователей (включая реальные учётки), остатки складов — и создаёт
 * тестовый набор: 5 основных учёток, тестовых клиентов, смартфоны на
 * складах, ~240 заявок с историей, чаты и черновики.
 *
 * Запуск (обязательно с --force):
 *   php api/bin/seed-demo.php --force
 *   php api/bin/seed-demo.php --force --password=ТестовыйПароль1
 *
 * Пароль общий для всех тестовых учёток. Без --password генерируется
 * случайный и печатается в конце.
 */

use App\Core\Config;
use App\Core\Database;

require __DIR__ . '/../src/autoload.php';

Config::load(__DIR__ . '/../config/.env');

$force = in_array('--force', $argv, true);
$password = null;
$passwordsFile = null;

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--password=')) {
        $password = substr($argument, 11);
    }

    if (str_starts_with($argument, '--passwords-file=')) {
        $passwordsFile = substr($argument, 17);
    }
}

if (!$force) {
    fwrite(STDERR, "seed-demo удаляет все текущие данные (заявки, чаты, пользователей, остатки).\n");
    fwrite(STDERR, "Запустите с флагом --force, предварительно сделав дамп базы.\n");

    exit(1);
}

$passwords = [];

if ($passwordsFile !== null) {
    $raw = file_get_contents($passwordsFile);

    if ($raw === false) {
        fwrite(STDERR, "Не найден файл паролей: $passwordsFile\n");

        exit(1);
    }

    $decoded = json_decode(ltrim($raw, "\xEF\xBB\xBF"), true);

    if (!is_array($decoded)) {
        fwrite(STDERR, "Файл паролей должен быть JSON-объектом {login: password}\n");

        exit(1);
    }

    $passwords = array_map('strval', $decoded);
}

if ($passwords === [] && ($password === null || $password === '')) {
    $password = bin2hex(random_bytes(6)) . 'Aa1';
}

mt_srand(20260924);

$pdo = Database::pdo();
$now = time();

/** @return string */
function uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/** @param array<int, mixed> $items */
function pick(array $items): mixed
{
    return $items[mt_rand(0, count($items) - 1)];
}

function stamp(int $timestamp): string
{
    return date('Y-m-d H:i:s', $timestamp);
}

$wipe = [
    'request_items',
    'stock_reservations',
    'request_history',
    'request_assignments',
    'request_comments',
    'request_activities',
    'request_attachments',
    'request_drafts',
    'requests',
    'chat_attachments',
    'chat_messages',
    'chat_reads',
    'chat_threads',
    'client_managers',
    'client_transfers',
    'substitutions',
    'notifications',
    'email_queue',
    'view_log',
    'audit_log',
    'login_attempts',
    'password_resets',
    'user_tokens',
    'user_history',
    'saved_filters',
    'rate_limits',
    'stock_import_jobs',
    'stock_levels_backup',
    'stock_levels',
    'nomenclature',
    'last_stock_update',
    'user_level_stock',
    'users',
];

echo "Очистка таблиц...\n";

foreach ($wipe as $table) {
    $pdo->exec("TRUNCATE TABLE `$table`");
}

echo "Создание учётных записей...\n";

$accountPasswords = [];

foreach ([['admin', 90], ['manager', 10], ['client', 5], ['administrator', 50], ['manager2', 10]] as [$login]) {
    $accountPasswords[$login] = $passwords[$login] ?? $password;
}

$accounts = [
    [1, 'admin', 90, 'Сисадмин Тестовый', 'admin@example.test', '+79000000001', ''],
    [2, 'manager', 10, 'Менеджер Тестовый', 'manager@example.test', '+79000000002', 'Менеджер продаж'],
    [3, 'client', 5, 'Клиент Тестовый', 'client@example.test', '+79000000003', 'Руководитель'],
    [4, 'administrator', 50, 'Администратор Тестовый', 'administrator@example.test', '+79000000004', ''],
    [5, 'manager2', 10, 'Менеджер Второй', 'manager2@example.test', '+79000000005', 'Менеджер продаж'],
];

$clientNames = [
    ['Иванов Иван', 'ООО «Ромашка»', '7700000001'],
    ['Петрова Анна', 'ООО «ТехноПлюс»', '7700000002'],
    ['Сидоров Пётр', 'ИП Сидоров П. П.', '7700000003'],
    ['Кузнецова Мария', 'ООО «Офис-Сервис»', '7700000004'],
    ['Смирнов Алексей', 'АО «Северторг»', '7700000005'],
    ['Волкова Ольга', 'ООО «Логистика Плюс»', '7700000006'],
    ['Морозов Дмитрий', 'ООО «СтройГарант»', '7700000007'],
    ['Фёдорова Елена', 'ИП Фёдорова Е. А.', '7700000008'],
    ['Николаев Сергей', 'ООО «АгроТрейд»', '7700000009'],
];

$insertUser = $pdo->prepare(
    'INSERT INTO users (ID, LOGIN, PASSWORD, LEVEL, FULL_NAME, COMPANY, INN, DOLGNOST, PHONE, EMAIL,
                        STATUS, ACTIVE, reg_state)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'Y\', \'Y\', \'active\')'
);

foreach ($accounts as [$id, $login, $level, $name, $email, $phone, $position]) {
    $insertUser->execute([
        $id,
        $login,
        password_hash($accountPasswords[$login], PASSWORD_DEFAULT),
        $level,
        $name,
        null,
        null,
        $position !== '' ? $position : null,
        $phone,
        $email,
    ]);
}

$clientIds = [3];
$clientHash = password_hash($passwords['client'] ?? $password, PASSWORD_DEFAULT);
$insertClient = $pdo->prepare(
    'INSERT INTO users (ID, LOGIN, PASSWORD, LEVEL, FULL_NAME, COMPANY, INN, DOLGNOST, PHONE, EMAIL,
                        STATUS, ACTIVE, reg_state)
     VALUES (?, ?, ?, 5, ?, ?, ?, ?, ?, ?, \'Y\', \'Y\', \'active\')'
);

foreach ($clientNames as $index => [$name, $company, $inn]) {
    $id = 6 + $index;
    $clientIds[] = $id;

    $insertClient->execute([
        $id,
        'client' . ($index + 1),
        $clientHash,
        $name,
        $company,
        $inn,
        pick(['Директор', 'Руководитель отдела', 'Специалист по закупкам', 'Менеджер']),
        '+790011122' . str_pad((string) ($index + 10), 2, '0', STR_PAD_LEFT),
        'client' . ($index + 1) . '@example.test',
    ]);
}

$managerPool = [2, 5];

$bindings = [
    3 => 2,
    6 => 2,
    7 => 5,
    8 => 5,
    9 => 2,
    10 => 5,
    11 => 2,
    12 => null,
    13 => 5,
];

$insertBinding = $pdo->prepare(
    'INSERT INTO client_managers (client_id, manager_id, assigned_by) VALUES (?, ?, ?)'
);

foreach ($bindings as $clientId => $managerId) {
    if ($managerId !== null) {
        $insertBinding->execute([$clientId, $managerId, 1]);
    }
}

echo "Смартфоны и остатки...\n";

$testWarehouseNames = [
    'Склад Тест-Центральный',
    'Склад Тест-Северный',
    'Склад Тест-Южный',
    'Склад Тест-Западный',
    'Склад Тест-Восточный',
    'Склад Тест-Возвраты',
    'Склад Тест-Логистический',
    'Склад Тест-Резервный',
    'Склад Тест-Сервисный',
    'Склад Тест-Северо-Западный',
    'Склад Тест-Юго-Восточный',
    'Склад Тест-Выставочный',
    'Склад Тест-Транзитный',
];

$stocks = $pdo->query('SELECT ID FROM stocks ORDER BY SORT, ID')->fetchAll() ?: [];
$renameStock = $pdo->prepare('UPDATE stocks SET NAME = ?, NAME_1C = ? WHERE ID = ?');
$insertStock = $pdo->prepare(
    'INSERT INTO stocks (ACTIVE, SID, NAME, NAME_1C, SORT, LEVEL, STATUS)
     VALUES (\'Y\', ?, ?, ?, ?, \'{90},{50},{10},{5},\', \'Y\')'
);

$existingStockIds = array_map(static fn (array $row): int => (int) $row['ID'], $stocks);

foreach ($testWarehouseNames as $index => $name) {
    if (isset($existingStockIds[$index])) {
        $renameStock->execute([$name, $name, $existingStockIds[$index]]);
        continue;
    }

    $insertStock->execute([uuid(), $name, $name, $index + 1]);
}

foreach ($existingStockIds as $index => $id) {
    if ($index < count($testWarehouseNames)) {
        continue;
    }

    $name = 'Склад Тест-' . ($index + 1);
    $renameStock->execute([$name, $name, $id]);
}

$warehouses = $pdo->query('SELECT ID, NAME, SID FROM stocks ORDER BY SORT, ID')->fetchAll() ?: [];

$phones = [
    ['Смартфон Apple iPhone 15 128GB', 'Apple iPhone 15, 128 ГБ, чёрный, OLED 6.1", камера 48 МП, аккумулятор 3349 мА·ч'],
    ['Смартфон Apple iPhone 15 256GB', 'Apple iPhone 15, 256 ГБ, синий, OLED 6.1", USB-C, Face ID'],
    ['Смартфон Apple iPhone 15 Pro 256GB', 'Apple iPhone 15 Pro, 256 ГБ, титан, OLED 6.1" 120 Гц, A17 Pro'],
    ['Смартфон Apple iPhone 16 128GB', 'Apple iPhone 16, 128 ГБ, белый, OLED 6.1", кнопка управления камерой'],
    ['Смартфон Apple iPhone 16 Pro 256GB', 'Apple iPhone 16 Pro, 256 ГБ, титан, OLED 6.3" 120 Гц, A18 Pro'],
    ['Смартфон Apple iPhone 14 128GB', 'Apple iPhone 14, 128 ГБ, фиолетовый, OLED 6.1", камера 12 МП'],
    ['Смартфон Samsung Galaxy S24 256GB', 'Samsung Galaxy S24, 256 ГБ, графит, AMOLED 6.2" 120 Гц, 8 ГБ ОЗУ'],
    ['Смартфон Samsung Galaxy S24 Ultra 512GB', 'Samsung Galaxy S24 Ultra, 512 ГБ, титан, AMOLED 6.8", S Pen'],
    ['Смартфон Samsung Galaxy A55 128GB', 'Samsung Galaxy A55, 128 ГБ, синий, Super AMOLED 6.6" 120 Гц'],
    ['Смартфон Samsung Galaxy A35 128GB', 'Samsung Galaxy A35, 128 ГБ, чёрный, Super AMOLED 6.6", камера 50 МП'],
    ['Смартфон Xiaomi Redmi Note 13 256GB', 'Xiaomi Redmi Note 13, 256 ГБ, чёрный, AMOLED 6.67", 8 ГБ ОЗУ'],
    ['Смартфон Xiaomi Redmi Note 13 Pro 256GB', 'Xiaomi Redmi Note 13 Pro, 256 ГБ, синий, AMOLED 6.67" 120 Гц, 200 МП'],
    ['Смартфон Xiaomi 14T 256GB', 'Xiaomi 14T, 256 ГБ, чёрный, AMOLED 6.67" 144 Гц, Dimensity 8300'],
    ['Смартфон Xiaomi Redmi 13C 128GB', 'Xiaomi Redmi 13C, 128 ГБ, зелёный, IPS 6.74", 5000 мА·ч'],
    ['Смартфон Google Pixel 9 128GB', 'Google Pixel 9, 128 ГБ, обсидиан, OLED 6.3" 120 Гц, Tensor G4'],
    ['Смартфон Google Pixel 8a 128GB', 'Google Pixel 8a, 128 ГБ, голубой, OLED 6.1" 120 Гц, 8 ГБ ОЗУ'],
    ['Смартфон Honor Magic6 Lite 256GB', 'Honor Magic6 Lite, 256 ГБ, чёрный, AMOLED 6.78" 120 Гц'],
    ['Смартфон Honor X8b 128GB', 'Honor X8b, 128 ГБ, серебристый, AMOLED 6.7", 108 МП'],
    ['Смартфон Honor 90 256GB', 'Honor 90, 256 ГБ, изумрудный, AMOLED 6.7" 120 Гц, 200 МП'],
    ['Смартфон Huawei nova 12i 128GB', 'Huawei nova 12i, 128 ГБ, чёрный, IPS 6.7" 90 Гц, 5000 мА·ч'],
    ['Смартфон Huawei Pura 70 256GB', 'Huawei Pura 70, 256 ГБ, белый, OLED 6.6" 120 Гц, камера 50 МП'],
    ['Смартфон Realme 12 Pro 256GB', 'Realme 12 Pro, 256 ГБ, синий, AMOLED 6.7" 120 Гц, 8 ГБ ОЗУ'],
    ['Смартфон Realme C67 128GB', 'Realme C67, 128 ГБ, зелёный, IPS 6.72" 90 Гц, 5000 мА·ч'],
    ['Смартфон OPPO Reno11 F 256GB', 'OPPO Reno11 F, 256 ГБ, зелёный, AMOLED 6.7" 120 Гц, 64 МП'],
    ['Смартфон OPPO A79 128GB', 'OPPO A79, 128 ГБ, чёрный, AMOLED 6.72" 90 Гц, 5000 мА·ч'],
    ['Смартфон vivo V30 256GB', 'vivo V30, 256 ГБ, синий, AMOLED 6.78" 120 Гц, 12 ГБ ОЗУ'],
    ['Смартфон vivo Y28 128GB', 'vivo Y28, 128 ГБ, оранжевый, IPS 6.68" 90 Гц, 6000 мА·ч'],
    ['Смартфон Tecno Camon 20 256GB', 'Tecno Camon 20, 256 ГБ, чёрный, AMOLED 6.67", камера 64 МП'],
    ['Смартфон Tecno Spark 20 128GB', 'Tecno Spark 20, 128 ГБ, голубой, IPS 6.6" 90 Гц, 5000 мА·ч'],
    ['Смартфон Infinix Note 40 256GB', 'Infinix Note 40, 256 ГБ, титан, AMOLED 6.78" 120 Гц, 45 Вт'],
    ['Смартфон Infinix Hot 40i 128GB', 'Infinix Hot 40i, 128 ГБ, зелёный, IPS 6.56" 90 Гц, 5000 мА·ч'],
    ['Смартфон Nokia G42 128GB', 'Nokia G42, 128 ГБ, фиолетовый, IPS 6.56" 90 Гц, ремонтопригодный'],
    ['Смартфон Motorola Moto G84 256GB', 'Motorola Moto G84, 256 ГБ, синий, pOLED 6.55" 120 Гц, 12 ГБ ОЗУ'],
    ['Смартфон Motorola Edge 50 256GB', 'Motorola Edge 50, 256 ГБ, зелёный, pOLED 6.7" 120 Гц, IP68'],
    ['Смартфон Nothing Phone (2a) 128GB', 'Nothing Phone (2a), 128 ГБ, чёрный, AMOLED 6.7" 120 Гц, Glyph'],
    ['Смартфон Asus ROG Phone 8 256GB', 'Asus ROG Phone 8, 256 ГБ, чёрный, AMOLED 6.78" 165 Гц, игровой'],
];

$insertNomenclature = $pdo->prepare(
    'INSERT INTO nomenclature (SID, NAME, NAME_1C, UNIT, DESCRIPTION) VALUES (?, ?, ?, \'шт\', ?)'
);
$insertLevel = $pdo->prepare(
    'INSERT INTO stock_levels (SID, ACTUAL_DATE, STOCK, STOCK_SID, NAME, NAME_SID, UNIT, QUANTITY)
     VALUES (?, ?, ?, ?, ?, ?, \'шт\', ?)'
);

$phoneSids = [];
$actualDate = date('Y-m-d');

foreach ($phones as [$name, $description]) {
    $sid = uuid();
    $phoneSids[$name] = $sid;
    $insertNomenclature->execute([$sid, $name, $name, $description]);
}

$levelCount = 0;

foreach ($warehouses as $warehouse) {
    foreach ($phones as [$name]) {
        $roll = mt_rand(1, 100);

        if ($roll > 85) {
            continue;
        }

        $quantity = $roll <= 20 ? 0 : mt_rand(1, 40);

        $insertLevel->execute([
            uuid(),
            $actualDate,
            (string) $warehouse['NAME'],
            (string) $warehouse['SID'],
            $name,
            $phoneSids[$name],
            $quantity,
        ]);

        $levelCount++;
    }
}

echo "Заявки...\n";

$statuses = $pdo->query('SELECT code FROM request_statuses WHERE is_active = 1 ORDER BY sort')->fetchAll(PDO::FETCH_COLUMN) ?: [];
$weightedStatuses = [
    'new' => 30,
    'accepted' => 22,
    'in_progress' => 52,
    'waiting' => 22,
    'resolved' => 26,
    'closed' => 58,
    'canceled' => 10,
];

$statusPool = [];

foreach ($weightedStatuses as $code => $weight) {
    if (in_array($code, $statuses, true)) {
        $statusPool = array_merge($statusPool, array_fill(0, $weight, $code));
    }
}

$subjects = [
    'Корпоративные смартфоны для отдела продаж',
    'Замена парка телефонов',
    'Поставка смартфонов для новых сотрудников',
    'Закупка телефонов для службы доставки',
    'Смартфоны для выездных инженеров',
    'Обновление устройств руководства',
    'Телефоны для колл-центра',
    'Партия смартфонов для филиала',
    'Доукомплектация складской команды',
    'Смартфоны с усиленной батареей для курьеров',
];

$bodies = [
    'Нужны телефоны до конца месяца, счёт и закрывающие документы обязательны.',
    'Просим подобрать модели в наличии, рассмотрим аналоги.',
    'Доставка на наш склад, контактное лицо на месте.',
    'Требуется гарантия не менее 12 месяцев и официальная поставка.',
    'Срочно: текущие устройства выходят из строя.',
    'Заказ для нового подразделения, оплата по счёту.',
];

$insertRequest = $pdo->prepare(
    'INSERT INTO requests (number, subject, body, client_id, manager_id, status_id, priority, source,
                           due_at, first_response_at, resolved_at, closed_at, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, \'cabinet\', ?, ?, ?, ?, ?, ?)'
);
$insertItem = $pdo->prepare(
    'INSERT INTO request_items (request_id, warehouse_id, warehouse_name, name, unit, description, quantity, created_at)
     VALUES (?, ?, ?, ?, \'шт\', ?, ?, ?)'
);
$insertHistory = $pdo->prepare(
    'INSERT INTO request_history (request_id, user_id, from_status_id, to_status_id, comment, created_at)
     VALUES (?, ?, ?, ?, ?, ?)'
);
$insertAssignment = $pdo->prepare(
    'INSERT INTO request_assignments (request_id, from_manager_id, to_manager_id, user_id, comment, created_at)
     VALUES (?, NULL, ?, ?, ?, ?)'
);
$insertComment = $pdo->prepare(
    'INSERT INTO request_comments (request_id, user_id, body, is_internal, created_at)
     VALUES (?, ?, ?, ?, ?)'
);
$insertActivity = $pdo->prepare(
    'INSERT INTO request_activities (request_id, user_id, type_code, title, body, created_at)
     VALUES (?, ?, ?, ?, ?, ?)'
);

$requestCount = 240;
$planned = [];

for ($i = 0; $i < $requestCount; $i++) {
    $created = $now - mt_rand(3600, 90 * 86400);
    $status = pick($statusPool);
    $clientId = pick($clientIds);
    $managerId = pick($managerPool);

    if (mt_rand(1, 100) <= 12) {
        $managerId = null;
    }

    $planned[] = [
        'created' => $created,
        'status' => $status,
        'client_id' => $clientId,
        'manager_id' => $managerId,
        'subject' => pick($subjects),
        'body' => pick($bodies),
        'priority' => pick([1, 2, 2, 2, 3]),
    ];
}

usort($planned, static fn (array $a, array $b): int => $a['created'] <=> $b['created']);

$poolTarget = 10;

foreach ($planned as $index => $plan) {
    if ($index >= $poolTarget) {
        break;
    }

    $planned[$index]['status'] = 'new';
    $planned[$index]['manager_id'] = null;
}

$pdo->beginTransaction();

$sequence = 0;

foreach ($planned as $plan) {
    $sequence++;
    $created = $plan['created'];
    $status = $plan['status'];
    $isFinal = in_array($status, ['closed', 'canceled'], true);
    $dueAt = $created + 24 * 3600;

    $firstResponse = $status === 'new' ? null : $created + mt_rand(600, 6 * 3600);
    $resolved = in_array($status, ['resolved', 'closed'], true) ? $created + mt_rand(4 * 3600, 30 * 3600) : null;
    $closed = $status === 'closed' ? $created + mt_rand(6 * 3600, 72 * 3600) : null;
    $updated = $closed ?? $resolved ?? $firstResponse ?? $created;

    $insertRequest->execute([
        sprintf('REQ-%s-%06d', date('Y', $created), $sequence),
        $plan['subject'],
        $plan['body'],
        $plan['client_id'],
        $plan['manager_id'],
        $status,
        $plan['priority'],
        stamp($dueAt),
        $firstResponse !== null ? stamp($firstResponse) : null,
        $resolved !== null ? stamp($resolved) : null,
        $closed !== null ? stamp($closed) : null,
        stamp($created),
        stamp(max($created, $updated)),
    ]);

    $requestId = (int) $pdo->lastInsertId();

    $itemCount = mt_rand(1, 4);
    $usedPhones = [];

    for ($j = 0; $j < $itemCount; $j++) {
        $phone = pick($phones);

        if (isset($usedPhones[$phone[0]])) {
            continue;
        }

        $usedPhones[$phone[0]] = true;

        $warehouse = $warehouses === [] ? null : pick($warehouses);

        $insertItem->execute([
            $requestId,
            $warehouse !== null ? (int) $warehouse['ID'] : null,
            $warehouse !== null ? (string) $warehouse['NAME'] : null,
            $phone[0],
            $phone[1],
            mt_rand(1, 6),
            stamp($created),
        ]);
    }

    $insertHistory->execute([
        $requestId,
        $plan['client_id'],
        null,
        'new',
        'Заявка создана',
        stamp($created),
    ]);

    if ($plan['manager_id'] !== null) {
        $insertAssignment->execute([
            $requestId,
            $plan['manager_id'],
            $plan['manager_id'],
            'Назначена в работу',
            stamp($created + mt_rand(300, 3600)),
        ]);
    }

    if ($status !== 'new') {
        $insertHistory->execute([
            $requestId,
            $plan['manager_id'] ?? 2,
            'new',
            $status,
            $isFinal ? 'Заявка завершена' : 'Переведена в текущий статус',
            stamp($created + mt_rand(1800, 8 * 3600)),
        ]);
    }

    if (mt_rand(1, 100) <= 15) {
        $insertComment->execute([
            $requestId,
            $plan['manager_id'] ?? 2,
            pick([
                'Клиент подтвердил состав заказа по телефону.',
                'Уточнили сроки поставки у склада.',
                'Ждём подтверждения оплаты.',
                'Предложили аналог, клиент думает.',
            ]),
            mt_rand(1, 100) <= 40 ? 1 : 0,
            stamp($created + mt_rand(3600, 20 * 3600)),
        ]);
    }

    if (mt_rand(1, 100) <= 12) {
        $activityByClient = mt_rand(1, 100) <= 30;
        $type = $activityByClient
            ? pick([['question', 'Вопрос по заявке'], ['invoice_paid', 'Оплатил счёт'], ['goods_received', 'Получил товар']])
            : pick([['call', 'Звонок клиенту'], ['email_sent', 'Отправил письмо'], ['supply_started', 'Передали в поставку']]);

        $insertActivity->execute([
            $requestId,
            $activityByClient ? $plan['client_id'] : ($plan['manager_id'] ?? 2),
            $type[0],
            $type[1],
            null,
            stamp($created + mt_rand(3600, 30 * 3600)),
        ]);
    }
}

$pdo->commit();

$pdo->exec(
    'UPDATE request_items ri
     JOIN stocks s ON s.ID = ri.warehouse_id
     JOIN stock_levels l ON l.STOCK_SID = s.SID AND l.NAME = ri.name
     SET ri.stock_level_id = l.ID
     WHERE ri.stock_level_id IS NULL'
);

echo "Чаты и черновики...\n";

$insertThread = $pdo->prepare(
    'INSERT INTO chat_threads (client_id, manager_id, last_message_at, created_at) VALUES (?, ?, ?, ?)'
);
$insertMessage = $pdo->prepare(
    'INSERT INTO chat_messages (thread_id, request_id, user_id, body, created_at) VALUES (?, ?, ?, ?, ?)'
);

$clientRequests = [];

foreach ($planned as $index => $plan) {
    $clientRequests[$plan['client_id']][] = $index + 1;
}

$clientPhrases = [
    'Добрый день! Подскажите по заказу.',
    'Когда ожидать поставку?',
    'Можно добавить ещё пару устройств?',
    'Спасибо, всё получили!',
    'Пришлите, пожалуйста, счёт.',
];

$managerPhrases = [
    'Добрый день! Заявка в работе, уточняю сроки.',
    'Поставка ожидается в течение трёх рабочих дней.',
    'Да, конечно, добавлю позиции в заявку.',
    'Рады помочь! Обращайтесь.',
    'Счёт направил на вашу почту.',
];

$chatCount = 0;

foreach ($bindings as $clientId => $managerId) {
    if ($managerId === null) {
        continue;
    }

    $created = $now - mt_rand(10 * 86400, 80 * 86400);
    $insertThread->execute([$clientId, $managerId, stamp($created), stamp($created)]);
    $threadId = (int) $pdo->lastInsertId();
    $chatCount++;

    $messages = mt_rand(2, 6);
    $lastAt = $created;

    for ($m = 0; $m < $messages; $m++) {
        $lastAt += mt_rand(3600, 3 * 86400);
        $fromClient = $m % 2 === 0;
        $requestId = null;

        if (mt_rand(1, 100) <= 30 && isset($clientRequests[$clientId]) && $clientRequests[$clientId] !== []) {
            $requestId = pick($clientRequests[$clientId]);
        }

        $insertMessage->execute([
            $threadId,
            $requestId,
            $fromClient ? $clientId : $managerId,
            $fromClient ? pick($clientPhrases) : pick($managerPhrases),
            stamp($lastAt),
        ]);
    }

    $stmt = $pdo->prepare('UPDATE chat_threads SET last_message_at = ? WHERE ID = ?');
    $stmt->execute([stamp($lastAt), $threadId]);
}

$drafts = [
    [2, 3, 'Черновик: смартфоны для склада', 'Собираю состав, отправлю после согласования.'],
    [2, 6, 'Черновик: замена телефонов', ''],
    [3, null, 'Черновик: нужны смартфоны', 'Уточняю модели.'],
];

$insertDraft = $pdo->prepare(
    'INSERT INTO request_drafts (user_id, client_id, subject, body, priority, items, created_at, updated_at)
     VALUES (?, ?, ?, ?, 2, ?, ?, ?)'
);

$draftItems = json_encode([
    [
        'warehouse_id' => $warehouses[0]['ID'] ?? null,
        'warehouse_name' => $warehouses[0]['NAME'] ?? '',
        'name' => $phones[0][0],
        'unit' => 'шт',
        'description' => $phones[0][1],
        'quantity' => 2,
    ],
], JSON_UNESCAPED_UNICODE);

foreach ($drafts as $index => [$userId, $clientId, $subject, $body]) {
    $insertDraft->execute([
        $userId,
        $clientId,
        $subject,
        $body,
        $index === 0 ? $draftItems : null,
        stamp($now - 2 * 86400),
        stamp($now - 86400),
    ]);
}

$pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES (\'sales.enabled\', \'1\')
               ON DUPLICATE KEY UPDATE `value` = \'1\'')->execute();
$pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES (\'stock.reserve_enabled\', \'0\')
               ON DUPLICATE KEY UPDATE `value` = \'0\'')->execute();
$pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES (\'stock.allow_zero\', \'0\')
               ON DUPLICATE KEY UPDATE `value` = \'0\'')->execute();

echo "Уведомления...\n";

$insertNotification = $pdo->prepare(
    'INSERT INTO notifications (user_id, type, title, body, request_id, read_at, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);

$statusTitles = [];

foreach ($pdo->query('SELECT code, title FROM request_statuses') as $row) {
    $statusTitles[(string) $row['code']] = (string) $row['title'];
}

$requestRows = $pdo->query(
    'SELECT ID, number, subject, client_id, manager_id, status_id, created_at FROM requests ORDER BY ID'
)->fetchAll();

$notificationCount = 0;

foreach ($requestRows as $row) {
    $requestId = (int) $row['ID'];
    $clientId = (int) $row['client_id'];
    $managerId = $row['manager_id'] !== null ? (int) $row['manager_id'] : null;
    $number = (string) $row['number'];
    $status = $statusTitles[(string) $row['status_id']] ?? (string) $row['status_id'];
    $base = strtotime((string) $row['created_at']);

    if (mt_rand(1, 100) <= 40) {
        $at = $base + mt_rand(3600, 5 * 86400);
        $insertNotification->execute([
            $clientId,
            'request.status',
            "Заявка {$number}: статус «{$status}»",
            'Статус вашей заявки обновлён менеджером.',
            $requestId,
            $at < $now - 7 * 86400 || mt_rand(1, 100) <= 40 ? stamp($at + 3600) : null,
            stamp($at),
        ]);
        $notificationCount++;
    }

    if (mt_rand(1, 100) <= 20) {
        $at = $base + mt_rand(3600, 6 * 86400);
        $insertNotification->execute([
            $clientId,
            'request.comment',
            "Новый комментарий к заявке {$number}",
            'Менеджер оставил комментарий по вашей заявке.',
            $requestId,
            mt_rand(1, 100) <= 50 ? stamp($at + 7200) : null,
            stamp($at),
        ]);
        $notificationCount++;
    }

    if ($managerId !== null && mt_rand(1, 100) <= 15) {
        $at = $base + mt_rand(600, 2 * 86400);
        $insertNotification->execute([
            $managerId,
            'request.new',
            "Новая заявка {$number}",
            (string) $row['subject'],
            $requestId,
            mt_rand(1, 100) <= 60 ? stamp($at + 3600) : null,
            stamp($at),
        ]);
        $notificationCount++;
    }
}

$threadRows = $pdo->query(
    'SELECT client_id, last_message_at FROM chat_threads ORDER BY ID'
)->fetchAll();

foreach ($threadRows as $row) {
    if (mt_rand(1, 100) > 50) {
        continue;
    }

    $at = strtotime((string) $row['last_message_at']) + mt_rand(600, 2 * 86400);
    $insertNotification->execute([
        (int) $row['client_id'],
        'chat.message',
        'Новое сообщение от менеджера',
        'Вам ответили в чате.',
        null,
        mt_rand(1, 100) <= 50 ? stamp($at + 1800) : null,
        stamp($at),
    ]);
    $notificationCount++;
}

echo "Активность клиентов на складах...\n";

$insertView = $pdo->prepare(
    'INSERT INTO view_log (entity_type, entity_id, user_id, action, meta, result_count, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);

$searchQueries = [
    'iPhone 15', 'iPhone 16', 'Samsung Galaxy S24', 'Samsung A55', 'Redmi Note 13',
    'Pixel 9', 'Honor', 'смартфон', 'телефон для курьера', 'iPhone 17',
    'защитное стекло', 'чехол', 'планшет', 'кнопочный телефон', 'Powerbank',
];

$viewCount = 0;

foreach ($clientIds as $clientId) {
    $searches = mt_rand(8, 22);

    for ($i = 0; $i < $searches; $i++) {
        $warehouse = $warehouses === [] ? null : pick($warehouses);
        $zero = mt_rand(1, 100) <= 30;

        $insertView->execute([
            'stocks',
            $warehouse !== null ? (int) $warehouse['ID'] : null,
            $clientId,
            'search',
            pick($searchQueries),
            $zero ? 0 : mt_rand(1, 15),
            stamp($now - mt_rand(3600, 60 * 86400)),
        ]);

        $viewCount++;
    }

    $lists = mt_rand(4, 12);

    for ($i = 0; $i < $lists; $i++) {
        $warehouse = $warehouses === [] ? null : pick($warehouses);

        $insertView->execute([
            'stocks',
            $warehouse !== null ? (int) $warehouse['ID'] : null,
            $clientId,
            'list',
            null,
            null,
            stamp($now - mt_rand(3600, 60 * 86400)),
        ]);

        $viewCount++;
    }

    if (mt_rand(1, 100) <= 40) {
        $warehouse = $warehouses === [] ? null : pick($warehouses);

        $insertView->execute([
            'stocks',
            $warehouse !== null ? (int) $warehouse['ID'] : null,
            $clientId,
            'export',
            null,
            null,
            stamp($now - mt_rand(3600, 30 * 86400)),
        ]);

        $viewCount++;
    }
}

$counts = [
    'пользователей' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'клиентов' => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE LEVEL = 5')->fetchColumn(),
    'номенклатуры' => (int) $pdo->query('SELECT COUNT(*) FROM nomenclature')->fetchColumn(),
    'складов' => (int) $pdo->query('SELECT COUNT(*) FROM stocks')->fetchColumn(),
    'остатков' => (int) $pdo->query('SELECT COUNT(*) FROM stock_levels')->fetchColumn(),
    'заявок' => (int) $pdo->query('SELECT COUNT(*) FROM requests')->fetchColumn(),
    'позиций заявок' => (int) $pdo->query('SELECT COUNT(*) FROM request_items')->fetchColumn(),
    'сообщений чата' => (int) $pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn(),
    'черновиков' => (int) $pdo->query('SELECT COUNT(*) FROM request_drafts')->fetchColumn(),
    'уведомлений' => (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn(),
    'событий складов' => $viewCount,
];

echo "\nГотово:\n";

foreach ($counts as $label => $value) {
    echo "  $label: $value\n";
}

echo "\nУчётные записи:\n";

foreach ($accounts as [$id, $login, $level]) {
    echo "  $login (уровень $level)\n";
}

foreach ($clientNames as $index => [, $name]) {
    echo '  client' . ($index + 1) . " (клиент: $name)\n";
}

if ($passwords === []) {
    echo "\nПароль (общий для всех учёток): $password\n";
} else {
    echo "\nПароли взяты из файла --passwords-file (общий: $password)\n";
}

echo "Все активные сессии сброшены — войдите заново.\n";
