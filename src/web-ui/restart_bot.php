<?php
    require_once('lib/common.php');
    require_once('lib/esctext.php');
    require_once('lib/db_tools.php');
    require_once('lib/db_config.php');
    require_once('lib/auth_check.php');
    require_once('lib/mini_core.php');
    require_once(__DIR__ . '/api_helper.php');

    if (!str_in($user_rights, 'admin'))
        error_exit("Rights restricted to %s", $user_rights);

    $mysqli = init_remote_db('trading');
    if (!$mysqli)
        die("#FATAL: DB inaccessible!\n");

    mysqli_report(MYSQLI_REPORT_OFF);
    $bot = rqs_param('bot', null) ?? $_SESSION['bot'];
    $core = new MiniCore($mysqli, $bot);
    if (!is_array($core->config))
        die("#FATAL: bot $bot config damaged in DB!\n");

    $engine     = $core->trade_engine;
    $account_id = $engine->account_id;
    $table      = $engine->TableName('tasks');

    $query  = "INSERT IGNORE INTO `$table` (`account_id`, `action`, `param`) VALUES \n";
    $query .= "($account_id, 'RESTART', '');";
    $res = $mysqli->try_query($query);

    $msg = $res
        ? "Restart scheduled for $bot (account $account_id)."
        : "Schedule task failed: " . $mysqli->error;

    http_reply((bool)$res, $msg, "Restart bot: $bot");
