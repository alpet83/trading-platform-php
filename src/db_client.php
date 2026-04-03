<?php
    require_once 'lib/db_tools.php';


    trait DBClientApp {
        public     $mysqli = null;

        public     $mysqli_datafeed = null;
        public     $mysqli_remote = null;     

        public     $mysqli_deffered = null; // class mysqli_buffer, for deffered queries

        protected  $db_local_host = 'db-local.lan'; // typically must be specified in /etc/hosts, due configuration loaded from DB
        protected  $db_remote_host = 'db-remote.lan';

        protected function RedundancyMode(): string {
            $mode = strtolower(trim((string)(getenv('REDUNDANCY_MODE') ?: 'paired')));
            return strlen($mode) ? $mode : 'paired';
        }

        protected function IsSingleRedundancyMode(): bool {
            return in_array($this->RedundancyMode(), ['single', 'standalone', 'none', 'off'], true);
        }

        protected function IsShutdownInProgress(): bool {
            if (property_exists($this, 'aborted') && boolval($this->aborted))
                return true;
            if (property_exists($this, 'active') && !boolval($this->active))
                return true;
            return false;
        }

        public function CheckDBConnection(string $kind = '', bool $force = false): ?mysqli_ex {
            global $db_servers;
            $key = 'mysqli';
            if (strlen($kind) > 0)
                $key .= "_$kind";

            if (!property_exists($this ,$key)) {
                $this->LogError("~C91#ERROR:~C00 database connection with key %s not allowed", $key);
                return null;
            }         

            if ($this->IsShutdownInProgress()) {
                $this->$key = null;
                return null;
            }

            $mysqli = $this->$key;        
            $minute = date('i') * 1;       
            
            while ($this->conn_attempts < 3) {
                if ($this->IsShutdownInProgress()) {
                    $this->$key = null;
                    break;
                }
                if (is_object($mysqli)) {
                    try {
                        if ($mysqli->ping())
                            return $mysqli;
                    } catch (Throwable $E) {
                        $mysqli = null;          // must be before LogError to prevent recursive ping via sqli() → CheckDBConnection
                        $this->$key = null;
                        $this->LogError("~C91 #WARN:~C00 database connection %s ping failed: %s", $kind, $E->getMessage());
                    }
                }
                if ('' == $kind)
                    $this->conn_attempts ++;

                if ('remote' == $kind && $this->IsSingleRedundancyMode()) {
                    $this->$key = null;
                    return null;
                }

                $local_host = getenv('DB_LOCAL_HOST') ?: $this->db_local_host;
                $remote_host = getenv('DB_REMOTE_HOST') ?: $this->db_remote_host;
                $host = 'remote' == $kind ? $remote_host : $local_host; 
                $error = 'not init yet';
                if (is_object($mysqli)) {
                    try {
                        $error = strval($mysqli->error ?: 'not init yet');
                    } catch (Throwable $E) {
                        $error = 'connection object invalid';
                    }
                }
                $this->LogMsg("~C91 #WARN:~C00 database connection %s to host %s lost (%s), %d attempt reconnect...", $kind, $host, $error, $this->conn_attempts); 
                if (is_object($mysqli)) {
                    try {
                        $mysqli->close();
                    } catch (Throwable $E) {
                        // Ignore close failures; a new handle will be created below.
                    }
                }

                $db_servers = [$host];        
                $db = 'trading';
                if ('datafeed' == $kind) 
                    $db = strtolower($this->Engine()->exchange); // now used dedicated database for historical data

                $this->$key = null; // reset connection 

                if ('remote' == $kind && is_null($mysqli) && 0 !== $minute % 5 && !$force) break;  // if remote failed, don't try frequently
                $mysqli = init_remote_db($db);
                if (is_object($mysqli)) {          
                    $this->$key = $mysqli;                
                    $this->conn_attempts = 0;
                    $mysqli->try_query("SET time_zone='+0:00'"); // GMT/UTC stricted
                }
                else
                sleep(30);        
                if ('mysqli' != $key) break; // only once try for datafeed and remote
            }            
            return null;
        }

    }