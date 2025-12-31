<?php
    require_once('lib/common.php');
    require_once('lib/esctext.php');
    require_once('lib/db_tools.php');
    require_once('lib/db_config.php');
    require_once('lib/auth_check.php');
    require_once('lib/mini_core.php');
    require_once('lib/basic_html.php');

    $log_file = fopen('logs/matched_orders.log', 'w');

    if (!str_in($user_rights, 'view'))
        error_exit("Rights restricted to %s", $user_rights);

    $mysqli = init_remote_db('trading');
    if (!$mysqli) 
            die("#FATAL: DB inaccessible!\n");
    
    $bot = rqs_param('bot', false) ?? $_SESSION['bot'];
    if (!$bot) {
        printf("<!-- %s -->\n", print_r($_SESSION, true));
        die("ERROR: no bot specified\n");
    }    

    $core = new MiniCore($mysqli, $bot);
    if (!is_array($core->config)) 
         die("ERROR: bot $bot config damaged in DB!\n");

    $exch = strtolower($core->config['exchange'] ?? false);   
    if (!is_string($exch))
        die("<pre>#FATAL: no exchange defined for $bot\n".print_r($core, true));

    $engine = $core->trade_engine;    
    $acc_id = $engine->account_id;    
    if (0 == $acc_id)
        die("ERROR: no account detected for $bot\n");

    $strict = '';
    $title = '';    

    // print_r($core);  
    $sig_filter = rqs_param('signal', false);
    if (false !== $sig_filter) {
        $sig_filter = intval($sig_filter);
        $strict .= " AND (signal_id = $sig_filter)";
        $title .= ", signal $sig_filter";
    }    

    $batch_filter = rqs_param('batch', false);     
    if (false !== $batch_filter) {
        print ( gettype($batch_filter) );
        $batch_filter = intval($batch_filter);
        $strict .= " AND (batch_id = $batch_filter)";        
        $title .= ", batch $batch_filter";
    }     
    $pair_filter = rqs_param('pair', false);    
    $self = $_SERVER['SCRIPT_NAME']."?bot=$bot&account=$acc_id";
    $filtered = $self;
    if ($sig_filter)
        $filtered .= "&signal=$sig_filter";
    if ($batch_filter)
        $filtered .= "&batch=$batch_filter";
    if ($pair_filter)
        $filtered .= "&pair=$pair_filter";
?>
<!DOCTYPE HTML>
<HTML>
    <HEAD>
    <TITLE>Matched orders for <?php echo $bot; ?></TITLE>
    <style type='text/css'>
        table { border-collapse: collapse; }
        td, th { padding-left: 4pt;
                padding-right: 4pt; } 
        .ra {   text-align: right; }        
        /* console colors   */
    </style>
    <link rel="stylesheet" href="css/dark-theme.css">
    <link rel="stylesheet" href="css/apply-theme.css">
    <link rel="stylesheet" href="css/colors.css">  
    <script type="text/javascript">
        function dumpBySignal(sig) {
            document.location = '<?php echo $filtered; ?>&signal=' + sig;
        }
        function dumpByPair(pair) {
            document.location = '<?php echo $filtered; ?>&pair=' + pair;
        }
    </script>  
</HEAD>  
<BODY>
<?php    
    $acc_id = $core->trade_engine->account_id;
    $sod = gmdate('Y-m-d 0:00');      
    
    if ($sig_filter || $batch_filter || $pair_filter)
        echo button_link('Clear filters', $self);    

    echo button_link("Go Home..", 'index.php')."\n";    

    if ($filtered != $self && !str_in($filtered, 'days_back')) 
        echo button_link('Max days back 180', $filtered."&days_back=180")."\n";
    
    $params = "INNER JOIN {$exch}__pairs_map PM ON PM.pair_id = MO.pair_id\n ";
    $params .= "WHERE (account_id = $acc_id) AND (ts > '$sod') $strict ORDER BY `updated` DESC";  

    $rows = $mysqli->select_rows('*', $exch.'__matched_orders AS MO', $params, MYSQLI_OBJECT);
    if (!is_array($rows) || !count($rows)) {
        printf("<h2>No matched orders found for %s:%d$title</h2>\n", $bot, $acc_id);
        printf("<!-- %s\n %s -->\n", $params, print_r($_REQUEST, true));        
        goto SKIP;
    }
    $title = sprintf("Matched orders %d for %s:%d", count($rows), $bot, $acc_id).$title;        
    
    echo "<h2>$title</h2>\n";
?>
 
 <table border=1  style='border-collapse: collapse;'>
 <thead>
  <tr><th>ID<th>Time<th>Host<th>Batch<th>Signal<th>Pair<th>Price<th>Amount<th>Position<th>Comment</tr>
 </thead>
 <?php    
    $_SESSION['bot'] = $bot;
    $_SESSION['account_id'] = $acc_id;
    $signals = [];    
    $id_map = array_flip($core->pairs_map);
    if (is_string($pair_filter)) 
        $pair_filter = $id_map[$pair_filter] ?? false;

    foreach ($rows as $row) {        
        if ($pair_filter && $row->pair_id != $pair_filter) continue;
        $signals [$row->signal_id] = 1;
        printf("\t<tr><td>%d<td>%s<td>%d<td>%d<td><a href='javascript:dumpBySignal(%d)'>%d</a>", 
               $row->id, $row->ts, $row->host_id, $row->batch_id, $row->signal_id, $row->signal_id);
        $amount = $row->matched * ($row->buy ? 1 : -1);     
        $pair = $row->pair;               
        printf("<td><a href='javascript:dumpByPair(\"%s\")'>%s</a><td class=ra>%s<td class=ra>%s<td class=ra>%s<td>%s</tr>\n", 
                $pair, $pair, $row->avg_price, $amount, $row->out_position, $row->comment); 
    }
    echo "</table>\n";
    printf("<!-- %s -->\n", $params);

    $c_table = "$exch.candles__btcusd__1D";
    if (!$mysqli->table_exists($c_table))
        $c_table = "bitfinex.candles__btcusd__1D"; // by default - from side exchange

    $btc_history = $mysqli->select_map('DATE(ts),close', $c_table, 'ORDER BY ts'); // для относительно точных расчетов фьючерсов

    function process_orders(array &$list, float &$saldo_qty, float &$saldo_volume) {
        global $core, $btc_history, $mysqli, $c_table;        
        $tmap = $core->trade_engine->tickers_map;
        
        if (0 == count($list)) {
            printf("<div>WARN: No orders to process</div>\n");
            return;
        }
        
        $used = [];
        foreach ($list as $row) {
            $amount = $row->matched;
            $ti = $tmap[$row->pair_id] ?? null;
            if ($ti === null) break;
            $used []= $row;
            $date = substr($row->updated, 0, 10);
            if (isset($btc_history[$date]))
                $btc_price = $btc_history[$date];
            else
                $btc_price = $mysqli->select_value('close', $c_table, "WHERE ts <= '$date' ORDER BY ts DESC");  // пропуск маловероятен, но возможен 

            $qty = AmountToQty($ti, $row->avg_price, $amount);            
            $saldo_qty += $qty;
            $saldo_volume += $qty * $row->avg_price;
        }            
        log_msg("Processed %d / %d orders, saldo qty = %s, volume = %s", 
                    count($used), count($list), format_qty($saldo_qty), format_qty($saldo_volume));
        return count($used);
    } 

    function dump_signal_stats(int $sig_id) {    // вывод статистики по сигналу
        global $mysqli, $core, $engine, $exch, $acc_id, $btc_price, $pair_filter, $sod;
        $days_back = rqs_param('days_back', 0);
        $ts_past = $days_back > 0 ? date(SQL_TIMESTAMP, time() - 86400 * $days_back) : $sod;         
        $params = "(signal_id = $sig_id) AND (account_id = $acc_id) AND (updated > '$ts_past')";
        $tmap = $core->trade_engine->tickers_map;

        if (is_int($pair_filter)) {
            $params .= "AND (pair_id = $pair_filter)";
            if (!isset($tmap[$pair_filter])) {
                $keys = array_keys($tmap);
                $keys = json_encode($keys);
                printf("<h3>ERROR: Record %d not found in tickers map, available $keys</h3>\n", $pair_filter);
                return;
            }
        }

        $buys = $mysqli->select_rows('*', "{$exch}__matched_orders", 
                                       "WHERE $params AND (buy > 0)  ORDER BY updated", MYSQLI_OBJECT);  
        $sells = $mysqli->select_rows('*', "{$exch}__matched_orders", 
                                       "WHERE $params AND (buy = 0)  ORDER BY updated", MYSQLI_OBJECT);  


        
        $buy_qty = $sell_qty = 0;
        $buy_volume = $sell_volume = 0;
        $buy_count = process_orders($buys, $buy_qty, $buy_volume);
        $sell_count = process_orders($sells, $sell_qty, $sell_volume);

        if (0 == $buy_count + $sell_count) return;
        log_msg("Signal %d: %d buys, %d sells in period from %s", $sig_id, $buy_count, $sell_count, $ts_past);                          
        
        $start = date(SQL_TIMESTAMP);        
        if ($buy_count > 0) 
            $start = min($start, $buys[0]->ts);           
        
        if ($sell_count > 0) 
            $start = min($start, $sells[0]->ts);                                   
        
        
        $s_table = $engine->TableName('ext_signals');
        $sig_info = $mysqli->select_row('*', $s_table, "WHERE id = $sig_id", MYSQLI_OBJECT);      
        
        if (is_object($sig_info)) {
            $flags = $sig_info->flags;            
            $is_grid = $flags & SIG_FLAG_GRID;
            $info = $is_grid ? 'Grid bot' : 'Signal';
            $info .= sprintf(' %d stats from %s', $sig_id, $start);
            printf("<h3>%s</h3>\n", $info);        
            $info = '';
            if ($is_grid)
              $info = sprintf("low bound %s, high bound %s", 
                              format_qty($sig_info->stop_loss), format_qty($sig_info->take_profit));
            else {
                if ($flags & SIG_FLAG_SL)
                    $info .= sprintf("stop loss %s ", format_qty($sig_info->stop_loss));
                if ($flags & SIG_FLAG_TP)
                    $info .= sprintf("take_profit %s ", format_qty($sig_info->take_profit));
            }
            printf("<div>%s</div>\n", $info);
        }
        if ($buy_volume + $sell_volume == 0) {
            printf("<h3>ERROR: Signal %d has no volume in period from %s</h3>\n", $sig_id, $ts_past);
            return;
        }    
        
        printf("<div>total orders for signal = %d in period from %s</div>\n", $buy_count + $sell_count, $ts_past);           
        printf("<table border=1>\n");
        printf("  <tr><th>Side<th>Orders count<th>Saldo qty<th>Volume<th>Avg. price</tr>\n");
        $avg_buy = $avg_sell = 0;
        if ($buy_qty > 0) $avg_buy = $buy_volume / $buy_qty;               
        if ($sell_qty > 0) $avg_sell = $sell_volume / $sell_qty;

        printf("  <tr><td>Buy<td>%d<td>%s<td>%s<td>%.5f</tr>\n", 
                $buy_count, format_qty($buy_qty), format_qty($buy_volume), $avg_buy);
        printf("  <tr><td>Sell<td>%d<td>%s<td>%s<td>%.5f</tr>\n", 
                $sell_count, format_qty($sell_qty), format_qty($sell_volume), $avg_sell);
        $cross = min($buy_qty, $sell_qty);        
        echo "</table>\n";    
        if ($cross > 0) {
            $RPnL = $cross * ($avg_sell - $avg_buy);
            $cl = $RPnL > 0 ? 'lime' : 'red';
            printf("<h4>Realized PNL = <font style='color:$cl'>$%s</font></h4>\n", format_qty($RPnL));
        }    
        flush();
    }

    flush();
    unset($signals[0]);
    ksort($signals);
    if (is_int($sig_filter))
        dump_signal_stats($sig_filter);
    elseif (is_int($pair_filter))  {
        foreach ($signals as $sig_id => $v)    
            dump_signal_stats($sig_id);                

        echo "<img src='exec_report.php?bot=$bot&exch=$exch&account=$acc_id&pair_id=$pair_filter&interval=15' title='Exec Report'>\n";
    }        

    session_write_close();
SKIP:       
 ?>
 </html>



