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








function sf__User_Stat__v1() {
    global $dbh, $req;

    if (!isset($dbh, $req['id'])) {
        return null;
    }

    if (!empty($req['sort'])) {
        $sortMap = [
            'a1' => 'TIME_ADD DESC',
            'a2' => 'TIME_ADD ASC',
            'b1' => 'NAME ASC',
            'b2' => 'NAME DESC',
            'c1' => 'TEXT ASC',
            'c2' => 'TEXT DESC',
            'd1' => 'IP ASC',
            'd2' => 'IP DESC',
        ];
    
        $SORT = $sortMap[$req['sort']] ?? NULL;
    }

    if(empty($SORT)){
        $SORT = 'TIME_ADD DESC';
    }

    if (!empty($req['limit']) AND is_numeric($req['limit'])) {
        $LIMIT = $req['limit'] * 1;
    }
    else{
        $LIMIT = '50';
    }

    $sql = "SELECT TIME_ADD as date_time, NAME as name, NAME_NUM as num, TEXT as text, IP as ip
                FROM users_stat 
                WHERE ";

    if(!empty($req['text'])){
        $sql .= "(
            NAME LIKE CONCAT('%', ?, '%') 
            OR TEXT LIKE CONCAT('%', ?, '%')
            OR IP LIKE CONCAT('%', ?, '%')
            ) AND ";
    }
    
    $sql .= "USER_SID != '' 
     AND USER_SID = ? 
     AND ACTIVE = 'Y' 
     AND SID != '' 
     AND ID != '' 
    ORDER BY $SORT 
    LIMIT $LIMIT";

    $stmt = $dbh->prepare($sql);

    if(!empty($req['text'])){
        $stmt->bind_param("ssss", $req['text'], $req['text'], $req['text'], $req['id']);

    }
    else {
        $stmt->bind_param("s", $req['id']);
    }

    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    return $result ?? null;
}



function sf__User_Name__v1() {
    global $dbh, $req;

    if (!isset($dbh, $req['id'])) {
        return null;
    }

    $sql = "SELECT FULL_NAME as name, FNAME as fname, NNAME as nname, ONAME as oname FROM users 
                 WHERE SID = ? 
                  AND ACTIVE = 'Y' 
                  AND SID != '' 
                  AND ID != ''
                 ORDER BY ID ASC 
                 LIMIT 999";

    $stmt = $dbh->prepare($sql);
    $stmt->bind_param("s", $req['id']);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    return $result ?? null;
}







$USER = sf__User_Verification__v1();

if (!empty($USER['SID']) AND !empty($USER['LEVEL'])) {
    $res['level'] = $USER['LEVEL'];
    
    $res['res']['list'] = sf__User_Stat__v1();

    $NAME = sf__User_Name__v1();

    if (!empty($NAME)) {
        $res['res']['user'] = $NAME;

    } else {
        $res['error'] = '404';

    }

} else {
    $res['error'] = '401';

}


?>