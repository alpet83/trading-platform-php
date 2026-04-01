<?php
    require_once("lib/common.php");
    require_once("lib/esctext.php");
    require_once("lib/db_tools.php");
    require_once("lib/db_config.php");

    require ('telegram-bot/vendor/autoload.php'); //Подключаем библиотеку    
    // ReplyKeyboardMarkup
    use Telegram\Bot\Api;    
    use Telegram\Bot\Keyboard\Keyboard; 
    use GuzzleHttp\RequestOptions;
    

    date_default_timezone_set("Asia/Nicosia");

    const GET_ALERTS = 'get-alerts';
    const GET_REPORTS = 'get-reports';
    const GET_SMS = 'get-sms';

    const CMD_SIGNAL = 'signal';
    const CMD_STATUS = 'bot_status';
    const CMD_RESTART = 'restart';
    const CMD_EDIT_COEF = 'pos_coef';
    const CMD_EDIT_OFFSET = 'pos_offset';

    const MESSAGE_FLAG_SILENT = 0x0001;
    const ATTACH_FLAG_IMAGE = 0x0008;
    const MESSAGE_FLAG_PERSONAL = 0x0800;
    const MESSAGE_FLAG_DIRECT = 0x1000;

    const USE_HOOKS = true;
    const USE_LOCAL = true;

    const PHP_ERRORS = '/var/log/php_errors.log';

    $log_file = fopen('/var/log/trade_ctrl_bot.log', 'w');
    $err_log = '/var/log/trade_ctrl_bot.err';

    $hostname = file_get_contents('/etc/hostname');
    $hostname = trim($hostname, "\n\t ");

    $bot_server = '10.119.10.50';

    $hr = date('H');

    $event_id = 0;
    $pause = 2;
    $flood = false;
    $history = [];    

    function connect_mysql()
    {
        global $mysqli;
        $mysqli = new mysqli_ex(null, MYSQL_USER, MYSQL_PASSWORD, 'trading', null, '/run/mysqld/mysqld.sock');
        return $mysqli;
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    connect_mysql();

    if (!$mysqli || $mysqli->connect_error)
            die ("#FATAL: cannot connect to local DB server\n");

    $token = trim(file_get_contents('/etc/api-token'));
    $telegram = new Api($token);    
    if (USE_LOCAL)
        $telegram->setBaseApiUrl('http://localhost:8081');
    $token = null;

    $location = __DIR__;
    $cwd = getcwd();

    log_msg("#INIT: script started from $location, cwd = $cwd, PID = ".getmypid());
    log_msg(" telegram object created...");
       
    $pid = getmypid();

    $pid_file = __DIR__.'/trade_ctrl_bot.pid';
    $fpid = fopen($pid_file, 'w');
    if (flock($fpid, LOCK_EX))
          fwrite($fpid, getmypid());
    else 
        error_exit("#FATAL: cannot lock PID file\n");

    $sendQuestion = array();
    $telegram->setConfig([RequestOptions::TIMEOUT => 10]);
    // $telegram->setTimeout(10);

    
    $machine_id = file_get_contents('/etc/machine-id') ?? 'we-are-in-container';
    $machine_id = trim($machine_id);
    // 'allowed_updates' => '["message","business_message","channel_post"]', 

    $params = ["url" => 'https://vps.alpet.me/tele_hook.php', 'secret_token' => $machine_id];    
    try {
        $web_hook = false;        
        if (USE_HOOKS) {    
            $web_hook = $telegram->setWebhook($params);
            if ($web_hook)
                log_cmsg("~C97#INIT:~C00 webhook was set to %s", $params['url']);
            else    
                log_cmsg("~C91#FAILED:~C00 setWebhook with params %s", json_encode($params));
        }
                    
    }  catch (Throwable $E) {
        log_cmsg("~C91#ERROR:~C00 setWebhook failed %s, using params %s", $E->getMessage(), json_encode($params));
        $web_hook = false;
    }


    // $reply_markup = $telegram->replyKeyboardMarkup(array('keyboard' => $customKeyboard, 'resize_keyboard' => true, 'one_time_keyboard' => false));
    
    $reply_markup = false;
    /* Keyboard::make()
        ->setResizeKeyboard(true)
        ->setOneTimeKeyboard(true)
        ->row([Keyboard::button('/ping'), Keyboard::button('/bot_status'), Keyboard::button('/restart')]); 
    //*/
    function send_buttons(int $chat) {
        global $telegram, $reply_markup;
        if ($reply_markup)
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

    function process_command(int  $chat_id, int $update_id, string $txt, string $user) {
        global $mysqli, $hostname, $allowed_privs, $run, $bot_server, $ts_start, $t_dec_prev;    
        $ts = date(SQL_TIMESTAMP);
        $mysqli->try_query("UPDATE chat_users SET last_msg=$update_id WHERE chat_id = $chat_id");
        // TODO: handle some commands
        $tokens = explode(' ', $txt);
        $cmd = $tokens [0];
        $cmd = strtolower($cmd);
        $ccp = strpos($cmd, '#');
        if (false === $ccp) $ccp = strpos($cmd, '/');              
        $cmd = trim($cmd, "#/\n\t ");              
        $is_cmd = true;
        if ($ccp === false || $ccp > 1) {
            log_cmsg("~C91#WARN:~C00 Received possible non-command [%s], cpp = %d ", $cmd, $ccp);
            $is_cmd = 0;
        }
        if ($cmd == 'start') {
            send_msg($chat_id, 0, "[$ts]. #HELLO: available commands for you ".implode(',', $allowed_privs[$chat_id]));  
            send_buttons($chat_id);               
        } elseif ($cmd == 'bot-status' && check_allowed($chat_id, CMD_STATUS))
        {
            send_msg($chat_id, 0, "[$ts]. #INFO: run countback = $run, was started '$ts_start'");
        }
        elseif ($cmd == 'restart' && check_allowed($chat_id, CMD_RESTART))
        {
            $run = 5;
            send_msg($chat_id, 0, "[$ts]. #INFO: script will be restarted in 10 seconds");
        }
        elseif ($cmd == 'ping')
            send_msg($chat_id, 0, "[$ts]. pong");
        elseif ($is_cmd && 'auth_me' == $cmd) {
            $pass = rand(100000, 999999);            
            $mysqli->try_query("UPDATE chat_users SET auth_pass = $pass WHERE (chat_id = $chat_id) AND (enabled = 1)");
            if ($mysqli->affected_rows > 0) {
                $t_dec_prev = time();
                $pass = $mysqli->select_value('auth_pass', 'chat_users', "WHERE chat_id = $chat_id");
                send_msg($chat_id, MESSAGE_FLAG_DIRECT | MESSAGE_FLAG_PERSONAL, "[$ts]. #INFO: you are authorisation pass will work 45 seconds. Proceed on https://$hostname/tele_login.php?login=$user&pass=$pass");
                log_cmsg("~C04~C97#AUTH:~C00 pass enabled for %s", $user);
            }    
            else
                send_msg($chat_id, 0, "[$ts]. #ERROR: you can't authorised");            
        }
        elseif ($is_cmd && 'pos_coef' == $cmd && count($tokens) >= 3 && check_allowed($chat_id, CMD_EDIT_COEF)) {
            $bot = $tokens[1].'_bot';
            $coef = floatval($tokens[2]);
            $account = -1;
            if (isset($tokens[3])) $account = $tokens[3];
            $url = "http://$bot_server/bot/admin_trade.php?pos_coef=$coef&enabled=on&bot=$bot&action=Update&response=short&account=$account";
            $res = curl_http_request($url);
            send_msg($chat_id, 0, "Request result: $res");
        }
        elseif ($is_cmd && 'pos_offset' == $cmd && count($tokens) >= 2 && check_allowed($chat_id, CMD_EDIT_OFFSET)) {
            $exch = $tokens[1];
            $action = 'Retrieve';
            if (count($tokens) >= 4) {
                $pair_id = $tokens[2];
                $offset = $tokens[3];
                $action = 'Update';
            }
            $account = -1;
            if (isset($tokens[4])) 
                $account = $tokens[4];

            $url = "http://$bot_server/bot/admin_pos.php?exch=$exch&account=$account&pair_id=$pair_id&offset=$offset&action=$action&reply=short";
            $res = curl_http_request($url);
            if ($res) {
            $hdr = substr($res, 1, 3);
            if (false === strpos($hdr, 'PNG'))
                send_msg($chat_id, 0, "Request result: $res");
            else
                send_attach($chat_id, 8, "Config table:", $res);
            }
        }
        elseif ($is_cmd && $cmd == 'signal' && check_allowed($chat_id, CMD_SIGNAL)) {
            array_shift($tokens); // remove cmd
            $host = rtrim((string)(getenv('TRADEBOT_PHP_HOST') ?: getenv('SIGNALS_API_URL') ?: 'http://host.docker.internal'), '/');
            $url = "$host/sig_edit.php?view=quick&user=$user";
            $signal = implode(' ', $tokens);
            echo " trying post $signal \n";
            log_cmsg("#SIGNAL: %s from %s", $signal, $user);
            $res = curl_http_request($url, array('signal' => $signal));
            var_dump($res);
            if (false !== $res)
                send_msg($chat_id, 0, 'Signal post result: '.$res);
            else
                send_msg($chat_id, 0, '#ERROR: Signal post failed');
        }
        else 
        {
            send_msg($chat_id, 0, "Unknown command [$cmd], is_cmd = ".var_export($is_cmd, true));
            $is_cmd = false;
        }

        if ($is_cmd)
            $mysqli->try_query("UPDATE chat_users SET last_cmd=$update_id WHERE chat_id=$chat_id");

    }

    function process_except($chat, $flags, Exception $e): bool {
        global $pause, $flood, $exceptions, $history, $event_id, $tag, $mysqli, $event;
        $msg = $e->getMessage();
        if (strpos($msg, 'Too Many Requests') !== false) {
            $pause ++;
            $map = $history[$chat] ?? [];
            log_cmsg("~C91#WARN:~C00 too many requests, in history now %d, pause will be increased to %d", count($map), $pause);
            $flood = true;          
            return false; 
        } 

        if (stripos($msg, 'Operation timed out' !== false) || 
            str_in($msg, 'cURL error 28')) {
            log_cmsg("~C91#WARN_TIMEOUTED:~C00 %s will saved into post_queue", $event);
            $flood = true;
            $query = " INSERT INTO `post_queue` (chat, event_id, tag, `event`) VALUES\n";
            $query .= "($chat, $event_id, '$tag', '$event');";
            $mysqli->try_query($query);
            return false;        
        }
        
        if (strpos($msg, 'user is deactivated') !== false) {
            log_cmsg('~C91#CLEANUP:~C00 user $chat is deactivated, removing from chat_users');
            disable_user($chat);
        }    
        $trace = debug_backtrace(0);
        $errFile = tp_debug_tmp_path('trade_ctrl_bot.err', 'telebot');
        file_put_contents($errFile, "$msg\n");
        file_add_contents($errFile, print_r($trace, true));

        log_cmsg ("~C91 #EXCEPTION(send_attach($chat, $flags)):~C00 class %s catched %s, stack:\n %s ",
                     get_class($e), $msg, $e->getTraceAsString());
        $exceptions ++;
        return false;
    } 

    function process_updates(array $updl) {  // processing updates retrieved by API
        global $mysqli, $timings, $postpone, $upd_params;
        $updc = 0;
        foreach ($updl as $update) {
            //
            $updc ++;
            echo "#UPD$updc:";
            $uid = $update->getUpdateId();
            if (0 == count($postpone))
                $upd_params['offset'] = $uid + 1;

            $json = strval($update);
            $rec = json_decode($json);
            if (isset($rec->channel_post)) {
                $cp = $rec->channel_post;
                $dt = $cp->date;
                $now = time();
                $elps = $now - $dt;
                log_cmsg("~C104~C92#CHANNEL_POST:~C00 was posted %d:%s in %d:%s, postponed now %d, elapsed = %d",
                        date(SQL_TIMESTAMP, $dt), $cp->message_id,  $cp->chat->id, $cp->chat->title, count($postpone), $elps);
                file_put_contents('data/last_channel_post.txt', print_r($rec, true));                
                verify_delivery($cp, 'pinned_message');                               
                continue;
            }

            $chat = $update->getChat();
            if (!$chat)            {
                log_cmsg("~C91#WARN:~C00 strange update, no chat_id: %s", $json);            
                continue;
            }
            // print_r($chat);

            $chat_id = $chat->getId();
            $tt = $chat->getType();
            $user = $chat->getUsername();

            $last_cmd = 0;
            // register user record if new
            if (intval($chat_id) > 0 && strlen($user) > 2)
            {
                $enabled = check_allowed($chat_id, CMD_STATUS) ? 1 : 0;
                $query = "INSERT IGNORE INTO `chat_users`(chat_id, user_name, last_notify, `enabled`)\n ";
                $query .= "VALUES($chat_id, '$user', '2020-12-01 00:00:00', $enabled);";
                $mysqli->try_query($query);
            }
            // processing message or command
            $msg = $update->recentMessage();
            if (!$msg) continue;
            $txt = $msg->getText();
            $txt = trim($txt);
            if (!check_allowed($chat_id, CMD_STATUS)) {
                log_cmsg("~C91#WARN:~C00 message from unauthorized %s: %s", $user,  $txt);
                continue;
            }
            //
            $query = "INSERT IGNORE INTO `last_messages`(update_id, chat, `message`) VALUES\n";
            $txt = $mysqli->real_escape_string($txt);
            $query .= "($uid, $chat_id, '$txt');";
            $mysqli->try_query($query);
        } // if (updl)

    }


    function load_messages() { // загрузка сообщений, которые собрал хук или обработка обновлений
    global $mysqli;
    $rows = $mysqli->select_rows('update_id, chat, message', 'last_messages', 'ORDER BY update_id LIMIT 10');
    if (is_null($rows) || 0 == count($rows)) return;
    log_cmsg("~C94#LOAD_MESSAGES:~C00 processing %d rows ", count($rows));
    try {
        foreach ($rows as $row) {
            list($uid, $chat_id, $txt) = $row;
            $mysqli->try_query("DELETE FROM last_messages WHERE update_id = $uid"); // in process

            $user = '?';
            $last_cmd = '';
            $last_msg = 0;
            if ($chat_id < 0) continue; // is a channel

            $usr = $mysqli->select_row('last_cmd,last_msg,user_name', 'chat_users', "WHERE chat_id = $chat_id", MYSQLI_OBJECT);
            if (is_object($usr)) {
                $last_cmd = $usr->last_cmd;
                $last_msg = $usr->last_msg;
                $user = $usr->user_name;
                echo "\n #USER_UPD: chat_id[$chat_id], from[$user] #$uid (last_cmd = $last_cmd, last_msg = $last_msg): ";
            } else  {
                log_cmsg("~C91#ERROR:~C00 no registration for #%d in chat_users...", $chat_id);
                continue;
            }            

            if ($uid > $last_msg)
                process_command($chat_id, $uid, $txt, $user);
            else
                log_cmsg("~C94#FLT:~C00 message ignored by uid %d", $uid);        

        } // foreach
    } catch (Throwable $E) {
        log_cmsg("~C91#EXCEPTION(load_messages):~C00 %s", $E->getMessage());
        log_cmsg("~C92#STACK:~C00 %s", $E->getTraceAsString());
    }
    } 


    function process_send(string $text, mixed $res): bool {
    global $tag, $event_id,  $mysqli, $telegram, $history;
    // добавление меток сообщений в лог, чтобы их потом удалить из чата
    if (!is_object($res)) { 
      log_cmsg('~C91 #ERROR:~C00 invalid res = %s ', var_export($res, true));
      return false;
    }         
    if ($res) 
    try {         
        $json = strval($res);                  
        
        $rec = json_decode($json);      
        $json = str_replace('"text":"', '"text":~C33"', $json);
        log_cmsg("~C94#SEND_RES:~C00 %s ", $json);
        $chat = $rec->chat;
        if (!is_object($chat)) {
            log_cmsg('~C91 #ERROR:~C00 check structure: %s ', $json);
            return false;
        }   
        $chat_id = $chat->id;
        $msg_id = $rec->message_id;      

        if (!isset($history[$chat_id])) $history[$chat_id] = [];      
        $history[$chat_id][time()] = $msg_id;
        $before = time() - 60;

        // сохранять в истории сообщения за последнюю минуту только, для оценки флуд-рейта
        while (count($history[$chat_id])  > 0 && array_key_first($history[$chat_id]) < $before) 
                array_shift($history[$chat_id]); 

        $t_exp = time() + 3600 * 4;
        
        if ($msg_id <= 0) {
            log_cmsg("~C91#ERROR:~C00 invalid message_id = %d", $msg_id);
            return false; 
        } 
        if ('REPORT' == $tag) {
            if (false !== stripos($text, 'account equity')) {
            $telegram->pinChatMessage(['chat_id' => $chat->id, 'message_id' => $msg_id]);
            return true;
            }
            if (false !== strpos($text, 'Volume report')) return true; // эти надо сохранить
            if (false !== stripos($text, 'Hourly report'))  $t_exp += 8 * 3600; 
            if (false !== stripos($text, 'Startup report')) $t_exp += 20 * 3600;
        }      

        $ts_expire = gmdate(SQL_TIMESTAMP, $t_exp);
        $query = "INSERT IGNORE INTO `chat_log` (id, event_id, chat, tag,  ts_del) ";
        $query .= "VALUES($msg_id, $event_id, $chat_id, '$tag', '$ts_expire');";
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

    $indent = '';

    function send_msg(int $chat, $flags, $text): bool
    {
      global $telegram, $exceptions;
      if (0 == $chat) {
        log_cmsg("~C91#ERROR:~C00 chat_id is zero, cannot send message");
        return false;
      }   
      $text = str_replace('<pre>', '', $text);

      $mparams = [
          'chat_id'              => $chat,
          'text'                 => substr($text, 0, 4096),
          'parse_mode'           => 'HTML',
          'disable_notification' => (0 != $flags & MESSAGE_FLAG_SILENT)
              ];
      if ($flags & MESSAGE_FLAG_PERSONAL) 
          $mparams['link_preview_options'] = ['is_disabled' => true];  // not allow Telegram server to open LINK in message
      $result = false;        
      try {       
        $res = $telegram->sendMessage($mparams);
        $result = process_send($text, $res);
      } catch (Exception $e) {     
        $result = process_except($chat, $flags, $e);
      }
      return $result;
    }  

    
    function send_attach(int $chat, $flags, $caption, $attach, $fname = 'attach'): bool {
    global $telegram, $exceptions, $flood, $pause, $indent, $tag, $event_id;
    if (0 == $chat) {
      log_cmsg("~C91#ERROR(send_attach):~C00 chat_id is zero, cannot send message");
      return false;
    } 
    $flood = false;

    $caption = str_replace('<pre>', '', $caption);
    $path = tp_debug_tmp_dir("telebot/chat$chat") . '/';
    // chgrp($path, 'telegram');         

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
          'disable_notification' => (0 != ($flags & MESSAGE_FLAG_SILENT))
            ];

    $ftype = ($flags & 0xf00) >> 8;
    $result = false;
    try {
        $start = pr_time();
        if ($flags & ATTACH_FLAG_IMAGE) {
          $fname .= $img_ext[$ftype];
          file_put_contents($fname, $attach);
          $mparams['photo'] = "file://$fname";
          log_cmsg("$indent~C93 #SEND_IMAGE:~C00 ftype = %d, flags = 0x%02x, params = %s", $ftype, $flags, json_encode($mparams));
          $res = $telegram->sendPhoto($mparams);
          $result = process_send($caption, $res);
        } else {
          $fname .= $doc_ext[$ftype];
          file_put_contents($fname, $attach);
          $mparams['document'] = $fname;
          log_cmsg("$indent~C93 #SEND_DOC:~C00 ftype = %d, flags = 0x%02x, params = %s", $ftype, $flags, json_encode($mparams));         
          $res = $telegram->sendDocument($mparams);
          $result = process_send($caption, $res);
        }
        $elps = pr_time() - $start;
        log_cmsg("~C96#PERF:~C00 send_attach elapsed %.3f sec", $elps);
    } catch (Exception $e) {             
      $result = process_except($chat, $flags, $e);      
    }
    // TODO: post timer to delete file
    //  unlink($fname) 
    return $result;
    }

    $upd_params = [
          'offset'  => '0',
          'limit'   => '16',
          'timeout' => '30',
        ];

    if (!$argv) $argv = [ 0, 0, 0 ];

    $run = 1000; // ожидаемое время цикла около 5 секунд, общее время порядка до 2 часов
    

    function sig_handler($signo)
    {
      global $run;
      log_cmsg("~C31#EXIT:~C00 received signal %d", $signo);
      $run = 2;
    }

    pcntl_signal(SIGQUIT, "sig_handler");
    pcntl_signal(SIGTERM, "sig_handler");
    // pcntl_signal(SIGHUP,  "sig_handler");

    $ts_start = date(SQL_TIMESTAMP);
    $show_status = 0;
    $con_errors = 0;
    $id = -1;
    $prev = 0;
    $chats = [];
    $tag = 'NOPE';
    $postpone = [];
    $timings = [];

    function verify_delivery(stdClass $post, string $field) {
    global $postpone; 
    $pm = $post->$field; 
    $now = time();
    if (is_object($pm))
    foreach ($postpone as $eid => $row) {
      $msg = $row[4];
      if (false !== stripos($msg, $pm->caption)) {
          $elps = $now  - ($timings[$eid] ?? $now);
          log_cmsg("~C103~C91#POSTPONE:~C40~C00 found postponed message %d:~C04%s, elapsed %d, processing as sent", $eid, $msg, $elps);
          unset($postpone[$eid]);
          process_send($msg, $post);
      }    
    }
    }

    function post_to_channel(array $evt): bool {
    global $mysqli, $wrong_chats, $pause, $timings, $postpone, $fails, $indent, $post_map;
    list($event_id, $ts, $from, $tag, $event, $flags, $value, $attach, $chat) = $evt;            

    $host = $from;
    if (is_numeric($host))
        $host = $mysqli->select_value('name', 'hosts', "WHERE id = $from");

    if (isset($postpone[$event_id]))
        return true; // not repeat yet

            // $block = false; if (isset($wrong_chats[$chat]))
    $block = $wrong_chats[$chat] ?? false;                 
    // checking if message addressed to channel
    if ($chat > 0 && !$block) try {              
        if (isset($post_map[$chat]) && $post_map[$chat] >= $event_id) return true;          // skip already posted

        $last_post = $mysqli->select_value('ts_last', 'channels', "WHERE chat_id = $chat");

        $now = gmdate(SQL_TIMESTAMP);
        $ts_utc = str_replace(' ', 'Z', $ts);
        $elps = time() - strtotime($ts_utc); // both are UTC
        if ($elps > 300) {
            $post_map[$chat] = $event_id;
            log_cmsg("~C91#OUTDATED:~C00 %s relative %s to late for %s", $ts, $now, $event);
            return true;
        }

        if (strtotime($ts) + 900 <= strtotime($last_post)) {                  
            return true; // пропуск, с учетом гонки              
        }

        $ch_id = intval("-100$chat");   
        $already = $mysqli->select_value('event_id', 'chat_log', "WHERE event_id = $event_id");
        $post = !is_null($already);
        $flood = false;

        set_time_limit(70);

        $t_msg = time();
        if (!$post) {                  
            $indent = "$event_id → ";                   
            if ($attach)
            $post = send_attach($ch_id, $flags, "[$ts]. #$tag($from): $event", $attach);
            else
            $post = send_msg($ch_id, $flags, "[$ts]. #$tag($from): $event, value=$value");
            $indent = '';                                     
        }                
        
        $fails_count = ($fails[$chat] ?? 0);

        if ($post) {                
            $map = $history[$ch_id] ?? [];
            if ( count($map) >= 20 ) {
                log_cmsg("~C91#WARN:~C00 ratelimit pause added, due last minute was send %d messages", count($map));
                sleep($pause); // additional pause, if too many messages per minute writes to channel
            }     

            $post_map[$chat] = $event_id;                 
            if (is_null($already)) {
                log_cmsg("~C92#SUCCESS:~C93 send event #%d [%s] %s to chat %s ", $event_id, $tag, $event, $ch_id);
                $mysqli->try_query("UPDATE channels SET ts_last='$ts' WHERE chat_id=$chat");        
            }    
            return true;  // procseed to next event
        }
        else {
            log_cmsg("~C91#FAILED:~C00 send event [%s] %s to chat %s", $tag, $event, $ch_id);
            $timings[$t_msg] =  $event_id; // for prevent resending hanged messages
            
            if (!$flood) 
                    $fails[$chat] = $fails_count + 1;
            if ($fails_count >= 100)
                $wrong_chats[$chat] = true;
            $evt[1] = date(SQL_TIMESTAMP);  // update timestamp
            $postpone [$event_id] = $evt; // try at next loop
            $first = array_key_first($postpone);
            if ($event_id - $first < 20)  // задержка в пределах терпимой?
                return true;
        }  

    } catch (Exception $E) {
        $indent = '';
        log_cmsg("~C91 #ERROR:~C00 exception catched: %s, seems bot not authorized in chat %d", $E->getMessage(), $chat);              
        if (!$flood) $fails[$chat] = $fails_count + 3;
        $wrong_chats[$chat] = $fails_count > 10;
    }
    elseif ($block) { 
        log_cmsg("~C91#WARN:~C00 event [%s] %s will send directly via bot, now wrong_chats %s", $tag, $event, json_encode($wrong_chats));             
        return false;
    }    
    return true;

    }

    echo format_color("~C97%s~C00\n", '-----------=================== Script restarted ===================-------------');

    
    
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
        $fails = [];
        $post_map = [];
        $t_dec_prev = time();
        while ($run > 0 && $con_errors < 10 && $exceptions < 20) {
            $run --;                   
            if ($exceptions > 10 && $run > 10) { 
                log_cmsg("~C91#FATAL:~C00 too many exceptions");
                $run = 10;
            }    
            
            if ($run < 10)
                log_cmsg("~C31#EXIT_COUNTDOWN:~C00 %d", $run);
            
            set_time_limit(900);
            

            $ts = date(SQL_TIMESTAMP);
            $info = [];
            pcntl_sigtimedwait([SIGQUIT, SIGTERM], $info, 1, 0);
            $time = time();
            $updl = false;

            if ($run >= 3 && !$web_hook)
            try
            {
                echo "\n[$ts/$time]. #MSG: waiting for updates -> ";
                $upd_params['timeout'] = 5;
                $updl = $telegram->getUpdates($upd_params);
                if (is_array($updl))
                    process_updates($updl); 
            }
            catch (Exception $e)
            {
                printf('#ERROR: telegram->getUpdates caught exception: %s ', $e->getMessage());
            }
            else
            sleep(1); //   
            $updc = 0;
            $now = time();
            $minute = date('i');
            if ($minute <= 1 && $exceptions > 10)  {
                send_msg($admin_id, 0, "[$ts]. #WARN: In this bot too many exceptions catched, $exceptions, check logs...");
                $exceptions = 0;
            }
            set_time_limit(90);

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

            if ($now - $t_dec_prev >= 45) {
                $mysqli->query("UPDATE chat_users SET auth_pass = auth_pass / 4  WHERE auth_pass > 0"); // destroy auth_pass
                $t_dec_prev = $now; 
            }
            
            $users = $mysqli->select_rows('chat_id,user_name,last_notify,enabled,rights', 'chat_users', 'WHERE enabled = 1');            

            // loading privs
            if (0 == count($allowed_privs)) {
                foreach ($users as $rec) {
                    $id = $rec[0];
                    $user = strtolower($rec[1]);
                    $rights = $rec[4];                    
                    if (str_contains($rights, 'admin') && $admin_id < 0) {
                        $admin_id = $id;
                        $allowed_privs[$id] = array(CMD_EDIT_COEF, CMD_EDIT_OFFSET, CMD_RESTART, CMD_SIGNAL, CMD_STATUS, GET_ALERTS, GET_REPORTS, GET_SMS);
                    }   

                    if (str_contains($rights, 'trade'))          
                        $allowed_privs[$id] = array(CMD_EDIT_COEF, CMD_SIGNAL, CMD_STATUS, GET_ALERTS, GET_REPORTS, GET_SMS);
            
                    if (str_contains($rights, 'view')) 
                        $allowed_privs[$id] = array(CMD_STATUS, GET_REPORTS);                 
                } 
                log_cmsg("~C97#INIT:~C00 for user `%s` allowed commands %s", $user, json_encode($allowed_privs));
            }

            $id = 0;
            load_messages();  


            // достаточно отправить лишь несколько последних сообщений
            $rows = $mysqli->select_from('id,ts,host,tag,event,flags,value,attach,chat', 'events', 'ORDER BY ts DESC LIMIT 25');
            $events = [];
            while ($rows && $row = $rows->fetch_array(MYSQLI_NUM))
                $events []= $row;
            $events = array_reverse($events);
            // print_r($events);
            $alerts = ['FATAL', 'FAILED', 'WARN', 'WARN_RST', 'WARN_HANG', 'ALERT'];
            $imp_types = ['FATAL', 'FAILED', 'WARN', 'WARN_RST', 'WARN_HANG', 'ALERT', 'LEVEL', 'REPORT', 'ORDER', 'SMS', 'LOGIN', 'LOGOUT'];
            $rpt_types = ['REPORT', 'ORDER', 'LEVEL'];
            if ($show_status)
                $imp_types []= 'STATUS';

            if (count($postpone) > 0) {                
            
                foreach ($postpone as $event_id => $rec) {
                $ts_utc = str_replace(' ', 'Z', $rec[1]); 
                $elps = time() - strtotime($ts_utc); 
                if ($elps > 40) {   // если за это время не подтвердилась досылка, можно повторить попытку
                    log_cmsg("~C91#POSTPONE_REPEAT:~C00 message %d was postponed %s, now retrying", $event_id, $ts_utc);
                    $events []= $rec;              
                    unset($postpone[$event_id]);
                }    
                }  
                $timings = [];
            }   


            foreach ($events as $evt) // <<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<< MESSAGE SPREADING LOOP
            {
                //   0          1    2      3     4        5      6        7        8 
                list($event_id, $ts, $from, $tag, $event, $flags, $value, $attach, $chat) = $evt;            

                $host = $from;
                if (is_numeric($host))
                    $host = $mysqli->select_value('name', 'hosts', "WHERE id = $from");

                if ($host && 'Unknown' !== $host)
                    $from = $host; 
                

                if (0 == $chat)
                    $flags |= MESSAGE_FLAG_DIRECT; // отправлять каждому пользователю бота напрямки

                $important = array_search($tag, $imp_types);

                if (false === $important)
                {
                    echo " #DBG: Ignored event with type [$tag]: $event \n";
                    continue;
                }


                // схема вывода сообщения в выделенный канал: оно не будет попадать в раздельные чаты пользователей.
                if (0 == ($flags & MESSAGE_FLAG_DIRECT) && post_to_channel($evt)) 
                    continue;             
                    

                foreach ($users as $usr)
                {
                    if (!$usr[3]) continue;
                    $user_chat  = strval($usr[0]);                    
                    if (0 != $chat && $chat != $user_chat) continue; // send only to specified user

                    $uname = $usr[1];
                    $last  = $usr[2];
                    if (!isset($allowed_privs[$user_chat])) {
                        echo " #SKIP: send anything not allowed for $uname #$user_chat, privs unset!\n";
                        // echo " #PRIVS: allowed chat_ids ".implode(', ', array_keys($allowed_privs))."\n";
                        continue;
                    }                                 

                    // echo tss()." #MSG: for [$uname] last ts [$last] \n";
                    if ('STATUS' == $tag && $show_status > 0) $show_status --;
                    if (false !== array_search($tag, $rpt_types) && !check_allowed($user_chat, GET_REPORTS)) {
                        //echo " #SKIP: send reports not allowed for $uname #$chat\n";
                        continue;
                    }    
                    if (false !== array_search($tag, $alerts) && !check_allowed($user_chat, GET_ALERTS)) {
                        //echo " #SKIP: send alerts not allowed for $uname #$chat\n";
                        continue;  
                    } 
                

                    if ('SMS' == $tag) {            
                        $flags = 8;
                        if (!check_allowed($user_chat, GET_SMS)) {
                        // printf(" #SKIP: send SMS not allowed for $uname #$chat, privs: %s\n", implode(',', $allowed_privs[$chat])); 
                        continue;
                        }   
                        $attach = file_get_contents($location.'/sms_header.png');
                    }  


                    if ($ts > $last)            
                    try {                        
                        if (isset($postpone[$event_id])) {
                            $failed = $postpone[$event_id];
                            if ($failed[8] == $user_chat) continue;  // is waited for confirmation of sending 
                        }

                        log_cmsg("~C97 #NOTIFY(bot):~C00  target user %s #%d, about [%s > %s]. #%s(%s). :0x%x: '%s'",
                                                    $user, $user_chat, $ts, $last, $tag, $from, $flags, $event);
                        $post = false;
                        if ($attach)
                            $post = send_attach($user_chat, $flags, "[$ts]. #$tag($from): $event", $attach);
                        else
                            $post = send_msg($user_chat, $flags, "[$ts]. #$tag($from): $event, value=$value");

                        if ($post)   
                            $mysqli->try_query("UPDATE chat_users SET last_notify='$ts' WHERE chat_id=$user_chat");
                        else  { 
                            log_cmsg("~C91#WARN:~C00 post message to $uname #$user_chat was failed, postponed\n");
                            $postpone[$event_id]= $evt;
                        }   
                        sleep($pause);  
                    } catch (Exception $E) {
                        $trace = debug_backtrace();
                        log_cmsg("~C91 #ERROR:~C00 exception catched:~C97 ".$E->getMessage());
                        log_cmsg("~C93 #TRACE:~C92  ".$E->getTraceAsString());  
                    }

                } // foreach users
            } // foreach rlist

        if (file_exists('patch.inc.php')) 
            try {
                include('patch.inc.php');
            }
            catch(\Error $E) {
                if ($E instanceof Throwable)        
                log_cmsg("~C91#EXCEPTION:~C00 in patch.inc.php:~C97 %s: %s", get_class($E), $E->getMessage());
            }    

        sleep(1);
        $hour = date('H');
        if ($hour != $prev) {
            if (count($wrong_chats) > 0 && 0 == $minute) {
                log_cmsg("~C91#WARN:~C00 script breaks due have wrong_chats: %s", json_encode($wrong_chats));
                $wrong_chats = [];          
                $fails = [];
            break;
            }  
            cleanup();         
        }    
        $prev = $hour;
        }

    } catch (Exception $E) {
        log_cmsg("~C07~C91 #EXCEPTION:~C00 in global scope catched:~C97 %s ", $E->getMessage());
        log_cmsg("~C93 #TRACE:~C97 ".$E->getTraceAsString());      
        $exceptions ++;    
    } // main try - catch

    flock($fpid, LOCK_UN);
    fclose($fpid);
    unlink($pid_file);
    // $telegram->sendMessage($params);
    try {
        $mysqli->close();
        $telegram->deleteWebhook([true]);
        if (!USE_LOCAL)
                $telegram->logOut();
    }  catch (Exception $E) {
        $msg  = $E->getMessage();
        if ('Logged Out' == $msg)
            die ("#FINISHED: $msg \n");
        log_cmsg("~C07~C91 #EXCEPTION:~C00 while finalization:~C97 %s ", $msg);
        log_cmsg("~C93 #TRACE:~C97 ".$E->getTraceAsString());      
    }       
?>