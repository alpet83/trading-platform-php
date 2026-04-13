<?php
    require_once('lib/common.php');
    require_once('lib/esctext.php');
    require_once('lib/db_tools.php');
    require_once('lib/ip_check.php');
    require_once('lib/db_config.php');
    require_once('lib/auth_check.php');

    $color_scheme = 'cli';
    error_reporting(E_ERROR | E_PARSE | E_WARNING);        
    $log_file = fopen(__DIR__."/logs/pairs_config.log", 'a');

    mysqli_report(MYSQLI_REPORT_OFF);
    $mysqli = init_remote_db('sigsys');
    if (!$mysqli) 
        error_exit("~C91#FATAL:~C00 DB inaccessible!\n");

    $title = "Pairs edit";

    $pair_id = rqs_param('id', 0);
    $key = rqs_param('edit', null);
    $text = rqs_param('text', null);
    if ($key && $text && $pair_id > 0) {
        $user_name = 'unknown';
        header('Location: '.$_SERVER['SCRIPT_NAME']); // redirect back
        $self = $_SERVER['SCRIPT_NAME'];
        print ("<script type='text/javascript'>document.location = '$self';</script>");
        printf("<!-- %d %s %s -->\n", $pair_id, $text, $key);
        // print_r($_REQUEST);
        if (isset($user_id) && $user_id > 0)
            $user_name = $mysqli->select_value('user_name', 'chat_users', "WHERE chat_id = $user_id");
        $res = $mysqli->safe_query("UPDATE `pairs_map` SET `$key` = ? WHERE id = ?", [$text, $pair_id]);
        var_dump($res);
        if ($res !== false) {
            log_cmsg("~C33#INFO:~C00 pair $pair_id $key updated to $text by %s @%s, affected %d rows", $_SERVER['REMOTE_ADDR'], $user_name, $mysqli->affected_rows);
            die('');                        
        }
        else {
            log_cmsg("~C91#ERROR:~C00 pair $pair_id $key update failed by %s @%s: %s", $_SERVER['REMOTE_ADDR'], $user_name, $mysqli->error);
            error_exit("~C91#ERROR:~C00 pair $pair_id $key update failed: %s", $mysqli->error);
        }      
    }
        
?>
    <!DOCTYPE html>
    <HTML>
    <HEAD>
    <TITLE><?php echo $title; ?></TITLE>   
    <style type='text/css'>
    table  { border-collapse: collapse; } 
    td, th { padding-left: 4pt;
           padding-right: 4pt;
              max-height: 24pt; 
          } 
    table { 
        border-collapse: collapse;
    }     
    </style>  
    <link rel="stylesheet" href="css/dark-theme.css">
    <link rel="stylesheet" href="css/apply-theme.css">
    <link rel="stylesheet" href="css/colors.css">     
    <script type="text/javascript">
    function EditValue(id, key, def) {
      document.location  = "<?php echo $_SERVER['SCRIPT_NAME']; ?>?edit=" + key + "&id=" + id + "&text=" + prompt("Enter " + key, def);
    }
    function UpdateCR(id) {
        var cr = document.getElementById('CR' + id).value * 1;
        if (id > 0 && cr >= 0)
            document.location  = "<?php echo $_SERVER['SCRIPT_NAME']; ?>?edit=contract_ratio&id=" + id + "&text=" + cr;
    }
    </script>  
    </HEAD>  
    <BODY>    
    <TABLE BORDER=1 >        
    <TR><TH>Pair ID<TH>Symbol<TH>Bitfinex<TH>BitMEX<TH>Binance<TH>Deribit<TH>Color<TH>Ratio</TR> 
<?php
    $text_cols = 'symbol,bitfinex_pair,bitmex_pair,binance_pair,deribit_pair,color';  
    $rows = $mysqli->select_rows("*", 'pairs_map', "WHERE id > 0 ORDER BY symbol", MYSQLI_OBJECT);
    $text_cols = explode(',', $text_cols);
    printf("<!-- %s\n %s, %s -->\n", json_encode($rows), $mysqli->last_query, $mysqli->error);
    foreach ($rows as $row) {
        printf("<TR><TD>%3d\n", $row->id);   
        foreach ($text_cols as $col) {
            $val = $row->$col;  
            $txt = $val;
            if ('color' == $col && $val != 'none') 
                $txt = "$val&nbsp;<SPAN style='color:$val'>█</SPAN>";
            printf("\t<TD><A HREF='javascript:EditValue(%d, \"%s\", \"%s\")' style='color:#a0ffff'>%s</A></TD>\n", $row->id, $col, $val, $txt);
        }     
        printf("\t<TD><INPUT type='text' id='CR%d' VALUE='%.7f' /><INPUT type='button' value='Update' onClick='UpdateCR(%d)' /></TD>\n",  $row->id, $row->contract_ratio, $row->id);                        
    }
?>
