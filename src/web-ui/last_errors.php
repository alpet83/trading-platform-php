<?php
    require_once('lib/common.php');
    require_once('lib/esctext.php');
    require_once('lib/db_tools.php');
    require_once('lib/db_config.php');
    require_once('lib/auth_check.php');
    require_once('lib/basic_html.php');

    $mysqli = init_remote_db('trading');
    if (!$mysqli) 
            die("#FATAL: DB inaccessible!\n");
    $bots = $mysqli->select_map('applicant,table_name', 'config__table_map'); 
    $bot = rqs_param('bot', 'bitmex_bot');
    if (!isset($bots[$bot])) 
        die("#FATAL: bot $bot config not exists in DB!\n");

    $config = $mysqli->select_map('param,value', $bots[$bot]);
    if (is_null($config)) 
        die("#FATAL: bot $bot config damaged in DB!\n");
    $exch = strtolower($config['exchange'] ?? 'NYMEX');   

    $acc_id = $mysqli->select_value('account_id', $bots[$bot]);
    $acc_id = rqs_param('account',  $acc_id);

    $detailed = rqs_param('ts', false);
    $params = "WHERE (account_id = $acc_id)";
    $params .= $detailed ? " AND ts ='$detailed'" : ''; 
    $params .= ' ORDER BY ts DESC LIMIT 500';

    $errors = $mysqli->select_rows('*', $exch.'__last_errors', $params, MYSQLI_ASSOC);
    if (is_null($errors) || 0 == count($errors))
            die("#OK: no errors found for $bot\n");
        

    if (1 == count($errors)) {
        $err = array_shift($errors); 
        $msg = colorize_msg($err['message']);
        printf("Source: %s, backtrace: %s\n", $err['source'], $err['backtrace']);
        printf("<pre>Code: %d, message: %s\n", $err['code'],  $msg);
        die('');
    } 
    
?>
<!DOCTYPE HTML>
<HTML>
 <HEAD>
  <style type='text/css'>
    td, th { padding-left: 4pt;
            padding-right: 4pt; } 
    /* console colors   */
  </style>
  <link rel="stylesheet" href="css/dark-theme.css">
  <link rel="stylesheet" href="css/apply-theme.css">
  <link rel="stylesheet" href="css/colors.css">  
</HEAD>  
<BODY> 
 <?php echo button_link("Go Home..", 'index.php')."\n"; ?>
 <table border=1  style='border-collapse: collapse;'>
 <thead>
  <tr><th>Time<th>Host<th>Code<th>Message<th>Source</tr>
 </thead>
 <?php
    foreach ($errors as $err) {
        $msg = colorize_msg($err['message']);
        printf("<tr><td>%s<td>%s<td>%d<td>%s<td>%s</tr>\n", $err['ts'], $err['host_id'], $err['code'], $msg, $err['source']);
    }
 ?>



 



     
