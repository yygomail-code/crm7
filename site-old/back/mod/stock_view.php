<?php

function sf__User_Verification__v1() {
    global $dbh, $req;

    if (!$dbh || !isset($req['ut'], $req['us'])) {
        return null;
    }

    $sql = "SELECT LEVEL, SID FROM users
             WHERE TOKEN_TIME = ? AND TOKEN_USER = ? AND (LEVEL = 90 OR LEVEL = 50 OR LEVEL = 10 OR LEVEL = 5)
                 AND ACTIVE = 'Y' AND SID != '' AND ID != ''
                 ORDER BY ID ASC LIMIT 1";

    $stmt = $dbh->prepare($sql);
    $stmt->bind_param("ss", $req['ut'], $req['us']);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    return $result && isset($result['LEVEL']) ? $result : null;
}





function sf__List_Stock__v1() {
    global $dbh, $req;

    if (!$dbh) {
        return null;
    }

    if (!empty($req['sort'])) {
        $sortMap = [
            'a1' => 'NAME ASC',
            'a2' => 'NAME DESC',
            'b1' => 'NAME_1C ASC',
            'b2' => 'NAME_1C DESC',
            'c1' => 'LAST_ACTIVITY_DATE ASC',
            'c2' => 'LAST_ACTIVITY_DATE DESC',
            'c1' => 'STATUS ASC',
            'c2' => 'STATUS DESC'
        ];
    
        $SORT = $sortMap[$req['sort']] ?? 'NAME ASC';
    }
    else {
        $SORT = 'NAME ASC';
    }

    $sql = "SELECT SID as id, NAME as name, NAME_1C as name_1c, LEVEL as level, LAST_ACTIVITY_DATE as active , STATUS as status 
            FROM stocks
            WHERE ACTIVE = 'Y' AND SID != '' AND ID != '' ";

    if (!empty($req['text'])) {
        $sql .= "AND NAME LIKE CONCAT('%', ?, '%') ";
    }

    $sql .= "ORDER BY $SORT LIMIT 999";

    $stmt = $dbh->prepare($sql);

    if (!empty($req['text'])) {
        $stmt->bind_param("s", $req['text']);
    }

    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($result as &$row) {
        if ($row['level']) {
            $row['level'] = str_replace(['{90}', '{50}', '{10}', '{5}', '{0}'], ['Сисадмин', 'Администратор', 'Менеджер', 'Дилер', 'Гость'], $row['level']);
            $row['level'] = str_replace(',', '. ', $row['level']);
        }
    }

    return $result ?: null;
}





// добавляем информацию об активности пользователя
function sf__Stat_Add__v1($user_id) {
    global $dbh, $req;

    if (!isset($dbh, $user_id)) {
        return false;
    }

    $A = '40';
    $B = 'Просмотр списка складов';
    $C = '';


    if (!empty($req['text'])) {
        $A = '41';
        $B = 'Поиск склада';
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







$LEVEL = sf__User_Verification__v1();

if (!empty($LEVEL['SID']) AND !empty($LEVEL['LEVEL']) AND $LEVEL['LEVEL'] >= 5) {

    $res['level'] = $LEVEL['LEVEL'];

    $STAT = sf__Stat_Add__v1($LEVEL['SID']);

    $LIST = sf__List_Stock__v1();

    if (!empty($LIST)) {
        $res['res']['stock'] = $LIST;
        $res['res']['st'] = $STAT;

    } else {
        $res['error'] = '404b';
    }
} else {
    $res['error'] = '401';
}



?>