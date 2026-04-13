<?php
    include_once('lib/common.php');
    include_once('lib/config.php');
    include_once('lib/db_tools.php');

    if (file_exists('/usr/local/etc/php/db_config.php'))
    require_once('/usr/local/etc/php/db_config.php');
    else
    require_once('lib/db_config.php');

    mysqli_report(MYSQLI_REPORT_OFF);
    $mysqli = init_remote_db('sigsys');
    if (!$mysqli)
    die("#FATAL: cannot connect to DB!\n");
    
    $verbose = false;  

    $src = '{"TimeUtc":"2021-02-09T21:14:30.1029482Z","AccountPositionsList":[{"AccountID":10,"Positions":[{"Symbol":"SPY","HypeQty":1.0},{"Symbol":"DIA","HypeQty":-1.0}]}],"IsManual":true}';
    
    $parse_iter = 0;
    
    
    function dbg_log($msg) {
     global $verbose;
     if ($verbose) 
        echo tss().' '.$msg;
     
    }  
    
    function parse_list($src) {
    global $mysqli, $verbose, $parse_iter;
    
    $parse_iter ++;
    $rec = json_decode($src, true);
    
    
    $ts = $rec['TimeUtc'];      
    $tm = strtotime($ts);
    
    dbg_log("UTC Timestamp: $ts = $tm\n");
    $m = array();
    $ms = '';
    if (preg_match('/(.\d\d\d)\d*Z/', $ts, $m))
       $ms = $m[1];
      
    $ts = date(SQL_TIMESTAMP, $tm).$ms;  
    // if ($verbose) print_r($rec);
      
    if (isset($rec['AccountPositionsList']))
    
    
      // ACCOUNT LOOP -----------------------------------------------------------
      foreach ($rec['AccountPositionsList'] as $acc) {
        $acc_id = $acc['AccountID'];
        dbg_log(" Account $acc_id:\n");  
        $sym_list = array();
        // scaning
        foreach ($acc['Positions'] as $pos) { 
           $sym = $pos['Symbol'];
           $qty = $pos['HypeQty'];
           // echo " $sym = $qty \n";    
           $sym_list []= $sym;
        }
        sort($sym_list);
        // upgrading map
        foreach ($sym_list as $sym) {
           $query = "INSERT IGNORE INTO `pairs_map` (symbol) VALUES ('$sym');";
           $mysqli->try_query($query); 
        } 
        $pair_map = array();
        $pairs = $mysqli->select_from('id, symbol', 'pairs_map');
        while ($pairs && $rec = $pairs->fetch_array(MYSQLI_NUM)) {
          $sym = $rec[1];
          $pair_map [$sym] = $rec[0];
        }  
        // if ($verbose) print_r($pair_map);
        
        
        // updating position
        $updated = 0;
        $pos_map = array();
        
        $res = $mysqli->select_from('ts, pair_id, value', 'hype_last', "WHERE account_id = $acc_id", MYSQLI_NUM);
        while ($res && $row = $res->fetch_array(MYSQLI_NUM)) {
           $pair_id = $row[1];
           $pos_map [$pair_id]  = array( $row[0], $row[2] );        
        }        
        
        // if ($verbose) print_r($pos_map);        
        
        foreach ($acc['Positions'] as $pos) {
           $sym = $pos['Symbol'];
           $qty = $pos['HypeQty'];
           if (!isset($pair_map[$sym])) continue; // TODO: need warn
           $pair_id = $pair_map[$sym];                       

           $strict = "(account_id = $acc_id) AND (pair_id = $pair_id)"; 

           $row = false;
           if (isset($pos_map[$pair_id]))
               $row = $pos_map[$pair_id];                           
           // $row = $mysqli->select_row('ts,value', 'hype_last', "WHERE $strict", MYSQLI_NUM);
           $affected = 0;
           // TODO: optimize table creation                     
           $hist_table = sprintf('hype_history_%d__%d', $acc_id, $pair_id);
           $query = "CREATE TABLE IF NOT EXISTS `$hist_table` ( `ts` TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) , `value` DOUBLE NOT NULL, PRIMARY KEY (`ts`)) ENGINE = InnoDB";
           $mysqli->try_query($query);                                
           // history fill             
           $last_qty = $mysqli->select_value('value', $hist_table, "WHERE ts < '$ts' ORDER BY ts DESC");                       
           if ($last_qty === false || $last_qty != $qty) {                        
             $query = "INSERT IGNORE INTO `$hist_table` (ts, value) VALUES ('$ts', $qty);";
             $mysqli->try_query($query);
             $affected = $mysqli->affected_rows;
             dbg_log(" insert into $hist_table affected rows $affected\n ");            
           }            
                      
           // last refill
           if (!$row) {
             $query = "INSERT INTO `hype_last` (ts, account_id, pair_id, value) \n";
             $query .= "VALUES ('$ts', $acc_id, $pair_id, $qty)\n";
             dbg_log($query);
             $mysqli->try_query($query);
             $affected = $mysqli->affected_rows;           
             $last_ts = $ts;                      
           }           
           else {   
             $last_ts = $row[0];
             $last_qty = $row[1];
             if ($last_ts >= $ts && $last_qty == $qty) continue;                                 
             dbg_log("previous $last_qty @ $last_ts\n");           
             $query = "UPDATE `hype_last` SET ts = '$ts', value = $qty WHERE $strict AND (value != $qty) AND (ts < '$ts');\n";
             dbg_log($query);                                       
             $mysqli->try_query($query);        
             $affected = $mysqli->affected_rows;
             dbg_log(" update affected rows $affected\n ");             
             $query = "UPDATE `hype_last` SET ts_checked = '$ts' WHERE $strict AND (value = $qty) AND (ts_checked < '$ts');\n"; // debug value update             
             $mysqli->try_query($query);     
           }          
             
                     
        } 
        if ($verbose)  
          dbg_log(" updated positions $updated \n");
        
        echo "OK";
        
      }   
    } 
    if (!$mysqli)
    die("not connected to DB!\n");
    
    
    
    $source = rqs_param('source', 'POST');
    
    // debug mode
    if (strpos($source, 'LOG') !== false) {                                  
    $data = file('position.log');
    echo '<pre>';
    $verbose = true;
    foreach ($data as $line)
      parse_list($line);
    }
    
    // production mode
    elseif (strpos($source, 'POST') !== false) {
    $src = file_get_contents('php://input');
    $verbose = false;
    parse_list($src);  
    }
    else
    echo "#ERROR: wrong source specified!\n ";

    if ($mysqli)
    $mysqli->close();
?>
