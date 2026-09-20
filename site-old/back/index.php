<?php
//header("Access-Control-Allow-Origin: *");
//header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS");
//header("Access-Control-Allow-Headers: origin, x-requested-with, content-type");

//header("Access-Control-Allow-Headers: Content-Type, Authorization");

header("Content-type: application/json; charset=utf-8");
//header("Content-type: text/html; charset=utf-8");
//header('Content-Type: text/html; charset=windows-1251');
ini_set('error_reporting', E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
date_default_timezone_set('Europe/Moscow');
//session_start();
set_time_limit(60);


    #############

    $CONFIG = array();

    $CONFIG['dir'] = './';

    $dbh = null;

    $bd_conect = false;

    $req = array();

    $res = array();

    $res['status'] = 'error';

    if(file_exists($CONFIG['dir'].'conf')){

        if(file_exists($CONFIG['dir'].'conf'.'/configuration.php')){

            include_once($CONFIG['dir'].'conf'.'/configuration.php');

        }

        if(!empty($host) AND !empty($user) AND !empty($pass) AND !empty($baza)){

            $dbh = @new mysqli($host, $user, $pass, $baza);

            if ($dbh->connect_error) {
                die('Connect Error (' . $dbh->connect_errno . ') ' . $dbh->connect_error);
                $bd_conect = false;
            }
            else{
                //$dbh->set_charset("utf8");
                $dbh->set_charset("utf8mb4");
                $bd_conect = true;
            }

        }

    }

    if(!empty($bd_conect) AND !empty($_REQUEST)){

        foreach($_REQUEST as $req_key => $req_val){

            if(isset($_REQUEST[$req_key]) AND $_REQUEST[$req_key] != '' AND $_REQUEST[$req_key] != 'undefined'){

                $req[$req_key] = trim($_REQUEST[$req_key]);

                $req[$req_key] = htmlspecialchars($req[$req_key], ENT_QUOTES);

                $req[$req_key] = str_ireplace('&quot;', '"', $req[$req_key]);

            }
            else{

                $req[$req_key] = '';

            }

        }

    }

    if(!empty($req['module'])){

        if($req['module'] == 'verification'){

            if(file_exists($CONFIG['dir'].'mod'.'/user_verification.php')){

                include_once($CONFIG['dir'].'mod'.'/user_verification.php');
    
            }

        }

        /////////

        if($req['module'] == 'authorization'){

            if(file_exists($CONFIG['dir'].'mod'.'/authorization.php')){

                include_once($CONFIG['dir'].'mod'.'/authorization.php');
    
            }

        }

        /////////

        if($req['module'] == 'reg_new'){

            if(file_exists($CONFIG['dir'].'mod'.'/reg_new.php')){

                include_once($CONFIG['dir'].'mod'.'/reg_new.php');
    
            }

        }

        /////////

        if($req['module'] == 're_passw'){

            if(file_exists($CONFIG['dir'].'mod'.'/re_passw.php')){

                include_once($CONFIG['dir'].'mod'.'/re_passw.php');
    
            }

        }

        /////////

        if($req['module'] == 'menu_top'){

            if(file_exists($CONFIG['dir'].'mod'.'/menu_top.php')){

                include_once($CONFIG['dir'].'mod'.'/menu_top.php');
    
            }

        }

        /////////

        if($req['module'] == 'menu_left'){

            if(file_exists($CONFIG['dir'].'mod'.'/menu_left.php')){

                include_once($CONFIG['dir'].'mod'.'/menu_left.php');
    
            }

        }

        /////////

        if($req['module'] == 'stock_view'){

            if(file_exists($CONFIG['dir'].'mod'.'/stock_view.php')){

                include_once($CONFIG['dir'].'mod'.'/stock_view.php');
    
            }

        }

        /////////

        if($req['module'] == 'remaining_quantity_item_warehouse'){

            if(file_exists($CONFIG['dir'].'mod'.'/remaining_quantity_item_warehouse.php')){

                include_once($CONFIG['dir'].'mod'.'/remaining_quantity_item_warehouse.php');
    
            }

        }

        /////////

        if($req['module'] == 'user_new_reg_zayavka__s1'){

            if(file_exists($CONFIG['dir'].'mod'.'/user_new_reg_zayavka_view.php')){

                include_once($CONFIG['dir'].'mod'.'/user_new_reg_zayavka_view.php');
    
            }

        }

        if($req['module'] == 'user_new_reg_zayavka__s2'){

            if(file_exists($CONFIG['dir'].'mod'.'/user_new_reg_zayavka__status_save.php')){

                include_once($CONFIG['dir'].'mod'.'/user_new_reg_zayavka__status_save.php');
    
            }

        }

        /////////

        if($req['module'] == 'user_view'){

            if(file_exists($CONFIG['dir'].'mod'.'/user_view.php')){

                include_once($CONFIG['dir'].'mod'.'/user_view.php');
    
            }

        }

        /////////

        if($req['module'] == 'user_new'){

            if(file_exists($CONFIG['dir'].'mod'.'/user_new.php')){

                include_once($CONFIG['dir'].'mod'.'/user_new.php');
    
            }

        }

        /////////

        if($req['module'] == 'user_add'){

            if(file_exists($CONFIG['dir'].'mod'.'/user_add.php')){

                include_once($CONFIG['dir'].'mod'.'/user_add.php');
    
            }

        }

        /////////

        if($req['module'] == 'user_edit__s1'){

            if(file_exists($CONFIG['dir'].'mod'.'/user_edit.php')){

                include_once($CONFIG['dir'].'mod'.'/user_edit.php');
    
            }

        }

        /////////

        if($req['module'] == 'user_edit__s2'){

            if(file_exists($CONFIG['dir'].'mod'.'/user_save.php')){

                include_once($CONFIG['dir'].'mod'.'/user_save.php');
    
            }

        }

        /////////

        if($req['module'] == 'user_delete__s1'){

            if(file_exists($CONFIG['dir'].'mod'.'/user_delete__s1__v1.php')){

                include_once($CONFIG['dir'].'mod'.'/user_delete__s1__v1.php');
    
            }

        }

        /////////

        if($req['module'] == 'user_delete__s2'){

            if(file_exists($CONFIG['dir'].'mod'.'/user_delete__s2__v1.php')){

                include_once($CONFIG['dir'].'mod'.'/user_delete__s2__v1.php');
    
            }

        }

        /////////

        if($req['module'] == 'user_stat__x1'){

            if(file_exists($CONFIG['dir'].'mod'.'/user_stat__x1__v1.php')){

                include_once($CONFIG['dir'].'mod'.'/user_stat__x1__v1.php');
    
            }

        }

        /////////

        if($req['module'] == 'stock_add'){

            if(file_exists($CONFIG['dir'].'mod'.'/stock_add.php')){

                include_once($CONFIG['dir'].'mod'.'/stock_add.php');
    
            }

        }

        /////////

        if($req['module'] == 'stock_save'){

            if(file_exists($CONFIG['dir'].'mod'.'/stock_save.php')){

                include_once($CONFIG['dir'].'mod'.'/stock_save.php');
    
            }

        }

        /////////

        if($req['module'] == 'file_exel_v2'){

            if(file_exists($CONFIG['dir'].'mod'.'/file_exel_v2.php')){

                include_once($CONFIG['dir'].'mod'.'/file_exel_v2.php');
    
            }

        }

    }

    if(!empty($res['res'])){

        $res['status'] = "ok";

    }

    if(!empty($req['url'])){

        $res['url'] = '?';

        if($_POST){

            foreach($_POST as $key => $value){

                $res['url'] .= "$key=$value&";

            }
        }

        if($_GET){

            foreach($_GET as $key => $value){

                $res['url'] .= "$key=$value&";

            }
        }

        

    }
    
    //echo $res;
    echo json_encode($res);
    
    unset($res);

    if(empty($bd_conect)){

        $dbh->close();

    }
    
    unset($dbh);

?>