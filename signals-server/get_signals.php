<?php
 require_once('lib/common.php');
 require_once('lib/db_tools.php');
 include_once('/usr/local/etc/php/db_config.php');

 mysqli_report(MYSQLI_REPORT_OFF);
 $mysqli = init_remote_db('trading');

 if (!$mysqli) {
    error_log("#DB-SERVER: $db_alt_server");
      if (is_array($db_profile))
          $db_user = $db_profile[0];     
      error_log('#DB_PROFILE: '.json_encode($db_profile));
      die("FATAL: user '$db_user' can't connect to servers ".json_encode($db_servers).", $db_error\n");
}


$account_id = 0;
$view = 'dump';

$remote = false;
$out = 'json';
$sapi = php_sapi_name();
 
if ($_SERVER && $sapi !== 'cli' && isset($_SERVER['REMOTE_ADDR']))
    $remote = $_SERVER['REMOTE_ADDR'];

if ($remote)  {
   $account_id = rqs_param('src_account', 256); 
   $view = rqs_param('view', 'json');
   $setup = rqs_param('setup', 0);
   $out = rqs_param('format', 'json');
}   

$pairs_map = $mysqli->select_map('id,symbol', 'pairs_map', '');
$rows = $mysqli->select_rows('id, buy, pair_id, ts, mult, limit_price, take_profit, stop_loss, ttl, qty, flags', 'signals', "WHERE setup = $setup", MYSQLI_ASSOC);
if (is_array($rows)) {
  $res = [];  
  foreach ($rows as $row) {
    foreach ($row as $key => $val) 
      if (is_numeric($val)) 
        $row[$key] = floatval($val); // for removing "
    $row['closed'] = 0; // is working signal

    $pair_id = $row['pair_id'];
    if (isset($pairs_map[$pair_id]))
        $row['pair'] = $pairs_map[$pair_id];
    $res []= $row;
  }
  if ($out == 'json')
     echo json_encode($res);
  if ($out == 'dump') {
     echo "<pre>\n";
     $dump = [];
     foreach ($res  as $rec) {
       $id = $rec['id'];
       unset($rec['id']);
       $dump[$id] = $rec;
     }
        
     print_r($dump);
  }   
}  
else 
  echo "#ERROR: failed to fetch signals: {$mysqli->error}\n, response = ".var_export($rows, true);