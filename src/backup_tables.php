#!/usr/bin/php
<?php
    include_once('lib/common.php');
    include_once('lib/db_tools.php');
    include_once('lib/db_config.php');
    

    $args = array('backup', '', '', '', '');    

    if (is_array($argv)) {
    foreach ($argv as $i => $arg) 
      $args[$i] = $arg;
    }   

    $op = $args[1];
    $host = $args[3];
    

    $driver = new mysqli_driver();
    $driver->report_mode = MYSQLI_REPORT_ALL ^ MYSQLI_REPORT_STRICT;


    if ('' == $host) {
    init_db('trading');
    $host = 'localhost';
    }   
    else {
    $db_servers = array($host);
    init_remote_db($db_user, $db_pass);
    }
      
    if (!$mysqli)
    die("#FATAL: DB server[$host] unaccessible!");

    
    $tables = array(); 
    $ps_ops = array('backup', 'discard_ts', 'import_ts', 'migrate_df', 'split_part');

    if (false !== array_search($op, $ps_ops)) {
    $res = $mysqli->try_query("SHOW TABLES;");        
    $filter = $args[2];          

    while ($res && $row = $res->fetch_array(MYSQLI_NUM)) {
       $table = $row[0];  
       if (false !== strpos($filter, '-candles') && false !== strpos($table, 'candle')) continue;
       if (false !== strpos($filter, '-listings') && false !== strpos($table, 'listings')) continue;
       $tables []= $table;
    }   
    } elseif ('restore' == $op) {
    $tables = file('/backup/trading/tables.lst');
    $tkps = $args[2];
    $ts = date("Hi"); 
    if ($tkps != $ts)  
      die("#ERROR: must be specified time passwd $ts\n");
    } elseif ('restore-fast' == $op) {
    $src = file('/backup/trading/tables.lst');
    $tkps = $args[2];
    $ts = date("Hi"); 
    if ($tkps != $ts)  
      die("#ERROR: must be specified time passwd $ts\n");
    foreach ($src as $table)   
      if (!strpos($table, 'candle') && !strpos($table, 'listings'))
         $tables []= $table;
    }


    
    function array_scan(string $needle, array $src): bool|string {
    foreach ($src as $s) 
      if (false !== strpos($s, $needle)) return $s;

    return false;  
    }  


    $needs = array();  

    $pwd = 'v0SBkC8SfJmzkM0r';

    $daily_tables = array('cm_listings');

    $idb_files = $mysqli->select_col('FILE_NAME', 'INFORMATION_SCHEMA.FILES', 'ORDER BY FILE_ID');
    print_r($idb_files);

    foreach ($tables as $table) {
    $table = trim($table);   
    $is_depth  = (false !== strpos($table, 'depth'));
    $is_candles  = (false !== strpos($table, 'candles'));
    if ($is_depth && 'backup' == $op) continue;   
    log_msg("-------------- trying $op $table ------------------------------------------");
    
    $cmd = false;
    $out = array();
    if (false !== strpos($op, 'restore')) {          
      // exec($cmd, $out);
      $out = array();     
      // exec("./rdb.sh");      
      // sleep(5);
      // init_db('trading');          
      chdir("/backup/trading");
      $cmd = "mysql -h $host -u trader -p$pwd trading < $table";
    }  elseif ('import' == $op)  {
      $mysqli->try_query(" ALTER TABLE $table IMPORT TABLESPACE;");
    }
    elseif ('backup' == $op) {
     // $mysqli->try_query("FLUSH TABLE $table;");  
     $cmd = "mysqldump -h $host -f -u trader -p$pwd trading $table > /backup/trading/$table.sql";
    } elseif ('discard_ts' == $op && $is_depth) {
     $mysqli->try_query("ALTER TABLE `$table` DISCARD TABLESPACE;"); 
    } elseif ('import_ts' == $op && $is_depth) {
     set_time_limit(300);
     $data_file = "./trading/$table";     
     $res = array_scan($data_file, $idb_files);
     if (false === $res) {
         log_msg("#DBG: trying attach $data_file...");
         $mysqli->try_query("ALTER TABLE `$table` IMPORT TABLESPACE;");
     }    
     else   
         log_msg("#OK: table have tablespace $res..."); 
    } elseif ('migrate_df' === $op && ($is_candles || $is_depth))  {
     $query  = "CREATE TABLE IF NOT EXISTS `datafeed`.`$table` LIKE `trading`.`$table`;";
     $mysqli->try_query($query);
     $fields = 'ts';
     if ($is_candles)
         $fields = 'ts,`open`,`close`,`high`,`low`,`volume`';

     $query = "INSERT IGNORE INTO `datafeed`.`$table` ($fields) ";
     $query .= "SELECT $fields FROM `trading`.`$table` ORDER BY ts;";
     if ($mysqli->try_query($query))
        log_cmsg("#PERF: affected ~C94 {$mysqli->affected_rows} ~C00 rows");
     else
        log_cmsg("~C91#ERROR: ~C00 failed sync query");
    } elseif ('split_part' == $op && $is_depth) {
     $res = $mysqli->try_query("SHOW CREATE TABLE `$table`;");
     if ($res && $row = $res->fetch_array(MYSQLI_NUM)) {
        $query = $row[1];
        if (false !== strpos($query, 'PARTITION')) {
          echo "#OK: table already partitioned\n";
          continue;
        }
     }
     $temp_table = str_ireplace('bitfinex', 'source', $table);
     $res = $mysqli->try_query("RENAME TABLE `$table` TO `$temp_table`;");
     if (!$res) continue;  // TODO: error check
     $res = $mysqli->try_query("CREATE TABLE $table LIKE `bitfinex__depth__btcusd`;");
     if (!$res) {
        log_msg("#ERROR: failed recreate table $table: ".$mysqli->error);
        break; 
     }
     set_time_limit(300);
     $fields = 'ts, frame_type, best_ask, best_bid, frame_data';
     $res = $mysqli->try_query("INSERT INTO `$table`($fields) SELECT $fields FROM `$temp_table` ORDER BY ts;");
     if (!$res || 0 == $mysqli->affected_rows)  
        log_error("#ERROR: data restore copying failed, affected rows {$mysqli->affected_rows}");
     else   
        $mysqli->try_query("DROP TABLE `$temp_table`;");             
     // break; // DEBUG, processing by one
    } 

    if ($cmd) {
     exec($cmd, $out);
     log_msg("#EXEC: ".implode("\n", $out));
    }  
    
    }

    if ('backup' == $op) { 
    exec("ls /backup/trading/*.sql > /backup/trading/tables.lst");
    } 
    log_msg("#FINISHED: script complete");
?>