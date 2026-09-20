<?php

function sf__User_Login__v1() {
    global $dbh, $req;

    if (!$dbh || !isset($req['email'])) {
        return null;
    }

    $EMAIL = base64_decode($req['email']);
    //$EMAIL = ($req['email']);

    $sql = "SELECT SID, EMAIL FROM users
             WHERE EMAIL = ?
                 AND STATUS = 'Y' AND ACTIVE = 'Y' AND SID != '' AND ID != ''
                 ORDER BY ID ASC LIMIT 1";

    $stmt = $dbh->prepare($sql);
    $stmt->bind_param("s", $EMAIL);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    return $result ?? null;
}






function  sf__Generate_Password() {
    // Массивы с допустимыми символами
    $letters = range('a', 'z'); // строчные буквы
    $numbers = range(0, 9);     // цифры
    $specialChars = ['!', '@', '#', '$', '%', '&', '^', '*']; // специальные символы

    // Формируем пароль длиной 7 символов
    $password = [];

    // Сначала добавляем 2 специальных символа
    $password[] = $specialChars[array_rand($specialChars)];
    $password[] = $specialChars[array_rand($specialChars)];

    // Затем добавляем 3 буквы
    $password[] = $letters[array_rand($letters)];
    $password[] = $letters[array_rand($letters)];
    $password[] = $letters[array_rand($letters)];

    // Наконец, добавляем 2 цифры
    $password[] = $numbers[array_rand($numbers)];
    $password[] = $numbers[array_rand($numbers)];

    // Перемешиваем массив для случайной последовательности
    shuffle($password);

    // Собираем итоговый пароль
    return implode('', $password);
}







function sf__New_Passw__v1($SID) {
    global $dbh, $req;

    if (!$dbh) {
        return null;
    }

    $PASSW = sf__Generate_Password();

    $password = password_hash($PASSW, PASSWORD_DEFAULT);

    $sql = "UPDATE users SET PASSWORD = ? WHERE SID = ?";

    $stmt = $dbh->prepare($sql);

    $stmt->bind_param(
        'ss',
        $password,
        $SID
    );

    if (!$stmt->execute()) {
        return null;
    }

    return $PASSW;
}






function sf__Email__v1($to, $passw) {
    $subject = 'Восстановление пароля';
    $message = "Новый пароль: $passw"; 
    $from = "webmaster@hm.yygo.ru";
    // Заголовки письма
    $headers = "From: $from\r\n";
    $headers .= "Reply-To: $from\r\n";
    $headers .= "Content-type: text/html; charset=utf-8\r\n";

    $message = wordwrap($message, 70, "\r\n");

    // Отправляем письмо
    if(mail($to, $subject, $message)){//, $headers
        return true;
    } else{
        return false;
    }
}








###########






function sf__Email__v2($to, $passw){

    $to2 = $to; 
    $title = 'Восстановление пароля';
    $message = "Новый пароль: $passw"; 

    if (!function_exists('get_data')){

        function get_data($smtp_conn){

        $data="";

        while($str = fgets($smtp_conn,515)){

            $data .= $str;

            if(substr($str,3,1) == " "){ 

            break; 
            }

        }

        return $data;

        }

    }
    
    $header="Date: ".date("D, j M Y G:i:s")." +0300\r\n"; 
    $header.="From: =?utf-8?Q?".str_replace("+","_",str_replace("%","=",urlencode('CRM')))."?= <all.beauty-market@mail.ru>\r\n"; 
    $header.="X-Mailer: CRM\r\n"; 
    $header.="Reply-To: =?utf-8?Q?".str_replace("+","_",str_replace("%","=",urlencode('CRM')))."?= <all.beauty-market@mail.ru>\r\n";
    $header.="X-Priority: 3 (Normal)\r\n";
    $header.="Message-ID: <".time().".".date("YmjHis")."@mail.hm.yygo.ru>\r\n";
    $header.="To: =?utf-8?Q?".str_replace("+","_",str_replace("%","=",urlencode($to2)))."?= <$to>\r\n"; // получатель
    $header.="Subject: =?utf-8?Q?".str_replace("+","_",str_replace("%","=",urlencode('CRM: ' . $title)))."?=\r\n";
    $header.="MIME-Version: 1.0\r\n";
    $header.="Content-Type: text/plain; charset=utf-8\r\n";
    $header.="Content-Transfer-Encoding: 8bit\r\n";
    
    
    $text="$message


    -------------
    Это сообщение сформировано и отправлено автоматически.
    Пожалуйста не отвечайте на него.
    -------------
    Данное сообщение является конфиденциальным и может содержать персональные данные.
    Вы несете полную ответственность за конфиденциальность информации содержащейся в данном сообщении в соответствии с законодательством РФ.
    -------------
    Если вы получили это сообщение и не являетесь адресатом, как можно быстрее сообщите нам об этом на адрес all.beauty-market@mail.ru и немедленно удалите это сообщение, все его копии и связанные с ним файлы.";

    $text = str_ireplace("  ","",$text);
    
    $smtp_conn = fsockopen("ssl://smtp.mail.ru", 465,$errno, $errstr, 10);
    $data = get_data($smtp_conn);
    
    $code = substr($data,0,3);
    //print 'Connection:'.$data;
    //print '<br>';
    
    fputs($smtp_conn,"EHLO mail.ru\r\n");
    $data = get_data($smtp_conn);
    
    $test='qwe';
    
    $code = substr($data,0,3);
    //echo 'EHLO:'.$data;
    //print '<br>';
    
    fputs($smtp_conn,"AUTH LOGIN\r\n");
    $data = get_data($smtp_conn);
    
    $code = substr($data,0,3);
    //echo 'AUTH:'.$data;
    //print '<br>';
    
    fputs($smtp_conn,base64_encode("all.beauty-market@mail.ru")."\r\n");
    $data = get_data($smtp_conn);
    
    $code = substr($data,0,3);
    //echo $data;
    //print '<br>';
    
    fputs($smtp_conn,base64_encode("xdDm3fjfVM1qJUc8kCdm")."\r\n");
    $data = get_data($smtp_conn);
    
    $code = substr($data,0,3);
    //echo 'pass:'.$data;
    //print '<br>';
    
    fputs($smtp_conn,"MAIL FROM:all.beauty-market@mail.ru\r\n");
    $data = get_data($smtp_conn);
    
    $code = substr($data,0,3);
    //echo 'MAIL:'.$data;
    //print '<br>';
    
    fputs($smtp_conn,"RCPT TO:$to\r\n"); // получатель
    $data = get_data($smtp_conn);
    
    $code = substr($data,0,3);
    //echo 'RCPT:'.$data;
    //print '<br>';
    
    fputs($smtp_conn,"DATA\r\n");
    $data = get_data($smtp_conn);
    
    $code = substr($data,0,3);
    //echo 'DATA:'.$data;
    //print '<br>';
    
    fputs($smtp_conn,$header."\r\n".$text."\r\n.\r\n");
    $data = get_data($smtp_conn);
    
    $code = substr($data,0,3);
    //echo 'header:'.$data;
    //print '<br>';
    
    fputs($smtp_conn,"QUIT\r\n");
    $data = get_data($smtp_conn);
    
    $code = substr($data,0,3);
    //echo 'QUIT:'.$data;
    //print '<br>';

    fclose($smtp_conn);

    return true;

}






$USER = sf__User_Login__v1();

if (!empty($USER['SID']) AND !empty($USER['EMAIL'])) {
         
    $PASSW = sf__New_Passw__v1($USER['SID']);

    if (!empty($PASSW)) {

        if(sf__Email__v2($USER['EMAIL'], $PASSW)){

            $res['res'] = 'ok';

        }
        else{
            
            $res['res'] = 'error_34';

        }
        
    } else {

        $res['error'] = '600';

    }

} else {

    $res['error'] = '401';

}


?>