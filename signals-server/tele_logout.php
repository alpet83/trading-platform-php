<?php
    require_once('lib/common.php');
    include_once('lib/db_tools.php');
    include_once('/usr/local/etc/php/db_config.php');

    $log_file = fopen(__DIR__."/logs/tele_login.log", 'a');
    error_reporting(E_ERROR | E_PARSE);
    mysqli_report(MYSQLI_REPORT_ERROR);  

    $mysqli = init_remote_db('sigsys');
    if (!$mysqli) 
        die("#FATAL: DB inaccessible!\n");    
    
    error_reporting(E_ERROR | E_PARSE | E_WARNING);    
?>

<?php
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }    
    $_SESSION['auth_token'] = false;
    if (!isset($_SESSION['session_id'])) {
        printf("WARN: You not logged yet\n");
        die();
    }
    $id = intval($_SESSION['session_id']);
    unset($_SESSION['session_id']); 
    session_write_close();   
    if ($id > 0 && $mysqli->try_query("DELETE FROM `trader__sessions` WHERE id = $id")) {
        $aff = $mysqli->affected_rows;
        if ($aff > 0)
            printf("#OK: session %d removed ", $id);
        else
            printf("#WARN: session %d was orphan", $id);        
    }
    else 
        printf("#WARN: no found session %d to remove\n", $id);    
?>
