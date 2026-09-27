<?php

function sf__User_Verification__v1() {
    global $dbh, $req;

    if (!$dbh || !isset($req['ut'], $req['us'])) {
        return null;
    }

    $sql = "SELECT LEVEL, SID FROM users
             WHERE TOKEN_TIME = ? AND TOKEN_USER = ? AND LEVEL = 50
                 AND STATUS = 'Y' AND ACTIVE = 'Y' AND SID != '' AND ID != ''
                 ORDER BY ID ASC LIMIT 1";

    $stmt = $dbh->prepare($sql);
    $stmt->bind_param("ss", $req['ut'], $req['us']);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    return $result && isset($result['LEVEL']) ? $result : null;
}







function sf__User_Name__v1($SID) {
    global $dbh, $req;

    if (!$dbh || !isset($req['id'])) {
        return null;
    }

    $sql = "SELECT SID as id, FULL_NAME as name, STATUS as status, LEVEL as level, LOGIN as login, TIME_ACTIVE as time_active,
        CASE WHEN SID = ? THEN 'Y' ELSE NULL END AS my 
         FROM users
         WHERE SID = ?
                 AND SID != '' AND ID != ''
                 ORDER BY ID ASC LIMIT 1";

    $stmt = $dbh->prepare($sql);
    $stmt->bind_param("ss", $SID, $req['id']);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    return $result && isset($result['id']) ? $result : null;
}







$USER = sf__User_Verification__v1();
if (!empty($USER['SID']) AND !empty($USER['LEVEL'])) {
    $res['level'] = $USER['LEVEL'];
    $VIEW = sf__User_Name__v1($USER['SID']);
    if (!empty($VIEW)) {
        $res['res'] = $VIEW;
    } else {
        $res['error'] = '404';
    }
} else {
    $res['error'] = '401';
}


?>