#!/usr/bin/php
<?php
  require_once 'lib/common.php';
  require_once 'lib/esctext.php';
  require_once 'lib/trading_info.php';

  $g_queue = new EventQueue();

  
  $g_queue->verbosity = 3;

  function non_block_read($fd, &$data) {
    $read = array($fd);
    $write = array();
    $except = array();
    $result = stream_select($read, $write, $except, 0);
    if($result === false) return false;
    if($result === 0) return false;
    $data = stream_get_line($fd, 2000, "\n");
    return is_string($data) && strlen($data) > 10;
  }

  $log_file = null;
  $test = false;
  $owner = 'default';

  if (isset($argv[1])) {
    $owner = $argv[1];
    $test = $argv[2] ?? false;    
    if ($test) {
       set_time_limit(25);
       if ($g_queue->start_sender())
         log_cmsg("~C92#SUCCESS:~C00 event_sender started with pid %d", $g_queue->sender_active());
       else
         log_cmsg("~C91#FATAL:~C00 event_sender not started\n");
       $g_queue->push_event('WARN', 'test event');
       sleep (1);
       $g_queue->process();
       $g_queue = null;
       sleep(1);
       $test = false;       
       log_cmsg("~C97#OK:~C00 Test completed");
    }    
             
  }

 function process_command(string $cmd) {
    global $owner; 
    if ($cmd == 'EXIT') {
        log_cmsg("~C97#OK:~C00 event_sender <b>$owner</b> exit\n");
        die('');
    }
    if ($cmd == 'PING') {
        echo "PONG\n";
        return;
    }
 }
  
 $prev_m = 0;
 $last_in = time(); 
 $g_queue->push_event('WARN', "#DBG: event 🆂🅴🅽🅳🅴🆁 for <b>$owner</b> started");
 $g_queue->channel = $owner;
 while (!$test && !is_null($g_queue)) {
    if (!$log_file)
         $log_file = fopen("logs/event_sender_$owner.log", 'w'); // multiple instances possible 
    $json = false;     
    set_time_limit(50);
    usleep(100000);   
    try {
        $minute = date('i');
        if ($minute != $prev_m)
           echo tss()." #INFO: alive\n";
        $elps = time() - $last_in;
        if (2 == $minute && $elps > 300) {
            log_cmsg("~C91#ERROR:~C00 event_sender $owner is stuck\n");
            break;
        }
        $prev_m = $minute;        
        $g_queue->process();     
        if (!non_block_read(STDIN, $json)) continue;        
        $rec = json_decode($json);
        if (is_object($rec) && isset($rec->value)) 
           log_cmsg("~C92#RX:~C00 %s: %s ", $json, $rec->value);        

        echo "#RX: $json\n";
        $last_in = time();
        if (!is_object($rec)) {
            log_cmsg("~C91#ERROR:~C00 event not object: %s", print_r($rec, true));   
            continue;
        }       
        elseif (isset($rec->id)) {     
            $rec->on_success = null;
            $rec->on_fail = null;     
            $event = new EventRecord();            
            $event->import(json_encode($rec));
            log_cmsg("~C93#PUSH:~C00 %s ", strval($event));
            $g_queue->push($event);
            log_cmsg("~C97#OK:~C00 event pushed\n");
        }    
        elseif (isset($rec->command)) {
            process_command($rec->command);             
        } else 
            log_cmsg("~C91#ERROR:~C00 not recognized:\n%s", print_r($rec, true));             
    }
    catch (Exception $e) {
      log_cmsg("~C91#ERROR:~C00 event processing error: %s", $e->getMessage());
      log_cmsg("~C93#TRACE:~C00 %s", $e->getTraceAsString());
    }
    
    sleep(1);
 } // while