<?php
    require_once('lib/common.php');
    include_once('lib/db_tools.php');
    include_once('/usr/local/etc/php/db_config.php');

    $log_file = fopen(__DIR__."/logs/sig_import.log", 'a');
    $color_scheme = 'cli';
    if (!$log_file || php_sapi_name() == 'cli')     
        $log_file = fopen("/tmp/sig_import.log", 'w');

    $source = file_get_contents( 'php://input' );        
    $source = str_replace('signal=', '', $source);
    $source = trim($source);
    $IP = $_SERVER['REMOTE_ADDR'] ?? 'localhost';
    log_cmsg("~C93#DBG:~C00 source data %s from %s", var_export($source, true), $IP); 

    function stop(){
        log_cmsg(...func_get_args());
        die("FAIL\n");
    }

    if (is_null($source) || strlen($source) < 20) 
        stop("~C91#ERROR:~C00 to short source data");        
        

    
    $allowed = ['52.89.214.238', '34.212.75.30', '54.218.53.128', '52.32.178.7'];
    if (!in_array($IP, $allowed))
        log_cmsg("~C91#WARN:~C00 IP $IP not allowed\n");
      
    $params = json_decode($source);   
    if (!is_object($params))
        stop ("~C91#ERROR:~C00 source data %s not valid JSON: %s, params %s", $source, var_export($params, true), json_encode($_GET));

    $keys = explode(',', 'symbol,price,amount,timestamp,producer');
    foreach ($keys as $i => $key)
    if (!isset($params->$key)) 
        stop("~C91#ERROR:~C00 param %s not specified in source", $key);

    $signal_no = rqs_param('signal_no', $params->signal_no ?? 20);
    if ($signal_no < 20 || $signal_no > 50) 
        stop("~C91#ERROR:~C00 signal_no %d out of range", $signal_no);   

    $allowed = ['TradingView-strategy'];  // TODO: hide in config

    if (!in_array($params->producer, $allowed))
        stop("~C91#ERROR:~C00 producer %s not allowed", $params->producer);


    $ts = gmdate('Y-m-d H:i:s', strtotime($params->timestamp));    
    log_cmsg("~C97#START:~C00 connecting to DB...");
    mysqli_report(MYSQLI_REPORT_OFF);
    $mysqli = init_remote_db('sigsys');
    $table_name = 'signals';
    $symbol = $params->symbol;        
    
    $others_col = $mysqli->select_col("CONCAT(COLUMN_NAME, '=\"$symbol\"')", 'INFORMATION_SCHEMA.COLUMNS',  "WHERE TABLE_NAME = 'pairs_map' AND COLUMN_NAME LIKE '%pair'") ;

    log_cmsg("~C33#COLUMNS:~C00 %s", json_encode($others_col));
    $others_col = implode(' OR ', $others_col);         
    $pair_id = $mysqli->select_value('id', 'pairs_map', "WHERE symbol = '$symbol' OR $others_col");    
    if (is_null($pair_id) || $pair_id < 1)
        stop("~C91#ERROR:~C00 unsupported symbol %s", $symbol);

    $elapsed = time() - strtotime($params->timestamp);
    if ($elapsed > 120)
        stop("~C94IGNORED:~C00 signal to old\n");  

    $action = $params->action ?? 'open';
    $setup = $params->setup ?? 0;
    if ('close' == $action) {
        $strict = "WHERE signal_no = {$params->signal_no} AND setup = $setup";
        if (!$mysqli->select_value('id', $table_name, $strict))
             die("ERROR: signal not exists\n");
        $query = "DELETE FROM `$table_name` $strict";
        log_cmsg("~C93#QUERY:~C00 %s", $query);
        if ($mysqli->try_query($query))
            stop("~C97#OK:~C00 signal removed");
        else
            stop("~C91#ERROR:~C00 signal not removed");        
    }
       

    $buy = $params->buy ?? 1;
    $buy = $buy ? 1 : 0;
    $flags = $params->price > 0 ? 4 : 0;
    $trader_id = intval($params->trader_id ?? 1);
    $query = "INSERT IGNORE INTO $table_name (ts, signal_no, trader_id, setup, buy, pair_id, limit_price, mult, flags, source_ip) VALUES\n";
    $query .= "\t ('$ts', $signal_no, $trader_id, $setup, $buy, $pair_id, {$params->price}, {$params->amount}, $flags, '$IP');";      
    log_cmsg("~C93#QUERY:~C00 %s", $query);
    mysqli_report(MYSQLI_REPORT_OFF);
    $inserted = 0;
    if ($mysqli->try_query($query)) {
        $inserted = $mysqli->affected_rows;
        log_cmsg("~C97#OK:~C00 signal %s", $inserted > 0 ? 'saved' : 'exists');
    }    
    else
        log_cmsg("~C91#ERROR:~C00 signal not saved");

    $extra = [];
    $map = ['sl' => 'stop_loss', 'tp' => 'take_profit', 'note' => 'comment', 'prio' => 'exec_prio'];
    foreach ($map as $key => $col) {
        if (!isset($parmas->$key)) continue;
            $extra = "$col = ".$mysqli->format_value($parmas->$key);
    }
    if (count($extra) > 0)
        $extra = implode(   ', ', $extra);
    else  {        
        if ($inserted > 0)
            stop('~C97#OK:~C00 fresh signal, update not need');  
        $extra = '';    
    }   
    $strict = "(pair_id = $pair_id) AND (signal_no = $signal_no) AND (setup = $setup)";
    $query = "UPDATE `$table_name` SET ts = '$ts', buy = $buy, limit_price = {$params->price}, mult = {$params->amount}, $extra flags = $flags, source_ip = '$IP'\n";
    $query .="\tWHERE $strict";    
    $res = ($mysqli->try_query($query) && $mysqli->affected_rows > 0); 
    $row = $mysqli->select_row('*', $table_name, "WHERE $strict", MYSQLI_ASSOC);
    if ($res)         
        log_cmsg("~C97#OK:~C00 signal updated: %s", json_encode($row));        
    else
        log_cmsg("~C91#WARN:~C00 signal not updated, query %s\n, result: %s", $query, json_encode($row));
    


