<?php
 require_once('lib/common.php');
 require_once('lib/db_tools.php');
 require_once('lib/ip_check.php');
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
$setup_raw = '0';
$view = 'dump';

$remote = false;
$out = 'json';
$sapi = php_sapi_name();
 
if ($_SERVER && $sapi !== 'cli' && isset($_SERVER['REMOTE_ADDR']))
    $remote = $_SERVER['REMOTE_ADDR'];

if ($remote)  {
   ip_check();
   $account_id = intval(rqs_param('src_account', 256));
   $view = rqs_param('view', 'json');
   $setup_raw = isset($_GET['setup']) ? (string)$_GET['setup'] : (string)rqs_param('setup', '0');
   $out = rqs_param('format', 'json');
}   

function parse_setup_list(string $raw): array {
  $raw = trim($raw);
  if ('' === $raw) return [];

  // Fast path for a single setup id.
  if (preg_match('/^\d+$/', $raw)) {
    $v = intval($raw);
    return ($v > 0) ? [$v] : [];
  }

  $ids = [];
  $decoded = json_decode($raw, true);
  if (is_array($decoded)) {
    if (isset($decoded['setup'])) {
      $v = intval($decoded['setup']);
      if ($v > 0) $ids[$v] = 1;
    } else {
      foreach ($decoded as $row) {
        if (is_array($row) && isset($row['setup'])) {
          $v = intval($row['setup']);
          if ($v > 0) $ids[$v] = 1;
        }
      }
    }
  }

  // Fallback for lightly sanitized payloads like [{¨setup¨:1,¨qty¨:1}].
  if (!count($ids)) {
    if (preg_match_all('/setup[^0-9-]*([0-9]+)/i', $raw, $m) && isset($m[1])) {
      foreach ($m[1] as $num) {
        $v = intval($num);
        if ($v > 0) $ids[$v] = 1;
      }
    }
  }

  ksort($ids);
  return array_keys($ids);
}

$setups = parse_setup_list($setup_raw);
if (!count($setups)) {
  echo "#ERROR: invalid setup param: " . substr($setup_raw, 0, 200);
  exit(0);
}

$setup_filter = implode(',', array_map('intval', $setups));

$pairs_map = $mysqli->select_map('id,symbol', 'pairs_map', '');
$rows = $mysqli->select_rows('id, buy, pair_id, ts, mult, limit_price, take_profit, stop_loss, ttl, qty, flags', 'signals', "WHERE setup IN ($setup_filter)", MYSQLI_ASSOC);
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