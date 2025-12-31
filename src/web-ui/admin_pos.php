<?php
    include_once('lib/common.php');
    include_once('lib/db_tools.php');
    include_once('lib/db_config.php');    
    include_once('table_render.php');
    require_once('lib/admin_ip.php');

    $user_rights = 'trade'; // default     
    require_once('lib/auth_check.php');
    // ob_implicit_flush();

    $addr = $_SERVER['REMOTE_ADDR'];
    $acc_id = rqs_param('account', -1);
    $exch = rqs_param('exch', 'default');
    $exch = rqs_param('exchange', $exch);

    $script = $_SERVER['SCRIPT_NAME'];
    $url_back = "$script?exch=$exch";

    $title = "$acc_id $exch pos";

    if ($acc_id > 0) 
        $url_back .= '&account='.$acc_id;
    

    $reply = rqs_param('reply', 'web');
    if ('short' === $reply || isset($header_printed))
        goto skip_head;

?>
<html>
 <head>
    <title><?php echo $title; ?></title>
 </head>
<body>
<?php
skip_head: 
    if (is_null($mysqli))
    $mysqli = init_remote_db('trading');
    if (!$mysqli)
    die("#FATAL: cannot initialze DB interface!\n");


?>
<script type="text/javascript">
    function on_load() { setTimeout(go_back, 5000); }
    function go_back() { <?php echo " document.location=\"$url_back\";\n"; ?> }
</script>
<?php
    $bot = $exch.'_bot';
    $cfg_table = $mysqli->select_value('table_name', 'config__table_map', "WHERE applicant = '$bot'");
    if (!$cfg_table)
        die("#ERROR: not found entry for bot $bot in config");

    if ($acc_id < 0)
        $acc_id = $mysqli->select_value('account_id', $cfg_table, "WHERE param = 'exchange'"); // default first

    $table = $exch.'__positions';

    $self = $_SERVER['PHP_SELF'];
    mysqli_report(MYSQLI_REPORT_OFF);

    //  $dict = file_get_contents($pmap_file);
    $pairs_map = $mysqli->select_map('pair_id,symbol', $exch.'__tickers');
    $append = $mysqli->select_map('pair_id,symbol', $exch.'__ticker_map');
    if (is_array($append))
        $pairs_map = array_replace($pairs_map, $append);
    
    $params = "LEFT JOIN `$exch"."__tickers` AS T ON T.pair_id = P.pair_id\n";
    $params .= "LEFT JOIN `$exch"."__ticker_map` AS TM ON TM.pair_id = P.pair_id\n";
    $params .= "WHERE account_id = $acc_id\n";
    $params .= "ORDER BY pair";

    $rows = $mysqli->select_rows('P.*,T.last_price,COALESCE(T.symbol, TM.symbol) as pair', "`$table` as P", $params, MYSQLI_ASSOC);
    if (!$rows)
        die("#FATAL: load failed from table $table, for account_id = $acc_id\n");

    foreach ($rows as $i => $row)
        if (is_null($row['pair'])) {
            $pair_id = $row['pair_id'];
            $rows[$i]['pair'] = "#$pair_id";            
        }    


    $action = rqs_param('action', 'nope');    
    if ('Update' === $action && str_in($user_rights, 'trade')) {
        if ('short' != $reply)
            echo "<body onLoad='on_load()'>\n<pre>\n";
        // print_r($_SERVER);
        $remote = $_SERVER['REMOTE_ADDR'];        
        if (!is_admin_ip($remote)) 
            die("#ERROR: operation not permited for $remote\n");

        $pair_id = rqs_param('pair_id', -1);
        $offset = rqs_param('offset', 0);
        $offset = doubleval($offset);
        $query = "UPDATE `$table` SET `offset` = $offset WHERE (account_id = $acc_id) AND (pair_id = $pair_id);";
        $mysqli->try_query($query);
        echo " affected rows: {$mysqli->affected_rows}\n";
        if ('short' != $reply)
            echo "</span><input type='button' value='Back' style='spacing-left:100px;width:200px;' onClick='go_back()'/>";
        flush();
        die('');
    }

    define('ROW_HEIGHT', 24);

    if ('short' === $reply) {
        $tr = new TableRender(400, (1 + count($rows)) * ROW_HEIGHT);
        $tr->SetColumns(0, 40, 250);
        $tr->DrawBack();
        $tr->DrawGrid(ROW_HEIGHT);
        $nr = 0;
        $tr->DrawText(1, 0, "Position offsets config for $exch", 'white');
        foreach ($rows as $row) {
            $pair_id = $row['pair_id'];
            if (!isset($pairs_map[$pair_id])) continue;
            $nr ++;
            $tr->DrawText(0, $nr, $pair_id);
            $tr->DrawText(1, $nr, $pairs_map[$pair_id]);
            $color = 'yellow';
            $ofs = $row['offset'];
            if ($ofs > 0) $color = 'green';
            if ($ofs < 0) $color = 'red';

            $tr->DrawText(2, $nr, $ofs, $color);
        }
        header ('Content-Type: image/png');
        $tr->StrokePNG();
        die('');
    }    
    echo "<h2>Position offset configuraton for account #$acc_id</h2>";
?>
    <pre></pre>
    <table cellpadding=7 border=1 style='border-collapse:collapse;'>
      <thead>
       <tr><th>Time</th><th>Pair<th>Current<th>Target<th>Last price<th>RPnL<th>UPnL</th><th>Offset
      <tbody>
<?php
    foreach ($rows as $row) {
        $ts = $row['ts_current'];
        $pair_id = $row['pair_id'];
        $offset = $row['offset'];  
        $price = $row['last_price'] ?? 0;          

        $pair = $row['pair'] ?? $pairs_map[$pair_id] ?? "unknown #".print_r($pair_id, true);       
        if (is_array($pair))
            $pair = $pair[0];  
        
        $cpos = $row['current'];
        $tpos = $row['target'];    
        $rpnl = $row['rpnl'] ?? 0;
        $upnl = $row['upnl'] ?? 0;
        $active = $is_trader ? '' : 'disabled';

        echo "  <tr><td>$ts<td>$pair<td>$cpos<td>$tpos<td>$price<td>$rpnl<td>$upnl\n";
        echo "<td><form action='$self' method='GET'>\n";
        echo "    <input type='hidden' name='exch' value='$exch'/>\n";
        echo "    <input type='hidden' name='account' value='$acc_id'/>\n";
        echo "    <input type='hidden' name='pair_id' value='$pair_id'/>\n";
        echo "    <input type='text'   name='offset' value='$offset' $active/>\n";
        echo "    <input type='submit' name='action' value='Update' $active/>\n";
        echo "   </form>\n";
    }
?>
 </table>
</form>

