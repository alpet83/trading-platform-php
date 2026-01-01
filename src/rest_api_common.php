<?php
    include_once('../lib/common.php');
    include_once('../lib/db_tools.php');
    require_once('trading_core.php');

    function parse_headers($src) {
        $lines = explode("\n", $src);
        $result = array();
        foreach ($lines as $line)

        if (strpos($line, ':') !== false) {
        list($key, $value) = explode(':', $line);
        $result[$key] = trim($value);
        }

        return $result;
    }

    function dump_r(mixed $data, int $limit = 2048): string {
            $dump = print_r($data, true);
            return substr($dump, 0, $limit);
    }

    function array_find_object(array &$source, string $key, $value) {
        if ($source)
            foreach ($source as $obj)
                if (isset($obj->$key) && $obj->$key === $value)
                    return $obj;

        return null;
    }

    class RestAPIEngine extends TradingEngine {

        protected   $apiKey        = null;
        protected   $secretKey     = null; // encoded value!

        // endpoints
        protected   $public_api    = 'http://localhost/';
        protected   $private_api   = 'http://localhost/';
        protected   $btc_price     = 0;
        protected   $btc_history   = []; // map of date => price
        protected   $last_request  = '';

        protected   $last_nonce = 0;
        protected   $prev_nonce = 0;
        public      $curl_last_error = '';
        public      $curl_response = '';

        public      $rate_limit = 0;
        public      $rate_remain = 0;
        public      $rate_reset = 0;


        public static function CurlInstance() {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 120);
            curl_setopt($ch, CURLOPT_TIMEOUT, 59);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; Easy PHP client; '.php_uname('s').'; PHP/'.phpversion().')');
            return $ch;
        }

        protected function MakeNonce(): int {
            // using replicated DB variable for prevent nonce fails
            $core = $this->TradeCore();


            $ref = $core->configuration->LoadValue('last_nonce');
            $ref = intval($ref);
            if ($ref > $this->last_nonce)
                $this->last_nonce = $ref;

            $this->prev_nonce = $this->last_nonce;  
            $nonce = time_ms() * 1000;
            if ($nonce <= $this->last_nonce)
                $nonce = $this->last_nonce + 1;
            else
                $this->last_nonce = $nonce;
                
            $core->configuration->SaveValue('last_nonce', sprintf('%luL', $nonce)); 
            return $nonce;      
        }

        protected function SwitchAPIServer(string $current) {
            return false;
        }

        protected function EncodeParams(&$params, &$headers, $method) {
            if (is_array($params))
                $params = http_build_query($params, '', '&');

            if ('POST' === $method) {
                $headers []= 'Content-Type: application/x-www-form-urlencoded';
            }
        }
        
        protected function ProcessRateLimit () {
            global $curl_resp_header; 
            if (strpos($curl_resp_header, 'ratelimit') === false) return;
            $core = $this->TradeCore();            
            $lines = explode("\n", $curl_resp_header);
            $rhdrs = [];      

            foreach ($lines as $line)
                if (false !== strpos($line, 'ratelimit') && false !== strpos($line, ':')) {
                list($k, $v) = explode(':', $line);
                $rhdrs [$k]= trim($v);
                }
        
            if (is_array($rhdrs) && isset($rhdrs['x-ratelimit-limit']) ) {
                $this->rate_limit = $rhdrs['x-ratelimit-limit'] * 1;   
                $this->rate_remain = $rhdrs['x-ratelimit-remaining'] * 1;  
                $this->rate_reset = $rhdrs['x-ratelimit-reset'] * 1;             
                if ($this->rate_remain < 10) {
                    $this->LogMsg("~C91#WARN:~C00 rate limit %d was reached, pause will be %d", $this->rate_limit, $this->rate_reset - time());
                    while (time() < $this->rate_reset) usleep(100000);
                    file_put_contents('data/last_curl_resp_header.txt', $curl_resp_header);
                }    
            } elseif (strlen($curl_resp_header) > 0) {
                $this->LogMsg("~C91#WARN:~C00 rate limit headers not found in response %s", $curl_resp_header);
                file_put_contents('data/wrong_curl_resp_header.txt', $curl_resp_header);
            }        
        }


        protected function RequestPublicAPI(string $rqs, $params, string $method = 'GET', $headers = false, $endpoint = false) {
        
            $this->curl_response = false;

            // first slash occuriences must be removed

            $core = $this->TradeCore();

            $ch = $this->CurlInstance();
            // TODO: autoselect mirror if problems...
            if (!$endpoint)
                $endpoint = $this->public_api;

            // remove excess slashes
            while ('/' === substr($endpoint, -1) && '/' === $rqs[0])
                $rqs = substr($rqs, 1);


            $url = $endpoint.$rqs;
            if (!$headers)
                $headers = array();

            $this->EncodeParams($params, $headers, $method);

            if ('POST' === $method) {
                $post_data = $params;
                // $headers []= 'Content-Length: '.strlen($post_data);


                // $this->LogMsg("Trying method POST with data: [$post_data]");
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
            }
            else
                $url .= '?'.$params;

            // Delete Method
            if ('DELETE' === $method)  {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                $this->LogMsg("#WARN: Curl assigned request method %s", $method);
            }

            // PUT Method
            if ('PUT' === $method)
                curl_setopt($ch, CURLOPT_PUT, true);

            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_URL, $url);
            $this->last_request = $url;

            $result = curl_exec($ch);

            $header = false;
            $this->ProcessRateLimit();

            if ($result === false) {
                $error = curl_error($ch);
                $this->curl_last_error = $error;
                $rcode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $this->curl_response = array('code' => $rcode);
                if (strpos($error, 'Connection timed out') !== false)
                    $this->SwitchAPIServer($endpoint);
            }
            else {
                $this->curl_last_error = 'OK';
                $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $header = substr($result, 0, $header_size);
                $result = substr($result, $header_size);
                $rcode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $this->curl_response = array('headers' => parse_headers($header), 'body' => $result, 'code' => $rcode);
            }



            curl_close($ch);
            return $result;
        }

        protected function set_btc_price(float $price) {
            $this->btc_price = $price;
            $rts = gmdate('Y-m-d H:i');
            $this->btc_history[$rts] = $price;
        }

        public function get_btc_price(string $ts = 'now'): float {
            if (0 == $this->btc_price) { 
                $bp_file = '/tmp/btc_price.last';        
                $age = time() - filemtime($bp_file);
                $this->btc_price = file_read_int($bp_file) or 100000;
                if ($age > 900)
                    $this->TradeCore()->LogError("~C91#WARN:~C00 used old cached btc_price = %.0f, elps = %.1f hours", $this->btc_price, $age / 3600);
            }        
            if ('now' == $ts) {
                file_put_contents('/tmp/btc_price.last', round($this->btc_price)); // frequently update, storing on tmpfs
                return $this->btc_price;
            }

            $rts = strtotime($ts);
            $rts = gmdate('Y-m-d H:i', $rts);
            if (isset($this->btc_history[$rts])) // кэш суточных данных
                return $this->btc_history[$rts];

            $table = $this->TableName('ticker_history');
            $datafeed =  $this->sqli('datafeed');

            if ($datafeed && false === strpos($table, '#ERROR')) {
                $val = $datafeed->select_value('last', $table, "WHERE ts >= '$ts' ORDER BY ts DESC");
                if (!is_null($val)) 
                    return  $this->btc_history[$rts] = floatval($val);
            }
            $table = $this->TableName('candles__btcusd'); // для текущей биржи могут свечи, а могут и не быть
            if (str_in($table, 'ERROR'))
                $table = 'candles__btcusd'; // default
            if ($datafeed && $datafeed->table_exists($table)) {
                $val = $datafeed->select_value('close', $table, "WHERE ts >= '$ts' ORDER BY ts DESC");
                if (!is_null($val)) 
                    return  $this->btc_history[$rts] = floatval($val);
            }

            $this->TradeCore()->LogError("~C91#WARN:~C00 btc price not found for ts = %s, using table %s", $ts, $table);  
            return $this->btc_price;      
        }

    };

?>