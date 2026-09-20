<?php

function sf__User_Verification__v1() {
    global $dbh, $req;

    if (!$dbh || !isset($req['ut'], $req['us'])) {
        return null;
    }

    $sql = "SELECT LEVEL, SID FROM users
             WHERE TOKEN_TIME = ? AND TOKEN_USER = ? AND (LEVEL = 90 OR LEVEL = 50 OR LEVEL = 10 OR LEVEL = 5)
                 AND STATUS = 'Y' AND ACTIVE = 'Y' AND SID != '' AND ID != ''
                 ORDER BY ID ASC LIMIT 1";

    $stmt = $dbh->prepare($sql);
    $stmt->bind_param("ss", $req['ut'], $req['us']);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    return $result && isset($result['LEVEL']) ? $result : null;
}





// получаем склады доступные пользователю и БЕЗ sid последнего импорта для склада
function sf__User_Level_Stock__v1($USER) {
    global $dbh;

    if (!$dbh || empty($USER['SID']) || empty($USER['LEVEL'])) {
        return null;
    }

    $date = date('Y-m-d');
    // Объединенный SQL-запрос с JOIN
    $sql = "SELECT uls.STOCK_SID, s.NAME
            FROM user_level_stock uls
            INNER JOIN stocks s ON uls.STOCK_SID = s.SID
            /*INNER JOIN last_stock_update lsu ON s.LAST_STOCK_UPDATE_SID = lsu.SID*/
            WHERE uls.USER_SID = ?
              AND uls.SID != ''
              AND uls.STOCK_SID != ''
              AND uls.USER_SID != ''
              AND uls.ACTIVE = 'Y'
              AND uls.ID != ''
              AND s.LEVEL LIKE CONCAT('%{', ?, '},%')
              AND s.SID != ''
              AND s.NAME != ''
              AND s.LAST_STOCK_UPDATE_SID != ''
              AND s.ACTIVE = 'Y'
              AND s.ID != ''
              /*AND lsu.ACTIVE = 'Y'
              AND lsu.ID != ''*/
            ORDER BY s.SID ASC
            LIMIT 9999";

    $stmt = $dbh->prepare($sql);
    $stmt->bind_param("si", $USER['SID'], $USER['LEVEL']);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return $result ?: null;
}






// получаем номенклатуру
function sf__Nomenklatura_List__v1() {
    global $dbh, $req;

    if (!$dbh) {
        return null;
    }

    $sortMap = [
        'a_1' => 'NAME ASC',
        'a_2' => 'NAME DESC',
        'b_1' => 'UNIT ASC',
        'b_2' => 'UNIT DESC',
    ];

    $SORT = $sortMap[$req['sort']] ?? 'NAME ASC';

    $LIMIT = !empty($req['limit']) && is_numeric($req['limit'] * 1) ? $req['limit'] : 9999;

    $sql = "SELECT NAME, UNIT, SID 
            FROM nomenclature
            WHERE NAME != '' 
                AND UNIT != '' 
                AND ACTIVE = 'Y' 
                AND SID != '' 
                AND ID != ''";

    if (!empty($req['text'])) {
        $sql .= " AND NAME LIKE CONCAT('%', ?, '%')";
    }

    $sql .= " ORDER BY $SORT LIMIT $LIMIT";

    $stmt = $dbh->prepare($sql);

    if (!empty($req['text'])) {
        $stmt->bind_param('s', $req['text']);
    }

    $stmt->execute();
    $temp = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (!$temp) {
        return null;
    }

    $result = [];

    foreach ($temp as $row) {
        $result[$row['NAME']]['SID'] = $row['SID']; // key = SID
        $result[$row['NAME']]['NAME'] = $row['NAME']; // key = SID
        $result[$row['NAME']]['UNIT'] = $row['UNIT']; // key = SID
    }

    return $result;
}






// получаем список товаров на складах и количество
function sf__Nomenklatura_Stock_List__v1($NOMENKLATURA, $USER_STOCK_LIST) {
    global $dbh, $req;

    if (!$dbh || empty($NOMENKLATURA) || empty($USER_STOCK_LIST)) {
        return null;
    }

    $sortMap = [
        'a_1' => 'NAME ASC',
        'a_2' => 'NAME DESC',
        'b_1' => 'UNIT ASC',
        'b_2' => 'UNIT DESC',
    ];

    $SORT = $sortMap[$req['sort']] ?? 'NAME ASC';

    $LIMIT = !empty($req['limit']) && is_numeric($req['limit'] * 1) ? $req['limit'] : 9999;
    
    $placeholders = implode(',', array_fill(0, count($NOMENKLATURA), '?'));
    $placeholders_stock = implode(',', array_fill(0, count($USER_STOCK_LIST), '?'));

    $temp_sql = "AND NAME_SID IN ($placeholders)";
    $temp_sql_stock = "AND STOCK_SID IN ($placeholders_stock)";

    $date = date('Y-m-d');

    $sql = "SELECT NAME, NAME_SID, UNIT, STOCK, STOCK_SID, QUANTITY, ACTUAL_DATE
            FROM stock_levels
            WHERE ACTIVE = 'Y'
            $temp_sql
            $temp_sql_stock
            AND ACTUAL_DATE <= ?
            ORDER BY STOCK_SID ASC, $SORT
            LIMIT $LIMIT";

    $stmt = $dbh->prepare($sql);

    $params_nomenklatura = array_column($NOMENKLATURA, 'SID'); // Получаем массив SID из $NOMENKLATURA
    $params_stock = array_column($USER_STOCK_LIST, 'STOCK_SID'); // Получаем массив SID из $USER_STOCK_LIST

    $params = array_merge($params_nomenklatura, $params_stock, [$date]);

    $types = str_repeat('s', count($params));     // Формируем строку типов для bind_param

    $stmt->bind_param($types, ...$params);

    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (!$result) {
        return null;
    }

    $output  = [];

    $STOCK_TEMP = [];

    foreach ($result as $row) {

        $output[$row['NAME_SID']]['stock'][$row['STOCK_SID']] = $row;
        $output[$row['NAME_SID']]['NAME'] = $row['NAME'];
        $output[$row['NAME_SID']]['UNIT'] = $row['UNIT'];

    }

    $output = array_values($output);

    //for ($i=0; $i < count($output); $i++) { 
        //$output[$i]['stock'] = array_values($output[$i]['stock']);
    //}

    return $output ?? null;
}





// добавляем информацию об активности пользователя
function sf__Stat_Add__v1($user_id) {
    global $dbh, $req;

    if (!isset($dbh, $user_id)) {
        return false;
    }

    $A = '50';
    $B = 'Просмотр списка товаров';
    $C = '';


    if (!empty($req['text'])) {
        $A = '51';
        $B = 'Поиск товаров';
        $C .= ' Поисковый запрос: ' . $req['text'] . '. ';
    }

    if (!empty($req['sort'])) {
        $C .= ' Сортировка ' . $req['sort'] . '. ';
    }

    $A = trim($A);
    $B = trim($B);
    $C = trim($C);

    $ip = $_SERVER['REMOTE_ADDR'];
    $ua = $_SERVER['HTTP_USER_AGENT'];

    $sql = "INSERT INTO users_stat (`USER_SID`, `NAME_NUM`, `NAME`, `TEXT`, `IP`, `USER_AGENT`)
             VALUES (?, ?, ?, ?, ?, ?)";

    $stmt = $dbh->prepare($sql);
    
    $DATE = $req['date'] ?? date('Y-m-d');

    $stmt->bind_param(
        'ssssss',
        $user_id,
        $A,
        $B,
        $C,
        $ip,
        $ua
    );

    if (!$stmt->execute()) {
        return false;
    }

    return true;
}








// верификация
$USER = sf__User_Verification__v1();

if (!empty($USER) AND !empty($USER['LEVEL'])) {
    $res['level'] = $USER['LEVEL'];
    //$res['USER'] = $USER;

    $STAT = sf__Stat_Add__v1($USER['SID']);

    // доступные склады пользователю
    $USER_STOCK_LIST = sf__User_Level_Stock__v1($USER);

    if (!empty($USER_STOCK_LIST)) {
        $res['res']['param'] = $USER_STOCK_LIST;

        // получаем список номенклатуры
        $NOMENKLATURA = sf__Nomenklatura_List__v1();
        //$res['NOMENKLATURA'] = $NOMENKLATURA;

        if (!empty($NOMENKLATURA)) {
            // формируем массив остатков
            $STOCK_NOMENKLATURA = sf__Nomenklatura_Stock_List__v1($NOMENKLATURA, $USER_STOCK_LIST);

            if (!empty($STOCK_NOMENKLATURA)) {
                $res['res']['stock'] = $STOCK_NOMENKLATURA;
                $res['res']['date'] = date('Y-m-d');

            }

        }

    }

}


?>