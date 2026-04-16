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

      $candidates = [];

      $env_user = getenv('SIGNALS_LEGACY_DB_USER');
      $env_pass = getenv('SIGNALS_LEGACY_DB_PASSWORD');
      if ($env_user !== false && $env_user !== '')
        $candidates[] = [$env_user, $env_pass !== false ? $env_pass : ''];

      if (defined('MYSQL_USER') && defined('MYSQL_PASSWORD'))
        $candidates[] = [MYSQL_USER, MYSQL_PASSWORD];

      if (isset($db_configs[$db_name]) && is_array($db_configs[$db_name]))
        $candidates[] = [$db_configs[$db_name][0] ?? 'sigsys', $db_configs[$db_name][1] ?? ''];

      if (isset($db_configs['sigsys']) && is_array($db_configs['sigsys']))
        $candidates[] = [$db_configs['sigsys'][0] ?? 'sigsys', $db_configs['sigsys'][1] ?? ''];

      $candidates[] = ['sigsys', ''];

      // De-duplicate candidates while preserving order.
      $unique = [];
      $seen = [];
      foreach ($candidates as $item) {
        $u = strval($item[0] ?? '');
        $p = strval($item[1] ?? '');
        $key = $u . "\0" . $p;
        if (isset($seen[$key]))
          continue;
        $seen[$key] = true;
        $unique[] = [$u, $p];
      }

      return $unique;
    }

    function connect_trade_event_db(array $cred_candidates, string $db_host, string $db_name, string $db_sock): mysqli_ex {
      $last_error = null;
      foreach ($cred_candidates as $pair) {
        $db_user = strval($pair[0] ?? '');
        $db_pass = strval($pair[1] ?? '');

        try {
          if ($db_sock && file_exists($db_sock))
            return new mysqli_ex(null, $db_user, $db_pass, $db_name, null, $db_sock);
          return new mysqli_ex($db_host, $db_user, $db_pass, $db_name);
        }
        catch (Throwable $E) {
          // Socket auth can fail when DB lives on a different container; retry over TCP.
          try {
            return new mysqli_ex($db_host, $db_user, $db_pass, $db_name);
          }
          catch (Throwable $E2) {
            $last_error = $E2;
          }
        }
      }

      if ($last_error)
        throw $last_error;

      throw new RuntimeException('No DB credentials candidates available');
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

    $db_creds = resolve_trade_event_db_creds('sigsys');
    $db_host = getenv('SIGNALS_LEGACY_DB_HOST') ?: 'signals-legacy-db';
    $db_name = getenv('SIGNALS_LEGACY_DB_NAME') ?: 'trading';
    $db_sock = getenv('MYSQL_SOCKET_PATH') ?: '/run/mysqld/mysqld.sock';
    try {
      $mysqli = connect_trade_event_db($db_creds, $db_host, $db_name, $db_sock);
    }
    catch (Throwable $E) {
      die ('cannot connect to DB server: '.$E->getMessage());
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

    // USE channel IDs from channels table
    if ($chat) {
      if (isset($chat_map[$chat]))
        $chat = $chat_map[$chat];
      else if (is_numeric($chat))
        ;
      else
        $chat = null;
    }

    if ($tag && $event && $hid) {
      if (is_string($attach) && strlen($attach) > 0) {
        // Direct attach text from request (already decoded)
        $attach = urldecode($attach);
      } elseif (isset($_FILES[$fname])) {
        $file_path = $_FILES[$fname]['tmp_name'];
        // save attach file as binary text
        $attach = file_get_contents($file_path);
        // log_cmsg("#INFO: got attached file %s, type %s, size = %d", $_FILES[$fname]['name'], $_FILES[$fname]['type'], strlen($attach));
      }

      if ($attach) {
        // $attach = str_ireplace("\n", '\n', $attach);  // prevent SQL errors 
        if (false !== strpos($attach, "\x00")) {
          // use base64 to avoid SQL binary issues
          // log_cmsg("#WARN: got binary attach, encoded to BASE64");
          $attach = 'BASE64:' . base64_encode($attach);
        }
        // Prevent SQL errors on utf8 bytes
        $attach = $mysqli->real_escape_string($attach);  
      }

      // TODO: review default event level here
      $ins = "INSERT INTO events (ts,id_task,id_host,tag,event,event_level,chat_id,attach,flags) 
              VALUES ('$ts',$pid, $hid, '$tag', '$event',0, " .
                ($chat ? "$chat" : "NULL") . ",".
                ($attach ? "'$attach'" : "NULL") . ",".
                "$flags)";
      // log_cmsg("#DBG: query = %s", $ins);
      $ok = $mysqli->query($ins);      
      if (!$ok) { 
        die(tss().". #FAIL($idx/$pid): event to DB cannot be inserted: ".$mysqli->error . ", query = ".$ins."\n");
      }

      if ($value) {
        $ins = "INSERT INTO host_stats (id_host,tag,value) VALUES ($hid,'$tag', $value)
              ON DUPLICATE KEY UPDATE value = VALUES(value), ts = CURRENT_TIMESTAMP()";
        // log_cmsg("#DBG: query = %s", $ins);
        $ok = $mysqli->query($ins);
        if (!$ok) { 
          die(tss().". #FAIL($idx/$pid): host stats to DB cannot be inserted: ".$mysqli->error . ", query = ".$ins."\n");
        }      
      }

      //if ($hid > 0) {
      //    $ok = $mysqli->query("UPDATE hosts SET ts = '$ts' WHERE id = $hid"); 
      //}

      $eid = intval($mysqli->insert_id ?? 0);
      if ($eid <= 0) {
        // Some MariaDB setups report insert_id=0 for explicit replication flows.
        $eid = intval($mysqli->select_value('id', 'events', "WHERE ts = '$ts' AND id_task = $pid AND id_host = $hid AND tag = '$tag' AND event = '$event' ORDER BY id DESC"));
      }

      if ($eid > 0) {
        printf(tss().". #SUCCESS($idx/$pid): event '$tag' added to DB with id = %d, host id = %d, task id = %d
", $eid, $hid, $pid);
        // in no valid condition event should be duplicated:
        $mysqli->query("DELETE FROM events WHERE id = $eid-1 AND ts = '$ts' AND id_task = $pid and id_host = $hid and tag = '$tag' and event = '$event'");
      }
      else {
        // Keep API response non-fatal: event row is already inserted.
        printf(tss().". #WARN($idx/$pid): event inserted, but row id cannot be resolved
");
      }
    } else {
      // if no one host found, insert with fake host id = 0
      $ins = "INSERT INTO events (ts,id_task,id_host,tag,event,flags) VALUES ('$ts',$pid, 0, '$tag', '$event',$flags)";
      $ok = $mysqli->query($ins);
      if ($ok) 
        printf(tss().". #WARN($idx/$pid): unknown host ID. Event inserted to DB with fake host id = 0\n");
      else
        die(tss().". #FAIL($idx/$pid): event cannot be inserted to DB with host id = 0\n");
    }
