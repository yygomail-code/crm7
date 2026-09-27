<?php

function sf__User_Verification__v1() {
    global $dbh, $req;

    if (!$dbh || !isset($req['ut'], $req['us'])) {
        return null;
    }

    $sql = "SELECT FULL_NAME, LEVEL, ID FROM users
             WHERE TOKEN_TIME = ? AND TOKEN_USER = ? 
                 AND STATUS = 'Y' AND ACTIVE = 'Y' AND SID != '' AND ID != ''
                 ORDER BY ID ASC LIMIT 1";

    $stmt = $dbh->prepare($sql);
    $stmt->bind_param("ss", $req['ut'], $req['us']);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    return $result && isset($result['LEVEL']) ? $result : null;
}



function sf__Menu_Top__v1($user_level, $user_name) {
    global $dbh;

    if (!$dbh || empty($user_level)) {
        return null;
    }

    $sql = "SELECT dbA.SID as A_SID, dbA.GROUP_SID as A_GROUP_SID, dbA.NAME as A_NAME, dbA.SVG as A_SVG, dbA.MODULE as A_MODULE, dbA.URL as A_URL, 
                   dbB.SID as B_SID, dbB.NAME as B_NAME, dbB.SVG as B_SVG
            FROM menu_left dbA
            INNER JOIN menu_left_groups dbB ON dbA.GROUP_SID = dbB.SID
            WHERE dbA.LEVEL LIKE CONCAT('%{', ?, '},%') AND dbB.LEVEL LIKE CONCAT('%{', ?, '},%') 
              AND dbA.NAME != '' AND dbA.ACTIVE = 'Y' AND dbA.GROUP_SID != '' AND (dbA.MODULE != '' OR dbA.URL != '') AND dbA.SID != '' AND dbA.ID != ''
              AND dbB.NAME != '' AND dbB.ACTIVE = 'Y' AND dbB.SID != '' AND dbB.ID != ''
            ORDER BY dbB.SORT ASC, dbB.NAME ASC, dbA.SORT ASC, dbA.NAME ASC
            LIMIT 999";

    $stmt = $dbh->prepare($sql);
    $stmt->bind_param("ss", $user_level, $user_level);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if ($result === false) {
        return null;
    }

    $arr_temp = [];
    foreach ($result as $item) {

        $item['A_NAME'] = str_ireplace('{USER_NAME}',$user_name, $item['A_NAME']);
        $item['A_NAME'] = str_ireplace('{DATE}', date('d.m.Y'), $item['A_NAME']);

        $item['B_NAME'] = str_ireplace('{USER_NAME}',$user_name, $item['B_NAME']);
        $item['B_NAME'] = str_ireplace('{DATE}', date('d.m.Y'), $item['B_NAME']);
        
        if(empty($arr_temp[$item['B_SID']])){
            $arr_temp[$item['B_SID']] = [
                'name' => $item['B_NAME'],
                'svg' => $item['B_SVG']
            ];
        }
        
        $arr_temp[$item['B_SID']]['sub'][] = [
            'name' => $item['A_NAME'],
            'svg' => $item['A_SVG'],
            'module' => $item['A_MODULE'],
            'url' => $item['A_URL']
        ];
    }

    return !empty($arr_temp) ? array_values($arr_temp) : null;
}



// добавить формирование menu_top

$USER = sf__User_Verification__v1();

if (!empty($USER['ID']) AND !empty($USER['LEVEL'])) {
    $res['level'] = $USER['LEVEL'];

    $MENU = sf__Menu_Top__v1($USER['LEVEL'], $USER['FULL_NAME']);

    if (!empty($MENU) AND !empty($MENU[0])) {
        $res['res'] = $MENU;

    } else {
        $res['error'] = '404';
    }

} else {
    $res['error'] = '401';
}

?>