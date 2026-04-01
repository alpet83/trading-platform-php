<?php
    require_once('lib/common.php');
    require_once('lib/esctext.php');
    require_once("lib/hosts_cfg.php");  // $msg_servers [] must bedefined in 
    

    $copt = new CurlOptions();
    $copt->connect_timeout = 5;
    $copt->total_timeout = 15; 
    

    $copt->extra = array(CURLOPT_TCP_KEEPALIVE => 1, CURLOPT_TIMEOUT => 3);  

    if (defined('CURLOPT_MAXLIFETIME_CONN')) 
    $copt->extra[CURLOPT_MAXLIFETIME_CONN] = 60;

    

    function is_send_success($res) {
    return $res && false === strpos($res, '#ERROR') && false === strpos($res, '#FATAL');
    }

    class EventRecord {

    public        int  $id = 0;
    public        string $tag;
    public        string $event;
    public        int    $flags = 0;
    public        int    $value = 0;
    public        $file = false;
    public        $result = false;

    public        $channel = 'default';

    public        $on_success = null;
    public        $on_error = null;



    public function __toString() {
        return json_encode($this, JSON_UNESCAPED_UNICODE);
    }


    
    protected function exec_cb(string $cb) {
      if (is_callable($this->$cb))
          call_user_func($this->$cb, $this);
      elseif (false !== $this->$cb && !is_null($this->$cb)) {
          $oinf = print_r($this, true);        
          $cinf = var_export($this->$cb, true);
          fprintf(STDERR, "#WARN: invalid callback '$cb' = [%s:%s] for %s...\n",  $cinf, gettype($this->$cb), substr($oinf, 0, 256)); 
      }  
    }


    public function import(string $json) {
        $data = json_decode($json, true);
        if (is_array($data)) {
            foreach ($data as $key => $value) {
              if (property_exists($this, $key))
                $this->$key = $value;
              else 
                log_cmsg('~C91#WARN_SKIP:~C00 property %s not exists', $key);  
            }
        }      
    }

    protected function send_text(string $server): string  {
        global $hostname, $copt;    
        $hostname = trim($hostname);
        $text = $this->event; //  mb_convert_encoding ($this->value, 'UTF-8', 'ISO-8859-1');
        $post_data = 'event='.urlencode($text);
        log_cmsg("~C32 #SEND_EVENT~C00(%s):  %s\n", $this->tag, $this->event);
        $pid = getmypid();
        $url = sprintf("%s/trade_event.php?tag=%s&host=%s&value=%d&id=%d&pid=$pid&channel=%s", $server, $this->tag, $hostname, $this->value, $this->id, $this->channel);
        $res =  curl_http_request($url, $post_data, $copt);
        return $res;
    }
    protected function send_media(string $server): string {
        global $hostname, $copt;   
        $fname = trim($this->file);
        $hostname = trim($hostname);
        $hostname = urlencode($hostname);
        if (!file_exists($fname)) {
            return "#FATAL: not exists file [$fname]!";
        }
        
        $content = 'data';
        $type = $this->flags & 0xf00;
        if (0 == $type) { // try detection
            $img_ext = array('png', 'jpg', 'gif', 'bmp', 'tiff');
            $doc_ext = array('dat', 'txt', 'doc', 'xls', 'jpg', 'png', 'gif', 'log');      
            $file_ext = pathinfo($fname, PATHINFO_EXTENSION);
            $i = array_search($file_ext, $img_ext);
            $j = array_search($file_ext, $doc_ext);
            if (false !== $i) {
                $type = 8 + ($i << 8); //  is image attach   
                $content = 'image';
            }  
            elseif (false !== $j)      
                $type = 0 + ($j << 8);        
        }       
        if (0 == $type)
            return "~C91#FATAL:~C00 unknown file type [$file_ext]!";
        $this->flags |= $type;            
        $data = file_get_contents($fname);
        $size = filesize($fname);
        $hash = md5($data);
        $data = base64_encode($data);

        $post_data = array('event' => $this->event, 'hash' => $hash, 'attach' => $data, 'filename' => basename($fname));
        log_cmsg("%s. #PERF: trying send %d base64 string, from  %s (%d size of $content, MD5 %s) to server~C92 %s~C00, used flags 0x%02x...\n", 
                  tss(), strlen($data), $fname, $size, $hash, $server, $this->flags);    
        $pid = getmypid();
        $url = sprintf("%s/trade_event.php?tag=%s&flags=%d&host=%s&value=%d&id=%d&pid=$pid&channel=%s", $server, $this->tag, $this->flags, $hostname, $this->value, $this->id, $this->channel);
        $res = curl_http_request($url, $post_data, $copt);
        return $res;
    }   
    

    public function send(string $server) {
      if (false !== $this->file)
          $this->result = $this->send_media($server);
      else  
          $this->result = $this->send_text($server);
      
      if (is_send_success($this->result)) 
          $this->exec_cb('on_success');
      else 
          $this->exec_cb('on_error');  
      return $this->result;   
    }
    }

    



    class EventQueue {
    protected $data = [];
    protected $use_server =  0;

    protected $index = 0;

    protected $server_fails = 0;

    protected $sender_inst = null; // instance of sender process

    protected $sender_pipes = [];

    protected $sender_last_out = 0;

    public $verbosity = 1; 
    public $channel = 'default';

    protected $prev_m = -1;

    public function count() {
        return count($this->data);
    }

    public function __destruct() {
      if ($this->sender_active()) {     
        fputs($this->sender_pipes[0], json_encode(['command' => "EXIT"])."\n");        
        sleep(1);
        if ($this->verbosity > 1)
            log_cmsg("~C91#WARN:~C00 stoping event_sender %d...", $this->sender_inst);
        proc_terminate($this->sender_inst);
        proc_close($this->sender_inst);
      }         

    }
    

    public function push_event(string $tag, string $event, $value = 0) {
        $rec = new EventRecord();        
        $rec->tag = $tag;        
        $rec->event = $event;
        $rec->value = $value;    
        return $this->push($rec);                            
    }
    public function push_media (string $tag, string $event, string $fname, $flags = 0, $value = 0) {
        foreach ($this->data as $rec) 
          if ($rec->file == $fname) {
            fprintf(STDERR, "#WARN: file %s already in queue...\n", $fname);
            return $rec;
          }
        $rec = $this->push_event($tag, $event, $value);
        $rec->id = $this->index++;
        $rec->flags = $flags;        
        $rec->file = $fname;
        return $rec;
    }        

    public function push(EventRecord $rec) {
        $rec->id = $this->index++;
        if ('default' == $rec->channel)        
           $rec->channel = $this->channel;
        $this->data []= $rec;
        return $rec;
    }
    public function process () {
        $minute = date('i');
        if ($this->server_fails > 10 && $minute % 10 < 9)  return false;
        if (0 == $minute % 10 && $this->prev_m != $minute && $this->sender_active())
           $this->ping_sender();           

        $batch = 10;   
        while (count($this->data) > 0 && $batch > 0) {
          $batch ++;
          $rec = $this->data[0]; // pick from head
          $untraceable = is_null($rec->on_error) && is_null($rec->on_success);
          $pid = $this->sender_active();
          $stdin = 0;
          if ($pid > 0 && $untraceable) {
            $msg = strval($rec);
            $stdin = $this->sender_pipes[0];
            if (fputs($stdin, "$msg\n") !== false) {            
                array_shift($this->data); 
                continue;
            }
            else 
              log_cmsg("~C91#ERROR:~C00 event not sent to %d, fputs failed\n", $pid);    
          }
          if ($this->verbosity-- > 1) 
              log_cmsg("~C96#SEND_TRY_LOCAL:~C00 record %s, sender? = %d:%s, traceable = %s ",  strval($rec), $pid, var_export($stdin, true), $untraceable ? 'no' : 'yes');
              
          if ($this->send($rec)) {
              array_shift($this->data); // remove from head              
              continue;  
          }                   
          $this->server_fails ++;
          if ($this->verbosity > 0)
             log_cmsg("~C91#ERROR:~C00 event not sent, server fails %d\n",$this->server_fails);
          // TODO: save to file queue;
          // file_put_contents(sprintf($dir.'/failed_send_%d@%d.json', $rec->id, getmypid()), json_encode($rec));
          return false; // something goes wrong
        }
        $this->prev_m = $minute;
        return true;
    } // process  


    public function sender_active() {
      if (!is_null($this->sender_inst)) {      
                if (!is_resource($this->sender_inst)) {
                        $this->sender_inst = null;
                        $this->sender_pipes = [];
                        return 0;
                }
        $status = proc_get_status($this->sender_inst);
        $elps = time() - $this->sender_last_out;
        if ($status['running'] && $elps < 600 && count($this->sender_pipes) == 3) {
            // checking no hang
            $out = fgets($this->sender_pipes[1]);
            if ($out) {
                if (false !== strpos($out, 'alive'))
                    $this->sender_last_out = time();
                if ($this->verbosity > 3)  
                    log_cmsg("~C96#RX:~C00 event_sender %d: %s", $status['pid'], $out);
                    
            }     
            return $status['pid'];
        }   
        else {
            log_cmsg("~C91#WARN:~C00 event_sender %d not alive, pid: %s, pipes: %s, last out elapsed %d sec", 
                        $status['pid'], json_encode($status), var_export($this->sender_pipes, true), $elps);
            proc_terminate($this->sender_inst);
            proc_close($this->sender_inst);
            $this->sender_inst = null;
            $this->sender_pipes = [];
        }  
      }
      return 0;
    }

    protected function ping_sender() {
        fputs($this->sender_pipes[0], json_encode(['command' => "PING"])."\n");
    }
    public function start_sender() {
        $ds = [array("pipe", "r"), array("pipe", "w"), array("pipe", "w")];               
        $bot = active_bot();      
        $php_bin = defined('PHP_BINARY') && is_string(PHP_BINARY) && strlen(PHP_BINARY) > 0 ? PHP_BINARY : '/usr/local/bin/php';
        $sender_script = realpath(__DIR__.'/../event_sender.php');
        if (!$sender_script) {
            $sender_script = __DIR__.'/../event_sender.php';
        }
        $cmd = sprintf('%s %s %s', escapeshellarg($php_bin), escapeshellarg($sender_script), escapeshellarg($this->channel));
        $this->sender_inst = proc_open($cmd, $ds, $this->sender_pipes, getcwd());      
        if ($this->sender_inst && count($this->sender_pipes) == 3)  {        
            stream_set_blocking($this->sender_pipes[1], false);
            $this->ping_sender();          
            $this->sender_last_out = time();
            log_cmsg("~C92#SUCCESS:~C00 event_sender started, pid = %d", $this->sender_active());
        }
        else
            log_cmsg("~C91#ERROR:~C00 event_sender not started, used command: %s", strval($cmd));
        return $this->sender_active();
    }


    protected function send(EventRecord $rec) {
        global $msg_servers;
        $res = false;        
        $server = $msg_servers[$this->use_server];
        $res = $rec->send($server);        
        if (is_send_success($res))
           return $res;
        else {
           $this->use_server = ($this->use_server + 1) % count($msg_servers);
           return false;
        }   
    } 


    }
