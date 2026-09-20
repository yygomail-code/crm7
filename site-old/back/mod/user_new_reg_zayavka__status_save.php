<?php

function sf__User_Verification__v1() {
    global $dbh, $req;

    if (!$dbh || !isset($req['ut'], $req['us'])) {
        return null;
    }

    $sql = "SELECT LEVEL, ID FROM users
             WHERE TOKEN_TIME = ? AND TOKEN_USER = ? AND LEVEL = 50
                 AND STATUS = 'Y' AND ACTIVE = 'Y' AND SID != '' AND ID != ''
                 ORDER BY ID ASC LIMIT 1";

    $stmt = $dbh->prepare($sql);
    $stmt->bind_param("ss", $req['ut'], $req['us']);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    return $result && isset($result['LEVEL']) ? $result : null;
}




function sf__Zayavki_Status_Save__v1() {
    global $dbh, $req;

    if (!$dbh || !isset($req['id'], $req['val'])) {
        return null;
    }

    $sql = "UPDATE users_reg_new
    SET STATUS = ?
    WHERE SID = ?";

    $stmt = $dbh->prepare($sql);
    $stmt->bind_param("ss", $req['val'], $req['id']);
    $stmt->execute();

    return $stmt->affected_rows > 0;
}






$USERS = sf__User_Verification__v1();

if (!empty($USERS['ID']) AND !empty($USERS['LEVEL'])) {
    $res['level'] = $USERS['LEVEL'];

    $STATUS = sf__Zayavki_Status_Save__v1();

    if (!empty($STATUS)) {
        $res['res'] = $STATUS;
    } else {
        $res['error'] = '404';
    }

} else {
    $res['error'] = '401';
}



?>