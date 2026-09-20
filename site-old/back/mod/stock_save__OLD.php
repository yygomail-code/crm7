<?php

function sf__User_Verification__v1() {
    global $dbh, $req;

    if (!$dbh || !isset($req['ut'], $req['us'])) {
        return null;
    }

    $sql = "SELECT LEVEL, ID FROM users
             WHERE TOKEN_TIME = ? AND TOKEN_USER = ? AND LEVEL = 50
                 AND ACTIVE = 'Y' AND SID != '' AND ID != ''
                 ORDER BY ID ASC LIMIT 1";

    $stmt = $dbh->prepare($sql);
    $stmt->bind_param("ss", $req['ut'], $req['us']);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    return $result && isset($result['LEVEL']) ? $result : null;
}







function sf__file_add__v1() {
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        // Проверяем, был ли загружен файл
        if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            // Получаем информацию о загружаемом файле
            $fileTmpPath = $_FILES['file']['tmp_name'];
            $fileName = time() . '_' . $_FILES['file']['name'];
            $fileSize = $_FILES['file']['size'];
            $fileType = $_FILES['file']['type'];
            $fileNameCleared = basename($fileName);

            // Проверяем, является ли файл изображением
            $allowedFileTypes = ['application/vnd.ms-excel'];
            if (in_array($fileType, $allowedFileTypes)) {
                // Указываем директорию для сохранения файла
                $uploadFileDir = './upload_file/';
                $dest_path = $uploadFileDir . $fileNameCleared;

                // Переносим файл из временной директории в целевую
                move_uploaded_file($fileTmpPath, $dest_path);

                return $fileName;
                
            } else {
                return null;
            }
        } else {
            return null;
        }
    }

}








function sf__File_Sid_Add__v1($FILE_NAME) {
    global $dbh, $req;

    if (!isset($req['date'], $FILE_NAME)) {
        return null;
    }

    $SID = md5($FILE_NAME . time() . mt_rand(1000000, 9999999));

    $sql = "INSERT INTO last_stock_update (`SID`, `ACTUAL_DATE`, `FILE`)
             VALUES (?, ?, ?)";

    $stmt = $dbh->prepare($sql);

    $stmt->bind_param(
        'sss',
        $SID,
        $req['date'],
        $FILE_NAME
    );

    if (!$stmt->execute()) {
        return null;
    }

    return $SID;
}









function sf__File_Read__v1($FILE_NAME){

    try {
        // Загрузка библиотеки
        require_once 'vendor/autoload.php';
        
        // Создание ридера и загрузка файла
        $uploadFileDir = './upload_file/';
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($uploadFileDir.$FILE_NAME);
        $spreadsheet = $reader->load($uploadFileDir.$FILE_NAME);
        
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










$LEVEL = sf__User_Verification__v1();

if (!empty($LEVEL['ID']) AND !empty($LEVEL['LEVEL'])) {
    $res['level'] = $LEVEL['LEVEL'];

    $FILE = sf__file_add__v1();

    if (!empty($FILE)) {
        $SID_LAST = sf__File_Sid_Add__v1($FILE);

        if (!empty($SID_LAST)) {
            $FILE_ARRAY = sf__File_Read__v1($FILE);

            if (!empty($FILE_ARRAY)) {
                $TABLES_ARRAY = sf__New_Array__v1($FILE_ARRAY);

                if (!empty($TABLES_ARRAY[0]) AND !empty($TABLES_ARRAY[1])) {
                    $NOMENCLATURE_ADD = sf__Stock_Add__v1($TABLES_ARRAY[0]);
                    $NOMENCLATURE_ADD = sf__Nomenclature_Add__v1($TABLES_ARRAY[1]);

                    if(!empty($req['date']) AND $req['date'] >= date('Y-m-d')){
                        $ADD = sf__User_Add__v1($TABLES_ARRAY[1], $SID_LAST);

                        if (!empty($ADD)) {
                            $res['res'] = 'ok';
                        } else {
                            $res['error'] = '400';
                        }
                    } else {
                        $res['error'] = '400a';
                    }
                } else {
                    $res['error'] = '400b';
                }
            } else {
                $res['error'] = '400c';
            }
        } else {
            $res['error'] = '400d';
        }
    } else {
        $res['error'] = '400e';
    }

} else {
    $res['error'] = '401';
}


?>