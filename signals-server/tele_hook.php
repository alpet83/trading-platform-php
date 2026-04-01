<?php
    require_once("lib/common.php");
    require_once("lib/esctext.php");
    require_once("lib/db_tools.php");
    require_once("lib/db_config.php");

    $color_scheme = 'cli';

    function stop(){
        log_cmsg(...func_get_args());
        $fmt = format_uncolor(...func_get_args());
        $tag = substr($fmt, 0, strpos($fmt, ':'));
        die("$tag\n");
    }

    $log_file = fopen("logs/tele_hook.log", 'a');
    error_reporting(E_ERROR | E_PARSE);
    $db_server = [null];
    $mysqli = init_remote_db('trading');
    error_reporting(E_ERROR | E_WARNING | E_PARSE);
    if (!is_object($mysqli))
        error_exit("~C91#FATAL:~C00 DB `trading` inaccessible!");
    
    function process_send(mixed $res): bool {
        global $mysqli, $history;
        // добавление меток сообщений в лог, чтобы их потом удалить из чата
        if (!is_object($res) || !isset($res->text) && !isset($res->caption)) { 
            log_cmsg('~C91 #ERROR:~C00 invalid res = %s ', var_export($res, true));
            return false;
        }                 
        try {             
            $text = $res->text ?? $res->caption;    
            if (strlen($text) < 10 || '/' == $text[0]) return false; // every short, seems is command or service  
            $t = $res->date;
            $ts = gmdate(SQL_TIMESTAMP, $t);
            $event = $mysqli->real_escape_string($text);
            $row = $mysqli->select_row('event_id,tag,event', 'post_queue', "WHERE event = '$event' ORDER BY ts DESC");  // latest by time           
            if (is_null($row)) {
                log_cmsg("~C91#WARN:~C00 event [%s] not found in post_queue", $event);
                return false;
            }    
            $event_id = $row[0];
            $tag = $row[1];
            $mysqli->try_query("DELETE FROM post_queue WHERE event_id = $event_id");

            $json = strval($res);                      
            log_cmsg("~C94#SEND_RES:~C00 %s ", $json);
            $rec = json_decode($json);      
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
                // $telegram->pinChatMessage(['chat_id' => $chat->id, 'message_id' => $msg_id]);
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
        return true; 
    }


    $data = file_get_contents( 'php://input' );
    if (strlen($data) < 10)
        error_exit("~C91#SHORT:~C00 $data");
    
    $rec = json_decode($data);
    if (!is_object($rec))
        error_exit("~C91#ERROR:~C00 can't parse json from %s", $data);
        
    log_cmsg("~C93#EVENT:~C00 from [%s] => [%s]: %s", $_SERVER['REMOTE_HOST'], $_SERVER['REMOTE_ADDR'], $_SERVER['REQUEST_URI']);    
    if (isset($rec->update_id) && isset($rec->channel_post)) {
        if (process_send($rec->channel_post))
            stop("#OK: post to channell processed");    
        else 
            stop("#NOPE: post ignored");
    }

    if (isset($rec->update_id) && isset($rec->message) && isset($rec->message->chat)) {
        if (process_send($rec->message))
            stop("#OK: direct post processed");
        $ts = gmdate(SQL_TIMESTAMP, $rec->message->date);
        $chat_id = $rec->message->chat->id;
        $message = $rec->message->text;
        $query = "INSERT IGNORE INTO `last_messages` (ts, update_id, chat, `message`) VALUES\n ";
        $query .= "('$ts', {$rec->update_id}, $chat_id, '$message');";      
        if ($mysqli->try_query($query)) {
            $op = $mysqli->affected_rows > 0 ? 'inserted' : 'exists';
            stop("#OK: message #%d '%s' $op for %d", $rec->update_id, $message, $chat_id);
        }    
        else
            stop("~C91#ERROR:~C00 message #%d not saved: %s", $rec->update_id, $mysqli->error);  
    }     
    error_exit("~C91#BAD_REC:~C00 no valid fields in %s", $data);
