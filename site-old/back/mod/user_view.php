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





function sf__Stock_View__v1($id) {
    global $dbh, $req;

    if (!$dbh) {
        return null;
    }

    if (!empty($req['sort'])) {
        $sortMap = [
            'a1' => 'dbA.FULL_NAME ASC',
            'a2' => 'dbA.FULL_NAME DESC',
            'b1' => 'dbA.FNAME ASC',
            'b2' => 'dbA.FNAME DESC',
            'c1' => 'dbA.COMPANY ASC',
            'c2' => 'dbA.COMPANY DESC',
            'd1' => 'dbA.LEVEL ASC',
            'd2' => 'dbA.LEVEL DESC',
            'e1' => 'dbA.STATUS ASC',
            'e2' => 'dbA.STATUS DESC',
            'f1' => 'dbA.TIME_ACTIVE ASC',
            'f2' => 'dbA.TIME_ACTIVE DESC',
        ];
    
        $SORT = $sortMap[$req['sort']] ?? NULL;
    }

    if(empty($SORT)){
        $SORT = 'dbA.TIME_ACTIVE DESC';
    }

    $sql = "SELECT dbA.SID as id, dbA.FULL_NAME as name, dbA.STATUS as status, dbA.TIME_ACTIVE as time_active, dbA.FNAME as fname, dbA.NNAME as nname, dbA.ONAME as oname, dbA.COMPANY as company, dbA.DOLGNOST as dolgn, dbA.PHONE as phone, dbA.EMAIL as email, 
             CASE WHEN dbA.ID = ? THEN 'Y' ELSE NULL END AS my, 
             CASE WHEN dbA.STATUS = dbB.SID THEN dbB.NAME END AS status_name
             FROM users dbA
             INNER JOIN users__status dbB ON dbA.STATUS = dbB.SID
             WHERE ";
             
    $sql = "SELECT 
            dbA.SID as id, 
            dbA.FULL_NAME as name, 
            dbA.STATUS as status,  
            dbA.LEVEL as level, 
            dbA.TIME_ACTIVE as time_active, 
            dbA.FNAME as fname, 
            dbA.NNAME as nname, 
            dbA.ONAME as oname, 
            dbA.COMPANY as company, 
            dbA.DOLGNOST as dolgn, 
            dbA.PHONE as phone, 
            dbA.EMAIL as email,
            CASE WHEN dbA.ID = ? THEN 'Y' ELSE NULL END as my,
            CASE WHEN dbA.LEVEL = dbC.SID THEN dbC.NAME ELSE NULL END as level_name, 
            CASE WHEN dbA.STATUS = dbB.SID THEN dbB.NAME ELSE NULL END as status_name
            FROM users dbA
            INNER JOIN 
                users__status dbB ON dbA.STATUS = dbB.SID
            INNER JOIN 
                users__levels dbC ON dbA.LEVEL = dbC.SID
            WHERE ";

    if(!empty($req['text'])){
        $sql .= "(
            dbA.FULL_NAME LIKE CONCAT('%', ?, '%') 
            OR dbA.FNAME LIKE CONCAT('%', ?, '%')
            OR dbA.NNAME LIKE CONCAT('%', ?, '%')
            OR dbA.ONAME LIKE CONCAT('%', ?, '%')
            OR dbA.COMPANY LIKE CONCAT('%', ?, '%')
            OR dbA.DOLGNOST LIKE CONCAT('%', ?, '%')
            OR dbA.PHONE LIKE CONCAT('%', ?, '%')
            OR dbA.EMAIL LIKE CONCAT('%', ?, '%')
            ) AND ";
    }

    $sql .= "dbA.ACTIVE = 'Y' 
            AND dbA.SID != '' 
            AND dbA.ID != ''
            ORDER BY $SORT 
            LIMIT 9999";

    $stmt = $dbh->prepare($sql);

    if(!empty($req['text'])){
        $stmt->bind_param("sssssssss", $id, $req['text'], $req['text'], $req['text'], $req['text'], $req['text'], $req['text'], $req['text'], $req['text']);
    }
    else{
        $stmt->bind_param("s", $id);
    }

    $stmt->execute();

    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    return $result ? $result : null;
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






$USER = sf__User_Verification__v1();

if (!empty($USER['ID']) AND !empty($USER['LEVEL'])) {
    $res['level'] = $USER['LEVEL'];

    $LIST = sf__Stock_View__v1($USER['ID']);

    if (!empty($LIST[0])) {
        $STATUS = sf__User_Status__v1();

        if(!empty($STATUS[0])){
            $res['res']['status'] = $STATUS;
        }

        $LEVELS = sf__User_Levels__v1();

        if(!empty($LEVELS[0])){
            $res['res']['levels'] = $LEVELS;
        }

        $res['res']['list'] = $LIST;

    } else {
        $res['error'] = '404';

    }

} else {
    $res['error'] = '401';
    
}



?>