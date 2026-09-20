<?php

function sf__User_Verification__v1() {
    global $dbh, $req;

    if (!$dbh || !isset($req['ut'], $req['us'])) {
        return null;
    }

    $sql = "SELECT LEVEL, ID FROM users
             WHERE TOKEN_TIME = ? AND TOKEN_USER = ?
                 AND ACTIVE = 'Y' AND SID != '' AND ID != ''
                 ORDER BY ID ASC LIMIT 1";

    $stmt = $dbh->prepare($sql);
    $stmt->bind_param("ss", $req['ut'], $req['us']);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if ($result && isset($result['LEVEL'], $result['ID'])) {
        $update_sql = "UPDATE users
                      SET TIME_ACTIVE = NOW()
                      WHERE ID = ?";

        $update_stmt = $dbh->prepare($update_sql);
        $update_stmt->bind_param("i", $result['ID']);
        $update_stmt->execute();

        return $result && isset($result['LEVEL']) ? $result : null;
    }

    return null;
}


$LEVEL = sf__User_Verification__v1();

if (!empty($LEVEL['LEVEL'])) {
    $res['level'] = $LEVEL['LEVEL'];
    $res['res'] = 'ok';
} else {
    $res['error'] = '401';
}


?>