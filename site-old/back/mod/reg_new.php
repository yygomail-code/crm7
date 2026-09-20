<?php

function sf__Reg_Add__v1() {
    global $dbh, $req;

    if (!$dbh || !isset($req['fname'], $req['nname'], $req['oname'], $req['comp'], $req['dolgn'], $req['tel'], $req['email'])) {
        return null;
    }

    $ip = $_SERVER['REMOTE_ADDR'];
    $ua = $_SERVER['HTTP_USER_AGENT'];

    $sql = "INSERT INTO users_reg_new (`FNAME`, `NNAME`, `ONAME`, `COMPANY`, `DOLGNOST`, `PHONE`, `EMAIL`, `IP`, `USER_AGENT`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $dbh->prepare($sql);

    $stmt->bind_param(
        'sssssssss',
        $req['fname'],
        $req['nname'],
        $req['oname'],
        $req['comp'],
        $req['dolgn'],
        $req['tel'],
        $req['email'],
        $ip,
        $ua
    );

    if (!$stmt->execute()) {
        return null;
    }

    return ['id' => mysqli_insert_id($dbh)];
}









     
$ADD = sf__Reg_Add__v1();

if (!empty($ADD)) {
    $res['res'] = 'ok';
} else {
    $res['error'] = '404';
}




?>