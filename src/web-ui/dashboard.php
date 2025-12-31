<?php
    require_once('lib/common.php');
    require_once('lib/esctext.php');
    require_once('lib/db_tools.php');
    require_once('lib/db_config.php');
    require_once('lib/auth_check.php');
    require_once('lib/mini_core.php');
    require_once('lib/basic_html.php');


    if (!str_in($user_rights, 'view'))
        error_exit("Rights restricted to %s", $user_rights);

    $mysqli = init_remote_db('trading');
    if (!$mysqli) 
        die("#FATAL: DB inaccessible!\n");

    $bots = $mysqli->select_map('applicant,table_name', 'config__table_map');     
 
    mysqli_report(MYSQLI_REPORT_OFF);

    $impl_name = $_SESSION['bot'] ??  rqs_param('bot', false);      
    // if ($impl_name)  printf("<!-- bot %s, SESSION: %s -->\n", $impl_name, print_r($_SESSION, true));


    $account_id = $_SESSION['account_id'] ?? rqs_param('account',  0);           
    
    $bot  = rqs_param('bot', $impl_name);   
    $core = new MiniCore($mysqli, $bot);
    $bots = $core->bots;
    $cfg_table = 'config__test';

    if (!$impl_name && $account_id > 0)
        foreach ($bots as $app => $table) {
            $acc_id = $mysqli->select_value('account_id', $table);
            if ($acc_id != $account_id) continue;
            printf("<!-- for account %d detected bot id %s -->\n", $account_id, $app);
            $impl_name = $app;
            $cfg_table = $table;
            break;                  
        }  

    
    if (!is_array($core->config)) 
            die("#FATAL: bot $bot config damaged in DB!\n");

    $_SESSION['bot'] = $bot;


    $exch = strtolower($core->config['exchange'] ?? false);   
    if (!is_string($exch))
        die("<pre>#FATAL: no exchange defined for $bot\n".print_r($core, true));        

    $config = $core->config;
    if (!is_array($config))
       die("#FATAL: no config for $impl_name\n");   

    $account_id = $core->trade_engine->account_id;    
    if (0 == $account_id)
       $account_id = $mysqli->select_value('account_id', $cfg_table); // any as default

    $_SESSION['account_id'] = $account_id;
   
    $engine = $core->trade_engine;        
    $exchange = rqs_param('exchange', $config['exchange'] ?? 'NyMEX');
    $title = "$bot dashboard for $account_id";

    $is_trader = str_in($user_rights, 'trade'); // means user can trade
 ?>

<!DOCTYPE html>
<HTML>
  <HEAD>
  <TITLE><?php echo $title; ?></TITLE>   
    <style type='text/css'>
    td, th { padding-left: 4pt;
            padding-right: 4pt;
                max-height: 24pt; 
            } 
    table { 
            border-collapse: collapse;
    }     
    .ra {   text-align: right; }        
    </style>  
    <link rel="stylesheet" href="css/dark-theme.css">
    <link rel="stylesheet" href="css/apply-theme.css">
    <link rel="stylesheet" href="css/colors.css">     
    <script type="text/javascript">        
        function Reload() {
            document.location.reload();
        }
        function CancelOrder(id) {
            var url = <?php printf("'cancel_order.php?bot=%s&id=' + id;\n", $bot); ?>
            var xhr = new XMLHttpRequest();
            xhr.open('GET', url, true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState == 4 && xhr.status == 200) {
                    alert(xhr.responseText);
                    setTimeout(Reload, 60000);
                }
            }
            xhr.send();
        }
    </script>

  </HEAD>  
  <BODY>    
<?php
    $header_printed = true; 
    echo "<H2>$title</H2>\n";
    echo button_link("Go Home..", 'index.php')."\n";    

    include_once('admin_pos.php');  


    $server_ip = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_ADDR'];

    $p_table = $engine->TableName('pending_orders');
    $m_table = $engine->TableName('mm_exec');
    $params = "UNION SELECT * FROM $p_table\n";
    $params .= "WHERE (account_id = $account_id) ORDER BY pair_id ASC,updated DESC";
    $pairs_map = &$core->pairs_map;

    $orders = $mysqli->select_rows('*', $m_table, $params, MYSQLI_OBJECT);
    if (!is_array($orders) || 0 == count($orders)) goto DRAW_EQUITY;


    function print_orders(string $title, mixed $orders) {
        global $pairs_map, $is_trader;
        if (!is_array($orders) || 0 == count($orders)) return;
        printf("<h3>%s %d</h3>\n", $title, count($orders));
?>
<table border=1>
    <thead>
     <tr><th>Time<th>Host<th>Batch<th>Signal<th>Pair<th>Price<th>Amount<th>Matched</th><th>Comment<th>Action</tr>
    </thead>
    <tbody>
<?php
        foreach ($orders as $order) {
            $pair = $pairs_map[$order->pair_id] ?? '#'.$order->pair_id;
            printf("\t<tr><td>%s<td>%d<td>%d<td>%d<td>%s", 
                $order->ts, $order->host_id, $order->batch_id, $order->signal_id, $pair);
            $amount = $order->amount * ($order->buy ? 1 : -1);
            $matched = $order->matched * ($order->buy ? 1 : -1);               
            printf("<td class=ra>%s<td class=ra>%s<td class=ra>%s<td>%s\n", 
                $order->price, $amount, $matched, $order->comment);
            if (!$is_trader) {
                echo "<td>View only</tr>\n";
                continue;
            }
                 
            if ($order->id > 0)               
                printf("<td><input type='button' onClick='CancelOrder(%d)' value='Cancel'/></tr>\n", $order->id);
            else
                printf("<td>invalid id %d\n", $order->id);
        }
        echo "\t</tbody>\n</table>\n";  
    }
    print_orders('Active orders for account', $orders);
    $table = $engine->TableName('mm_limit');
    $orders = $mysqli->select_rows('*', $table, 'ORDER BY pair_id ASC, updated DESC', MYSQLI_OBJECT);
    print_orders('Limit orders for account', $orders);


DRAW_EQUITY:  
    echo "<h2>Last days equity chart</h2>\n";  
    printf ("<div><img src='draw_chart.php?exchange=%s&account_id=%d' ></div>\n", $exchange, $account_id);    
    session_write_close();
?>
  