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




function sf__Zayavki_Status__v1() {
    global $dbh;

    if (!$dbh) {
        return null;
    }

    $sql = "SELECT NAME as name, SID as id FROM users_reg_new__status
                 WHERE FORM_VIEW = 'Y' AND ACTIVE = 'Y' AND SID != '' AND ID != ''
                 ORDER BY NAME ASC LIMIT 999";

    $stmt = $dbh->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    return $result ?? null;
}





function sf__Zayavki_View__v1($id) {
    global $dbh, $req;

    if (!$dbh) {
        return null;
    }

    if (!empty($req['sort'])) {
        $sortMap = [
            'a1' => 'dbA.FNAME ASC, dbA.NNAME ASC, dbA.ONAME ASC',
            'a2' => 'dbA.FNAME DESC, dbA.NNAME DESC, dbA.ONAME DESC',
            'b1' => 'dbB.NAME ASC, dbA.FNAME ASC, dbA.NNAME ASC, dbA.ONAME ASC',
            'b2' => 'dbB.NAME DESC, dbA.FNAME ASC, dbA.NNAME ASC, dbA.ONAME ASC',
            'c1' => 'dbA.TIME_ADD ASC',
            'c2' => 'dbA.TIME_ADD DESC',
            'd1' => 'dbA.COMPANY ASC',
            'd2' => 'dbA.COMPANY DESC',
            'e1' => 'dbA.DOLGNOST ASC',
            'e2' => 'dbA.DOLGNOST DESC',
            'f1' => 'dbA.PHONE ASC',
            'f2' => 'dbA.PHONE DESC',
            'g1' => 'dbA.EMAIL ASC',
            'g2' => 'dbA.EMAIL DESC',
        ];
    
        $SORT = $sortMap[$req['sort']] ?? NULL;
    }

    if(empty($SORT)){
        $SORT = 'dbA.FNAME ASC, dbA.NNAME ASC, dbA.ONAME ASC';
    }

    $sql = "SELECT dbA.SID, dbA.STATUS, dbA.TIME_ADD, dbA.TIME_ACTIVE, dbA.FNAME, dbA.NNAME, dbA.ONAME, dbA.COMPANY, dbA.DOLGNOST, dbA.PHONE, dbA.EMAIL, 
             CASE WHEN dbA.STATUS = dbB.SID THEN dbB.NAME END AS status
             FROM users_reg_new dbA
             INNER JOIN users_reg_new__status dbB ON dbA.STATUS = dbB.SID
             WHERE ";

    if(!empty($req['text'])){

        $sql .= "(
            dbA.FNAME LIKE CONCAT('%', ?, '%') 
            OR dbA.NNAME LIKE CONCAT('%', ?, '%') 
            OR dbA.ONAME LIKE CONCAT('%', ?, '%')
            OR dbA.COMPANY LIKE CONCAT('%', ?, '%')
            OR dbA.DOLGNOST LIKE CONCAT('%', ?, '%')
            OR dbA.PHONE LIKE CONCAT('%', ?, '%')
            OR dbA.EMAIL LIKE CONCAT('%', ?, '%')
        ) AND ";

    }

    $sql .= "dbA.STATUS != '' 
            AND dbA.STATUS > '0' 
            AND dbA.ACTIVE = 'Y' 
            AND dbA.SID != '' 
            AND dbA.ID != '' 
            AND dbB.NAME != '' 
            AND dbA.ACTIVE = 'Y' 
            AND dbA.SID != '' 
            AND dbA.ID != '' 
            ORDER BY $SORT 
            LIMIT 9999";

    $stmt = $dbh->prepare($sql);

    if(!empty($req['text'])){
        
        $stmt->bind_param("sssssss", $req['text'], $req['text'], $req['text'], $req['text'], $req['text'], $req['text'], $req['text']);

    }
    
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if ($result === false) {
        return null;
    }

    $arr_temp = [];
    foreach ($result as $item) {
        
        $arr_temp[] = [
            'id' => $item['SID'],
            'name' => $item['FNAME'] . ' ' . $item['NNAME'] . ' ' . $item['ONAME'],
            'company' => $item['COMPANY'],
            'dolgnost' => $item['DOLGNOST'],
            'phone' => $item['PHONE'],
            'email' => $item['EMAIL'],
            'data' => (substr($item['TIME_ADD'],8,2) . '-' . substr($item['TIME_ADD'],5,2) . '-' . substr($item['TIME_ADD'],0,4)),
            'status' => $item['STATUS'],
            'status_name' => $item['status']
        ];
    }

    return !empty($arr_temp) ? array_values($arr_temp) : null;
}






$USER = sf__User_Verification__v1();

if (!empty($USER['ID']) AND !empty($USER['LEVEL'])) {
    $res['level'] = $USER['LEVEL'];

    $ZAYAVKI = sf__Zayavki_View__v1($USER['ID']);

    if (!empty($ZAYAVKI[0])) {
        $STATUS = sf__Zayavki_Status__v1();

        if(!empty($STATUS[0])){
            $res['res']['status'] = $STATUS;
        }

        $res['res']['list'] = $ZAYAVKI;

    } else {
        $res['error'] = '404';

    }

} else {
    $res['error'] = '401';
    
}



?>