<?php
    include_once('lib/common.php');
    include_once('lib/db_tools.php');
    
    
    $remote = false;
    if ($_SERVER && isset($_SERVER['REMOTE_ADDR']))
        $remote = $_SERVER['REMOTE_ADDR'];
    $allow_ip = array();
    $white_list = file(__DIR__.'/.allowed_ip.lst');
    foreach ($white_list as $ip) {
    $ip = trim($ip);
    $allow_ip [$ip] = 1;
    }
     
    
    if ($remote && !isset($allow_ip[$remote])) {  
    http_response_code (403);
    print_r($allow_ip);
    die("#FORBIDDEN: access not allowed for $remote\n");
    }   
    
    // $db_user = 'trader';
    // $db_pass = file_get_contents('/etc/trader.pwd');
    // $db_pass = trim($db_pass);
    $db_profile = 'nope';

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

    if ($remote)  {
     $account_id = rqs_param('src_account', 256);  // TODO: ugly hack here
     $view = rqs_param('view', 'json');
    }   

    $updated_ts = rqs_param('updated_after', '2021-01-01 0:00:00');      

    try_query('SET SESSION query_cache_type = OFF;');
    $pair_field = rqs_param('field', 'symbol');  
    $sort_field = rqs_param('sort_field', 'ts');
    $sort_dir = rqs_param('sort_dir', 'ASC');  
    
    
    $strict_acc = "account_id > $account_id";
    if ($account_id > 50)
     $strict_acc = "hype_last.account_id = $account_id";
      
     
    $mysqli->debug("F:L:o:T:t,/tmp/mysqli.dbg"); 

    
    $res = $mysqli->select_from('pair_id,ts,ts_checked,`value`,value_change,symbol,account_id as src_account,'.$pair_field, 'hype_last', 
                               "INNER JOIN `pairs_map` ON hype_last.pair_id = pairs_map.id\n WHERE ($strict_acc) AND (ts >= '$updated_ts') ORDER BY $sort_field $sort_dir LIMIT 500");

    $pos_list = array();

    while ($res && $row = $res->fetch_array(MYSQLI_ASSOC)) {
    $pair_id = $row['pair_id'];
    unset($row['pair_id']); 
    // if (!isset($pos_list [$pair_id]))
    $pos_list [$pair_id]= $row; // WARN: replace by newer with same pair_id !!!!!!!!!!!!!!!!!!!
    }     
    
    if ($remote) {
    // file_put_contents("lastpos_$pair_field.dump", print_r($pos_list, true));
    // file_put_contents("query_$pair_field.sql", $mysqli->last_query);    
    } else 
    echo "#QUERY: {$mysqli->last_query}\n";
    

    
    
    if ('json' == $view)
     echo json_encode($pos_list); // TODO: bad format, one position per pair_id
    elseif ('dump' == $view) {
     echo "<pre>";
     print_r($pos_list);
    }
    elseif ('table' == $view) {
     echo "<html>\n<body>\n";
     echo "<table border=1 cellpadding=7 style='border-collapse:collapse;'>\n";
     echo " <thead><tr><th>Symbol<th>Position<th>Change<th>Time\n";
     echo " <tbody>\n";
     $date = date('Y-m-d');
     foreach ($pos_list as $pos) {
       $st = '';
       $ts = $pos['ts'];
       if (false !== strpos($ts, $date)) 
         $st = "style='font-weight:700;'";
       
       echo "  <tr $st>";
       $pair = trim($pos[$pair_field]);
       if (0 == strlen($pair))
          $pair = $pos['symbol']; 
       printf(" <td>%s<td>%.3f<td>%.3f<td>%s\n", $pair, $pos['value'], $pos['value_change'], $ts); 
     }
     echo "</table>\n";  
    }
        
    
    $mysqli->close();  
?>