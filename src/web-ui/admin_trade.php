<?php
    include_once('lib/common.php');
    include_once('lib/db_tools.php');
    include_once('lib/db_config.php');
    require_once('lib/admin_ip.php');
    require_once('lib/auth_check.php');
    
    $remote = $_SERVER['REMOTE_ADDR'];

    if (!is_admin_ip($remote)) 
      die("#FORBIDDEN: $remote not in white list");

    if (!str_in($user_rights, 'view')) 
      error_exit("ERROR: user $user_name has no rights to view/edit signals\n");          

    $acc_id = rqs_param('account', 0);

    //init_db('trading');
    $mysqli = init_remote_db('trading');
    if (!$mysqli)
      die("#FATAL: cannot initialze DB interface!\n");



    $self = $_SERVER['PHP_SELF'];
    $action = rqs_param('action', 'nope');
    $is_trader = str_in($user_rights, 'trade');

    if ('Update' === $action && $is_trader) {
        $response = rqs_param('response', 'default');
        $rs = ('short' === $response);
        if (!$rs) echo "<html>\n <body>\n  <pre>";
        $bot = rqs_param('bot', false);
        if (!$bot)
          die("#FAILED: invalid parameters: ".print_r($_REQUEST, true));
        $acc_id  = rqs_param('account', -1);
        $coef    = rqs_param('pos_coef', -1);
        $enabled = rqs_param('enabled', 0);
        $table = $mysqli->select_value('table_name', 'config__table_map', "WHERE applicant = '$bot'");
        if (!$table)
          die("#ERROR: not found entry for bot $bot in config");

        $enabled = intval('on' === strtolower($enabled));

        if ($acc_id < 0)
            $acc_id = $mysqli->select_value('account_id', $table); // default first

        $prev_coef = $mysqli->select_value('value', $table, "WHERE (account_id = $acc_id) and (param = 'position_coef')");
        $prev_enabled = $mysqli->select_value('value', $table, "WHERE (account_id = $acc_id) and (param = 'trade_enabled')");
        printf("#CONFIG($bot @ $acc_id): coef = %.4f, enabled = %d \n", $prev_coef, $prev_enabled);
        // trade_enabled = $enabled
        if ($coef > -1) {
          $query = "UPDATE `$table` SET value = $coef WHERE (account_id = $acc_id) and (param = 'position_coef');";
          $mysqli->try_query($query);
          if ($mysqli->affected_rows) echo "#SUCCESS: updated 'coef' = $coef\n";
        }
        $query = "UPDATE `$table` SET value = $enabled WHERE (account_id = $acc_id) and (param = 'trade_enabled');";
        $mysqli->try_query($query);
        if ($mysqli->affected_rows) echo "#SUCCESS: updated 'enabled' = $enabled\n";
        if ($rs) die("\n");

        $url_back = $_SERVER['HTTP_REFERER']; // $self."?exch=$exch&account=$acc_id";
        echo "<input type='button' value='Back' onClick='document.location=\"$url_back\"'/>";
        die('');
    }

    $tables = $mysqli->select_map('applicant,table_name', 'config__table_map');
    if (!is_array($tables))
        die("#FAILED: load config__table_map\n");

    $config = array();
    foreach ($tables as $bot => $table)  {
      $res = $mysqli->select_from('account_id,param,value', $table);
      while ($row = $res->fetch_array(MYSQLI_NUM)) {
        $acc_id = $row[0];
        $key = $row[1];
        $val = $row[2];
        if (!isset($config[$bot]))
              $config[$bot] = array($acc_id => array());
        $config[$bot][$acc_id][$key] = $val;
      }

    }

    $table = 'bot__activity';
    $res = $mysqli->select_from('applicant,account_id,funds_usage,uptime', $table);
    if (!$res)
      die("#FATAL: load failed from table $table\n");

    if (!isset($header_printed)) {
        echo "<html>\n\t<body>\n";
    }  

    ?>

    <table cellpadding=7 border=1 style='border-collapse:collapse;'>
      <thead>
      <tr><th>Bot<th>Account<th>Fund usage<th>Uptime<th>Position coef<th>Trade Enabled<th>Command
      <tbody>
    <?php
    while ($row = $res->fetch_array(MYSQLI_NUM)) {
      $bot    = $row[0];
      $acc_id = $row[1];
      $funds  = $row[2];
      $uptime = gmdate('H:i:s', $row[3]);

      // $pair = $pairs_map[$pair_id];
      if (!isset($config[$bot]) || !isset($config[$bot][$acc_id])) continue;
      $cfg = $config[$bot][$acc_id];
      $coef    = $cfg['position_coef'];
      $enabled = $cfg['trade_enabled'] ? 'checked' : '';
      $active = $is_trader ? '' : 'disabled';
      echo " <form action='$self' method='GET'>\n";
      echo "  <tr><td>$bot<td>$acc_id<td>$funds<td>$uptime\n";
      echo "      <td><input type='text', name='pos_coef', value='$coef' $active /> \n";
      echo "      <td><input type='checkbox', name='enabled' $enabled $active /> \n";
      echo "          <input type='hidden' name='bot'     value='$bot'/>\n";
      echo "          <input type='hidden' name='account' value='$acc_id'/>\n";    
      
      echo ("      <td><input type='submit' name='action' value='Update' $active/>\n");  

      echo " </tr>\n</form>\n";

    }
?>
 </table>
</form>