<?php

function sf__User_Verification__v1() {
    global $dbh, $req;

    if (!$dbh || !isset($req['ut'], $req['us'])) {
        return null;
    }

    $sql = "SELECT LEVEL, SID FROM users
             WHERE TOKEN_TIME = ? AND TOKEN_USER = ? AND LEVEL = 50
                 AND ACTIVE = 'Y' AND SID != '' AND ID != ''
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

    $sql = "SELECT SID as id, FULL_NAME as name, STATUS as status, LEVEL as level, LOGIN as login, TIME_ACTIVE as time_active, FNAME as fname, NNAME as nname, ONAME as oname, COMPANY as company, DOLGNOST as dolgn, PHONE as phone, EMAIL as email,
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









function sf__List_My_Stock__v1($USER) {
    global $dbh, $req;

    if (!$dbh) {
        return null;
    }

    $sql = "SELECT STOCK_SID AS stock_id, STATUS AS status
            FROM user_level_stock
            WHERE USER_SID = ? AND ACTIVE = 'Y' AND SID != '' AND ID != ''
            ORDER BY ID ASC
            LIMIT 999";

    $stmt = $dbh->prepare($sql);

    $stmt->bind_param("s", $USER);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $out = [];
    foreach($result as $row){
        $out[$row['stock_id']] = (($row['status'] && $row['status'] == 'Y') ? true : false);
    }

    return $out ?: null;
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

if (!empty($LEVEL['SID']) AND !empty($LEVEL['LEVEL'])) {
    $res['level'] = $LEVEL['LEVEL'];

    $USER = sf__User_Name__v1($LEVEL['SID']);

    if (!empty($USER)) {
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

        $MY_STOCK = sf__List_My_Stock__v1($USER['id']);
        if (!empty($MY_STOCK)) {
            $res['res']['my_stock'] = $MY_STOCK;
        }

        $res['res']['list'] = $USER;

    } else {
        $res['error'] = '404';

    }

} else {
    $res['error'] = '401';

}


?>