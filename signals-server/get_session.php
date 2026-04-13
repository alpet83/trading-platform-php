<?php
    require_once('lib/common.php');
    require_once('lib/esctext.php');
    include_once('lib/db_tools.php');
    include_once('lib/db_config.php');
    include_once('lib/auth_lib.php');
    $log_file = fopen(__DIR__."/logs/get_session.log", 'a');
    error_reporting(E_ERROR | E_PARSE);
    mysqli_report(MYSQLI_REPORT_ERROR);  

    $mysqli = init_remote_db('sigsys');
    if (!$mysqli) 
        die("#FATAL: DB inaccessible!\n");    
    
    error_reporting(E_ERROR | E_PARSE | E_WARNING);    
    $IP = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];    
    $id = rqs_param('id', 0); 
    $id = intval($id);
    $IP = trim($IP);
    $color_scheme = 'none';
    log_cmsg("~C97#INFO:~C00 get session %d from %s", $id, $IP);
    try {
        $sess = $mysqli->select_row('*', 'trader__sessions', "WHERE (id = $id)", MYSQLI_OBJECT);
        // log_cmsg("~C93#SELECT:~C00 %s", gettype($row));
        if (is_null($sess)) { 
            echo "{\"id\":$id, \"error\":\"no session found\"}";
            $list = $mysqli->select_col('id', 'trader__sessions');
            log_cmsg("~C91#ERROR($IP):~C00  no session found for $id, available = %s", json_encode($list));
        }    
        elseif (is_object($sess)) {                       
            if ($IP != $sess->IP && !str_in($IP, '10.119.10.')) {
                log_cmsg("~C31#IP_FILTER:~C00 %s != %s", $IP, $sess->IP);    
                die("#ERROR: IP mismatch\n");            
            }    
            $sess->auth_token = fmt_auth_token( $sess->ts, $sess->user_id, $sess->IP);
            $json = strval($sess);
            $rec = json_decode($json);
            $rec->rights = $mysqli->select_value('rights', 'chat_users', "WHERE chat_id = {$sess->user_id}");        
            echo json_encode($rec);
            log_cmsg("~C96#RESPONSE:~C00 %s", json_encode($rec));
        }    
        else {
            echo "#ERROR: unexpected data\n";
            log_cmsg("~C91#ERROR($IP):~C00  strange response for session %d: %s", $id, var_export($sess, true));
        } 
    } catch (Throwable $e) {
        log_cmsg("~C91#EXCEPTON($IP):~C00  %s from %s", $e->getMessage(), $e->getTraceAsString());        
        echo "#EXCEPTION:!\n";
    }   
?>

