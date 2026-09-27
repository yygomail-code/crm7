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


function sf__List_Stock__v1() {
    global $dbh, $req;

    if (!$dbh) {
        return null;
    }

    $sql = "SELECT SID as id, NAME as name
            FROM stocks
            WHERE ACTIVE = 'Y' AND SID != '' AND ID != '' ";

    $sql .= "ORDER BY NAME ASC LIMIT 999";

    $stmt = $dbh->prepare($sql);

    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    return $result ?: null;
}




function sf__User_Status__v1() {
    global $dbh;

    if (!$dbh) {
        return null;
    }

    $sql = "SELECT NAME as name, SID as id FROM users__status
                 WHERE FORM_VIEW = 'Y' AND ACTIVE = 'Y' AND SID != '' AND ID != ''
                 ORDER BY NAME ASC LIMIT 999";

    $stmt = $dbh->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    return $result ?? null;
}




function sf__User_Levels__v1() {
    global $dbh;

    if (!$dbh) {
        return null;
    }

    $sql = "SELECT NAME as name, SID as id FROM users__levels
                 WHERE FORM_VIEW = 'Y' AND ACTIVE = 'Y' AND SID != '' AND ID != ''
                 ORDER BY NAME ASC LIMIT 999";

    $stmt = $dbh->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    return $result ?? null;
}







$LEVEL = sf__User_Verification__v1();

if (!empty($LEVEL['ID']) AND !empty($LEVEL['LEVEL'])) {
    $res['level'] = $LEVEL['LEVEL'];

    $STOCK = sf__List_Stock__v1();
    if (!empty($STOCK)) {

        $res['res']['stocks'] = $STOCK;

    }

    $STATUS = sf__User_Status__v1();
    if(!empty($STATUS[0])){
        $res['res']['status'] = $STATUS;
    }

    $LEVELS = sf__User_Levels__v1();
    if(!empty($LEVELS[0])){
        $res['res']['levels'] = $LEVELS;
    }

} else {
    $res['error'] = '401';

}



?>