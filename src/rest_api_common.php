<?php
    include_once(__DIR__.'/lib/common.php');
    include_once(__DIR__.'/lib/db_tools.php');
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

    abstract class RestAPIEngine extends TradingEngine {

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
        public      ?bool $api_key_trade_allowed = null;
        public      int $api_key_rights_checked_at = 0;


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

        protected function InitializeAPIKey(array $override = []): array {
            $exchange_name = trim(strval($override['exchange_name'] ?? $this->exchange ?? 'Exchange'));
            $exchange_slug = preg_replace('/[^a-z0-9]+/', '', strtolower($exchange_name));
            $env_prefix = strtoupper(trim(strval($override['env_prefix'] ?? $exchange_slug)));

            $env_key_name = strval($override['env_key_name'] ?? ($env_prefix . '_API_KEY'));
            $env_secret_name = strval($override['env_secret_name'] ?? ($env_prefix . '_API_SECRET'));
            $file_key_path = strval($override['file_key_path'] ?? ('.' . $exchange_slug . '.api_key'));
            $file_secret_path = strval($override['file_secret_path'] ?? ('.' . $exchange_slug . '.key'));
            $split_secret_lines = boolval($override['split_secret_lines'] ?? true);

            $env = getenv();
            $key = trim((string)($env[$env_key_name] ?? ''));
            $secret_raw = trim((string)($env[$env_secret_name] ?? ''));
            $source = 'env';

            // NOTE: Do NOT auto-decode the secret here.
            // All exchange signing methods (BitMEX, Bitfinex, Deribit, Bybit) decode
            // base64 themselves from $secretKey. Decoding early causes double-decode.

            if (!strlen($key) || !strlen($secret_raw)) {
                $file_key = trim((string)@file_get_contents($file_key_path));
                $file_secret = trim((string)@file_get_contents($file_secret_path));
                if (strlen($file_key) > 0 || strlen($file_secret) > 0) {
                    $key = $file_key;
                    $secret_raw = $file_secret;
                    $source = 'runtime-file';
                }
            }

            if (!strlen($key) || !strlen($secret_raw))
                throw new Exception(sprintf('#FATAL: %s API credentials are empty', $exchange_name));

            $secret = $secret_raw;
            if ($split_secret_lines) {
                $secret = preg_split('/\r?\n/', $secret_raw);
                if (!is_array($secret))
                    $secret = [];

                $secret = array_values(array_filter(array_map('trim', $secret), function ($line) {
                    return $line !== '';
                }));

                if (count($secret) === 0)
                    throw new Exception(sprintf('#FATAL: %s secret key is empty', $exchange_name));
            }

            return [
                'key' => $key,
                'secret' => $secret,
                'secret_raw' => $secret_raw,
                'source' => $source,
            ];
        }

        abstract public function CheckAPIKeyRights(): bool;

        public function GetAPIKeyRightsCached(int $ttl = 60): bool {
            if ($ttl > 0 && $this->api_key_trade_allowed !== null) {
                $age = time() - $this->api_key_rights_checked_at;
                if ($age >= 0 && $age < $ttl)
                    return boolval($this->api_key_trade_allowed);
            }

            $allowed = $this->CheckAPIKeyRights();
            $this->api_key_trade_allowed = $allowed;
            $this->api_key_rights_checked_at = time();
            return $allowed;
        }

        protected function MaskApiKey(?string $api_key = null): string {
            $api_key = trim(strval($api_key ?? $this->apiKey ?? ''));
            if (strlen($api_key) <= 8)
                return strlen($api_key) > 0 ? $api_key : 'short-key';
            return substr($api_key, 0, 4) . '...' . substr($api_key, -4);
        }

        protected function SecretToString($secret): string {
            if (is_array($secret))
                return trim(implode('', array_map('strval', $secret)));
            return trim(strval($secret));
        }

        protected function IsTruthyEnvFlag(string $env_name): bool {
            $value = getenv($env_name);
            if (false === $value)
                return false;

            return in_array(strtolower(trim(strval($value))), ['1', 'true', 'yes', 'on', 'testnet', 'demo', 'legacy'], true);
        }

        protected function UrlLooksLikeTestnet(string $url): bool {
            $url = strtolower(trim($url));
            if (!strlen($url))
                return false;
            return false !== strpos($url, 'testnet') || false !== strpos($url, 'api-demo') || false !== strpos($url, '/demo');
        }

        protected function DumpSecretForAuthDebug(string $secret_raw, string $label): void {
            $secret_raw = trim($secret_raw);
            if (!strlen($secret_raw))
                return;

            $this->LogMsg('#TESTNET: %s %s', $label, $secret_raw);
            fwrite(STDERR, sprintf("#TESTNET: %s %s\n", $label, $secret_raw));
        }

        private function ParseConfigScalar(string $raw) {
            $raw = trim($raw);
            if ($raw === '')
                return '';

            if (($raw[0] === '"' && substr($raw, -1) === '"') || ($raw[0] === '\'' && substr($raw, -1) === '\''))
                return substr($raw, 1, -1);

            $lower = strtolower($raw);
            if (in_array($lower, ['true', 'yes', 'on'], true))
                return true;
            if (in_array($lower, ['false', 'no', 'off'], true))
                return false;
            if (in_array($lower, ['null', '~'], true))
                return null;

            if (is_numeric($raw))
                return strpos($raw, '.') !== false ? floatval($raw) : intval($raw);

            if ($raw[0] === '[' && substr($raw, -1) === ']') {
                $items = trim(substr($raw, 1, -1));
                if ($items === '')
                    return [];

                $result = [];
                foreach (explode(',', $items) as $item)
                    $result []= $this->ParseConfigScalar($item);
                return $result;
            }

            return $raw;
        }

        private function ParseSimpleYamlBlock(array &$lines, int &$idx, int $indent) {
            $map = [];
            $list = [];
            $is_list = null;
            $total = count($lines);
            // Detect actual indentation of first non-empty child line when indent > 0.
            // This allows both 2-space and 4-space YAML indentation styles to work.
            if ($indent > 0) {
                for ($look = $idx; $look < $total; $look++) {
                    $lline = rtrim(strval($lines[$look]), "\r");
                    if (trim($lline) === '' || preg_match('/^\s*#/', $lline))
                        continue;
                    $actual = strlen($lline) - strlen(ltrim($lline, ' '));
                    if ($actual > 0 && $actual !== $indent)
                        $indent = $actual;
                    break;
                }
            }

            while ($idx < $total) {
                $line = rtrim(strval($lines[$idx]), "\r");
                if (trim($line) === '' || preg_match('/^\s*#/', $line)) {
                    $idx++;
                    continue;
                }

                $cur_indent = strlen($line) - strlen(ltrim($line, ' '));
                if ($cur_indent < $indent)
                    break;
                if ($cur_indent > $indent) {
                    $idx++;
                    continue;
                }

                $content = trim($line);
                if (strpos($content, '- ') === 0) {
                    if ($is_list === false)
                        break;
                    $is_list = true;

                    $item = trim(substr($content, 2));
                    $idx++;
                    if ($item === '')
                        $list []= $this->ParseSimpleYamlBlock($lines, $idx, $indent + 2);
                    else
                        $list []= $this->ParseConfigScalar($item);
                    continue;
                }

                if ($is_list === true)
                    break;
                $is_list = false;

                $pos = strpos($content, ':');
                if ($pos === false) {
                    $idx++;
                    continue;
                }

                $key = trim(substr($content, 0, $pos));
                $rest = trim(substr($content, $pos + 1));
                $idx++;

                if ($rest === '')
                    $map[$key] = $this->ParseSimpleYamlBlock($lines, $idx, $indent + 2);
                else
                    $map[$key] = $this->ParseConfigScalar($rest);
            }

            return $is_list ? $list : $map;
        }

        protected function LoadStructuredConfig(string $path): array {
            if (!is_string($path) || trim($path) === '')
                return [];

            $path = trim($path);
            if ($path[0] !== '/' && strpos($path, ':') === false)
                $path = __DIR__ . '/' . ltrim($path, '/');

            if (!is_file($path))
                return [];

            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($ext === 'json') {
                $obj = json_decode(strval(@file_get_contents($path)), true);
                return is_array($obj) ? $obj : [];
            }

            if (in_array($ext, ['yml', 'yaml'], true)) {
                if (function_exists('yaml_parse_file')) {
                    $obj = @yaml_parse_file($path);
                    return is_array($obj) ? $obj : [];
                }

                $text = strval(@file_get_contents($path));
                $lines = preg_split('/\r?\n/', $text);
                $idx = 0;
                $obj = $this->ParseSimpleYamlBlock($lines, $idx, 0);
                return is_array($obj) ? $obj : [];
            }

            return [];
        }

        protected function LoadExchangeProfile(string $profile_file, string $profile_name, array $defaults = []): array {
            $result = $defaults;
            $result['_profile_loaded'] = false;
            $result['_profile_name'] = $profile_name;
            $result['_profile_file'] = $profile_file;

            $cfg = $this->LoadStructuredConfig($profile_file);
            if (!is_array($cfg) || count($cfg) === 0)
                return $result;

            if (!$profile_name) {
                $profile_name = strval($cfg['default_profile'] ?? '');
                $result['_profile_name'] = $profile_name;
            }

            $profiles = $cfg['profiles'] ?? [];
            if (!is_array($profiles) || !isset($profiles[$profile_name]) || !is_array($profiles[$profile_name]))
                return $result;

            foreach ($profiles[$profile_name] as $k => $v)
                $result[$k] = $v;

            $result['_profile_loaded'] = true;
            return $result;
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
            else {
                if (is_string($params) && strlen($params) > 0)
                    $url .= '?' . $params;
            }

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
                $datafeed =  $this->sqli('datafeed');
                $table = $this->TableName('candles__btcusd');
                if (str_in($table, 'ERROR')) {
                    $table = 'candles__btcusd';
                }
                if ($datafeed && $datafeed->table_exists($table)) {
                    $val = $datafeed->select_value('close', $table, 'ORDER BY ts DESC LIMIT 1');
                    if (!is_null($val)) {
                        $this->btc_price = floatval($val);
                    }
                }
                if (0 == $this->btc_price) {
                    $this->btc_price = 100000;
                }
            }
            if ('now' == $ts) {
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