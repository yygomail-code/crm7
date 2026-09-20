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







// импортируем файл
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







// добавляем информацию об импортировании в БД и получаем SID записи
function sf__File_Sid_Add__v1($FILE_NAME) {
    global $dbh, $req;

    if (!isset($dbh, $FILE_NAME)) {
        return null;
    }

    $SID = md5($FILE_NAME . time() . mt_rand(1000000, 9999999));

    $sql = "INSERT INTO last_stock_update (`SID`, `ACTUAL_DATE`, `FILE`)
             VALUES (?, ?, ?)";

    $stmt = $dbh->prepare($sql);
    
    $DATE = $req['date'] ?? date('Y-m-d');

    $stmt->bind_param(
        'sss',
        $SID,
        $DATE,
        $FILE_NAME
    );

    if (!$stmt->execute()) {
        return null;
    }

    return $SID;
}







// обрабатываем файл exel
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







// добавляем склады
function sf__New_Stock__v1($FILE_ARRAY) {
    global $dbh;

    if (!isset($dbh, $FILE_ARRAY)) {
        return null;
    }

    array_splice($FILE_ARRAY, 0, 4);
    array_pop($FILE_ARRAY);

    $sql = "INSERT IGNORE INTO stocks (`NAME`, `NAME_1C`) 
             VALUES (?, ?)";

    $stmt = $dbh->prepare($sql);

    foreach ($FILE_ARRAY as $row) {
        if (!empty($row['A']) && stripos($row['A'], 'Склад ') !== false) {
            $A = $row['A'] ?? null;
            $B = $row['A'] ?? null;

            $stmt->bind_param('ss', $A, $B);

            if (!$stmt->execute()) {
                return null;
            }

        }
    }

    return ['id' => mysqli_insert_id($dbh)];
}







// добавляем номенклатуру
function sf__New_Nomenclature__v1($FILE_ARRAY) {
    global $dbh;

    if (!isset($dbh, $FILE_ARRAY)) {
        return null;
    }

    array_splice($FILE_ARRAY, 0, 4);
    array_pop($FILE_ARRAY);

    $sql = "INSERT IGNORE INTO nomenclature (`NAME`, `NAME_1C`, `UNIT`) 
             VALUES (?, ?, ?)";

    $stmt = $dbh->prepare($sql);

    foreach ($FILE_ARRAY as $row) {
        if (!empty($row['A']) && stripos($row['A'], 'Склад ') === false) {
		$A = $row['A'] ? trim($row['A']) : null;
		$B = $row['A'] ? trim($row['A']) : null;
		$C = $row['B'] ? trim($row['B']) : null;

            $stmt->bind_param('sss', $A, $B, $C);

            if (!$stmt->execute()) {
                return null;
            }

        }
    }

    return ['id' => mysqli_insert_id($dbh)];
}







// запрашиваем sid складов
function sf__Stock__v1() {
    global $dbh;

    if (!isset($dbh)) {
        return null;
    }

    $sql = "SELECT NAME_1C, SID FROM stocks
             WHERE NAME_1C != '' AND ACTIVE = 'Y' AND SID != '' AND ID != ''
             ORDER BY ID ASC
             LIMIT 999";

    $stmt = $dbh->prepare($sql);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (empty($rows)) {
        return null;
    }

    $result = array_column($rows, null, 'NAME_1C');

    return $result;
}







// запрашиваем sid номенклатуры
function sf__Nomenclature__v1() {
    global $dbh;

    if (!isset($dbh)) {
        return null;
    }

    $sql = "SELECT NAME_1C, SID FROM nomenclature
             WHERE NAME_1C != '' AND ACTIVE = 'Y' AND SID != '' AND ID != ''
             ORDER BY ID ASC
             LIMIT 9999";

    $stmt = $dbh->prepare($sql);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (empty($rows)) {
        return null;
    }

    $result = array_column($rows, null, 'NAME_1C');

    return $result;
}







// обновляем дату последней активности склада и sid последнего импорта
function sf__update_stock_date_active__v1($NAME_1C, $LAST_STOCK_UPDATE_SID) {
    global $dbh;
    
    if (!$dbh) {
        return false;
    }

    $update_sql = "UPDATE stocks 
                   SET LAST_ACTIVITY_DATE = ?, LAST_STOCK_UPDATE_SID = ?
                   WHERE NAME_1C = ?";
    $update_stmt = $dbh->prepare($update_sql);

    $current_time = date('Y-m-d H:i:s');
    $update_stmt->bind_param("sss", $current_time, $LAST_STOCK_UPDATE_SID, $NAME_1C);
    $update_stmt->execute();

    return $update_stmt->affected_rows > 0;
}







// формируем массив для заполнения БД
function sf__New_Array__v2($FILE_ARRAY, $LAST_STOCK_UPDATE_SID) {
    if (!isset($FILE_ARRAY)) {
        return null;
    }

    $STOCK = sf__Stock__v1();

    if (!isset($STOCK)) {
        return null;
    }

    $NOMENKLATURE = sf__Nomenclature__v1();

    if (!isset($NOMENKLATURE)) {
        return null;
    }

    array_splice($FILE_ARRAY, 0, 4);
    array_pop($FILE_ARRAY);

    $array = [[], []];
    $stock = ['',''];

    foreach ($FILE_ARRAY as $rowIndex => $row) {

        if (!empty($row['A']) && stripos($row['A'], 'Склад ') !== false) {
            sf__update_stock_date_active__v1($row['A'], $LAST_STOCK_UPDATE_SID);
            $array[0][] = $row['A'];
            $stock[0] = $row['A'];
            $stock[1] = $STOCK[$row['A']]['SID'] ?? '';

        } elseif (!empty($row['A']) && stripos($row['A'], 'Склад ') === false) {
            $temp_A = trim($row['A']);
            $array[1][$rowIndex] = ['','','','','','0'];
            $array[1][$rowIndex][0] = $stock[0] ? trim($stock[0]) :  '';
            $array[1][$rowIndex][1] = $stock[1] ? trim($stock[1]) :  '';
            $array[1][$rowIndex][2] = $temp_A;
            $array[1][$rowIndex][3] = $NOMENKLATURE[$temp_A]['SID'] ?? '';
            $array[1][$rowIndex][4] = $row['B'] ? trim($row['B']) :  '';
            $array[1][$rowIndex][5] = $row['C'] ? trim($row['C']) : '0';

        }

    }

    $array[1] = array_values($array[1]);

    return (!empty($array[0]) && !empty($array[1])) ? $array[1] : null;
}







// заполняем БД
function sf__Stock_Nomenklatura_Add__v1($TABLES_ARRAY, $SID_LAST) {
    global $dbh, $req;

    if (!isset($dbh, $TABLES_ARRAY, $SID_LAST)) {
        return null;
    }

    $dbh->query("TRUNCATE stock_levels"); // костыль

    $sql = "INSERT INTO stock_levels (`LAST_STOCK_UPDATE_SID`, `ACTUAL_DATE`, `STOCK`, `STOCK_SID`, `NAME`, `NAME_SID`, `UNIT`, `QUANTITY`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $dbh->prepare($sql);

    foreach ($TABLES_ARRAY as $element) {
        $DATE = $req['date'] ?? date('Y-m-d');
        $A = $element[0] ?? null;
        $B = $element[1] ?? null;
        $C = $element[2] ?? 0;
        $D = $element[3] ?? 0;
        $E = $element[4] ?? 0;
        $F = $element[5] ?? 0;

        $F = preg_replace('/[^0-9.]/', '', $F);
       
        /*$F = str_ireplace(' ','', $F);
        $F = str_ireplace(',','', $F);
        if(is_numeric($F) === false){
            $F = 0;
        }*/

        $stmt->bind_param('ssssssss', $SID_LAST, $DATE, $A, $B, $C, $D, $E, $F);
        if (!$stmt->execute()) {
            return null;
        }
    }

    return ['id' => mysqli_insert_id($dbh)];

}










if(!empty($req['date']) AND $req['date'] >= date('Y-m-d')){

    $LEVEL = sf__User_Verification__v1();

    if (!empty($LEVEL['ID']) AND !empty($LEVEL['LEVEL']) AND $LEVEL['LEVEL'] >= 50) {
        $res['level'] = $LEVEL['LEVEL'];

        $FILE = sf__file_add__v1();

        //$FILE = '1739172610_10.xls';

        if (!empty($FILE)) {
            $SID_LAST = sf__File_Sid_Add__v1($FILE);

            if (!empty($SID_LAST)) {
                $FILE_ARRAY = sf__File_Read__v1($FILE);

                if (!empty($FILE_ARRAY)) {
                    sf__New_Stock__v1($FILE_ARRAY);
                    sf__New_Nomenclature__v1($FILE_ARRAY);
                    $TABLES_ARRAY = sf__New_Array__v2($FILE_ARRAY, $SID_LAST);

                //var_dump($TABLES_ARRAY);

                    if (!empty($TABLES_ARRAY)) {
                            $ADD = sf__Stock_Nomenklatura_Add__v1($TABLES_ARRAY, $SID_LAST);

                            if (!empty($ADD)) {
                                $res['res'] = 'ok';
                            } else {
                                $res['error'] = '400';
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
} else {
    $res['error'] = '400a';
}



?>