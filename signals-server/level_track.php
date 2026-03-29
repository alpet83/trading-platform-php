<?php
  include_once('lib/common.php');
  include_once('lib/db_tools.php');
  include_once('lib/ip_check.php');
  include_once('/usr/local/etc/php/db_config.php');

  $db_user = 'trader';  
  $mysqli = init_remote_db('trading');
  if (!$mysqli)
     die("#FATAL: cannot connect to DB!\n");

  error_reporting(E_ERROR | E_WARNING | E_PARSE);
  mysqli_report(MYSQLI_REPORT_ERROR);  

  $pairs_map = $mysqli->select_map('symbol,id', 'pairs_map');
  $id_map    = $mysqli->select_map('id,symbol', 'pairs_map');
     
  $pair_id = rqs_param('pair_id', 1);    

  $symbol = rqs_param('symbol', false);
  if (strlen($symbol) >= 3) {
     $pair_id = $mysqli->select_value('id', 'pairs_map', "WHERE symbol = '$symbol'") * 1; 
     if (0 == $pair_id)
       die("#FATAL: symbol '$symbol' not registered in pairs_map table!\n");
     
  }     
  else  
     $symbol = $mysqli->select_value('symbol', 'pairs_map', "WHERE id = $pair_id"); 

  echo "<!-- symbol $symbol pair_id = $pair_id -->\n";

  $level   = rqs_param('level', 0);
  $amount  = rqs_param('amount', 1);
  $valid   = time() + rqs_param('ttl', 60) * 24 * 3600;
   
  if ($level > 0) {
     $log_file = fopen('logs/level_track_config.log', 'w+');
     $ts_valid = date(SQL_TIMESTAMP, $valid);
     $query = "INSERT INTO `levels_map`(pair_id, `level`, amount, ts_valid) VALUES($pair_id, $level, $amount, '$ts_valid')\n";     
     $query .= "ON DUPLICATE KEY UPDATE ts_valid = '$ts_valid', amount = $amount;";
     $res = $mysqli->try_query($query);
     if ($res) {
       log_msg("#INSERT: $query affected rows {$mysqli->affected_rows}");
     }  
     else 
       log_msg("#ERROR: insert failed");
     
  }

  require_once('load_sym.inc.php');

  $action = rqs_param('action', 'nope');
  if ('nope' !== $action)
     ip_check();  

  $prev_levels = $mysqli->select_map('pair_id,level', 'levels_map', 'WHERE ts_notify > "2023-01-01 00:00:00" ORDER BY ts_notify'); // lastest notified level will be stored in map

  if ('track' == $action) {
    $log_file = fopen('logs/level_track_progress.log', 'w+');    
    file_put_contents('logs/levels_notified.map', print_r($prev_levels, true));
    $rows = $mysqli->select_rows('*', 'levels_map', "ORDER BY id, level DESC", MYSQLI_ASSOC); 
    $checks = 0;
    $prices = array();
    foreach ($rows as $row) {
       $pair_id = $row['pair_id'];
       if (!isset($cm_symbols[$pair_id])) {
         log_msg("#WARN: no symbol record for pair_id $pair_id");
         continue;
       }          
       $id = $row['id'];
       $pair = $id_map[$pair_id];
       $info = $cm_symbols[$pair_id];
       $curr_price = $info->last_price; 
       $prices[$pair_id] = $curr_price;
       $amount = $row['amount'];
       $prev_price = $row['last_price'];
       $level = $row['level'];
       $lbound = min($prev_price, $curr_price);
       $rbound = max($prev_price, $curr_price);       
       $checks ++;
       if (0 == $lbound) continue;
       if (isset($prev_levels[$pair_id]) && $prev_levels[$pair_id] == $level) continue;       

       if ($lbound < $level && $level < $rbound || $level == $curr_price) {
         // breakout detection
         $signals_host = rtrim((string)(getenv('TRADEBOT_PHP_HOST') ?: getenv('SIGNALS_API_URL') ?: 'http://host.docker.internal'), '/');
         $notify_host = trim((string)(getenv('DOMAIN') ?: getenv('HOSTNAME') ?: 'local'));
         $event = "Price $curr_price for {$info->symbol} ({$info->name}) breaks level $level, amount = $amount, prev price = $prev_price\n";
         log_msg($event);
         $event .= ' '.$signals_host.'/level_track.php?symbol='.$pair;
         $tnotify = strtotime($row['ts_notify']);
         if (time() - $tnotify > 3600) {
           curl_http_request($signals_host."/trade_event.php?tag=LEVEL&value=$level&host=".urlencode($notify_host), array('event' => $event));
           $mysqli->try_query("UPDATE `levels_map` SET ts_notify = CURRENT_TIMESTAMP() WHERE (id = $id);");
         }   
       }       
       else  
         log_msg("#CHECK({$info->symbol}): prev = $prev_price, now = $curr_price, level = $level notify last {$row['ts_notify']} ");
    }
    foreach ($prices as $pair_id => $price)
      $mysqli->try_query("UPDATE `levels_map` SET last_price = $price WHERE pair_id = $pair_id;"); // multi
    die("#TRACK: complete with $checks checks\n");
  }
  if ('delete' == $action && $id = rqs_param('id', false)) {
    $mysqli->try_query("DELETE FROM `levels_map` WHERE id = $id;");
    if ($mysqli->affected_rows > 0)
       echo "#DELETED: row id #$id\n";
  }
  
?>
<html>
 <head>
  <style type='text/css'>
   td { padding-left: 4pt;
        padding-right: 4pt; } 
  </style>
  <script type='text/javascript'>
    function DeleteLevel(id) {
       document.location="level_track.php?action=delete&id=" + id; 
    }
    function BuyLevel(pair, amount) {
       // [now] [DOTUSD]: BUY.X1#1  
       let input = "[now] [" + pair + ']: BUY.X' + amount;  
       document.location= encodeURI("sig_edit.php?signal=" + input) + "%239999"; 
    }
    function SellLevel(pair, amount) {
       // [now] [DOTUSD]: BUY.X1#1  
       let input = "[now] [" + pair + ']: SELL.X' + amount;  
       document.location= encodeURI("sig_edit.php?signal=" + input) + "%239999"; 
    }
  </script>    
 </head>  
 <body>
<?php
   $last_price = false;
   // $sym = str_replace('USD', '', $symbol);   
   if (is_array($cm_symbols) && isset($cm_symbols[$pair_id])) {
     $rec = $cm_symbols[$pair_id];     
     if (isset($rec->symbol) && $rec->symbol.'USD' === $symbol) {
          $last_price = 1.0 * $rec->last_price;
          echo "<h2> For <b>$symbol</b> last_price = $last_price at {$rec->ts_updated} UTC</h2>\n";
     }    

     if (!$last_price) { 
       echo "<pre>Failed locate $symbol #$pair_id ...\n"; 
       print_r($cm_symbols);
     }  
   } 
?>

 
<form name='level' method='POST' action='level_track.php'>    
  <table border=0>
   <tr><td>Symbol/pair:<td>     
     <?php  
       printf("<input list='symbols' name='symbol' value='%s' style='width:90pt;'>\n", $symbol);
       echo "\t<datalist id='symbols'>\n";
       foreach ($pairs_map as $sym => $id)     
         printf("\t\t<option value='$sym'></option>\n"); 
     ?> 
     </datalist>
     <br />
   <tr><td>Level:<td><input type='text' name='level' id='level' value='0' style='width:90pt;'/><br />
   <tr><td>Amount:<td><input type='text' name='amount' id='amount' value='1' style='width:90pt;'/><br />
   <tr><td>Valid days:<td><input type='text' name='ttl' id='ttl' value='60' style='width:90pt;'/>
   <tr><td colspan=2><input type='submit' value='Post'/>  
 </table> 
 </form>  
  <table border=1 style='border-collapse:collapse;'> 
  <tr><th>Level<th>Amount<th>Valid until<th>Actions</tr>
  <?php

   $rows = $mysqli->select_rows('*', 'levels_map', "WHERE pair_id = $pair_id ORDER BY level DESC", MYSQLI_ASSOC);
   foreach ($rows as $row)  {
     $bgc = 'none';
     $level = $row['level'];
     $amount = $row['amount'];
     $pair_id = $row['pair_id'];
     if ($last_price && $level > $last_price) 
        $bgc = '#FFCCBB';
     elseif ($last_price && $level < $last_price) $bgc = '#CCFFBB';
     $style = "background-color: $bgc;";
     if (isset($prev_levels[$pair_id]) && $prev_levels[$pair_id] == $level) 
        $style .= 'font-weight: bold;'; 

     $id = $row['id'];
     $buy_button  = "<input type='button' onClick='BuyLevel(\"$symbol\", $amount)' value='Buy' />";
     $sell_button = "<input type='button' onClick='SellLevel(\"$symbol\", $amount)' value='Sell' />";
     $del_button  = "<input type='button' onClick='DeleteLevel($id)' value='Del' />";

     printf("<tr style='$style'><td>$%s<td>%.1f<td>%s<td>$buy_button $sell_button $del_button\n", $level, $amount, $row['ts_valid']);
   }  
  ?>  

