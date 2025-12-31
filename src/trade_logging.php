<?php
    require_once 'lib/common.php';
    require_once 'lib/db_tools.php';
    require_once 'lib/esctext.php';
    require_once 'lib/basic_logger.php';
    /**
     * Трейт для логгирования, который включается в торговое ядро (TradingCore)
     */


    trait TradeLogging {

        public     $auto_color_log = true;
        protected  $logger = null;
        protected  $order_logger = null;
        protected  $error_logger = null;    
        protected  $engine_logger = null;

        protected  $incidents = []; // [hash] = Incident
        protected  $mm_logger = null;
        protected  $unhandled_exceptions = 0;
        protected  $total_exceptions = 0;


        public function ErrorsCount()   {
            return $this->error_logger->lines;  
        }

        protected function InitLogging() {
            $this->logger        = new BasicLogger($this->impl_name, 'core');
            $this->engine_logger = new BasicLogger($this->impl_name, 'engine', null);                  
            $this->error_logger  = new BasicLogger($this->impl_name, 'errors');      
            $this->order_logger  = new BasicLogger($this->impl_name, 'order', null);
            $this->mm_logger     = new BasicLogger($this->impl_name, 'market_maker', null);                  
            $this->error_logger->std_out = STDERR;
        }

        public function  LogError() {
            global $color_scheme;
            $args = func_get_args();
            $this->LogWrite($this->error_logger, $args);
            $this->LogWrite($this->logger, $args); // duplicate message
            log_error(format_color(...$args));
            $color_scheme = 'none';
            $msg = format_color(...$args);
            $color_scheme = 'cli';      
            $this->SaveLastError($msg, "{$this->impl_name}->LogError");
            $fmt = array_shift($args);
            $trace = format_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 'basic', 7, 1);
            $irec = new Incident($fmt, $trace);
            $key = $irec->key();
            if (isset($this->incidents[$key])) {
                $irec = $this->incidents[$key];
                $irec->count ++;
            }
            else
                $this->incidents[$key] = $irec;
            return $irec;
        }

        public function LogException() {
            $args = func_get_args();
            $E = array_shift($args); // object must be first;
            if (is_object($E)) {
                $msg = array_shift($args);      
                $loc = $E->getFile().':'.$E->getLine();
                $irec = $this->LogError("#EXCEPTION: $msg at [$loc]: ".$E->getMessage(), ...$args); 
                $count = $irec->count;
                $this->LogMsg("~C97~C31#EXCEPTION($count): ~C00 %s from %s ", $E->getMessage(), $E->getTraceAsString());         
                $this->total_exceptions ++;
                $error = sprintf($msg, ...$args); 
                $evt = sprintf("Exception %s #%d: %s", $E->getMessage(), $count, $error);         
                $this->RegEvent('EXCEPTION', $evt);
                $fname = 'logs/traceback.log';
                file_put_contents($fname, $E->getTraceAsString());
                $this->send_media('ALERT', $evt, $fname);
            }   
        }

        public function LogOrder() {
            $this->LogWrite($this->order_logger, func_get_args());
        }
    

        public function  LogObj(mixed $obj, string $tab, string $descr = '', mixed $use_log = false) {
            $dump = printRLevel($obj, 2);      
            $dump = explode("\n", $dump);
            for ($i = 0; $i < count($dump); $i++)
                $dump[$i] = $tab.$dump[$i];
            $msg = $descr.implode("\n", $dump);
            if ($this->auto_color_log)
                $msg = colorize_msg($msg);
            else
                $msg = format_uncolor('%s', $msg);

            if ($use_log && isset($this->$use_log))
                $this->$use_log->log($msg);
            else
                $this->logger->log($msg);
        }

        private function LogWrite($logger, $args) {
            $msg = '';
            if ($this->auto_color_log)
                $msg = format_color(...$args);
            else
                $msg = format_uncolor(...$args);

            $logger->log($msg);
        }

        public function  LogMsg() {
            $this->LogWrite($this->logger, func_get_args());
        }

        public function LogEngine() {    
            $this->LogWrite($this->engine_logger, func_get_args());       
        }

        public function LogMM() {
            $args = func_get_args();      
            if (isset($args[0]) && str_in($args[0], '#ERROR'))
                $this->LogWrite($this->error_logger, $args); // duplicate      
            $this->LogWrite($this->mm_logger, $args);       
        }
        // can print message once per hour
        public function NotifyOnce() {        
            $args = func_get_args();
            $id = array_shift($args);
            if (!isset($core->nofified[$id])) {
                $this->LogMsg(...$args);        
            }   
            $this->notified [$id] = 1;
        }

        public function RegEvent(string $event, string $message) { // register internal event in DB
            $engine = $this->Engine();
            $table = $engine->TableName('events');
            $acc_id = $engine->account_id;
            $host_id = $engine->host_id;
            $message = substr($message, 0, 63);
            $query = "INSERT INTO `$table` (account_id, host_id, `event`, `message`) VALUES \n";
            $query .= "($acc_id, $host_id, '$event', '$message')";
            $mysqli = $this->CheckDBConnection();
            if ($mysqli)
                $mysqli->try_query($query);
        }

        public function SaveLastError(string $error, string $source = '', int $code = 0, mixed $backtrace = false) {     
            $engine = $this->Engine();
            if (!is_object($engine)) return;      
            $table = $engine->TableName('last_errors');
            $acc_id = $engine->account_id;
            $msg = $this->mysqli->real_escape_string($error);      
            if (false === $backtrace)
                $backtrace = format_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 'basic', 5, 2);
            $backtrace = $this->mysqli->real_escape_string($backtrace);
            // save only limited part of error message and trace
            $msg = substr($msg, 0, 4095);
            $backtrace = substr($backtrace, 0, 1023);      
            $query = "INSERT IGNORE INTO  `$table`(account_id, host_id, `message`, `source`, `code`, `backtrace`) ";
            $query .= "VALUES($acc_id, {$engine->host_id}, '$msg', '$source', $code, '$backtrace')"; // silent add try      
            if (is_object($this->mysqli_deffered))
                $this->mysqli_deffered->push_query($query, N, N, N);
            elseif (is_object($this->mysqli))
                $this->mysqli->query($query);  
        }

        public function  SetIndent(string $indent, string $logger_name = 'logger' ) {
            if (isset($this->$logger_name) && is_object($this->$logger_name) &&
                get_class($this->$logger_name) == 'BasicLogger')
                $this->$logger_name->indent = $indent;
            else
                $this->LogError("~C91#ERROR:~C00 SetIndent: invalid logger name %s: %s", $logger_name, var_export($this->$logger_name, true));
        }

    }