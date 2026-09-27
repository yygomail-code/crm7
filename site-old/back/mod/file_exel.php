<?php


function sf__User_Verification__v1() {
    global $dbh, $req;

    if (!$dbh || !isset($req['ut'], $req['us'])) {
        return null;
    }

    $sql = "SELECT LEVEL FROM users
             WHERE TOKEN_TIME = ? AND TOKEN_USER = ? AND LEVEL = 50
                 AND ACTIVE = 'Y' AND SID != '' AND ID != ''
                 ORDER BY ID ASC LIMIT 1";

    $stmt = $dbh->prepare($sql);
    $stmt->bind_param("ss", $req['ut'], $req['us']);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    return $result && isset($result['LEVEL']) ? $result : null;
}


$LEVEL = sf__User_Verification__v1();

if (!isset($LEVEL)) {
    return null;
}





function sf__File_Read__v1($filePath){

    try {
        // Загрузка библиотеки
        require_once 'vendor/autoload.php';
        
        // Создание ридера и загрузка файла
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
        $spreadsheet = $reader->load($filePath);
        
        // Получение активного листа
        $worksheet = $spreadsheet->getActiveSheet();
        
        // Преобразование содержимого листа в массив
        $dataArray = $worksheet->toArray(null, true, true, true);
        
        return $dataArray;
    } catch (\Exception $e) {
        //error_log("Ошибка при чтении файла: " . $e->getMessage());
        return null;
    }
}

$filePath = './upload_file/1737491178_остатки.xls';

$FILE_ARRAY = sf__File_Read__v1($filePath);

if (!isset($FILE_ARRAY)) {
    return null;
}




function sf__New_Array__v1($FILE_ARRAY) {
    if (!isset($FILE_ARRAY)) {
        return null;
    }

    array_splice($FILE_ARRAY, 0, 4);
    array_pop($FILE_ARRAY);

    $array = [[], []];
    $index = 0;

    foreach ($FILE_ARRAY as $rowIndex => $row) {
        if (!empty($row['A']) && stripos($row['A'], 'Склад ') === false) {

            if(!isset($array[1][$row['A']])){
                $array[1][$row['A']] = ['','','0','0','0'];
            }
            if(empty($array[1][$row['A']][0])){
                $array[1][$row['A']][0] = $row['A'];
            }
            if(empty($array[1][$row['A']][1])){
                $array[1][$row['A']][1] = $row['B'] ?? null;
            }
            if(empty($array[1][$row['A']][1 + $index])){
                $array[1][$row['A']][1 + $index] = $row['C'] ?? '0';
            }
            continue;
        }
        if (!empty($row['A']) && stripos($row['A'], 'Склад ') !== false) {
            $array[0][$index] = $row['A'];
            $index++;
            continue;
        }
        unset($FILE_ARRAY[$rowIndex]);
    }

    $array[1] = array_values($array[1]);

    return (!empty($array[0]) && !empty($array[1])) ? $array : null;
}

$TABLES_ARRAY = sf__New_Array__v1($FILE_ARRAY);

if (!isset($TABLES_ARRAY)) {
    return null;
}





function sf__Nomenclature_Add__v1($TABLES_ARRAY) {
    global $dbh, $req;

    if (!isset($TABLES_ARRAY)) {
        return null;
    }

    $sql = "INSERT IGNORE INTO nomenclature (`NAME`, `NAME_1C`) 
            VALUES (?, ?)";

    $stmt = $dbh->prepare($sql);

    foreach ($TABLES_ARRAY as $element) {
        $A = $element[0] ?? null;
        $B = $element[0] ?? null;

        $stmt->bind_param('ss', $A, $B);
        if (!$stmt->execute()) {
            return null;
        }
    }

    return ['id' => mysqli_insert_id($dbh)];
}

$NOMENCLATURE_ADD = sf__Nomenclature_Add__v1($TABLES_ARRAY[1]);





function sf__Stock_Add__v1($TABLES_ARRAY) {
    global $dbh, $req;

    if (!isset($TABLES_ARRAY)) {
        return null;
    }

    $sql = "INSERT IGNORE INTO stocks (`NAME`, `NAME_1C`) 
            VALUES (?, ?)";

    $stmt = $dbh->prepare($sql);

    foreach ($TABLES_ARRAY as $element) {
        $A = $element ?? null;
        $B = $element ?? null;

        $stmt->bind_param('ss', $A, $B);
        if (!$stmt->execute()) {
            return null;
        }
    }

    return ['id' => mysqli_insert_id($dbh)];
}

$NOMENCLATURE_ADD = sf__Stock_Add__v1($TABLES_ARRAY[0]);





function sf__User_Add__v1($TABLES_ARRAY, $SID_LAST) {
    global $dbh, $req;

    if (!isset($TABLES_ARRAY)) {
        return null;
    }

    $sql = "INSERT INTO stock_levels (`SID_LAST_STOCK_UPDATE`, `ACTUAL_DATE`, `NAME`, `UNIT`, `STOCK_1`, `STOCK_2`, `STOCK_3`, `STOCK_4`, `STOCK_5`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $dbh->prepare($sql);

    foreach ($TABLES_ARRAY as $element) {
        $DATE = $req['date'] ?? date('Y-m-d');
        $A = $element[0] ?? null;
        $B = $element[1] ?? null;
        $C = $element[2] ?? 0;
        $D = $element[3] ?? 0;
        $E = $element[4] ?? 0;
        $F = $element[5] ?? 0;
        $G = $element[6] ?? 0;

        $stmt->bind_param('ssssiiiii', $SID_LAST, $DATE, $A, $B, $C, $D, $E, $F, $G);
        if (!$stmt->execute()) {
            return null;
        }
    }

    return ['id' => mysqli_insert_id($dbh)];
}

$SID_LAST = 'D15d';

$ADD = sf__User_Add__v1($TABLES_ARRAY[1], $SID_LAST);

if (!isset($ADD)) {
    return null;
}





$res['res'] = $ADD;

return $res['res'];


?>