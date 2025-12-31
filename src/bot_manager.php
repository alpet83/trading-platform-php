<?php
    echo '***';
    set_include_path("lib:.:../lib:/usr/sbin/lib");


    include_once 'lib/common.php';
    include_once 'lib/esctext.php';
    include_once 'lib/db_tools.php';
    include_once 'lib/db_config.php';
    include_once 'lib/ip_config.php';  // hostmap as presentation of /etc/hosts
    
    $active = true;
    $host = trim(file_get_contents('/etc/hostname'));
    $host_ip = '127.0.0.1';
    if (isset($host_map[$host]))
        $host_ip = $host_map[$host];

    $log_file = fopen(getcwd().'/log/bot_manager.log', 'w');

    function send_event($tag, $event, $value = 0) {
        global $host;
        $post_data = 'event='.urlencode($event);
        log_cmsg ("~C93 #SEND_EVENT($tag):~C02  $event");
        return curl_http_request("http://vps.vpn/trade_event.php?tag=$tag&host=$host&value=$value", $post_data);
    }
    
    function sig_handler(int $signo) {        
        global $active;
        if (SIGUSR1 == $signo)  {
            log_cmsg("#SIGUSR1: current location: %s ", format_backtrace());
            return;
        }
        log_cmsg("~C101~C97 #SIGNAL_STOP: ~C00 signal %d received, exiting...", $signo);
        if ($active)
            $active = false;
        else
            die("#TERMINATED: already not active\n");
        if (SIGTERM == $signo) 
            throw new Exception("SIGTERM signal received, exiting...");
    } // sig_handler

    echo '...';

    class TradingBot {
        public string       $exchange;
        public     $account_id = 0;
        public     $restarts = 0;
        protected string    $table; 
        public   string     $impl_name;

        protected string    $api_key = '';
        
        protected $stdout;    
        protected $stderr;    
        protected string $api_secret = '';
        protected $start_t = 0;
            
        protected $instance = null; 
        public  $trader_pwd = '_nope_';
        private  $account_params;


        public function __construct($table, $account) {
            $this->table = $table;
            $this->impl_name = $account->name;
            $this->account_id = $account->id;
            $this->exchange = $this->ConfigValue('exchange');
            $this->account_params = $account;
        } 

        public function ConfigValue($param, mixed $default = false)  {
            $result = sqli()->select_value('value', $this->table, "WHERE param = '$param'");
            if ($result)
                return $result;
            else  
                return $default;
        }

        public function IsActive() {
            $wdt_file = "/tmp/{$this->impl_name}.wdt";
            if (file_exists($wdt_file)) try {
                $wdt = file_get_contents($wdt_file);
                $wdt = intval($wdt);
                $elps = time() - $wdt;
                if ($elps >= 300) 
                    $this->Touch();
                
                if ($elps >= 420 && $wdt > $this->start_t) { 
                    $uptime = time() - $this->start_t;
                    log_cmsg("~C91#WARN:~C00 for %s watchdog expired, last update was %d sec ago, total uptime %d sec", 
                                $this->Name(), $elps, $uptime);
                    $this->Stop();
                }
            }  catch (Throwable $E) {
                log_cmsg("~C91#EXCEPTION:~C00 for %s watchdog error %s", $this->Name(), $E->getMessage());
            }
            return $this->GetStatus()['running'];      
        }

        public function GetStatus(): array {
            $st = ['bad_instance' => 'yes', 'running' => false, 'exitcode' => -254];
            $st_real = false;      
            if ($this->instance)
                $st_real = proc_get_status($this->instance);
            $st = (is_array($st_real) ? $st_real: $st);
            $st['name'] = $this->Name();
            return $st;
        }


        public function Name() {
            return "{$this->exchange}@{$this->account_id}";
        }

        public function  Run() {
            $this->restarts ++;
            $name = $this->Name();  

            if (!$this->Stop()) { // before run
                log_cmsg("~C91 #ERROR:~C00 failed stop instance {$this->impl_name}, will try again later");
                return false; 
            }    
            // $err = array("file", "/tmp/$name.stderr", "a");
            $exch = strtolower($this->exchange);
            if (strlen($exch) < 4) {
                log_cmsg("~C91 #FATAL:~C00 invalid bot exchange $exch ");
                return false;
            }
                
            
            $api_key_name = $this->ConfigValue('api_key_name');
            $api_key_sec  = $this->ConfigValue('api_secret_name', '');
            $separator    = $this->ConfigValue('api_secret_sep', '-');      
                

            if (strlen($this->api_key) < 4) {        
                log_cmsg("~C94 #DBG:~C00 API key not cached, trying retrieve and set env[$api_key_name], secret separator [$separator]");        
                // once instance created, safe for session 
                // TODO: allow account difference, or storage selection.  
                $sfx = '';
                if (!$this->account_params->trade_enabled)
                    $sfx = '_ro'; // specify debug key
                $pf = "$exch@{$this->account_id}$sfx";
                $pp = "api/$pf";
                $this->api_key = trim(exec("pass $pp")); 
                $list = [];
                $found = 0;
                exec('pass ls api', $list);
                foreach ($list as $pass)
                if (false !== strpos($pass, $pf)) 
                    $found ++;
                $secret = '?';
                if ($found > 1)
                $secret = trim(exec("pass $pp".'_s0')).$separator.trim(exec("pass $pp".'_s1')); 
                else  
                log_cmsg("~C91 #WARN:~C00 not enough secrets for~C92 $pf~C00 in:\n\t %s", implode("\n\t", $list));
                $this->api_secret = chunk_split(base64_encode($secret), 8, "\n");
                log_msg("#DBG: cached $sfx secret size = ".strlen($this->api_secret));
            }       
            $ds = [["pipe", "r"], ["pipe", "w"], ["pipe", "w"]];
        $env_vars = getenv();
            $env_vars['impl_name'] = $this->impl_name;
            $env_vars['config_table'] = $this->table;

            if (strlen($this->api_key) > 0) {        
                if (strlen($api_key_name))  {
                log_cmsg("~C94 #DBG:~C00 reusing cached API %s creds...", $api_key_name); 
                $env_vars[$api_key_name] = $this->api_key;
                $env_vars[$api_key_sec]  = $this->api_secret;
                } else 
                log_cmsg("~C91#WARN:~C00 not specified API credentials meta in DB!");              
            }  

            $path = __DIR__."/$exch/"; // subdir for each exchange bots
            // php -d xdebug.auto_trace=ON -d xdebug.trace_output_dir=logs/ bot_instance.php
            $cmd = "su trader -c '$path/start_bot.sh $exch'";
            $name = $this->Name();
            log_cmsg("~C97#DBG:~C00 trying start %s bot", $exch);
            // print_r($env_vars);
            set_time_limit(30);
            if ($this->instance = proc_open($cmd, $ds, $pipes, $path, $env_vars)) {                     
                send_event("WARN", "Starting bot $name workdir $path, restarts = {$this->restarts} ");          
                $this->start_t = time();
                $this->stdout = $pipes[1];
                $this->stderr = $pipes[2];
                stream_set_blocking($this->stderr, false);
                stream_set_blocking($this->stdout, false);
                $start = time();
                $s = '';
                while ($this->stdout) {
                    usleep(10000);
                    $c = fgets($this->stderr);
                    if ($c) $s .= $c;
                    $elps = time() - $start;
                    if ($elps > 20 || false !== strpos($s, 'assword') || false !== strpos($s, 'ароль')) break;
                }
                if ($s && strlen($s) > 1)
                    log_msg("#OUT: $s");

                log_cmsg("~C94#DBG:~C00 trying authorize session...");
                $pwd = base64_decode($this->trader_pwd);
                fwrite($pipes[0], "$pwd\n"); // login trader, TODO use pass!
                sleep(1);
                $st = proc_get_status($this->instance);   
                echo stream_get_contents($this->stderr);
                echo stream_get_contents($this->stdout);

                if ($st['running']) 
                    return true;
                else {
                    log_cmsg("~C91 #ERROR:~C00 failed run instance $cmd");
                    var_dump($st);                 
                }            
            }  

            send_event("ALERT", "BOT Manager failed starting bot $name ");
            if ($this->stderr) fclose($this->stderr);
            if ($this->stdout) fclose($this->stdout);
            if($this->instance) 
                proc_close($this->instance);            
            $this->instance = false; 
            return false;
        }

        public function Output() {
            $name = $this->Name();
            $s = stream_get_contents($this->stderr);
            if (strlen($s) > 1) 
                log_msg("#STDERR($name): %s\n", $s);
            $s = stream_get_contents($this->stdout);
            if (strlen($s) > 1) 
                log_msg("#STDOUT($name): %s\n", $s);
        }

        public function Stop() {      
            if (is_resource($this->instance)) {
                log_cmsg("~C93 #STOP:~C00 soft terminating instance %s initiated... ", $this->impl_name);
                proc_terminate($this->instance, SIGQUIT);
                sleep(30);
            }          

            $pid_file = $this->impl_name.'.pid';
            $alive = file_exists($pid_file);
            $pid = false;
            if ($alive) {        
                log_cmsg("~C93 #STOP:~C00 found pid file $pid_file");
                $pid = file_get_contents($pid_file);
                $out = [];        
                system("ps $pid", $result);
                if (0 == $result)    // process exists
                    send_event('ALERT', "Trying terminate alive bot $this->impl_name with pid $pid");          
                else             
                    $alive = false;           
            }
            exec("sudo kill_bot {$this->impl_name} 60", $out);  // kill any previous instance                
            $name = $this->Name();
            if ($pid)            
                log_msg("#KILL($pid): bot $name result:".implode("\n", $out));                    
            if (!is_resource($this->instance)) return true;
            if ($this->GetStatus()['running']) {
                proc_terminate($this->instance, SIGTERM);
                sleep(5);
                proc_terminate($this->instance, SIGHUP);
            }  
            $this->instance = null;
            return !$alive;
        }

    } // class TradingBot

    echo '+++';

    function db_connect() {
        global $mysqli, $db_error, $db_servers;  
        // attempt to connect
        $db_servers = [null, 'db-local.lan', 'db-remote.lan'];
        while (true) {
            $mysqli = init_remote_db('trading');
            if (!$mysqli)  {
                if (0 == date('i') % 10)          
                    send_event("ALERT", "bot_manager cannot connect to DB. Last error $db_error");
                else 
                    log_cmsg("~C91#ERROR:~C00 failed connect to DB. Last error $db_error");
                sleep(60);
                continue;
            }
            break;
        }  
    }

    log_cmsg("~C93 #START: initializing BOT Manager at host $host ($host_ip)...~C00");
    mysqli_report(MYSQLI_REPORT_OFF);

    if (!isset($db_configs['trading'])) 
        die("#FATAL: DB config not loaded!\n");
    
    $res = 0;  
    $trader_pwd = exec('pass users/trader 2> /tmp/pass.stderr', $out, $res);
    if (0 !== $res)  {
        $err = file_get_contents('/tmp/pass.stderr');
        log_cmsg ("~C91 #FATAL~C00(%d): can't load credentials via pass\n~C91$err~C00\n", $res);
        die($res);
    }  

    $trader_pwd = trim($trader_pwd);
    $trader_pwd = base64_encode($trader_pwd);
        
    db_connect();

    $mysqli = sqli();
    // $tables = $mysqli->try_query("SHOW TABLES;");
    // while ($tables && $row = $tables->fetch_[]) printf(" %s ", $row[0]);
    
    $configs = $mysqli->select_rows('table_name,applicant', 'config__table_map', "WHERE table_key = 'config'", MYSQLI_ASSOC);

    $bots = [];
    foreach ($configs as $cfg) {
        $app = $cfg['applicant'];
        $table = $cfg['table_name'];
        log_msg("#CHECK_CFG: request params from $table...");
        $rows = $mysqli->select_rows('account_id,param,value', $table, "WHERE ((param = 'trade_enabled') or (param = 'monitor_enabled'))", MYSQLI_ASSOC);
        $acc_map = [];
        foreach ($rows as $row) {
            $id = $row['account_id'];
            $key = $app.'@'.$id; 
            $par = $row['param'];
            $val = $row['value'];
            $account = new stdClass();      
            if (isset($acc_map[$key]))                       
                $account = $acc_map[$key]; // load previous
            $account->id = $id;            
            $account->$par = $val;
            $account->name = $app;
            if ($val > 0)
                $account->active = true;
                
            $acc_map[$key] = $account;        
        }
        
        foreach ($acc_map as $account)  {      
            if ($account->active ?? false) {             
                $bot = new TradingBot($table, $account);
                $bot->trader_pwd = $trader_pwd;
                $bots []= $bot;
            }   
            else  
                log_msg("#DBG: No instance created for account ".json_encode($account));
        }     
        log_cmsg("#DBG: loaded %d bot configs", count($bots));
    }
    

    function SlaveRecovery(): bool {
        return ( try_query('STOP SLAVE;') &&
            try_query('SET GLOBAL SQL_SLAVE_SKIP_COUNTER = 1;') &&
            try_query('START SLAVE;'));
            
    }

    function RedudancyRecover($row): bool {
        global $db_servers, $host_map, $host_ip; 
        log_cmsg("~C91 #WARN:~C00 MariaDB redudancy failed... trying recovery. ");
        print_r($row);
        /* // TODO: select opposite DB server
        $avail = curl_http_request('http://10.113.10.1/db_active.php');
        if (strpos($avail, "#ERROR") !== false) {
        log_cmsg("~C91 $avail~C00 - DB recovery not possible yet...");
        return true;
        }
        */
        
        if ('No' === $row['Slave_SQL_Running'])
        if (SlaveRecovery()) {
            log_cmsg("#DBG: SQL_SLAVE_SKIP_COUNTER = 1 applied ");      
            return true;  // TODO: may not help, count recovery attemps 
        }   

        $spare = false;
        foreach ($host_map as $id => $ip)
        if ($ip !== $host_ip)
            $spare = $ip; 

        if (!$spare) {
        log_cmsg("~C91 #ERROR:~C00 cannot determine spare host IP");
        }

        $db_servers = array($spare);
        // TODO: ugly hardcoded creds
        $master_user = 'db_master';
        if (false !== strpos($spare, '.10.50'))  // #TODO: bad rescuer side detection
            $master_user .= '2';
        
        $master_pass = 'dbMaster!496';
        $remote = init_remote_db ($master_user, $master_pass, 'trading');
        if (!$remote) {
            log_cmsg("~C91 #ERROR:~C00 cannot connect to DB on $spare ");
            return false;
        }
        
        $rep_errors = [];

        $res = $remote->try_query('SHOW MASTER STATUS');
        if ($res && $info = $res->fetch_array()) {
        print_r($info);
        $used_file = $row['Relay_Master_Log_File'];
        if ($used_file != $info['File'])
            $rep_errors []= "SLAVE_CFG_FILE: used $used_file, but must be {$info['File']}";
        $used_pos = $row['Read_Master_Log_Pos'];
        if ($used_pos != $info['Position']) 
            $rep_errors []= "SLAVE_CFG_POS: used $used_pos, but must be {$info['Position']}";       
            
        }

        if (count($rep_errors)) {  
            send_event("WARN", "Replication errors:\n ".implode("\n", $rep_errors));
            print_r($rep_errors);
        }   

        /* step-by-step:
        * detecting why node is obsolete and needs sync by positions in spare log
        * if local node slave config outdated, process recovery from last backup...
            \ stop  slave
            \ kill bots
            \ restore db from /backup/trading.remote/last-archive (bzcat)
            \ parsing master_info for log name and pos
            \ configure slave (change master query)
            \ start slave
        //*/
        //       

        $remote->close();
        return true;
        
    }

    function RedudancyCheck() {
        global $bots;
        $minute = date('i') * 1;
        $res = try_query("SHOW SLAVE STATUS;");
        $row = false;
        if ($res) 
            $row = $res->fetch_array(MYSQLI_ASSOC);
        if (is_array($row) && isset($row['Slave_SQL_Running'])) {      
            $sv_run = $row['Slave_SQL_Running'];
            // if ('Yes' == $sv_run) return;
            $io_run = $row['Slave_IO_Running'];
            if ('No' == $io_run) {
                // $host = file_get_contents('/etc/hostname');
                // send_event('ALERT', "Slave_IO_Running = NO at host $host ");         
                sleep (60);
                return 0 != $minute;
            } 
            elseif ('No' != $sv_run) {
                $rows = sqli()->select_value('COUNT(master_host)', 'bot__redudancy');
                if (0 == $rows)
                    log_cmsg("~C91 #WARN:~C00 bot__redudancy is void table");
                return $rows > 0;
            }   

            return RedudancyRecover($row);
        } else  {
            log_cmsg("~C91 #WARN:~C00 MariaDB check SLAVE status failed... ");
            var_dump ($row);
        }       
        
        return false;
    }
    

    // print_r($bots);
    log_cmsg("~C97 #STARTUP:~C00 main loop begins...");
    $loops = 0;
    $states = [];
    if (pcntl_signal (SIGQUIT, 'sig_handler') && pcntl_signal (SIGTERM, 'sig_handler') &&
        pcntl_signal (SIGUSR1, 'sig_handler'))
            log_cmsg("~C97#SUCCESS:~C00 signal handler registered, async handling %s", 
                                        pcntl_async_signals(null) ? 'yes' : 'no');
    pcntl_async_signals(true);                                        

    function wait_sig(int $timeout) {
        $info = [];
        pcntl_sigtimedwait([SIGQUIT, SIGTERM], $info, $timeout,0); 
    }

    while ($active) {  // MAIN LOOP
        set_time_limit(70);
        $minute = date('i') * 1;  
        $ready = false; 
        $loops ++;
        if ($mysqli->ping())   
            $ready = true; // TODO: use redudancy config, for call RedudancyCheck();
        else {
            db_connect();
            sleep(3);
            continue;
        }  
        $acnt = 0;
        $count = count($bots);    

        if ($ready)       
            foreach ($bots as $idx => $bot) {    
                if (0 == $minute % 10)
                    $bot->restarts = 0;
                set_time_limit(60);
                if (!$bot->IsActive()) {         
                    log_cmsg("#INACTIVE ($idx / $count): %s, restarts %d, prev = %s ", json_encode($bot->GetStatus()), $bot->restarts, isset($states[$idx]) ? json_encode($states[$idx]) : 'none');
                    if (0 == $minute) 
                        $bot->restarts = 0;                                   
                    if ($minute < 59 && $bot->restarts < 10 && !$bot->Run()) 
                    wait_sig(30);
                } else {
                    $bot->restarts = 0; 
                    $bot->Output();
                    $acnt ++;
                } 
                $states[$idx] = $bot->GetStatus();
                // 
            } 
        else // not ready
            log_cmsg("~C91 #NOT_READY~C00(%d): redudancy checks failed ", $loops);

        wait_sig(30);
        sleep(1);
    }   // while main 

?>