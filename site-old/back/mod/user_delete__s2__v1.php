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







function sf__User_Delete__v1($SID) {
    global $dbh, $req;

    if (!$dbh || !isset($req['id'], $SID)) {
        return null;
    }

    $id = $req['id'];

    if($SID == $id) {
        return null;
    }

    $deleteSql = "DELETE FROM users WHERE SID = ?";
    $deleteStmt = $dbh->prepare($deleteSql);
    $deleteStmt->bind_param("s", $id);

    if ($deleteStmt->execute()) {
        return $deleteStmt->affected_rows > 0;
    }

    return null;
}







$USER = sf__User_Verification__v1();
if (!empty($USER['SID']) AND !empty($USER['LEVEL'])) {
    $res['level'] = $USER['LEVEL'];
    $DEL = sf__User_Delete__v1($USER['SID']);
    if (!empty($DEL)) {
        $res['res'] = 'ok';
    } else {
        $res['error'] = '404';
    }
} else {
    $res['error'] = '401';
}


?>