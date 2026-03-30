<?php
    set_include_path(get_include_path().':/app/src:/app/src/lib:/usr/local/lib/php');
    if (file_exists('/usr/local/etc/php/db_config.php'))
    require_once('/usr/local/etc/php/db_config.php');
    require_once("lib/common.php");
    require_once("lib/esctext.php");
    require_once("lib/db_tools.php");

    $telegram_autoload_candidates = [
    __DIR__.'/telegram-bot/vendor/autoload.php',
    __DIR__.'/vendor/autoload.php',
    '/app/src/vendor/autoload.php'
    ];

    $telegram_autoload = false;
    foreach ($telegram_autoload_candidates as $autoload) {
    if (file_exists($autoload)) {
      $telegram_autoload = $autoload;
      break;
    }
    }

    if (false === $telegram_autoload) {
    die("#FATAL: Telegram SDK autoload not found. Install dependency (for example irazasyed/telegram-bot-sdk) and rerun.\n");
    }

    require_once($telegram_autoload); // РџРѕРґРєР»СЋС‡Р°РµРј Telegram SDK
    use Telegram\Bot\Api;

    date_default_timezone_set("Asia/Nicosia");

    define('GET_ALERTS',      'get-alerts');
    define('GET_REPORTS',     'get-reports');
    define('GET_SMS',         'get-sms');

    define('CMD_SIGNAL',      'signal');
    define('CMD_STATUS',      'bot_status');
    define('CMD_RESTART',     'restart');  
    define('CMD_EDIT_COEF',   'pos_coef');
    define('CMD_EDIT_OFFSET', 'pos_offset');

    $log_file = fopen('/var/log/trade_ctrl_bot.log', 'w');

    $hostname = file_get_contents('/etc/hostname');
    $hostname = trim($hostname);

    // Deprecated host hint for direct bot calls. In shared trd_default network keep default as service "bot".
    $bot_server = getenv('BOT_SERVER_HOST') ?: 'bot';

    $hr = date('H');

    $event_id = 0;

    function connect_mysql()
    {
    global $mysqli, $db_configs;
    $db_name = getenv('SIGNALS_LEGACY_DB_NAME') ?: 'trading';
    $db_host = getenv('SIGNALS_LEGACY_DB_HOST') ?: 'signals-legacy-db';
    $db_sock = getenv('MYSQL_SOCKET_PATH') ?: '/run/mysqld/mysqld.sock';
    $db_user = getenv('SIGNALS_LEGACY_DB_USER') ?: (defined('MYSQL_USER') ? MYSQL_USER : ($db_configs[$db_name][0] ?? ($db_configs['trading'][0] ?? 'trading')));
    $db_pass = getenv('SIGNALS_LEGACY_DB_PASSWORD') ?: (defined('MYSQL_PASSWORD') ? MYSQL_PASSWORD : ($db_configs[$db_name][1] ?? ($db_configs['trading'][1] ?? '')));
     if ($db_sock && file_exists($db_sock)) {
       try {
         $mysqli = new mysqli_ex(null, $db_user, $db_pass, $db_name, null, $db_sock);
         return $mysqli;
       }
       catch (Throwable $e) {
         // Fall back to TCP host mode when socket auth/permissions do not match.
         $mysqli = null;
       }
     }

     $mysqli = new mysqli_ex($db_host, $db_user, $db_pass, $db_name);
    return $mysqli;
    }

    connect_mysql();

    if (!$mysqli || $mysqli->connect_error)
      die ("#FATAL: cannot connect to local DB server\n");

    function resolve_telegram_token() {
    if (defined('TELEGRAM_API_KEY') && strlen(trim(strval(TELEGRAM_API_KEY))) > 0)
    return trim(strval(TELEGRAM_API_KEY));

    $env_token = getenv('TELEGRAM_API_KEY');
    if ($env_token && strlen(trim(strval($env_token))) > 0)
    return trim(strval($env_token));

    $token_file = getenv('TELEGRAM_API_TOKEN_FILE') ?: '/etc/api-token';
    if ($token_file && file_exists($token_file)) {
    $file_token = trim(strval(file_get_contents($token_file)));
    if (strlen($file_token) > 0)
      return $file_token;
    }

    return '';
    }

    $telegram_token = resolve_telegram_token();
    if ('' == $telegram_token)
    die("#FATAL: TELEGRAM_API_KEY is not set (constant/env/file)\n");

    $telegram = new Api($telegram_token);

    $location = __DIR__;
    $cwd = getcwd();

    log_msg("#INIT: script started from $location, cwd = $cwd, PID = ".getmypid());
    log_msg(" telegram object created...");
    $pid = getmypid();
    file_put_contents('/var/run/trade_ctrl_bot.pid', $pid);

    $sendQuestion = array();
    $customKeyboard = [['/bot-status', '/ping']];
    $reply_markup = $telegram->replyKeyboardMarkup(array('keyboard' => $customKeyboard, 'resize_keyboard' => true, 'one_time_keyboard' => false));

    function send_buttons(int $chat) {
    global $telegram, $reply_markup;
    try {
     $mparams = array('chat_id' => $chat, 'text'=> 'usual commands', 'reply_markup' => $reply_markup, 'disable_notification' => true);
     $telegram->sendMessage($mparams);
    }
    catch (Exception $E) {
      $msg = sprintf("~C91 #EXCEPTION(send_buttons)~C00: used params ", $E->getMessage(), print_r($mparams, true));
      log_cmsg($msg); 
    }   
    }

    $admin_id = -1;
    $exceptions = 0;

    $allowed_privs = array();

    function check_allowed(int $user_id, string $priv) {
    global $allowed_privs;
    if (!isset($allowed_privs[$user_id])) return false;
    if (false !== array_search($priv, $allowed_privs[$user_id])) return true;
    echo " not allowed [$priv] for $user_id";
    return false;
    }

    function disable_user(int $chat_id) {
    global $mysqli, $users;
    $mysqli->try_query("UPDATE `chat_users` SET enabled = 0 WHERE chat_id = $chat_id");
    foreach ($users as $key => $rec) 
    if ($rec[0] == $chat_id) {
      unset($users[$key]);
      break;
    }    
    } 

    function process_send(string $text, mixed $res): bool {
    global $tag, $event_id,  $mysqli, $telegram;
    // РґРѕР±Р°РІР»РµРЅРёРµ РјРµС‚РѕРє СЃРѕРѕР±С‰РµРЅРёР№ РІ Р»РѕРі, С‡С‚РѕР±С‹ РёС… РїРѕС‚РѕРј СѓРґР°Р»РёС‚СЊ РёР· С‡Р°С‚Р°
    if (!is_object($res)) { 
      log_cmsg('~C91 #ERROR:~C00 invalid res = %s ', var_export($res, true));
      return false;
    }         
    if ($res) 
    try {       
      $json = strval($res);
      log_cmsg("~C94#SEND_RES:~C00 %s ", $json);
      $rec = json_decode($json);      
      $chat = $rec->chat;
      if (!is_object($chat)) {
        log_cmsg('~C91 #ERROR:~C00 check structure: %s ', $json);
        return false;
      }   
      $t_exp = time() + 3600 * 4;
      $msg_id = $rec->message_id;      
      if ($msg_id <= 0) {
        log_cmsg("~C91#ERROR:~C00 invalid message_id = %d", $msg_id);
        return false; 
      } 
      if ('REPORT' == $tag) {
        if (false !== stripos($text, 'account equity')) {
          $telegram->pinChatMessage(['chat_id' => $chat->id, 'message_id' => $msg_id]);
          return true;
        }
        if (false !== strpos($text, 'Volume report')) return true; // СЌС‚Рё РЅР°РґРѕ СЃРѕС…СЂР°РЅРёС‚СЊ
        if (false !== stripos($text, 'Hourly report'))  $t_exp += 8 * 3600; 
        if (false !== stripos($text, 'Startup report')) $t_exp += 20 * 3600;
      }      

      $ts_expire = gmdate(SQL_TIMESTAMP, $t_exp);
      $query = "INSERT IGNORE INTO `chat_log` (id, event_id, chat, tag,  ts_del) ";
      $query .= "VALUES($msg_id, $event_id, {$chat->id}, '$tag', '$ts_expire');";
      // log_cmsg("~C93#TRY_MYSQL:~C00 %s", $query);
      if($mysqli->try_query($query)) {
        log_cmsg("~C97#ECO:~C00 message %d:%d will be deleted after %s ", 
                    $chat->id, $msg_id, date(SQL_TIMESTAMP, $t_exp));
        return true;            
      }              
    } catch (Exception $E) {      
      log_cmsg("~C07~C91#EXCEPTION:~C00 %s", $E->getMessage());       
      log_cmsg("~C07~C93#TRACE:~C00 %s", $E->getTraceAsString());
    }
    else 
      log_cmsg("~C91 #WARN:~C00 not OK: %s", var_export($res, true));

    return true; 
    }


    function on_msg_deleted(int $chat_id, int $msg_id, string $tag) {
    global $mysqli;
    $mysqli->try_query("DELETE FROM chat_log WHERE (id = $msg_id) and (chat = $chat_id)");  
    if ('ORDER' == $tag || 'ALERT' == $tag)
      $mysqli->try_query("UPDATE `events` SET `attach` = NULL WHERE id = $msg_id");
    }
    function cleanup() {
    global $telegram, $mysqli;    
    $rows = $mysqli->select_rows('id, chat, tag, ts_del', 'chat_log', "WHERE NOW() >= ts_del", MYSQLI_ASSOC);
    if (!is_array($rows) || 0 == count($rows)) {
       log_cmsg("~C97#CLEANUP:~C00 no messages to delete yet"); 
       return;
    } 
    $batch = [];   
    $tags = [];

    foreach ($rows as $row) {
      $msg_id = intval($row['id']);
      $chat_id = $row['chat'] * 1;      
      $ts_del = $row['ts_del'];
      $tags[$msg_id] = $row['tag'];
      $res = false;
      $params = [];      

      if (isset($batch[$chat_id])) {
        $batch[$chat_id] []= $msg_id;          
      }
      else
        $batch[$chat_id] = [$msg_id];

    }
    // trying batch method first
    foreach ($batch as $chat_id => $msg_ids) {      
      if (rand(1, 5) > 5)
      try { 
        $params = ['method'=> 'deleteMessage', 'chat_id' => $chat_id, 'message_ids' => $msg_ids, 'messages' => $msg_ids, 'revoke' => true];        
        log_cmsg('~C94#DBG:~C00 trying deleteMessages %s for chat %d ', json_encode($msg_ids), $chat_id);        
        $res = false;        
        // if (method_exists($telegram, 'deleteMessages'  ))
        $res = $telegram->deleteMessages($params);           
        if ($res)  { 
          log_msg("deleteMessages result: ".var_export($res, true));
          log_cmsg("~C93#CLEANUP:~C00 messages was deleted from chat");
          foreach ($msg_ids as $msg_id)
             on_msg_deleted($chat_id, $msg_id, $tags[$msg_id]);             
          continue;  
        } 
        else 
          log_cmsg("~C91#WARN:~C00 deleteMessages not supported, response: %s", var_export($res, true));
      }
      catch (Exception $E) {
        log_cmsg("~C91#EXCEPTION:~C00 %s %s, trace:\n %s", get_class($E), $E->getMessage(), $E->getTraceAsString());
      }    

      // trying single method    
      foreach ($msg_ids as $msg_id)
      try {
        $params = ['chat_id' => $chat_id, 'message_id' => $msg_id]; // 'method' => 'deleteMessage', 
        $res = $telegram->deleteMessage($params);
        if ($res) {
          log_cmsg("~C93#CLEANUP:~C00 message %d was deleted from chat %d, res: %s, ts_del: %s", $msg_id, $chat_id, strval($res), $ts_del);          
          on_msg_deleted($chat_id, $msg_id, $tags[$msg_id]);
          continue;
        }        
        else 
          log_cmsg("~C91#CLEANUP_FAIL:~C00 message %d was not deleted from chat %d, res: %s", $msg_id, $chat_id, strval($res));          

      } catch (Exception $E) {
        $msg = $E->getMessage();
        if (strpos($msg, 'message to delete not found') !== false) {
          log_cmsg("~C97#CLEANUP:~C00 message %d was already deleted from chat %d", $msg_id, $chat_id);
          on_msg_deleted($chat_id, $msg_id, $tags[$msg_id]);
          continue;
        }
        else
          log_cmsg("~C91#EXCEPTION:~C00 %s %s", get_class($E), $E->getMessage());   
      } 

    } // foreach batch 
    }


    function send_msg(int $chat, $flags, $text): bool
    {
    global $telegram, $exceptions;
    if (0 == $chat) return false;

    $text = str_replace('<pre>', '', $text);

    $mparams = [
         'chat_id'              => $chat,
         'text'                 => substr($text, 0, 4096),
         'parse_mode'           => 'HTML',
         'disable_notification' => (0 != $flags & 1)
            ];
    $result = false;        
    try {       
      $res = $telegram->sendMessage($mparams);
      $result = process_send($text, $res);
    } catch (Exception $E) {
      $msg = sprintf("~C91 #EXCEPTION(sendMessage($chat, $flags))~C00: %s, used params %s", $E->getMessage(), print_r($mparams, true));

      if (strpos($msg, 'user is deactivated') !== false) {
        log_cmsg('~C91#CLEANUP:~C00 user $chat is deactivated, removing from chat_users');
        disable_user($chat);
      }
      log_cmsg($msg);
      log_cmsg("~C93#TRACE:~C00 %s", $E->getTraceAsString());
      $exceptions ++;
      $result = false;
    }
    return $result;
    }

    define('ATTACH_FLAG_IMAGE', 8);
    define('ATTACH_FLAG_SILENT', 1);
    function send_attach(int $chat, $flags, $caption, $attach, $fname = 'attach'): bool {
    global $telegram, $exceptions;
    if (0 == $chat) return false;

    $caption = str_replace('<pre>', '', $caption);
    $path = "./chat$chat/";
    if (!file_exists($path)) mkdir($path);
    $fname = $path.$fname;

    if ($flags & 4) {
     $words = explode(' ', $caption);
     $fname = $path.$words[0];
     array_shift($words);
     $caption = implode(' ', $words);
    }


    $img_ext = array('.png', '.jpg', '.gif', '.bmp', '.tiff');
    $doc_ext = array('.dat', '.txt', '.docx', '.xlsx', '.jpg', '.png', '.gif', '.log');



    $mparams = [
        'chat_id'              => $chat,
        'caption'              => $caption,
        'parse_mode'           => 'HTML',
        'disable_notification' => (0 != ($flags & ATTACH_FLAG_SILENT))
           ];

    $ftype = ($flags & 0xf00) >> 8;
    $result = false;
    try {

      if ($flags & ATTACH_FLAG_IMAGE) {
         $fname .= $img_ext[$ftype];
         file_put_contents($fname, $attach);
         $mparams['photo'] = $fname;
         log_cmsg("~C93 #SEND_IMAGE:~C00 ftype = %d, flags = 0x%02x, params = %s", $ftype, $flags, json_encode($mparams));
         $res = $telegram->sendPhoto($mparams);
         $result = process_send($caption, $res);
      } else {
         $fname .= $doc_ext[$ftype];
         file_put_contents($fname, $attach);
         $mparams['document'] = $fname;
         log_cmsg("~C93 #SEND_DOC:~C00 ftype = %d, flags = 0x%02x, params = %s", $ftype, $flags, json_encode($mparams));         
         $res = $telegram->sendDocument($mparams);
         $result = process_send($caption, $res);
      }
    } catch (Exception $e) {
     if (strpos($e->getMessage(), 'user is deactivated') !== false) {
      log_cmsg('~C91#CLEANUP:~C00 user $chat is deactivated, removing from chat_users');
      disable_user($chat);
     }    
     log_cmsg ("~C91 #EXCEPTION(send_attach($chat, $flags)):~C00 catched %s, stack: %s ", $e->getMessage(), $e->getTraceAsString());
     $exceptions ++;
     $result = false;
    }
    // TODO: post timer to delete file
    //  unlink($fname) 
    return $result;
    }

    $params = [
        'offset'  => '0',
        'limit'   => '16',
        'timeout' => '30',
      ];

    if (!$argv) $argv = [ 0, 0, 0 ];

    $run = 1000;

    function sig_handler($signo)
    {
    global $run;
    log_msg("received signal $signo");
    $run = 2;
    }

    // pcntl_signal(SIGKILL, "sig_handler");
    pcntl_signal(SIGTERM, "sig_handler");
    pcntl_signal(SIGHUP,  "sig_handler");

    $ts_start = date(SQL_TIMESTAMP);
    $show_status = 0;
    $con_errors = 0;
    $id = -1;
    $prev = 0;
    $chats = [];
    $tag = 'NOPE';

    echo format_color("~C97%s~C00\n", '-----------=================== Script restarted ===================-------------');


    define('PHP_ERRORS', '/var/log/php_errors.log');
    if (file_exists(PHP_ERRORS)) {
    $lines = file_get_contents(PHP_ERRORS);
    $last = array_slice(explode("\n", $lines), -20);
    foreach ($last as $line)
      if (false !== strpos($line, __FILE__)) 
         log_cmsg("~C91#PHP_ERROR_LAST:~C00 %s". $line);
    }   
    // MAIN LOOP 
    try {
    $wrong_chats = [];
    $post_map = [];
    while ($run >= 0 && $con_errors < 10)
    //for ($loop = 0; $loop < 10; $loop++)
    {
        if ($run < 100)
        {
            $run --;
            $params['timeout'] = 1;
        }

        set_time_limit(900);
        sleep(1); //

        $ts = date(SQL_TIMESTAMP);
        $time = time();

        echo "\n[$ts/$time]. #MSG: waiting for updates -> ";

        $updl = false;

        if ($run >= 3)
          try
          {
            $updl = $telegram->getUpdates($params);
          }
          catch (Exception $e)
          {
            printf('#ERROR: telegram->getUpdates caught exception: %s ', $e->getMessage());
          }
        $updc = 0;
        $minute = date('i');
        if ($minute <= 1 && $exceptions > 10)  {
            send_msg($admin_id, 0, "[$ts]. #WARN: In this bot too many exceptions catched, $exceptions, check logs...");
            $exceptions = 0;
        }
        set_time_limit(60);

        $ts = date(SQL_TIMESTAMP); //
        echo "[$ts]\n";
        // print_r($params);

        if (!$mysqli->ping())
        {
            log_msg("#WARN: MySQL server ping failed, trying reconnect!");
            $mysqli->close();
            connect_mysql();
            if (!$mysqli || $mysqli->connect_error)
            {
              $con_errors ++; 
              log_msg("#ERROR($con_errors): cannot connect to DB server");          
              continue;
            }
            $con_errors = 0;
            $mysqli->select_db('infrastructure');
        }
        // load users set, before updates
        $res = $mysqli->select_from('chat_id,user_name,last_notify,enabled', 'chat_users');
        $users = array();
        while ($res && $row = $res->fetch_array(MYSQLI_NUM))
            $users []= $row;

        // loading privs
        if (0 == count($allowed_privs)) {
          foreach ($users as $rec) {
            $id = $rec[0];
            $user = strtolower($rec[1]);
            // TODO: use DB for loading privs!
            // Replace 'admin_user' and 'operator_user' with actual Telegram usernames
            if ('admin_user' == $user) {
                $admin_id = $id;
                $allowed_privs[$id] = array(CMD_EDIT_COEF, CMD_EDIT_OFFSET, CMD_RESTART, CMD_SIGNAL, CMD_STATUS, GET_ALERTS, GET_REPORTS, GET_SMS);
            }

            if ('operator_user' == $user)
                $allowed_privs[$id] = array(CMD_EDIT_COEF, CMD_SIGNAL, CMD_STATUS, GET_ALERTS, GET_REPORTS, GET_SMS);            if ('cryptomotherfuckers' == $user) 
                $allowed_privs[$id] = array(CMD_STATUS, GET_REPORTS);                 
          } 
          log_cmsg("~C97#INIT:~C00 assigned user privileges\n~C92 ".print_r($allowed_privs, true)."~C00");
        }

        $id = 0;

        if ($updl)
          foreach ($updl as $update) {
            //
            $updc ++;
            echo "#UPD$updc:";
            $uid = $update->getUpdateId();
            $params['offset'] = $uid + 1;

            $json = strval($update);
            $rec = json_decode($json);
            if (isset($rec->channel_post)) {
              log_cmsg("~C94#CHANNEL_POST:~C00 %s", print_r($rec->channel_post, true));
              continue;
            }

            $chat = $update->getChat();

            if (!$chat)
            {
              log_cmsg("~C91#WARN:~C00 strange update, no chat_id: %s", $json);            
              continue;
            }
            // print_r($chat);

            $id = $chat->getId();
            $tt = $chat->getType();
            $user = $chat->getUsername();

            $last_cmd = 0;
            // register user record if new
            if (intval($id) > 0 && strlen($user) > 2)
            {

              $enabled = check_allowed($id, CMD_STATUS) ? 1 : 0;
              $query = "INSERT IGNORE INTO `chat_users`(chat_id, user_name, last_notify, `enabled`)\n ";
              $query .= "VALUES($id, '$user', '2020-12-01 00:00:00', $enabled);";
              $res = $mysqli->try_query($query);

              $row = $mysqli->select_row('last_cmd,last_msg', 'chat_users', "WHERE chat_id = $id");
              if ($row &&  is_array($row)) {
                $last_cmd = $row[0];
                $last_msg = $row[1];
                echo "\n #USER_UPD: chat_id[$id], type[$tt], from[$user] #$uid (last_cmd = $last_cmd, last_msg = $last_msg): ";
              } else  
                  echo "#ERROR: no registration for #$id in chat_users...\n";
            }
            // processing message or command
            $msg = $update->recentMessage();
            if (!$msg) continue;


            $txt = $msg->getText();
            $txt = trim($txt);
            if (!check_allowed($id, CMD_STATUS)) {
              echo " message from unauthorized $user:  $txt\n";
              continue;
            }
            //
            if ($uid > $last_msg)
            {
                  // var_dump($msg);
                  $mysqli->try_query("UPDATE chat_users SET last_msg=$uid WHERE chat_id=$id");

                  // TODO: handle some commands
                  $tokens = explode(' ', $txt);
                  $cmd = $tokens [0];
                  $cmd = strtolower($cmd);
                  $ccp = strpos($cmd, '#');
                  if (false === $ccp) $ccp = strpos($cmd, '/');              
                  $cmd = trim($cmd, "#/\n\t ");              
                  $is_cmd = true;

                  if ($ccp === false || $ccp > 1) {
                    printf("Received possible non-command [%s], cpp = %d \n", $cmd, $ccp);
                    $is_cmd = 0;
                  }
                  if ($cmd == 'start') {
                  send_msg($id, 0, "[$ts]. #HELLO: available commands for you ".implode(',', $allowed_privs[$id]));  
                  send_buttons($id);               
                  } elseif ($cmd == 'bot-status' && check_allowed($id, CMD_STATUS))
                  {
                    send_msg($id, 0, "[$ts]. #INFO: run countback = $run, was started '$ts_start'");
                  }
                  elseif ($cmd == 'restart' && check_allowed($id, CMD_RESTART))
                  {
                    $run = 5;
                    send_msg($id, 0, "[$ts]. #INFO: script will be restarted in 10 seconds");
                  }
                  elseif ($cmd == 'ping')
                    send_msg($id, 0, "[$ts]. pong");
                  elseif ($is_cmd && $cmd == 'pos_coef' && count($tokens) >= 3 && check_allowed($id, CMD_EDIT_COEF)) {
                    $bot = $tokens[1].'_bot';
                    $coef = floatval($tokens[2]);
                    $account = -1;
                    if (isset($tokens[3])) $account = $tokens[3];
                    $url = "http://$bot_server/bot/admin_trade.php?pos_coef=$coef&enabled=on&bot=$bot&action=Update&response=short&account=$account";
                    $res = curl_http_request($url);
                    send_msg($id, 0, "Request result: $res");
                  }
                  elseif ($is_cmd && $cmd == 'pos_offset'  && count($tokens) >= 2 && check_allowed($id, CMD_EDIT_OFFSET)) {
                    $exch = $tokens[1];
                    $action = 'Retrieve';
                    if (count($tokens) >= 4) {
                      $pair_id = $tokens[2];
                      $offset = $tokens[3];
                      $action = 'Update';
                    }
                    $account = -1;
                    if (isset($tokens[4])) $account = $tokens[4];

                    $url = "http://$bot_server/bot/admin_pos.php?exch=$exch&account=$account&pair_id=$pair_id&offset=$offset&action=$action&reply=short";
                    $res = curl_http_request($url);
                    if ($res) {
                      $hdr = substr($res, 1, 3);
                      if (false === strpos($hdr, 'PNG'))
                        send_msg($id, 0, "Request result: $res");
                      else
                        send_attach($id, 8, "Config table:", $res);
                    }
                  }
                  elseif ($is_cmd && $cmd == 'signal' && check_allowed($id, CMD_SIGNAL)) {
                    array_shift($tokens); // remove cmd
                    $signals_host = getenv('SIGNALS_API_URL') ?: 'http://localhost';
                    $url = rtrim($signals_host, '/') . "/sig_edit.php?view=quick&user=$user";
                    $signal = implode(' ', $tokens);
                    echo " trying post $signal \n";
                    log_cmsg("#SIGNAL: %s from %s", $signal, $user);
                    $res = curl_http_request($url, array('signal' => $signal));
                    var_dump($res);
                    if (false !== $res)
                        send_msg($id, 0, 'Signal post result: '.$res);
                    else
                        send_msg($id, 0, '#ERROR: Signal post failed');
                  }
                  else {
                    send_msg($id, 0, "Unknown command [$cmd], is_cmd = ".var_export($is_cmd, true));
                    $is_cmd = false;
                  }

                  if ($is_cmd)
                      $mysqli->try_query("UPDATE chat_users SET last_cmd=$uid WHERE chat_id=$id");

            } else
              echo " message ignored by uid $uid\n";
          } // if (updl)

        // РґРѕСЃС‚Р°С‚РѕС‡РЅРѕ РѕС‚РїСЂР°РІРёС‚СЊ Р»РёС€СЊ РЅРµСЃРєРѕР»СЊРєРѕ РїРѕСЃР»РµРґРЅРёС… СЃРѕРѕР±С‰РµРЅРёР№
        $rows = $mysqli->select_from('id,ts,host,tag,event,flags,value,attach,chat', 'events', 'ORDER BY ts DESC LIMIT 25');
        $events = array();
        while ($rows && $row = $rows->fetch_array(MYSQLI_NUM))
            $events []= $row;
        $events = array_reverse($events);
        // print_r($events);
        $alerts = array('FATAL', 'FAILED', 'WARN', 'WARN_RST', 'WARN_HANG', 'ALERT');
        $imp_types = array('FATAL', 'FAILED', 'WARN', 'WARN_RST', 'WARN_HANG', 'ALERT', 'LEVEL', 'REPORT', 'ORDER', 'SMS');
        $rpt_types = array('REPORT', 'ORDER', 'LEVEL');
        if ($show_status)
            $imp_types []= 'STATUS';


        foreach ($events as $evt)
        {
            list($event_id, $ts, $from, $tag, $event, $flags, $value, $attach, $chat) = $evt;

            $host = $mysqli->select_value('name', 'hosts', "WHERE id = $from");
            if ($host && 'Unknown' !== $host)
                $from = $host; 
            // echo " ts [$ts] tag [$tag] from [$from] \n";

            $important = array_search($tag, $imp_types);

            if (false === $important)
            {
              echo " #DBG: Ignored event with type [$tag]: $event \n";
              continue;
            }
            // СЃС…РµРјР° РІС‹РІРѕРґР° СЃРѕРѕР±С‰РµРЅРёСЏ РІ РІС‹РґРµР»РµРЅРЅС‹Р№ РєР°РЅР°Р»: РѕРЅРѕ РЅРµ Р±СѓРґРµС‚ РїРѕРїР°РґР°С‚СЊ РІ СЂР°Р·РґРµР»СЊРЅС‹Рµ С‡Р°С‚С‹ РїРѕР»СЊР·РѕРІР°С‚РµР»РµР№.
            if ($chat > 0 && !isset($wrong_chats[$chat]) && 'ALERT' != $tag) try {              
              if (isset($post_map[$chat]) && $post_map[$chat] >= $event_id) continue;          // skip already posted

              $last_post = $mysqli->select_value('ts_last', 'channels', "WHERE chat_id = $chat");
              if (strtotime($ts) + 900 <= strtotime($last_post)) continue; // РїСЂРѕРїСѓСЃРє, СЃ СѓС‡РµС‚РѕРј РіРѕРЅРєРё


              $ch_id = "-100$chat";   
              $already = $mysqli->select_value('event_id', 'chat_log', "WHERE event_id = $event_id");
              $post = ( !is_null($already));

              if (!$post) {
                if ($attach)
                  $post = send_attach($ch_id, $flags, "[$ts]. #$tag($from): $event", $attach);
                else
                  $post = send_msg($ch_id, $flags, "[$ts]. #$tag($from): $event, value=$value");
              }

              if ($post) {
                $post_map[$chat] = $event_id;                 
                if (is_null($already)) {
                  log_cmsg("~C92#SUCCESS:~C93 send event #%d [%s] %s to chat %s ", $event_id, $tag, $event, $ch_id);
                  $mysqli->try_query("UPDATE channels SET ts_last='$ts' WHERE chat_id=$chat");
                }    
                continue;
              }
              else {
                log_cmsg("~C91#FAILED:~C00 send event [%s] %s to chat %s", $tag, $event, $ch_id);
                $wrong_chats[$chat] = true;
              }  

            } catch (Exception $E) {
              log_cmsg("~C91 #ERROR:~C00 exception catched: %s, seems bot not authorized in chat %d", $E->getMessage(), $chat);              
              $wrong_chats[$chat] = true;
            }

            foreach ($users as $usr)
            {
              if (!$usr[3]) continue;

              $chat  = strval($usr[0]);
              $uname = $usr[1];
              $last  = $usr[2];
              if (!isset($allowed_privs[$chat])) {
                echo " #SKIP: send anything not allowed for $uname #$chat, privs unset!\n";
                // echo " #PRIVS: allowed chat_ids ".implode(', ', array_keys($allowed_privs))."\n";
                continue;
              }

              // echo tss()." #MSG: for [$uname] last ts [$last] \n";
              if ('STATUS' == $tag && $show_status > 0) $show_status --;
              if (false !== array_search($tag, $rpt_types) && !check_allowed($chat, GET_REPORTS)) {
                //echo " #SKIP: send reports not allowed for $uname #$chat\n";
                continue;
              }    
              if (false !== array_search($tag, $alerts) && !check_allowed($chat, GET_ALERTS)) {
                  //echo " #SKIP: send alerts not allowed for $uname #$chat\n";
                  continue;  
              } 


              if ('SMS' == $tag) {            
                $flags = 8;
                if (!check_allowed($chat, GET_SMS)) {
                  // printf(" #SKIP: send SMS not allowed for $uname #$chat, privs: %s\n", implode(',', $allowed_privs[$chat])); 
                  continue;
                }   
                $attach = file_get_contents($location.'/sms_header.png');
              }  

              if ($ts > $last)
              try {
                  log_cmsg("~C97 #NOTIFY:~C00 target user %s #%d, about [%s > %s]. #%s(%s). :0x%x: '%s'",
                                              $user, $chat, $ts, $last, $tag, $from, $flags, $event);
                  $post = false;
                  if ($attach)
                    $post = send_attach($chat, $flags, "[$ts]. #$tag($from): $event", $attach);
                  else
                    $post = send_msg($chat, $flags, "[$ts]. #$tag($from): $event, value=$value");

                  if ($post)  
                    $mysqli->try_query("UPDATE chat_users SET last_notify='$ts' WHERE chat_id=$chat");
                  else  
                     echo "#WARN: post message to $uname #$chat was failed, check logs...\n";
              } catch (Exception $E) {
                  $trace = debug_backtrace();
                  log_cmsg("~C91 #ERROR:~C00 exception catched:~C97 ".$E->getMessage());
                  log_cmsg("~C93 #TRACE:~C92  ".$E->getTraceAsString());  
              }

            } // foreach users
        } // foreach rlist


      sleep(1);
      $hour = date('H');
      if ($hour != $prev) 
          cleanup();
      $prev = $hour;
    }
    } catch (Exception $E) {
    log_cmsg("~C07~C91 #EXCEPTION:~C00 in global scope catched:~C97 ".$E->getMessage());
    log_cmsg("~C93 #TRACE:~C92  ".$E->getTraceAsString());      
    }   

    // $telegram->sendMessage($params);
    $mysqli->close();
    ?>