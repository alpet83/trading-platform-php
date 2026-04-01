<?php
    header("Content-Encoding: utf-8");
    header("Content-Type: text/html");

    ob_implicit_flush();
    set_time_limit(150);
    $app_root = dirname(__DIR__);
    include_once($app_root."/src/lib/common.php");
    include_once($app_root."/src/lib/db_tools.php");
    if (file_exists('/usr/local/etc/php/db_config.php'))
      include_once('/usr/local/etc/php/db_config.php');

    function resolve_trade_event_db_creds(string $db_name): array {
      global $db_configs;

      $user = getenv('SIGNALS_LEGACY_DB_USER');
      $pass = getenv('SIGNALS_LEGACY_DB_PASSWORD');
      if ($user !== false && $user !== '')
        return [$user, $pass !== false ? $pass : ''];

      if (defined('MYSQL_USER') && defined('MYSQL_PASSWORD'))
        return [MYSQL_USER, MYSQL_PASSWORD];

      if (isset($db_configs[$db_name]) && is_array($db_configs[$db_name]))
        return [$db_configs[$db_name][0] ?? 'trading', $db_configs[$db_name][1] ?? ''];

      if (isset($db_configs['trading']) && is_array($db_configs['trading']))
        return [$db_configs['trading'][0] ?? 'trading', $db_configs['trading'][1] ?? ''];

      return ['trading', ''];
    }

    $color_scheme = 'cli';

    file_put_contents('/var/log/attach.log', print_r($_FILES, true));
    $log_file = fopen('/var/log/trade_event.log', 'a');

    $idx = rqs_param('id', -1);
    $pid = rqs_param('pid', -1);
    $tag = rqs_param('tag', false);
    $host = rqs_param('host', false);
    $event = rqs_param('event', false);
    $value = rqs_param('value', 0);
    $flags = rqs_param('flags', 0);
    $fname = rqs_param('filename', 'attach');
    $attach = rqs_param('attach', null, '');
    $chat = rqs_param('channel', 'default');

    [$db_user, $db_pass] = resolve_trade_event_db_creds('trading');
    $db_host = getenv('SIGNALS_LEGACY_DB_HOST') ?: 'signals-legacy-db';
    $db_sock = getenv('MYSQL_SOCKET_PATH') ?: '/run/mysqld/mysqld.sock';
    try {
      if ($db_sock && file_exists($db_sock))
        $mysqli = new mysqli_ex(null, $db_user, $db_pass, 'trading', null, $db_sock);
      else
        $mysqli = new mysqli_ex($db_host, $db_user, $db_pass, 'trading');
    }
    catch (Throwable $E) {
      $mysqli = new mysqli_ex($db_host, $db_user, $db_pass, 'trading');
    }
    if ($mysqli->connect_error)
      die ('cannot connect to DB server: '.$mysqli->connect_error);


    $prev = $mysqli->character_set_name();  
    if (false === stripos($prev, 'utf8mb4')) {
      log_cmsg("#WARN: charset is %s, changing to utf8mb4...", $prev);
      $mysqli->set_charset('utf8mb4');
    }    
    $list = $mysqli->try_query("SHOW VARIABLES LIKE 'character_set%';");
    if ($list) {
      $rows = $list->fetch_all(MYSQLI_NUM);   
      foreach ($rows as $row)
          log_cmsg("#INFO: charset vars:\n %s", implode(' = ', $row));
    }  

    $event = urldecode($event);
    $event = str_ireplace("\n", '\n', $event);  // prevent SQL errors 
    $event = $mysqli->real_escape_string($event); 

    $rqk = json_encode(array_keys($_REQUEST));
    $color_scheme = 'cli';
    // $mysqli->select_from('id,task,complete,status,ts_added,ts_updated', 'tasks', $strict);
    if ($tag && $event) {
      // $event =  mb_convert_encoding ($event,'ISO-8859-1', 'UTF-8');  // encode UTF-8 chars
      log_cmsg("~C97 #DBG:~C00 Event registrator params [$tag, $host, '$event']");
    }   
    else {
      $input = file_get_contents( 'php://input' );
      if ('' !== $input) {
        echo 'Total input: '.strlen( $input )."\n";   
        file_put_contents('/var/log/attach.log', $input);
      } else
        file_put_contents('/var/log/attach.log', print_r($_REQUEST, true)); 

      die(tss().". #FATAL($idx/$pid): Must be specified TAG + EVENT! Request keys: $rqk \n");
    }  

    // log_cmsg("~C95 #DBG:~C00 used keys in request %s", $rqk);
    // log_cmsg("~C95 #DBG:~C00 used keys in _POST %s", json_encode(array_keys($_POST)));
    // flush();


    // $mysqli->select_db('trading');
    $chat_map = $mysqli->select_map('channel,chat_id', 'channels');

    if (!$host && isset($_SERVER['REMOTE_ADDR']))
      $host = trim($_SERVER['REMOTE_ADDR']);

    $ts = date(SQL_TIMESTAMP);

    $hid = false;
    if ($host)
      $hid = $mysqli->select_value('id', 'hosts', "WHERE (name = '$host') OR (ip = '$host')");

    if (!$hid)
    {
      echo "#WARN: Host [$host] is unknown!\n";
      $hid = 9;
    }



    if (is_null($attach)) // accepted only string encoded data
        $attach = 'null';
    else {
        $attach = base64_decode($attach);
        $ref  = rqs_param('hash', 'nope');
        $hash = md5($attach);   
        if ($ref == $hash)
            $ref = 'SO_GOOD';
        else 
            $ref = "sender hash = $ref!";
        log_cmsg("~C95 #PERF:~C00 processing attach data, MD5 = $hash $ref...");   
        $attach =  "'". $mysqli->real_escape_string ($attach). "'";
    }  

    $chat_id = $chat_map[$chat] ?? 0;

    $query = "INSERT IGNORE INTO `events`\n (tag, host, event, `value`, flags, attach, chat)\n";
    $query .= "VALUES('$tag', $hid, '$event', $value, $flags, $attach, $chat_id)";

    $cut = substr($query, 0, 180);
    log_cmsg("#DBG: Trying query, flags = 0x%02x:\n %s\n", $flags, $cut);

    $res = $mysqli->try_query($query);
    if ($res) {
      $ar = $mysqli->affected_rows;
      log_cmsg("~C92#SUCCES:~C00 affected rows $ar from $host");
      printf("#SUCCESS: added $ar event $idx/$pid");
    }  
    else {
      $err = $mysqli->error;
      log_cmsg("~C91#FAILED:~C00 error %s\n query: $query", $err);
      printf("#ERROR: mysqli failed %s for event $idx/$pid\n", $err);
    } 

    $mysqli->close();

?>