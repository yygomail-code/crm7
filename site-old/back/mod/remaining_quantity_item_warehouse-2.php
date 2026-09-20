<?php

function sf__User_Verification__v1() {
    global $dbh, $req;

    if (!$dbh || !isset($req['ut'], $req['us'])) {
        return null;
    }

    $sql = "SELECT LEVEL, SID, ID FROM users
             WHERE TOKEN_TIME = ? AND TOKEN_USER = ? AND (LEVEL = 90 OR LEVEL = 50 OR LEVEL = 10 OR LEVEL = 5)
                 AND ACTIVE = 'Y' AND SID != '' AND ID != ''
                 ORDER BY ID ASC LIMIT 1";

    $stmt = $dbh->prepare($sql);
    $stmt->bind_param("ss", $req['ut'], $req['us']);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    return $result && isset($result['LEVEL']) ? $result : null;
}





function sf__Actual_Stock__v1($LEVEL) {
    global $dbh, $req;

    if (!$dbh) {
        return null;
    }

    if (!empty($req['date']) AND $LEVEL >= 50){ 
        $date_sql = "<=";
        $date_val = $req['date'];

    } elseif (!empty($req['date']) AND $req['date'] <= date('Y-m-d') AND $LEVEL < 50){ 
        $date_sql = "<=";
        $date_val = $req['date'];

    } else {
        $date_sql = "<=";
        $date_val = date('Y-m-d');

    }

    $sql = "
        SELECT ACTUAL_DATE AS date, SID
        FROM last_stock_update
        WHERE ACTUAL_DATE {$date_sql} ?
          AND ACTIVE = 'Y'
          AND SID != ''
          AND ID != ''
        ORDER BY ACTUAL_DATE DESC, ID DESC
        LIMIT 1
    ";

    $stmt = $dbh->prepare($sql);
    $stmt->bind_param("s", $date_val); 
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    return $result ?: null;
}






function sf__User_Level_Stock__v1($USER_SID, $LIST) {
    global $dbh;

    if (!$dbh || empty($LIST[1])) {
        return null;
    }

    $sql = "SELECT STOCK_SID 
            FROM user_level_stock
            WHERE USER_SID = ? 
              AND ACTIVE = 'Y' 
              AND SID != '' 
              AND ID != '' 
            ORDER BY ID ASC 
            LIMIT 999";

    $stmt = $dbh->prepare($sql);
    $stmt->bind_param("s", $USER_SID);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    return $result ?: null;
}






function sf__List_Stock__v1($LEVEL) {
    global $dbh;

    if ($dbh) {

        $LEVEL_SQL = "";
        if($LEVEL == '50'){
            $LEVEL_SQL = "AND STATUS_ADMIN = 'Y' ";
        }
        if($LEVEL == '5'){
            $LEVEL_SQL = "AND STATUS_DILER = 'Y' ";
        }

        $sql = "SELECT SID as STOCK_SID, NAME, STOCK_NUM, STATUS_ADMIN, STATUS_DILER FROM stocks
             WHERE ACTIVE = 'Y' AND SID != '' AND ID != '' $LEVEL_SQL
             ORDER BY SORT ASC, NAME ASC LIMIT 999";

        $stmt = $dbh->prepare($sql);
        $stmt->execute();
        $temp = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        if(!$temp){
            return null;
        }

        $result = [[],[]];

        foreach ($temp as $key => $val) {
            $result[0][$key]['id'] = $val['STOCK_SID'];
            $result[0][$key]['name'] = $val['NAME'];
        }

        $result[1] = $temp;

        return $result ? $result : null;
    }

    return null;
}






function sf__Stock_View__v1($ACTUAL, $USER_LIST) {
    global $dbh, $req;

    if (!$dbh || empty($ACTUAL['SID']) || empty($USER_LIST)) {
        return null;
    }

    $sortMap = [
        'a_1' => 'NAME ASC',
        'a_2' => 'NAME DESC',
        'b_1' => 'UNIT ASC',
        'b_2' => 'UNIT DESC',
        's0_1' => 'STOCK_1 ASC',
        's0_2' => 'STOCK_1 DESC',
        's1_1' => 'STOCK_2 ASC',
        's1_2' => 'STOCK_2 DESC',
        's2_1' => 'STOCK_3 ASC',
        's2_2' => 'STOCK_3 DESC',
        's3_1' => 'STOCK_4 ASC',
        's3_2' => 'STOCK_4 DESC',
        's4_1' => 'STOCK_5 ASC',
        's4_2' => 'STOCK_5 DESC',
    ];

    $SORT = $sortMap[$req['sort']] ?? 'NAME ASC';

    //$STOK_LIST = implode(',', array_column($LIST[1], 'STOCK_SID'));
    //$STOK_PLACEHOLDERS = str_repeat('?,', count($LIST[1]) - 1) . '?';

    $STOK_LIST = implode(',', array_column($USER_LIST, 'STOCK_SID'));
    $STOK_PLACEHOLDERS = str_repeat('?,', count($USER_LIST) - 1) . '?';

    $sql = "SELECT ACTUAL_DATE, STOCK, STOCK_SID, NAME, NAME_SID, UNIT, QUANTITY 
            FROM stock_levels
            WHERE LAST_STOCK_UPDATE_SID = ? 
                AND STOCK_SID IN ($STOK_PLACEHOLDERS)
                AND ACTIVE = 'Y' 
                AND SID != '' 
                AND ID != '' 
            ORDER BY $SORT 
            LIMIT 9999";

    $params = [$ACTUAL['SID']];
    $params = array_merge($params, explode(',', $STOK_LIST));

    if (!empty($req['text'])) {
        $sql .= " AND NAME LIKE CONCAT('%', ?, '%')";
        $params[] = $req['text'];
    }

    $stmt = $dbh->prepare($sql);
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    $temp = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (!$temp) {
        return null;
    }

    $result = [];

    foreach ($temp as $row) {
        $result[$row['NAME']]['date'] = $row['ACTUAL_DATE'];
        $result[$row['NAME']]['name'] = $row['NAME'];
        $result[$row['NAME']]['name_id'] = $row['NAME_SID'];
        $result[$row['NAME']]['unit'] = $row['UNIT'];
        $result[$row['NAME']]['stock'][$row['STOCK_SID']]['stock'] = $row['STOCK'];
        $result[$row['NAME']]['stock'][$row['STOCK_SID']]['stock_id'] = $row['STOCK_SID'];
        $result[$row['NAME']]['stock'][$row['STOCK_SID']]['quantity'] = $row['QUANTITY'];
    }

    return $result;
}





$LEVEL = sf__User_Verification__v1();
$res['level'] = $LEVEL['LEVEL'];

$ACTUAL = sf__Actual_Stock__v1($LEVEL['LEVEL']);
$LIST = sf__List_Stock__v1($LEVEL['LEVEL']);
$USER_LIST = sf__User_Level_Stock__v1($LEVEL['SID'], $LIST);

$STOCK = sf__Stock_View__v1($ACTUAL, $USER_LIST);

$res['res']['ACTUAL'] = $ACTUAL;
$res['res']['LIST'] = $LIST;
$res['res']['USER_LIST'] = $USER_LIST;
$res['res']['STOCK'] = $STOCK;


/*

$LEVEL = sf__User_Verification__v1();

if (!empty($LEVEL['ID']) AND !empty($LEVEL['LEVEL'])) {
    $res['level'] = $LEVEL['LEVEL'];

    $LIST = sf__List_Stock__v1($LEVEL['LEVEL']);

    if (!empty($LIST[0])) {
        $res['res']['param'] = [
            'warehouse_count' => count($LIST),
            'warehouse_name' => array_column($LIST, 'NAME'),
            'warehouse_number' => array_column($LIST, 'STOCK_NUM')
        ];

        $ACTUAL = sf__Actual_Stock__v1();

        if (!empty($ACTUAL['date']) || !empty($ACTUAL['SID'])) {
            $res['res']['date'] = $ACTUAL['date'];

            $STOCK = sf__Stock_View__v1($ACTUAL['SID'], $LIST);

            if (!empty($STOCK[0])) {
                $res['res']['stock'] = $STOCK;
            } else {
                $res['error'] = '404';
            }
        } else {
            $res['error'] = '404a';
        }
    } else {
        $res['error'] = '404b';
    }
} else {
    $res['error'] = '401';
}
*/


?>