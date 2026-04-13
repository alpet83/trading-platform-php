<?php
    include_once('lib/common.php'); 
    require_once 'lib/db_tools.php';
    require_once 'lib/auth_lib.php'; 
    const DB_CONFIG = '/usr/local/etc/php/db_config.php';
    if (!file_exists(DB_CONFIG)) {
        http_response_code(500);
        printf("FATAL: no DB config installed\n");
        error_exit("~C91#FATAL:~C00 no DB config installed");    
    }    
    require_once DB_CONFIG;
    // echo '+';
    error_reporting(E_ERROR | E_PARSE | E_WARNING);

    $uid = rqs_param('id', 0) * 1;  
    $mysqli = init_remote_db('sigsys');
    if (!$mysqli) {
        http_response_code(500);
        printf("FATAL: DB inaccessible!");
        error_exit("~C91#FATAL:~C00 DB inaccessible! Last server %s", $db_alt_server);
    }
    // echo '+';

    $res = $mysqli->select_row('user_name,rights,base_setup', 'chat_users', "WHERE chat_id = $uid", MYSQLI_OBJECT);
    $mysqli->close();
    if ($res)
        echo json_encode(['rights' => $res->rights, 'base_setup' => intval($res->base_setup)]);
    else
        echo json_encode(['rights' => 'none', 'base_setup' => 0]);

?>


