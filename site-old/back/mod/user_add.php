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








function sf__User_Name__v1() {
    global $dbh, $req;

    if (!$dbh || !isset($req['login'])) {
        return false;
    }

    $login = base64_decode($req['login']);

    $sql = "SELECT ID FROM users
             WHERE LOGIN LIKE CONCAT(?)
                 AND SID != '' AND ID != ''
                 ORDER BY ID ASC LIMIT 1";

    $stmt = $dbh->prepare($sql);
    $stmt->bind_param("s", $login);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    return $result && isset($result['ID']) ? false : true;
}








function sf__User_Add__v1() {
    global $dbh, $req;

    if (!isset($req['name'], $req['status'], $req['level'], $req['login'], $req['passw'])) {
        //return null;
    }

    $login = base64_decode($req['login']);

    $passw = base64_decode($req['passw']);
    $password = password_hash($passw, PASSWORD_DEFAULT);

    $status = ($req['status'] == 'Y') ? 'Y' : 'N';
    $level = $req['level'] ?? '0';
    
    $NNAME = !empty($req['nname']) ? $req['nname'] : NULL;
    $ONAME = !empty($req['oname']) ? $req['oname'] : NULL;
    $FNAME = !empty($req['fname']) ? $req['fname'] : NULL;
    $COMPANY = !empty($req['company']) ? $req['company'] : NULL;
    $DOLGNOST = !empty($req['dolgn']) ? $req['dolgn'] : NULL;
    $PHONE = !empty($req['phone']) ? $req['phone'] : NULL;
    $EMAIL = !empty($req['email']) ? $req['email'] : NULL;

    $sql = "INSERT INTO users (`LOGIN`, `PASSWORD`, `LEVEL`, `FULL_NAME`, `STATUS`, `FNAME`, `NNAME`, `ONAME`, `COMPANY`, `DOLGNOST`, `PHONE`, `EMAIL`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $dbh->prepare($sql);

    $stmt->bind_param(
        'ssssssssssss',
        $login,
        $password,
        $level,
        $req['name'],
        $status,
        $FNAME, 
        $NNAME, 
        $ONAME, 
        $COMPANY, 
        $DOLGNOST, 
        $PHONE, 
        $EMAIL
    );

    if (!$stmt->execute()) {
        return null;
    }

    return ['id' => mysqli_insert_id($dbh)];
}










$LEVEL = sf__User_Verification__v1();

if (!empty($LEVEL['ID']) AND !empty($LEVEL['LEVEL'])) {
    $res['level'] = $LEVEL['LEVEL'];

    if (!empty(sf__User_Name__v1())) {        
        $ADD = sf__User_Add__v1();

        if (!empty($ADD)) {
            $res['res'] = 'ok';
        } else {
            $res['error'] = '600';
        }
    } else {
        $res['error'] = '600a';
    }

} else {
    $res['error'] = '401';
}


?>