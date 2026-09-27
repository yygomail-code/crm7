<?php

function sf__User_Start__v1() {
    global $dbh, $req;
    
    // Проверяем наличие необходимых переменных
    if ($dbh && isset($req['ul'], $req['up'])) {
        $LOGIN = base64_decode($req['ul']);
        $PASSWORD = base64_decode($req['up']);
        
        // Получаем информацию о пользователе по логину
        $sql = "SELECT ID, FULL_NAME, PASSWORD, SID, LEVEL, ACTIVE
                FROM users
                WHERE LOGIN = ? AND STATUS = 'Y' AND ACTIVE = 'Y'";
        
        $stmt = $dbh->prepare($sql);
        $stmt->bind_param("s", $LOGIN); // Передаем только логин
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        // Если пользователь найден
        if ($result) {
            // Сравниваем хэш введённого пароля с хранимым
            if (password_verify($PASSWORD, $result['PASSWORD'])) {
                // Генерируем токены
                $TOKEN_TIME = md5($result['SID'] . time() . mt_rand(1000000, 9999999));
                $TOKEN_USER = md5($TOKEN_TIME . mt_rand(10000, 99999));
                
                // Обновляем токен в базе данных
                $update_sql = "UPDATE users
                               SET TOKEN_TIME = ?, TOKEN_USER = ?
                               WHERE ID = ?";
                               
                $update_stmt = $dbh->prepare($update_sql);
                $update_stmt->bind_param("ssi", $TOKEN_TIME, $TOKEN_USER, $result['ID']);
                $update_stmt->execute();
                
                return [
                    'utid' => $TOKEN_TIME,
                    'usid' => $TOKEN_USER,
                    'name' => $result['FULL_NAME'],
                    'level' => $result['LEVEL'],
                    'SID' => $result['SID']
                ];
            }
        }
    }
    
    return null;
}

$START = sf__User_Start__v1();





// добавляем информацию об активности пользователя
function sf__Stat_Add__v1($user_id) {
    global $dbh, $req;

    if (!isset($dbh, $user_id)) {
        return false;
    }

    $A = '10';
    $B = 'Авторизация';
    $C = '';

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


if (!empty($START['utid']) AND !empty($START['usid']) AND !empty($START['level'])) {

    $STAT = sf__Stat_Add__v1($START['SID']);

    //unset($START['SID']);

    $res['level'] = $START['level'];
    unset($START['level']);
    $res['res'] = $START;

}
else {
    $res['error'] = '401';
}

?>