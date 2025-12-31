<?php
    require_once('lib/common.php');
    require_once('lib/esctext.php');
    require_once('lib/db_tools.php');
    require_once('lib/db_config.php');
    require_once('lib/auth_check.php');
    require_once('lib/mini_core.php');

    $mysqli = init_remote_db('trading');
    if (!$mysqli) 
        die("#FATAL: DB inaccessible!\n");

    if (!str_in($user_rights, 'trade')) 
         error_exit("Rights restricted to %s", $user_rights);                

    $bots = $mysqli->select_map('applicant,table_name', 'config__table_map');     

    mysqli_report(MYSQLI_REPORT_OFF);
    $bot = rqs_param('bot', null) ?? $_SESSION['bot'];
    $core = new MiniCore($mysqli, $bot);
    if (!is_array($core->config)) 
            die("#FATAL: bot $bot config damaged in DB!\n");
    
    $id = rqs_param('id', 0) * 1;            
    if ($id <= 1000)
        die("ERROR: invalid params ". var_export($_GET, true));

    $engine = $core->trade_engine;    
    $p_table = $engine->TableName('pending_orders');
    $m_table = $engine->TableName('mm_exec');

    $exists = $mysqli->select_value('order_no', $p_table, "WHERE id = $id") ??
              $mysqli->select_value('order_no', $m_table, "WHERE id = $id");
    if (!$exists)
        die("ERROR: order $id not found in DB");

    $account_id = $engine->account_id;
    $table = $engine->TableName('tasks');
    $query = "INSERT IGNORE INTO `$table` (`account_id`, `action`, `param`) VALUES \n";
    $query .= "($account_id, 'CANCEL_ORDER', '$id');";
    $res = $mysqli->try_query($query);
    if ($res)
        printf("OK: %d tasks scheduled ", $mysqli->affected_rows);
    else
        echo "ERROR: schedule task failed $query";

