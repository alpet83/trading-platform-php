<?php
    require_once('lib/common.php');
    require_once('lib/esctext.php');
    require_once('lib/db_tools.php');
    require_once('lib/db_config.php');
    require_once('lib/admin_ip.php');
    require_once('lib/auth_check.php');
    require_once('lib/mini_core.php');

    if (!str_in($user_rights, 'view'))
        error_exit("Rights restricted to %s", $user_rights);
    $is_admin  = str_in($user_rights, 'admin'); // means user can enable/disable bots 
    $is_trader = str_in($user_rights, 'trade'); // means user can affect to trading  
 ?>

<!DOCTYPE html>
<HTML>
  <HEAD>
  <TITLE>Trading BOTs Home Page</TITLE>   
  <style type='text/css'>
   td, th { padding-left: 4pt;
           padding-right: 4pt; 
           text-shadow: 1px 1px 3px #202020;
    } 

   table { 
        border-collapse: collapse;
   }        
   .dark-font td { color: #0a0a01; }
   .light-font td { color: #fefede; }

   .ra { text-align: right; }
   .error { color: red; }
   .microtext {
        font-size: 8pt;
      font-family: 'Arial';
   }
  </style>  
  <link rel="stylesheet" href="css/dark-theme.css">
  <link rel="stylesheet" href="css/apply-theme.css">
  <link rel="stylesheet" href="css/colors.css">     


  <script>
    function Edit(app, param, value) {
      document.location ='index.php?impl_name='+ app + '&param_edit=' + param + '&value='+prompt("Edit " + param, value);
    }    
    function Toggle(app, param) {
      document.location ='index.php?impl_name='+ app +'&param_toggle=' + param;
    }
    function Reload() {
      document.location = 'index.php';
    }
    function DefferedReload() {
      setTimeout(Reload, 2000);  
    }
  </script>
  </HEAD>    
<?php
    mysqli_report(MYSQLI_REPORT_OFF);
    $mysqli = init_remote_db('trading');
    if (!$mysqli) 
            die("#FATAL: DB inaccessible!\n");
    $bots = $mysqli->select_map('applicant,table_name', 'config__table_map', "ORDER BY applicant");     
    $activity = $mysqli->select_map('account_id,ts,ts_start,applicant,funds_usage,last_error', 'bot__activity', 'ORDER BY applicant' );
    $redundancy = $mysqli->select_map ('account_id,exchange,master_pid,uptime', 'bot__redudancy', '');

    $query = false;
    $remote = $_SERVER['REMOTE_ADDR'];
    $impl = rqs_param('impl_name', null);
    $param = rqs_param('param_edit', null);
    if (is_string($impl) && isset($bots[$impl])) {
        $editable =  ['position_coef', 'debug_pair', 'max_order_cost'];
        $cfg_table = $bots[$impl];
        if (in_array($param, $editable) && $is_trader) {
            $value = rqs_param('value', null);
            $query = "UPDATE `$cfg_table` SET value = '$value' WHERE param = '$param';";      
        }
        $switchable = ['trade_enabled'];

        $param = rqs_param('param_toggle', null);
        if (in_array($param, $switchable) && $is_admin)             
            $query = "UPDATE `$cfg_table` SET value = value ^ 1 WHERE param = '$param';";     
       
        if (is_admin_ip($remote) && $query) {                        
            if ($mysqli->try_query($query)) 
                print("#OK: parameter updated\n");
            else
                printf("<span class=error>ERROR: %s</span>\n", $mysqli->error);
            
        } else {
            print("<span class=error>ERROR: access denied for $remote</span>\n");
        } 
      echo "<BODY onLoad='DefferedReload()'>\n";
   }    
   else 
      echo "<BODY>\n";
?>


<TABLE BORDER=1> 
 <THEAD>
  <TR><TH>Bot<TH>Account</TH><TH>Started</TH><TH>Last alive</TH><TH>Matched orders</TH><TH>Funds usage</TH><TH>Restarts</TH><TH>Exceptions</TH><TH>Errors</TH><TH>Last error</TH><TH>PID</TH><TH>Position coef</TH><TH>Enabled</TH></TR>
 </THEAD>
 <TBODY>

<?php
    $day_start = gmdate('Y-m-d 00:00:00'); // today
    $color_scheme = 'html';
    

    $acc_map = [];
    $cfg_map = [];
    $pairs_configs = [];  
    $btc_price = 80000; 
    $btc_time = date('Y-m-d 0:00');

    foreach ($activity as $acc_id => $row) {        
        $app = $row['applicant'];
        $ts = $row['ts'];        
        $started = $row['ts_start'];                
        $rd_info = $redundancy[$acc_id] ?? null;                

        $pid = '?';
        $uptime = -time();
        if ($rd_info) {
            $pid = $rd_info['master_pid'];
            $uptime = $rd_info['uptime'];
        }
        $cfg_table = $bots[$app] ?? 'none';
        $config = $mysqli->select_map('param,value', $cfg_table, "WHERE account_id = $acc_id");
        if (is_null($config)) {
            print("<tr><td colspan=5>ERROR: no config for $app => $cfg_table</tr>\n"); 
            continue;
        }

        $bot_dir = strtolower("/tmp/$app");                        
        $cfg_map[$app] = $config;
        $acc_map[$app] = $acc_id;

        $exch = strtolower($config['exchange'] ?? 'NYMEX');               

        $ticker = $mysqli->select_row('last_price,ts_updated', "{$exch}__tickers", "WHERE pair_id = 1");
        if (is_array($ticker) && $ticker[1] > $btc_time) {
            $btc_price = $ticker[0];
            $btc_time = $ticker[1];
        }
 
        $pairs_table = "{$exch}__pairs_map";        
        $bot_pairs = $mysqli->select_map('pair_id,pair', $pairs_table, 'ORDER BY pair');
        $pairs_configs[$app] = [];
        foreach ($bot_pairs as $pair_id => $pair) {
            if (is_file("{$bot_dir}/$pair.json")) {
                $pairs_configs[$app][$pair_id] = file_load_json("{$bot_dir}/$pair.json"); // pair config load
            }
        }


        $mo_table = "{$exch}__matched_orders";          
        $err_table = "{$exch}__last_errors";                  
        $last_err = $row['last_error'];
        if ('' == $last_err)         
            $last_err = $mysqli->select_value('CONCAT(TIME(ts), " ", message)', $err_table, "WHERE (account_id = $acc_id) ORDER BY ts DESC LIMIT 1") ?? '';
        $last_err = colorize_msg($last_err);

        if (strlen($last_err) > 80)
            $last_err = '<span style=microtext>'.substr($last_err, 0,80).'...</span>';


        $matched_count = $mysqli->select_value('COUNT(*)', $mo_table, "WHERE (account_id = $acc_id) AND (ts >= '$day_start')");
        $errors = $mysqli->select_value('COUNT(*)', $err_table, "WHERE (account_id = $acc_id)");

        $evt_table = "{$exch}__events";
        $events = $mysqli->select_map('event,COUNT(*)', $evt_table, "WHERE account_id = $acc_id AND ts >= '$day_start' GROUP BY event");
        $restarts = 0;
        $exceptions = 0;
        if (is_array($events)) {
            $restarts = $events['INITIALIZE'] ?? 0;
            $exceptions = $events['EXCEPTION'] ?? 0;
        }

        $bgc = 'Canvas';        
        $color = $config['report_color'] ?? false;
        if ($color && str_in($color, ','))
            $bgc = "rgb($color)";      

        $bot_details = "dashboard.php?bot=$app&account=$acc_id&exchange=$exch";
        printf("<TR style='background-color:$bgc'><TD><a href='$bot_details'> %s</a><TD> %d <TD>%s <TD>%s", 
                    $app, $acc_id, $started, $ts);
        printf("<TD><A href='matched_orders.php?bot=$app'>%d</a><TD>%.1f%%",$matched_count, $row['funds_usage']);

        printf("<TD>%d<TD>%d", $restarts, $exceptions);
        printf("<TD><a href='last_errors.php?bot=$app'>&nbsp;%7d</a>\n", $errors);
        printf("\t\t<TD>%s<TD>%s\n",  $last_err, $pid);

        $pos_coef = $config['position_coef'] ?? 0.0;
        $enabled =  $config['trade_enabled'];
        if ($is_trader)
            printf("\t\t<TD><u onClick=\"Edit('%s', 'position_coef', %.6f)\">%.6f</u></TD>\n", $app, $pos_coef, $pos_coef);
        else
            printf("\t\t<TD>%.6f</TD>\n", $pos_coef);

        $active = $is_admin ? '' : 'disabled';
        printf ("\t\t<TD><INPUT TYPE='checkbox', onClick=\"Toggle('%s', 'trade_enabled')\" %s $active />", $app, $enabled ? 'checked' : '');
        echo "</TD></TR>\n";
    }        
?>
 </TBODY>
</TABLE>
<?php
    $json = curl_http_request("http://vps.vpn/pairs_map.php?full_dump=1");
    $symbol_map = json_decode($json, true); // for all bots

    if (!is_array($symbol_map)) {        
        var_dump($symbol_map);
        error_exit("ERROR: wrong pairs_map");
    }    
?>

<h3>Risk mapping</h3>
<TABLE BORDER=1> 
  <THEAD><TR><TH>Pair/Symbol
<?php
       
    $pos_map = [];    
        


    // вывод матрицы позиций, по всем ботам, с пересечением по инструментам
    foreach ($activity as $acc_id => $row) {        
        $app = $row['applicant'];        
        echo "<TH>$app<!--";        
        foreach ($cfg_map as $bot => $config) {
            if ($acc_id != $acc_map[$bot]) continue;
            $exch = strtolower($config['exchange'] ?? 'NYMEX');            
            $pos_map[$bot] = $mysqli->select_map('`pair_id`,`current`,`offset`', "{$exch}__positions", "WHERE account_id = $acc_id");
            

        }
        echo "-->";
    }
    echo "<TH>Summary<TH>Volume</TR>\n</THEAD>\n";


    printf("<!-- BTC PRICE %.1f at %s -->\n", $btc_price, $btc_time);
    $price = 1;
    $short_volume = 0;
    $long_volume = 0;

    function is_light_color(string $color) {
        if (in_array(strtolower($color),[ 'white', 'lawngreen', 'khaki', 'gold', 'yellow', 'cyan']) || str_in($color, 'light'))
            return true;
        if (str_in($color, '#')) {
            $r = hexdec(substr($color, 1, 2));
            $g = hexdec(substr($color, 3, 2));
            $b = hexdec(substr($color, 5, 2));
            $lum = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
            return $lum > 160;     
        }
        return false;
    }

    foreach ($symbol_map as $pair_id => $rec) {
        $row = [];
        $sum = 0;
        $count = 0;
        $price = 1;
        $volume = 0;
        $bgc = 'Canvas';
        $fgc = 'CanvasText';
        if (isset($rec['color'])) {
            $bgc = $rec['color'];
            $fgc = is_light_color($bgc) ? 'dark-font' : 'light-font';
        }      

        foreach ($pos_map as $bot => $positions) {
            $ti = $pairs_configs[$bot][$pair_id] ?? null;
            if (isset($positions[$pair_id]) && is_object($ti)) {
                $pos = $positions[$pair_id];
                if (isset($ti->last_price))
                    $price = $ti->last_price;
                $qty = AmountToQty($ti, $price, $pos['current']);                
                $sum += $qty;
                $vol = $price * $qty;                
                if (in_array($ti->quote_currency, ['XBT', 'BTC']))
                    $vol *= $btc_price;
                
                $volume += $vol;
                $row []= format_qty($qty);
                $count ++;
            }
            else
                $row []= '';                        
        }   // foreach bot => pos

        $row = implode('<TD>', $row);
        if ($count > 0) 
            printf("<TR class=$fgc style='background-color:$bgc;'><TD>%s<TD>%s<TD class=ra>%s<TD class=ra>$%.2f</TR>\n", 
                    $rec['symbol'], $row, format_qty($sum), $volume);
        if ($volume > 0) $long_volume += $volume;
        if ($volume < 0) $short_volume -= $volume;
        
    }

    printf("<h4>Long volume: $%.2f, Short volume: $%.2f</h4>\n", $long_volume, $short_volume);
?>

</HTML>

