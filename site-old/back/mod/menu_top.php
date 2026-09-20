<?php

function sf__User_Verification__v1() {
    global $dbh, $req;

    if (!$dbh || !isset($req['ut'], $req['us'])) {
        return null;
    }

    $sql = "SELECT FULL_NAME, FNAME, NNAME, ONAME, LEVEL, ID FROM users
             WHERE TOKEN_TIME = ? AND TOKEN_USER = ? 
                 AND ACTIVE = 'Y' AND SID != '' AND ID != ''
                 ORDER BY ID ASC LIMIT 1";

    $stmt = $dbh->prepare($sql);
    $stmt->bind_param("ss", $req['ut'], $req['us']);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    return $result && isset($result['LEVEL']) ? $result : null;
}



function sf__Menu_Top__v1($user) {
    global $dbh;

    if (!$dbh || empty($user['LEVEL'])) {
        return null;
    }

    $sql = "SELECT NAME AS name, SVG AS svg, MODULE AS module, URL AS url, SID, GROUP_SID
            FROM menu_top
            WHERE LEVEL LIKE CONCAT('%{', ?, '},%') 
              AND NAME != '' AND ACTIVE = 'Y' AND SID != '' AND ID != ''
            ORDER BY SORT ASC, GROUP_SORT ASC
            LIMIT 999";

    $stmt = $dbh->prepare($sql);
    $stmt->bind_param("i", $user['LEVEL']);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if ($result === false) {
        return null;
    }

    $arr_temp = [];
    foreach ($result as $item) {
        if($item['name'] == '{USER_FULL_NAME}'){
            $item['name'] = $user['FULL_NAME'];
        }
        if($item['name'] == '{USER_NAME}'){
            $item['name'] = $user['FNAME'] . ' ' . $user['NNAME'] . ' ' . $user['ONAME'];
        }
        
        if (empty($item['GROUP_SID'])) {
            $arr_temp[$item['SID']] = [
                'name' => $item['name'],
                'svg' => $item['svg'],
                'module' => $item['module'],
                'url' => $item['url']
            ];
        } else {
            $arr_temp[$item['GROUP_SID']]['sub'][] = [
                'name' => $item['name'],
                'svg' => $item['svg'],
                'module' => $item['module'],
                'url' => $item['url']
            ];
        }
    }

    return !empty($arr_temp) ? array_values($arr_temp) : null;
}



// добавить формирование menu_top

$USER = sf__User_Verification__v1();

if (!empty($USER['ID']) AND !empty($USER['LEVEL'])) {
    $res['level'] = $USER['LEVEL'];

    $MENU = sf__Menu_Top__v1($USER);

    if (!empty($MENU) AND !empty($MENU[0])) {
        $res['res'] = $MENU;

    } else {
        $res['error'] = '404';
    }

} else {
    $res['error'] = '401';
}

?>