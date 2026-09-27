<?php

function sf__User_Verification__v1() {
    global $dbh, $req;

    if (!$dbh || !isset($req['ut'], $req['us'])) {
        return null;
    }

    $sql = "SELECT LEVEL, ID, SID FROM users
             WHERE TOKEN_TIME = ? AND TOKEN_USER = ? AND LEVEL = 50
                 AND STATUS = 'Y' AND ACTIVE = 'Y' AND SID != '' AND ID != ''
                 ORDER BY ID ASC LIMIT 1";

    $stmt = $dbh->prepare($sql);
    $stmt->bind_param("ss", $req['ut'], $req['us']);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    return $result && isset($result['LEVEL']) ? $result : null;
}








function sf__User_Save__v1($SID_MY, $LEVEL_MY)
{
    global $dbh, $req;

    if (!$dbh || !isset($req['id'], $req['name'], $req['status'], $req['level'], $req['login'])) {
        return false;
    }

    $login = base64_decode($req['login']);

    $SID = $req['id'];
    $FULL_NAME = !empty($req['name']) ? $req['name'] : NULL;

    $NNAME = !empty($req['nname']) ? $req['nname'] : NULL;
    $ONAME = !empty($req['oname']) ? $req['oname'] : NULL;
    $FNAME = !empty($req['fname']) ? $req['fname'] : NULL;
    $COMPANY = !empty($req['company']) ? $req['company'] : NULL;
    $DOLGNOST = !empty($req['dolgn']) ? $req['dolgn'] : NULL;
    $PHONE = !empty($req['phone']) ? $req['phone'] : NULL;
    $EMAIL = !empty($req['email']) ? $req['email'] : NULL;

    if($SID_MY == $SID){
        $STATUS = 'Y';
        $LEVEL = $LEVEL_MY;
    }
    else{
        $STATUS = ($req['status'] == 'Y') ? 'Y' : 'N';
        $LEVEL = $req['level'] ?? '0';
    }

    $update_sql = "UPDATE users
                   SET FULL_NAME = ?, STATUS = ?, LEVEL = ?, FNAME = ?, NNAME = ?, ONAME = ?, COMPANY = ?, DOLGNOST = ?, PHONE = ?, EMAIL = ?
                   WHERE SID = ? AND LOGIN = ?";

    $update_stmt = $dbh->prepare($update_sql);
    $update_stmt->bind_param("ssssssssssss", $FULL_NAME, $STATUS, $LEVEL, $FNAME, $NNAME, $ONAME, $COMPANY, $DOLGNOST, $PHONE, $EMAIL, $SID, $login);

    if (!empty($req['passw'])) {
        $passw = base64_decode($req['passw']);
        $PASSWORD = password_hash($passw, PASSWORD_DEFAULT); // base64
        $update_sql = "UPDATE users
                       SET FULL_NAME = ?, STATUS = ?, LEVEL = ?, PASSWORD = ?, FNAME = ?, NNAME = ?, ONAME = ?, COMPANY = ?, DOLGNOST = ?, PHONE = ?, EMAIL = ?
                       WHERE SID = ? AND LOGIN = ?";

        $update_stmt = $dbh->prepare($update_sql);
        $update_stmt->bind_param("sssssssssssss", $FULL_NAME, $STATUS, $LEVEL, $PASSWORD, $FNAME, $NNAME, $ONAME, $COMPANY, $DOLGNOST, $PHONE, $EMAIL, $SID, $login);
    }

    $update_stmt->execute();

    return $update_stmt->affected_rows > 0;
}





function sf__User_Stock_Add__v1() {
    global $dbh, $req;

    // Проверяем наличие необходимых данных
    if (
        !$dbh ||
        !isset($req['id'], $req['name'], $req['sklad'])
    ) {
        return null;
    }

    // Удаляем существующие записи для данного пользователя и склада
    $deleteSql = "DELETE FROM user_level_stock WHERE USER_SID = ?";
    $deleteStmt = $dbh->prepare($deleteSql);
    $deleteStmt->bind_param("s", $req['id']);
    $deleteStmt->execute();

    $temp_stock = $req['sklad'];
    $arr_stock = explode(',', $req['sklad']);
    foreach($arr_stock as $row){
        if($row){
            $arr_stock_status = explode('_', $row);
            $A = $arr_stock_status[0];
            $B = $arr_stock_status[1];

            if($B == 'true'){
                $B = 'Y';
                $insertSql = "
                    INSERT INTO user_level_stock (FULL_NAME, USER_SID, STOCK_SID, STATUS)
                    VALUES (?, ?, ?, ?)
                ";
                $insertStmt = $dbh->prepare($insertSql);
                $insertStmt->bind_param(
                    "ssss",
                    $req['name'],
                    $req['id'],
                    $A,
                    $B
                );

                if (!$insertStmt->execute()) {
                    return null;
                }
            } else {
                $B = 'N';
            }

        }

    }

    return ['id' => $dbh->insert_id];
}








$USER = sf__User_Verification__v1();

if (!empty($USER['ID']) AND !empty($USER['LEVEL'])) {
    $res['level'] = $USER['LEVEL'];

    $SAVE = sf__User_Save__v1($USER['SID'], $USER['LEVEL']);
        
    $SAVE_STOK = sf__User_Stock_Add__v1();

    if (!empty($SAVE) OR !empty($SAVE_STOK)) {

        $res['res'] = 'ok';
        
    } else {
        $res['error'] = '500';
    }

} else {
    $res['error'] = '401';
}



?>