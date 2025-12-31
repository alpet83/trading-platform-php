<?php
    /*
        Основная цель этого скрипта — управление торговыми операциями, такими как создание, обновление и закрытие ордеров, а также управление позициями и сигналами.
        Механика по сути простая: забирать целевые позиции или сигналы с сервера, обрабатывать их и создавать соответствующие ордера до достижения целевых позиций.
        С настройками по умолчанию, весь процесс происходит с интервалом в 1 минуту, может зависеть от задержек как источника позиций, так и от лага данных с биржи. 
        Базовый класс TradingCore определяет торговое ядро, которое управляет рабочим циклом, включая запрос данных с серверов и команды движку по управлению заявками
    */
    require_once 'lib/common.php';
    require_once 'lib/db_tools.php';
    require_once 'lib/esctext.php';
    require_once 'lib/basic_logger.php';
    require_once 'lib/hosts_cfg.php';
    require_once 'lib/table_render.php';
    require_once 'lib/trading_info.php';
    require_once 'lib/print_r_level.php';  
    require_once 'lib/smart_vars.php';

    require_once 'bot_globals.php';
    require_once 'ticker_info.php';
    require_once 'compos.php';
    require_once 'exec_opt.php';
    require_once 'orders_lib.php';
    require_once 'ext_signals.php';
    require_once 'orders_batch.php';
    require_once 'pos_feed.php';
    require_once 'db_client.php';
    require_once 'trade_config.php';
    require_once 'trade_logging.php';
    require_once 'trading_engine.php';
    require_once 'trading_context.php';
    require_once 'trading_loop.php';
    require_once 'market_maker.php';
    require_once 'reporting.php';


    $g_queue = new EventQueue();

    function value_between($ref, $min, $max) {
        return ($min <= $ref && $ref <= $max);
    }

    function active_bot(): ?TradingCore {
        global $g_bot;
        return $g_bot;
    }
    
    function on_shutdown() {
        global $g_bot;
        print_r(error_get_last());

        if (is_object($g_bot) && $g_bot instanceof TradingCore && $g_bot->Alive()) {
            $g_bot->LogError("~C91#BREAK:~C00 script execution interrupted, on_shutdown...\n");
            $g_bot->Shutdown("Shutdown callback");
        }
        
    }

    function sig_term_safe($signo, $siginfo) {
        global $mysqli;
        $bot = active_bot();
        debug_print_backtrace();
        if ($bot) {
            // $bot->LogError("~C91#BREAK:~C00 script execution interrupted, batch: $signo...\n");
            if ($siginfo)
                var_dump($siginfo);        
            $bot->Shutdown('SIGTERM/SIGQUIT received');      
            if (15 == $signo) throw new Exception("SIGTERM received");
        }
        else {            
            die("#EXITING: sig_handler called\n");
        }            
    }
    
    function sig_usr($signo, $siginfo){
        $bot = active_bot();
        if ($bot) {      
            $bot->LogMsg("~C70#SIGNAL:~C00 batch: $signo %s ...", var_export($siginfo, true));
        } 
        debug_print_backtrace();    
    }

    function floor_qty($qty, $price, $min_cost, $coef = 100): float { // adjust decimals in qty
        if ($min_cost <= 0 || $price <= 0)
        return $qty;    
        // сценарий: price = 95000, qty = 0.0001, min_cost = 100; 
        // mult = 1; vol = 9.5$; vol = 9.5 / 95000 = 0.0001    
        $mult = $min_cost / $coef; // мульипликатор для округления
        $min_qty = $min_cost / $price; // min_qty = 0.000105
        $qp = 2;
        if ($min_qty < 1)
            $qp = ceil (-log10($min_qty) + log10($coef)); // 

        $vol = $qty * $price;
        $vol = floor($vol * $mult) / $mult;    
        return round($vol /= $price, $qp);    
    }
  
  
  /*
  12.02.2021
   класс TradingCore - интерфейс абстрактного механизма, для достижения целевых позиций по заданным в настройках валютным парам.
   Порожденные от него классы, как предполагается, будут реализовывать логику для конкретных бирж, тогда как наследники движка - взаимодействие с API.

  */

  class TradingCore {
    
    use DBClientApp, TradeLogging, TradingLoop, TradeReporting;

    protected  $active = true;
    public     $aborted = false;

    
    public     $pairs_map  = [];
    public     $pairs_coef = []; // global coef config
    public     $impl_name = 'trading_core'; // edited in override constructor for specific implementation
    public     $configuration = null; // instance of class TradeConfig
    public     $target_pos = [];
    public     $current_pos = [];
    public     $offset_pos = []; // table configured by admin
    public     $trades  = [];
    
    public     $default_data_exch = ''; // биржа для которых в datafeed собрано больше всего данных, особенно свечек

    public     $updated_time = 0;
    public     $total_funds = -1;   // account summary in USD
    public     $used_funds = 0;   // % of possible capital
    public     $total_btc  = -1;    // account BTC
    public     $total_eth = -1;     // account ETH

    public     $session_btc_price = 0; // BTC price for signals TDP amount calculation (futures), with zero drift intraday
    public     $session_start = '2025-01-01 00:00'; // when intraday session started (it can before restart bot!)

    public     $postpone_orders = []; // count back list for make trades, for some temporary errors
    
    public     $notify_orders = 0;  // TODO: must be toggled by Telegram
    public     $active_role;  // REDUDANCE: master or rescue    
    public     $reserve_status = 0;
    public     object $exec_opt;    
    
    public     $auth_errors = [];

    public     $order_timeout = 420;
    
    public     $tmp_dir = '';
    public     $uptime = 0;


    protected  $last_pending = []; // map last active orders [pair_id]
    protected  int $start_ts     = 0;   // in msec
    
    protected  $trade_engine = null;
    protected  $position_feed = null;
    protected  $signal_feed = null;   // new: used instead position_feed
    protected  $update_period = 60;  // period requesting positions from feed
    protected  $updates      = 0;   // count of performed updates
    
    protected  $conn_attempts  = 0;
    protected  $opened_positions = 0;
    protected  $high_liq_pairs = [];    
    protected  $ignored_ctr = [];
    private    string $pid_file_name  = '';
    private    $pid_file_handle = null;
    private    string $wdt_file = '';
    
 
    private    $notified = [];


    public function  __construct() {
        global $g_bot, $g_logger, $g_queue;
        $env = getenv();
        if (isset($env['impl_name']))
            $this->impl_name = $env['impl_name']; // may be overrided after/before this constructor

        
        $fpid_name = "../{$this->impl_name}.pid";            
        set_time_limit(60);
        

        while (file_exists($fpid_name)) {         
            $pid = file_get_contents($fpid_name);
            $pid = intval(trim($pid));                    
            system("ps $pid", $result);
            if (0 == $result) {
                log_cmsg("~C91#WARN:~C00 file %s exists, possible bot is already running with PID = %d ", $fpid_name, $pid);
                sleep(10);             
            }  
            else
                break;
        }              
        $g_bot = $g_logger = $this;      
        $this->configuration = new TradeConfig($this);
        $this->InitLogging();
        

        $this->LogMsg("PID file $fpid_name");
        $this->pid_file_name = $fpid_name;
        $this->SavePID();  

        $this->wdt_file = str_replace('.pid', '.wdt', $this->pid_file_name);  
        $this->wdt_file = str_replace('../', '/tmp/', $this->wdt_file);  // using tmpfs directory
        file_put_contents($this->wdt_file, time());

        $this->exec_opt      = new SmartExecOpt($this);      
        $this->position_feed = new PositionFeed($this);            
    }
    public function __destruct() {
        global $bot;
        $this->signal_feed = null;
        $this->LogMsg("~C94#REPORTS:~C00 %s", json_encode($this->reports_map));
        $this->LogMsg("#FINISH: TradeCore::destruct, bye!");      
        $this->mysqli = null;
        $bot = null;
    }

    public   function Alive() {
        global $hostname; 
        $this->SavePID();        
        file_put_contents($this->wdt_file, time());
        if ($this->active)
            $this->CheckDBConnection()->try_query("UPDATE config__hosts SET last_alive = NOW() WHERE host = '$hostname'");
        return $this->active;
    }

    /** returns session or real BTC price */
    public function BitcoinPrice() { 
        $sp = $this->session_btc_price ?? 0;
        return $sp > 9000 ? $sp : $this->Engine()->get_btc_price();
    }
    protected function Finalize(bool $eod) {
        global $g_queue;
        $this->LogMsg("~C04~C97#FINALZIZE:~C00 %s", $eod ? 'EoD' : 'interrupted');
        $this->mysqli_deffered = null;            
        $engine = $this->Engine();
        $this->SignalFeed()->Finalize($eod);
        $engine->Finalize($eod);
        
        $m = date('i');
        if ('master' == $this->active_role) {
            if ($m < 3 || $m >= 58);
                $this->SlippageReport(24 * 3600); 
            $this->VolumeReport();    
        }
        $this->RegEvent('FINALIZE', '');
        while ($g_queue->count() > 0)
                $g_queue->process(); 
        
        if ($engine->sign_fails >= 5) 
            $this->send_event('ALERT', "API signature fails = {$engine->sign_fails}  times, check other errors");
        $this->Engine()->Cleanup();
        $this->mysqli = null;
        $this->mysqli_datafeed = null;
        $this->mysqli_deffered = null;
        $this->mysqli_remote = null;
    }

    public function  Initialize($mysqli) {
        global $g_queue, $hostname;
        ini_set('precision', 13);
        ini_set('serialize_precision', 12);
        set_time_limit(30);
        $this->mysqli_deffered = new mysqli_buffer($mysqli);
        $mysqli->error_logger = $this;
        $mysqli->log_func = 'LogError';
        $init_start = time();
        declare(ticks = 1);      

        pcntl_async_signals(true);
        if (pcntl_signal (SIGTERM, 'sig_term_safe') && pcntl_signal (SIGQUIT, 'sig_term_safe'))
            $this->LogMsg("~C97#OK:~C00 set signal handler for SIGTERM/SIGQUIT\n");
        else
            $this->LogError("~C91#FAILED:~C00 set signal handler for SIGTERM/SIGQUIT!\n");

        pcntl_signal (SIGUSR1, 'sig_usr');
        pcntl_signal (SIGUSR2, 'sig_usr');

        register_shutdown_function('on_shutdown');
        set_exception_handler(function (Throwable $E) {
               $this->LogException($E, 'Top level exception handler');
               $this->Shutdown('Non-caught exception');               
            });

        $engine = $this->Engine();

        $this->CheckDBConnection('remote', true ); 
        $this->CheckDBConnection('datafeed' ); 

        $this->mysqli = $mysqli;
        $mysqli->query("INSERT IGNORE INTO `config__hosts` (host, last_alive) VALUES('$hostname', NOW())");

        $this->configuration->Init($this->impl_name);

        $engine->CheckAccount($this->configuration->config_table);
        $this->configuration->Load($engine->account_id);
                
        $g_queue->channel = "{$engine->exchange}@{$engine->account_id}"; // channel for messages                      
        $g_queue->start_sender();  // account_id available, can run sender

        $exch = $engine->exchange;    
        $this->tmp_dir = strtolower("/tmp/$this->impl_name"); //
        if (!file_exists($this->tmp_dir))
            mkdir($this->tmp_dir, 0775, true); 


        $this->update_period = $this->ConfigValue('update_period', $this->update_period);
        $this->order_timeout = $this->ConfigValue('order_timeout', $this->order_timeout);

        $this->default_data_exch = $this->ConfigValue('default_data_exchange', 'bitfinex');

        $hlp_list = $this->ConfigValue('high_liq_pairs', '1,3,4,54'); // non-realtime config!
        $hlps = explode(',', $hlp_list);      
        foreach ($hlps as $pair_id)  
            $this->high_liq_pairs[$pair_id] = 1;

        $this->exec_opt->price_indent_pp = $this->ConfigValue('exec_price_indent', $this->exec_opt->price_indent_pp);

        $color = $this->ConfigValue('report_color', '0,0,0');
        $this->report_color = explode(',', $color);


        $this->position_feed->applicant = $this->impl_name;
        $this->position_feed->Initialize();
        $this->pairs_map = $this->position_feed->LoadPairsConfig($this->pairs_coef);      

        $t_start = time();    
        // $this->LogMsg("~C96#PERF:~C00 Initializing engine class %s", get_class($engine));
        $engine->Initialize(); // late order
        $this->RegEvent('INITIALIZE', '');
        // $this->LogMsg("~C96#PERF:~C00 Engine initialized in %d sec", time() - $t_start);

        $this->signal_feed = new SignalFeed($this);
        $binance = strpos($this->impl_name, 'binance') !== false;
        $this->signal_feed->common_symbol = $this->ConfigValue('common_symbol', $binance);
        $this->CheckRedudancy();

        $t_start = time();
        $this->signal_feed->LoadFromDB();
        $this->LogMsg("~C96#PERF:~C00 SignalFeed loaded in %d sec", time() - $t_start);      
        
        // $this->Engine()->exchange;
        $pos_file = getcwd()."/data/{$engine->account_id}_target_pos.json";
        if (file_exists($pos_file))
            $this->target_pos = file_load_json($pos_file, null, true);         

        $this->session_start = gmdate('Y-m-d 00:00');
        $this->session_btc_price = $this->CalcVWAP(BITCOIN_PAIR_ID, $this->session_start);  // используем усредненную цену BTC за прошлый день, это не точно, зато стабильно
        $this->LogMsg("~C93#SESSION_START:~C00 using BTC price $%.1f", $this->session_btc_price);
        $elps = time() - $init_start;
        $this->LogMsg("~C70#PERF~C00: %s::Initialize complete, update_period = %d, used %d seconds", get_class($this), $this->update_period, $elps);
        $this->LogMsg("~C97 MySQL timings on init:\n %s",$this->mysqli->format_timings(1, '   '));
    }

    
    protected function CheckSaveFunds() {
        if ($this->total_funds < 0 || $this->total_btc < 0) return;
        $engine = $this->Engine();
        $acc_id = $engine->account_id;
        $table = strtolower($engine->exchange).'__funds_history';
        $coef = $this->configuration->position_coef;      
        $quants = $this->total_funds / 10000;
        if ($this->total_funds > 10000)
            $quants = round($quants, 0);
        else  
            $quants = round($quants, 1);
        $limit = round(0.02 * $quants, 5);
        if ($coef > $limit && $this->total_funds > 0 && 0 == date('i') % 10) {         
            $this->send_event('ALERT', sprintf("For account %s, position coef %.3f is too high due funds level %.2f, set to %.3f",
                                                $acc_id, $this->configuration->position_coef, $this->total_funds, $limit));
            $this->configuration->SaveValue('position_coef', $limit);           
        }

        $dbc = $this->CheckDBConnection();

        $prev = $dbc->select_value('value', $table, "WHERE account_id = $acc_id");
        $diff = abs($this->total_funds - $prev);
        if ($this->total_funds > 0 && $diff > 1) { 
            $query = "INSERT IGNORE INTO `$table`(account_id, value, value_btc, position_coef)\n VALUES($acc_id, {$this->total_funds}, {$this->total_btc}, $coef)\n";
            $dbc->try_query($query);
        }     
    }                 

    public function  Run() {
        global $mysqli, $g_queue;  
        
        $eod = false;  
        $this->active = 1;
        $this->start_ts = time_ms();      
        $prev_t = 0;
        $prev_h = 0;
        $prev_m = -1;
        

        while ($this->conn_attempts < 10) {
            $ts = date('H:i:s');
            $minute = date('i') * 1;
            $hour = date('H') * 1;
            
            
            if (0 == $minute % 30 && $minute != $prev_m) {
            fseek($this->pid_file_handle, 0);
            fwrite($this->pid_file_handle, getmypid()); // overwrite
            }  
            $now = time();    
            if ($now > $prev_t + 20) {
            $this->Alive();
            $prev_t = $now;
            }           

            if (str_in($ts, '23:59:') || 
                0 == $hour && 23 == $prev_h) {
            $this->LogMsg("End-of-Day [$ts] trigger signaled, breaking work...");
            $this->active = false;
            $this->trade_enabled = false;
            $eod = true;                              
            }

            if (!$this->active) {
                $this->LogMsg("~C07~C97#INFO: ------------- TradingCore::Run interrupted active flag -------------- ");
                break;
            }

            $g_queue->process(); 
            $mysqli = $this->CheckDBConnection('');
            if (is_null($mysqli)) {
            $this->LogMsg("~C91#WARN:~C00 database connection lost, can't continue cycle");
            sleep(30);           
            continue;
            }
            $this->CheckDBConnection('remote' ); 
            $this->CheckDBConnection('datafeed' ); 

            if ($this->CheckDBConnection()) {
                $this->Update();
                $this->mysqli_deffered->try_commit();
            }   
            sleep(1);
            $prev_h = $hour;
            $prev_m = $minute;

        
        } 

        $this->Finalize($eod);      
        fclose($this->pid_file_handle);            
        unlink ($this->pid_file_name);
        unlink ($this->wdt_file);
    }

    public function  FindPair($pair_id) {
        return isset($this->pairs_map[$pair_id]) ? $this->pairs_map[$pair_id] : false;
    }

    /**
     * function  CalcVWAP uses 1m candles to calculate VWAP, if is not available for current exchange using default data exchange
     * @param int $pair_id
     * @param string $ts_before
     * @param int $count
     * @return void
     */
    public function CalcVWAP(int $pair_id, string $ts_before, int $count = 24 * 60): float  {        
        $engine = $this->Engine();
        $ti = $engine->TickerInfo($pair_id);        
        if (!is_object($ti)) 
            throw new Exception("TickerInfo not found for pair_id $pair_id");
        if ($ts_before < '2010-01-01 00:00:00')
            throw new Exception("Invalid timestamp $ts_before for pair_id $pair_id");


        $datafeed = $this->mysqli_datafeed;
        $default = $ti->vwap ?? $ti->last_price; // TODO: use tickers
        if (!is_object($datafeed)) {
            $this->LogError('~C31 #WARN:~C00 datafeed DB not available, using current data for pair #%d', $pair_id);
            return $default;
        }
                
        $dedic = strtolower($engine->exchange);
        $dbs = [$datafeed->active_db(), $engine->history_db, $dedic, 'bitfinex', 'binance'];
        $candles = [];
        $reject = [];

        foreach ($dbs as $db_name) {
            $s_table = $engine->TableName("$db_name.ticker_map", true, $datafeed); // ОПАСНОСТЬ: в текущем дизайне таблицы сильно отличаются для БД datafeed и trading         
            if (!$datafeed->table_exists($s_table))  {
                $reject[$db_name] = "!ticker_map";
                continue;            
            }

            $ticker = $datafeed->select_value('ticker', $s_table, "WHERE pair_id = $pair_id");
            if ($ticker === null) {
                $reject[$db_name] = "!ticker #$pair_id";
                continue;          
            }          
            $table = strtolower("$db_name.candles__{$ticker}"); // ожидается что в этой таблице есть АКТУАЛЬНЫЕ минутные свечи        
            if (!$datafeed->table_exists($table))  {
                $reject[$db_name] = "!$table";
                continue;
            }
                
            $strict = "WHERE (ts <= '$ts_before') AND (volume > 0) ORDER BY ts DESC LIMIT $count";
            $candles = $datafeed->select_rows('*', $table, $strict, MYSQLI_OBJECT);
            if (is_array($candles) && count($candles) >= $count) break;
        }

        if (!is_array($candles) || 0 == count($candles)) { 
            $t_table = $engine->TableName('ticker_history');
            // запасный вариант: грубая оценка по истории тикера
            $rows = [];
            $db_name = $datafeed->active_db();
            if ($datafeed->table_exists($t_table)) 
                $rows = $datafeed->select_rows('*', $t_table, "WHERE (pair_id = $pair_id) AND (ts < '$ts_before') ORDER BY ts DESC LIMIT $count", MYSQLI_OBJECT);
            else
                $reject[$db_name] = "!$t_table";

            if (is_array($rows) && count($rows) == $count)  {
                $rows = array_reverse($rows);
                $v_prev = 0;
                $volume = 0;
                $qty = 0;
                foreach ($rows as $row) {
                    if (0 == $v_prev) {
                        $v_prev = $row->daily_vol;
                        continue;
                    }
                    $vchange = $row->daily_vol - $v_prev;
                    $qty += $vchange;
                    $volume += $vchange * $row->last;
                }
                if ($volume > 0)
                    return $ti->RoundPrice($volume / $qty);
                
            }
            $this->LogMsg("~C31#WARN(CalcVWAP):~C00 can't load candles for pair_id %d %s, using default price %s", $pair_id, json_encode($reject), $default);
            return $default;
        }

        $volume = 0;
        $qty = 0;
        foreach ($candles as $cd) {
            $qty += $cd->volume;
            $volume += $cd->volume * ($cd->open + $cd->close) * 0.5; // avg median
        }
        if ($qty > 0)
            return $ti->RoundPrice($volume / $qty);
        else 
            return $default;
    }

    public function  ConfigValue($key, $default = false) {
        return $this->configuration->GetValue($key, $default);
    }   

    public function GetDebugPair(): int {
        $dbg_pair = $this->ConfigValue('debug_pair', 0);
        if (is_string($dbg_pair) && strlen($dbg_pair) >= 3) {
            $dbg_ti = $this->trade_engine->FindTicker($dbg_pair);
            if (is_object($dbg_ti)) 
                $dbg_pair = $dbg_ti->pair_id;           
        }         

        if (!is_numeric($dbg_pair))
            $dbg_pair = 0;
        return intval($dbg_pair);
    }

    public function ProcessTasks() {
        $mysqli = $this->CheckDBConnection();
        $engine = $this->Engine();
        $tasks_table = $engine->TableName('tasks');
        if (!$mysqli->table_exists($tasks_table)) return;        
        $acc_id = $engine->account_id;
        $tasks = $mysqli->select_rows('*', $tasks_table, "WHERE account_id = $acc_id", MYSQLI_OBJECT);
        foreach ($tasks as $task) {
            $this->LogMsg("~C04~C97#TASK:~C00 %s '%s' ", $task->action, $task->param);
            $strict = sprintf("WHERE action = '%s' AND param = '%s' ", $task->action, $task->param);
            $mysqli->delete_from($tasks_table, $strict);
            if ('CANCEL_ORDER' == $task->action) {
                $order_id = intval($task->param);
                $oinfo = $engine->FindOrder($order_id, 0, true);
                if ($oinfo) 
                    $engine->CancelOrder($oinfo);
                else
                    $this->LogError("~C91#ERROR:~C00 can't find order %d for cancel", $order_id); 
            }
        }
    }
    
    public function SignalFeed(): ?SignalFeed {
      return $this->signal_feed;
    }
    
    protected function SyncPositions($pair_id, $rec){
        // position table sync, #slow code possible
        // optimizations: if have pending orders, or elapsed(ts) < 600 ?
        $mysqli = $this->mysqli;
        $engine = $this->Engine();
        $account_id = $engine->account_id;

        $raw_pos = doubleval($rec['value']);

        $cpobj = $this->CurrentPos($pair_id);
        $curr_pos = $cpobj->amount;      

        // NOTE: ticker->trade_coef calculated from contract ratio and trade_coef for exchange

        $scaled_pos = $engine->ScaleQty($pair_id, $raw_pos);

        $tinfo = $engine->TickerInfo($pair_id);

        $batch_price = $tinfo->last_price;
        $batch_btc_price = $engine->get_btc_price();


        if ($tinfo->is_quanto) {
            $ref_price = $this->last_batch_price($pair_id);
            if ($ref_price)
                $batch_price = $ref_price;
            $batch_btc_price = $this->last_batch_btc_price($pair_id);
        }   


        $pos = $engine->NativeAmount ($pair_id, $scaled_pos, $batch_price, $batch_btc_price);


        $table = $engine->TableName('positions');

        $matched = $engine->GetOrdersList('matched')->FindByPair($pair_id);
        $ts_last_matched = 0;
        foreach ($matched as $info)
            $ts_last_matched = max($ts_last_matched, strtotime_ms($info->updated));

        $pending = $engine->GetOrdersList('pending')->FindByPair($pair_id);
        foreach ($pending as $info)
        if ($info->matched > 0)
            $ts_last_matched = max($ts_last_matched, strtotime_ms($info->updated));

        if (0 == $ts_last_matched) {
            $ts_last_matched = time_ms();  // undefined, no orders in system
            if (count($matched))
            $this->LogMsg("#WARN: ts_last_matched = 0, but matched orders = %d". count($matched));
        }

        $ts_curr = date_ms(SQL_TIMESTAMP.'.q', $ts_last_matched);
        $offset = 0;
        if (isset($this->offset_pos[$pair_id]))
            $offset = $this->offset_pos[$pair_id];
        $ts_pos = date_ms(SQL_TIMESTAMP);
        if (isset($rec['ts']))
            $ts_pos = $rec['ts'];
        

        $row = $mysqli->select_row('target,current,ts_current,ts_target', $table, "WHERE (pair_id = $pair_id) AND (account_id = $account_id)", MYSQLI_ASSOC);
        $acc_id = $engine->account_id;
        if ($row) {
            $changed = 0; 
            
            if ($row['target'] != $pos) {
            $query = "UPDATE `$table` SET ts_target = '$ts_pos', target = $pos\n";
            $query .= " WHERE (pair_id = $pair_id) AND (account_id = $acc_id);"; // обновление целевой позиции в таблице
            if ($mysqli->try_query($query))
                $changed += $mysqli->affected_rows;
            else
                $this->LogError("~C91#FAILED:~C00 update position in $table, error: {$mysqli->error} query $query");      
            
            }
            if ($row['current'] != $curr_pos) {          
                $changed += $cpobj->SaveToDB($ts_curr);
            }  
            if ($changed > 0) 
                $this->LogMsg("~C94#SYNC_POS:~C00 pair %s, updated to %s in DB, previous = %s", $tinfo->pair, $tinfo->FormatAmount($curr_pos, Y, Y), json_encode($row));          
        } 
        else {  
            $vals = array('account_id' => $account_id, 'pair_id' => $pair_id, 'ts_target' => $ts_pos, 'target' => $pos, 'ts_current' => $ts_curr, 'current' => $curr_pos, 'offset' => $offset);
            $cl = array_keys($vals);
            $vals = $mysqli->pack_values($cl, $vals);        
            $columns = mysqli_ex::format_columns($cl);
            $query = "INSERT INTO `$table`($columns) VALUES($vals);\n";        
            if ($mysqli->try_query($query))
            $cpobj->SaveToDB($ts_curr);
            else
            $this->LogError("FAILED: insert position into $table, error: {$mysqli->error} query $query");          
        }   

    }
  
    private function last_batch_price(int $pair_id) {
      $engine = $this->Engine();      
      $exch = $engine->exchange;  
      // logic: используются все сигналы последней направленности (покупки или продажи), для высчета средней цены входа
      $price = 0;
      $qty   = 0;
      $rows =  sqli()->select_rows('exec_price,start_pos,target_pos,exec_qty', strtolower($exch.'__batches'), "WHERE (pair_id = $pair_id) AND (exec_price > 0) AND (exec_qty > 0) ORDER BY id DESC LIMIT 50");
      $prv_dir = 0;
      $lines = [];
      foreach ($rows as $row) {
        $pos_dir = signval($row[2] - $row[1]);
        if ($prv_dir != 0 && $pos_dir != $prv_dir) break;        
        $price += $row[0] * $row[3];
        $qty   += $row[3];
        $prv_dir = $pos_dir;
        $lines []= sprintf("+ %f x %f = %f ", $row[0], $row[3], $price);
      }      
      if ($qty > 0)
         $price =  $price / $qty; // average it
      file_put_contents("data/last_batch_price_$pair_id.dbg", " result = $price \n");
      return $price;  
    }

    private function last_batch_btc_price(int $pair_id) {
      $engine = $this->Engine();      
      $exch = $engine->exchange;  
      $table = strtolower($exch.'__batches');      
      $mysqli = $this->mysqli;
      $last = $mysqli->select_value('ts', $table, "WHERE (pair_id = $pair_id) ORDER by id DESC");
      $result = $mysqli->select_value('btc_price', $table, "WHERE (pair_id = $pair_id) AND (btc_price > 0) ORDER BY id DESC");
      if ($result && $result > 0)
          return $result;

      $result = $engine->get_btc_price();  

      if ($last) {        
        $res  = $mysqli->select_row('price', "{$exch}__mixed_orders" , "WHERE (pair_id = 1) AND (price > 0) AND (ts >= '$last') ORDER BY id DESC LIMIT 1");  
        if (is_array($res)) {
           $result = $res[0];
           $mysqli->try_query("UPDATE `$table` SET btc_price = $result WHERE (pair_id = $pair_id) AND (ts >= '$last')"); // patch
        }   
        $this->LogMsg("~C91 #WARN:~C00 cannot retrieve last btc_price from~C92 %s~C00 for #%d, last batch %s, detected = %g ", $table, $pair_id, $last, $result); // typicaly never trade for this pair        
      }    
      return $result;
    }    

    protected function SaveTickerMapping (int $pair_id, string $ticker, string $symbol) {
        $engine = $this->Engine();      
        $table = $engine->TableName('ticker_map');
        $query = "INSERT INTO `$table` (pair_id, ticker, symbol) VALUES ($pair_id, '$ticker', '{$symbol}')\n\t";
        $query .= "ON DUPLICATE KEY UPDATE ticker = '$ticker'; ";
        return $this->mysqli->try_query($query);
    }
    public function SetTargetPositions(array $src) {
        $engine = $this->Engine();
        // $this->LogObj($this->target_pos, '  ', 'target_pos before replace');
        ksort($src);

        foreach ($src as $pair_id => $rec) {
            $tinfo = $engine->TickerInfo($pair_id);
            $ts_chg = $rec['ts'];
            if (!$tinfo) continue;
            $this->SaveTickerMapping($pair_id, $rec['symbol'], $tinfo->symbol);
            if (isset($tinfo->source_pair_id))
            $this->SaveTickerMapping($tinfo->source_pair_id, $rec['symbol'], $tinfo->symbol);
            
            if ($pair_id > 110)
                $this->LogMsg("~C91#WARN:~C00 set target position pair %s (%d), from %s ", $tinfo->pair, $pair_id, json_encode($rec));
    
            if (!isset($rec['src_account'])) {
                $rec['src_account'] = 0; // incomplete data?
                $this->LogMsg("~C91#WARN:~C00 unspecified src_account for pair %s", $tinfo->pair);
            }
            $prev = ['ts' => ''];
            if (isset($this->target_pos[$pair_id]))
                $prev = $this->target_pos[$pair_id];

            $scaled = $engine->ScaleQty($pair_id, $rec['value']);   
            $rec['scaled'] = $scaled;   
            $this->target_pos[$pair_id] = $rec;

            if ($prev['ts'] == $ts_chg) continue;
            $pair = $tinfo->pair;        
            $check = $this->signal_feed->CalcPosition($pair_id);
            $this->LogMsg("~C01~C93#SET_TARGET:~C00 %s target position change detected to %10f (local = %10G) at %s, mult calculated %9G", $pair, $rec['value'], $scaled, $ts_chg, $check);
            $tinfo->native_price = $tinfo->last_price;
        }
        ksort($this->target_pos);
        file_save_json(getcwd()."/data/{$engine->account_id}_target_pos.json", $this->target_pos);
    }

    public function  Engine(): ?TradingEngine {
      return $this->trade_engine instanceof TradingEngine ? $this->trade_engine : null;
    }

    public function CurrentPos(int $pair_id):?ComplexPosition {
      return $this->current_pos[$pair_id] ?? null;
    }
    public function  ImportPositions(array $list): bool {
       $engine = $this->Engine();
       $max_pos = 0; 
       $open_pos = 0;
       $updated = 0;
       $now = time_ms();
       $keys = array_keys($list);
       if (0 == count($keys)) {
         $this->LogError("~C91 #ERROR:~C00 ImportPositions parameter is empty");
         return false; 
       }

       foreach ($list as $pair_id => $pos) {
         if (is_object($pos) && $pos instanceof ComplexPosition) {
            $this->current_pos[$pair_id]  = $pos; // direct import
            if (0 != $pos->amount) $open_pos ++;
            continue;
         }

         if (!isset($this->current_pos[$pair_id]))
            $this->current_pos[$pair_id] = new ComplexPosition($engine, $pair_id);

         $obj = $this->current_pos[$pair_id];
         $diff = $obj->amount - $pos;         
         if ($pos != 0) $open_pos ++;
         $obj->time_chk = $now;
         $pending = $engine->MixOrderList('pending,market_maker', $pair_id);
         if (0 == abs($diff)) {
            if (count($pending) > 10)
                $this->LogMsg("~C94#POSINFO:~C00 position for #%d stil unchanged = %10f", $pair_id,  $pos);
            continue;
         } 
         $updated ++;
         $pair = $this->pairs_map[$pair_id];
         $tinfo = $engine->TickerInfo($pair_id);
         if (!$tinfo) return false;  // what strange...            
         $prev = $obj->amount;       
         $curr = $pos;  
         $prev = $tinfo->FormatAmount($prev, Y, Y);  
         $curr = $tinfo->FormatAmount($curr, Y, Y);
         $prev = sprintf('%5G', $obj->amount);
         $curr = sprintf('%5G', $pos);                    
         $obj->amount = $pos;
         $this->LogMsg("~C03~C96#DBG(ImportPosition):~C00 for~C92 %s #$pair_id~C00, previous =~C94 %f~C00, update =~C94 %f~C00, change =~C94 %f~C00, price =~C94 %f~C00", $pair, $prev, $curr, $diff, $obj->chg_price);         
         $obj->time_chg = $now;         
         $max_pos = max($max_pos, abs($pos));
         $obj->SaveToDB();        

       }

       if (0 == $open_pos && $this->opened_positions > 2) {
         $this->LogMsg("~C91#WARN:~C00 import open positions result - all {$this->opened_positions} closed, imported %s", print_r($list, true));
         $this->send_event('WARN', 'All positions retrived as closed, enabled trading pause', $this->opened_positions);
         $this->trade_skip = 5; // pause for intercepts
       }
       
       $this->opened_positions = $open_pos;  // change detected state
       $closed = 0;
       foreach ($this->pairs_map as $pair_id => $pair)
         if (!isset($list[$pair_id]) && isset($this->current_pos[$pair_id])) {
            $obj = $this->current_pos[$pair_id];
            if ($obj->amount == 0) continue;
            $this->LogMsg("#DBG: detected position was closed for~C93 %s #$pair_id~C00, current =~C94 %f~C00, last_change = %s", $pair, $obj->amount, date('Y-m-d H:i:s', $obj->time_chg / 1000));
            $obj->amount = 0;            
            // $this->current_pos[$pair_id] = $obj; // override
            $closed ++;
       }
       return $updated > 0;
       // if ($closed > 0)  $this->LogObj($this->current_pos, '  ',  'current_pos dump:');
    }

    protected function SetActiveRole(bool $master, $status = 'capture'): bool {
      global $hostname;
      $acc_id = $this->Engine()->account_id;
      $exch = $this->Engine()->exchange;      
      $pid = getmypid();      
      $now = date(SQL_TIMESTAMP);
      $uptime = (time_ms() - $this->start_ts) / 1000; // seconds
      $uptime = floor ($uptime);
      $table = 'bot__redudancy';
      $role = $master ? 'master' : 'rescuer';
      if ($role != $this->active_role) {
         $this->send_event('WARN', sprintf('Instance 0x%x for %s account %d changed role to %s, status = %s ', $pid, $exch, $acc_id, $role, $status));                  
         if ($master)
            $this->OnSetMasterRole(); 
      }
      $strict = "(exchange = '{$exch}') AND (account_id = $acc_id)";
     

      if (!$master) { // no actions in DB is need
        $this->active_role = $role;          
        $rdw = $pid * 256;
        sqli()->try_query("UPDATE `$table`\n SET reserve_status = $rdw\n WHERE $strict AND (reserve_status < 1024) ");
        return true; 
      }
      $mysqli = $this->mysqli;

      $row = ['?', '?'];
      $repa = $this->CheckReplication();
      $remote = $this->CheckDBConnection('remote');        
      

      $minute_ago  = date(SQL_TIMESTAMP, time() - 60);
      $priority = $this->mysqli->select_value('priority', 'config__hosts', "WHERE host = '$hostname' "); // get self priority            
      $concurent = $mysqli->select_value('host', 'config__hosts', "WHERE (host != '$hostname') AND (priority > $priority) AND (last_alive > '$minute_ago')"); // get concurent host            
      $r_status = $mysqli->select_value('reserve_status', $table, "WHERE (account_id = $acc_id) AND (exchange = '$exch')");  // reserve_status == pid of rescuer, typically shifted for 1+ digits
      if (!is_null($concurent) && $r_status > 0x100) {
         $this->LogMsg("~C31#WARN:~C00  concurent host %s with reserve_status 0x%x have higher priority and seems alive, skip fixing master role yet", $concurent, $r_status);
         return false;         
      }

      $query = "INSERT INTO `$table`(exchange, account_id, master_host, master_pid, ts_alive, uptime, `status`, reserve_status)\n 
                VALUES('$exch', $acc_id, '$hostname', $pid, '$now', $uptime, '$status', 0)\n
                ON DUPLICATE KEY UPDATE master_host = '$hostname', master_pid = $pid, ts_alive = '$now', uptime = $uptime, `status` = '$status', reserve_status = reserve_status / 16; "; // refresh mains
      file_put_contents('data/alive.sql', "$query # TZ = ".date_default_timezone_get());
      if (try_query($query) && sqli()->affected_rows > 0) {         
        // verify, due replication may revert changes
        sleep(1);
        $row = sqli()->select_row('master_host,master_pid', 'bot__redudancy', "WHERE $strict "); 
        if ($row[0] == $hostname && $row[1] == $pid) {
           $this->active_role = $role;  
           if (!$repa && is_object($remote))
                $remote->try_query($query); // update in remote DB
           return true;           
        }          
        
      } 

      $this->LogError("~C91 #FAILED:~C00 capture unsuccessul, current master node {$row[1]} @ {$row[0]}  ");   
      return false; 
    }

    protected function OnSetMasterRole()  {
        $engine = $this->Engine();   
        $engine->OnSetMasterRole();
    }

    protected function CheckRedudancy(): bool   {
      global $hostname, $gmt_tz;
      $acc_id = $this->Engine()->account_id;
      $exch = $this->Engine()->exchange;    
      $pid = getmypid();       
      $table = 'bot__redudancy';

      if (count($this->auth_errors) > 0) {
         $this->LogError("~C91#ERROR~C00: redudancy role absent, due auth errors count > 0 ");
         return false;
      }
      
      // select 6 fields
      $row = sqli()->select_row('master_host,master_pid,ts_alive,errors,`status`,reserve_status', $table, "WHERE (exchange = '{$exch}') AND (account_id = $acc_id) ");
      if (!$row) 
        return $this->SetActiveRole(true, 'registered');  // first bot in array

      list($mhost, $mpid, $mts, $errors, $last_st, $this->reserve_status) = $row;

      $self_alive = ( time_ms() - $this->start_ts ) / 1000.0;

      if ($mhost != $hostname || $mpid != $pid) {  // master is another instance, lets check them
         $this->active_role = 'rescuer';         
         $dt = new DateTime($mts);
         $now = new DateTime('now');
         $alive = $dt->getTimestamp();
         $elps  = $now->getTimestamp() - $alive;
         if ($elps < 120 && $mhost != $hostname) {  // check master is here, if host different
            $this->SetActiveRole(false, 'waiting');
            $this->LogMsg("~C93 #OK:~C00 master instance 0x%x from %s, errors = $errors, last active time '%s' (+$elps) status = '%s', seems its good...", $mpid, $mhost, $mts, $last_st);
            return false;
         }          
          if ($hostname != $mhost)  {            
            $this->trade_skip = $this->CheckReplication() ? 1 : 3;
            $this->LogMsg("~C91 #WARN:~C00 due master instance 0%x from %s, errors = $errors, last active time '%s' (+$elps) status = '%s', switching self $pid to master role. Trade skip %d", 
                      $mpid, $mhost, $mts, $last_st, $this->trade_skip);            
          } 
          else {            
            $this->trade_skip = min($this->trade_skip, 1);
            $this->LogMsg("~C97 #RESTORE:~C00 due master instance 0%x, errors = $errors, last active time '%s' (+$elps) status = '%s', switching self $pid to master role. Trade skip %d", $mpid, $mts, $last_st, $this->trade_skip);
            if (str_in($this->impl_name, 'deribit' ) ||
                str_in($this->impl_name, 'binance' )  ) $this->trade_skip = 0;
          } 
         return $this->SetActiveRole($this->active);
      }

      if ($self_alive < 30) return false; // warming-up
      // Im already master instance, need update details 
      return $this->SetActiveRole($this->active, 'working');
    }

    public function CheckReplication(): bool {
        $mysqli = $this->mysqli;
        $table = 'bot__redudancy';
        $engine = $this->Engine();
        $acc_id = $engine->account_id;
        $exch   = $engine->exchange;            
        $remote = $this->CheckDBConnection('remote');
        if (is_null($remote)) return false;        
        // при работающей репликации расхождений быть не должно
        $row = $mysqli->select_row('master_pid,ts_alive', $table, "WHERE (exchange = '{$exch}') AND (account_id = $acc_id) ", MYSQLI_ASSOC);
        $ref = $remote->select_row('master_pid,ts_alive', $table, "WHERE (exchange = '{$exch}') AND (account_id = $acc_id) ", MYSQLI_ASSOC);
        if (!is_array($ref)) return false;
        foreach ($row as $key => $value)
          if ($ref[$key] != $value) 
              return false; 

        $hosts = $mysqli->select_rows('*', 'config__hosts', '', MYSQLI_ASSOC);
        if (is_array($hosts))
            foreach ($hosts as $row)  {
              $id = $row['id'];
              if ($id == $engine->host_id) continue;
              $elps = time() - strtotime($row['last_alive']);
              if ($elps < 120) return true; // есть другие активные хосты
            }              
        return false;
    }

    public function  Update() {
      global $g_queue;
      set_time_limit(60);
      $now = new DateTime();
      $elps = time() - $this->updated_time;
      $engine = $this->Engine();

      $hour   = date('H') * 1;
      $minute = date('i') * 1;
      if (0 == $minute)
         $this->auth_errors = []; // cleanup
      $uptime = time_ms() - $this->start_ts;  // seconds
      $diff = $now->diff(dt_from_ts($this->start_ts / 1000));

      $this->uptime = $diff->format('%h:%I:%S'); // formated uptime
      $startup = (0 == $this->updates);

      if ($this->update_period < 10)
          throw new Exception("Invalid update period value");
      if (!$g_queue->sender_active())
           $g_queue->start_sender();

      $master = 'master' == $this->active_role;     
      if ($elps > $this->update_period || $startup) {
        // 

        $this->LogMsg('-------------------- uptime [%s] elps %2d > %d requesting target positions from feed ----------------------', date_ms('H:i:s.q', $uptime, true), $elps, $this->update_period);

        $after_ts = false;
        $do_update =  true;
        while ($do_update)// once cycle
        try {
            $do_update = false; // may restart
            //if ($this->updated_time > 0)
            //  $after_ts = date(SQL_TIMESTAMP, $this->updated_time - 3600); // request changes for last hour
            $this->updated_time = time();          
            $this->configuration->Load($engine->account_id);  // update runtime config

            $this->trade_enabled = $this->ConfigValue('trade_enabled', false);  // internals can drop this flag!

            $status = $engine->PlatformStatus();
            if ($status <= 0 || $status > 100) {
                $this->LogError("~C91#WARN:~C00 platform status %d, trading disabled", $status);
                sleep(5);
                return; // прерывание в этом месте важно, т.к. хост перестанет обновлять показания мастера
            }
            $load_t = $engine->LoadTickers();          
            if ($load_t)
                $engine->SaveTickersToDB();
            else {
                $this->LogError("~C91#WARN:~C00 tickers load failed, trading loop skip");
                return;   
            }                
            
            $master = $this->CheckRedudancy();
            if (!$master) 
                $engine->ProcessRescuer();       // load DB data into memory    

            if (!$master) {
                $repa = $this->CheckReplication() ? '~C97 ACTIVE~C00' : '~C91 FAILED~C00';
                $this->LogMsg("~C95#DBG:~C00 current redudance role is %s, replication status %s, breaking ",$this->active_role, $repa);
                sleep(10);
                break; // лучше не конфликтовать лишний раз за использование общего NONCE
            }     

            $load_p = $engine->LoadPositions();   // depended from ticker info          

            $load_o = $engine->LoadOrders($startup);
            
            $load_lt = $engine->LoadTrades();         

            $engine->CalcPositions();

            if ($startup && $load_t > 0) {
                $engine->RecoveryBatches();
            }

            $engine->Update(); // processing batches 

            $this->CheckSaveFunds();

            $this->updates ++;

            if (false !== $load_p && intval($load_o) >= 0 && $load_t > 0 && $this->active) {
                $this->signal_feed->LoadSignals();
                // $this->position_feed->LoadPositions($after_ts);             

                if (!$startup && $this->TradingAllowed() && ($hour < 23 || $minute <= 58) && $load_p >= 0) { // no trades before script reload 
                $engine->ConfigureMM(); // загрузить настройки маркет-мейкера
                if (0 == $this->trade_skip)               
                    $engine->ProcessMM();   // обработать заявки маркет-мейкера
                    $this->Trade(); // <<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<               
                }   
                $kc = 'HR'.date('H'); // looks as HR05
                if (!isset($this->reports_map[$kc]) && 0 == $this->trade_skip) {
                    $this->LogObj($this->reports_map, " $kc", "Reports map adding key");
                    $this->SendReports($kc);              
                    if ( $minute <= 1) {
                        $engine->ne_assets = [];
                        $this->notified = []; // reset notifications               
                        if ($this->logger && $this->logger->file_size() >= 10485760) // due large file produced, close and open new
                            $this->logger->close('heavy size'); 
                        if ($this->total_exceptions > 10)
                            $this->Shutdown("to many exceptions {$this->total_exceptions} ...");    
                    }    
                }

            }
            else
                $this->LogError("~C91#FAIL:~C00 trading disabled, due load status = %d, %d, %d, active = %d", $load_p, $load_o, $load_t, $this->active);

        }
        catch (Throwable $e) {
            $this->unhandled_exceptions ++;        
            $cname = get_class($this);
            $impl = $this->impl_name;

            $this->LogMsg("~C91#EXCEPTION:~C00 cathed in %s->Update, %s:%d  message: [%s], code: %d", $cname, $e->getFile(), $e->getLine(), $e->getMessage(), $e->getCode());
            $this->LogMsg("~C93stack traceback:~C97".$e->getTraceAsString()."~C00");          
            $this->LogMsg("~C00 unandled exceptions count %d", $this->unhandled_exceptions);                  
            $location = sprintf("%s:%d", $e->getFile(), $e->getLine());
            $msg = sprintf("#EXCEPTION($impl/$cname.Update#%d): %s at %s", $this->unhandled_exceptions, $e->getMessage(), $location);
            $fname = 'logs/traceback.log';
            file_put_contents($fname, $e->getTraceAsString());
            $this->send_media('ALERT', $msg, $fname);
            if ($this->unhandled_exceptions >= 5) {
                $this->Shutdown("to many unandled exceptions {$this->unhandled_exceptions} ...");              
            }
        } // while($do_update) try-catch

        $fname = shell_exec('ls *debug.inc.php | head -n 1'); 
        $fname = trim($fname);

        if ($this->active && str_in($fname, 'debug.inc.php') && 
                file_exists($fname) && $uptime > 240 && $minute < 58) 
            try {
                include($fname);
            }
            catch(\Error $E) {
              if ($E instanceof Throwable)
                  $this->LogException($E, "RTM live code failed, see error log for exception details");           
              else 
                  $this->LogError("~C91#ERROR:~C00 RTM live code failed, with Error %s", var_export($E, true));                 
            }

      } 
      else { // elps <= update_period
        if ($master && $this->trade_enabled && 0 == $this->trade_skip) {
           $engine->TrackOrders();
           $this->ProcessTasks();           
           sleep(10);    
        }      
        //         
      }
    }


    protected function SavePID(bool $force = false) {
        $fpid_name = $this->pid_file_name;       
        if (file_exists($fpid_name) &&
            is_resource($this->pid_file_handle) && !$force) return;          
        
        if (is_resource($this->pid_file_handle))    
            fclose($this->pid_file_handle); // unlock if my

        $fh_pid = fopen($fpid_name, 'w');
        if (!$fh_pid)
            throw new Exception("#FATAL: cannot created $fpid_name\n");        
            
        if (flock($fh_pid, LOCK_EX))
            fwrite($fh_pid, getmypid());
        else  {
            $this->LogError("#FATAL: cannot lock $fpid_name\n");  
            die(-1);
        }                 
        $this->pid_file_handle = $fh_pid;    
    }

    public function Shutdown(string $reason ) {
      $this->LogMsg("~C91#WARN:~C00 shutdown signal received, reason: $reason");
      $this->active = false;
      $this->aborted = true;      
    }
  
  };


?>
