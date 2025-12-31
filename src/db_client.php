<?php
    require_once 'lib/db_tools.php';


    trait DBClientApp {
        public     $mysqli = null;

        public     $mysqli_datafeed = null;
        public     $mysqli_remote = null;     

        public     $mysqli_deffered = null; // class mysqli_buffer, for deffered queries

        protected  $db_local_host = 'db-local.lan'; // typically must be specified in /etc/hosts, due configuration loaded from DB
        protected  $db_remote_host = 'db-remote.lan';

        public function CheckDBConnection(string $kind = '', bool $force = false): ?mysqli_ex {
            global $db_servers;
            $key = 'mysqli';
            if (strlen($kind) > 0)
                $key .= "_$kind";

            if (!property_exists($this ,$key)) {
                $this->LogError("~C91#ERROR:~C00 database connection with key %s not allowed", $key);
                return null;
            }         

            $mysqli = $this->$key;        
            $minute = date('i') * 1;       
            
            while ($this->conn_attempts < 3) {
                if (is_object($mysqli) && $mysqli->ping()) 
                    return $mysqli;           
                if ('' == $kind)
                    $this->conn_attempts ++;

                $host = 'remote' == $kind ? $this->db_remote_host : $this->db_local_host; 
                $error = $mysqli ? $mysqli->error : 'not init yet';
                $this->LogMsg("~C91 #WARN:~C00 database connection %s to host %s lost (%s), %d attempt reconnect...", $kind, $host, $error, $this->conn_attempts); 
                if (is_object($mysqli))
                    $mysqli->close();      

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