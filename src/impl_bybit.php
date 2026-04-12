<?php
    include_once(__DIR__.'/lib/common.php');
    include_once(__DIR__.'/lib/db_tools.php');
    require_once('trading_core.php');
    require_once('rest_api_common.php');

    define('BYBIT_DEFAULT_CATEGORY', 'linear');
    define('BYBIT_DEFAULT_SETTLE_COIN', 'USDT');

    final class BybitEngine extends WebsockAPIEngine {

        const API_BASE_MAIN    = 'https://api.bybit.com/';
        const API_BASE_DEMO    = 'https://api-demo.bybit.com/';
        const API_BASE_TESTNET = 'https://api-testnet.bybit.com/';

        private   $open_orders = [];
        private   $loaded_data = [];
        private   $api_secret_raw = '';
        private   $auth_source = 'env';

        protected $time_bias = 0; // local_time_ms - server_time_ms
        protected $external_orders = [];
        protected $category = BYBIT_DEFAULT_CATEGORY;
        protected $settle_coins = [BYBIT_DEFAULT_SETTLE_COIN];
        protected $allow_spot_fallback = false;
        protected $require_private_auth_check = true;
        protected $recv_window = 5000;

        protected $last_load = ['orders' => 0, 'tickers' => 0, 'positions' => 0];

        // WS transport state (arthurkushman/php-wss)
        private   ?\WSSC\WebSocketClient $ws_client          = null;
        private   string                 $ws_last_opcode     = 'text';
        // Set after each wsReceive(); true = last read returned data (more may follow)
        private   bool                   $ws_last_had_data   = false;

        // Secondary public WebSocket (market tickers — separate URL from private)
        private   ?\WSSC\WebSocketClient $ws_client_pub      = null;
        private   bool                   $ws_pub_connected   = false;
        private   bool                   $ws_dispatch_is_pub = false; // set by wsOnExtraCycle before dispatch

        public function __construct(object $core) {
            parent::__construct($core);
            $this->exchange = 'Bybit';

            $auth = $this->InitializeAPIKey([
                'split_secret_lines' => false,
            ]);
            $this->auth_source = strval($auth['source'] ?? 'env');
            $this->apiKey = strval($auth['key'] ?? '');
            $this->api_secret_raw = $this->DecodeSecret(strval($auth['secret_raw'] ?? ''));

            if (!strlen($this->api_secret_raw))
                throw new Exception('#FATAL: Bybit API secret is empty');

            $profile_name = trim((string)(getenv('BYBIT_PROFILE') ?: getenv('EXCHANGE_PROFILE_NAME') ?: 'main'));

            $profile_file = trim((string)(getenv('BYBIT_PROFILE_FILE') ?: getenv('EXCHANGE_PROFILE_FILE') ?: 'config/exchanges/bybit.yml'));
            $profile = $this->LoadExchangeProfile($profile_file, $profile_name, [
                'api_base' => self::API_BASE_MAIN,
                'category' => BYBIT_DEFAULT_CATEGORY,
                'settle_coins' => [BYBIT_DEFAULT_SETTLE_COIN],
                'recv_window' => 5000,
                'allow_spot_fallback' => false,
                'require_private_auth_check' => true,
            ]);

            $api_base = trim((string)(getenv('BYBIT_API_BASE_URL') ?: ($profile['api_base'] ?? '')));
            if (!strlen($api_base))
                $api_base = self::API_BASE_MAIN;
            if ('/' !== substr($api_base, -1))
                $api_base .= '/';

            $this->category = strtolower(trim((string)(getenv('BYBIT_CATEGORY') ?: ($profile['category'] ?? BYBIT_DEFAULT_CATEGORY))));

            $settle_raw = trim((string)(getenv('BYBIT_SETTLE_COINS') ?: getenv('BYBIT_SETTLE_COIN') ?: ''));
            $settles = [];
            if (strlen($settle_raw)) {
                foreach (explode(',', $settle_raw) as $coin) {
                    $coin = strtoupper(trim($coin));
                    if ($coin !== '' && !in_array($coin, $settles, true))
                        $settles []= $coin;
                }
            } else {
                $profile_settles = $profile['settle_coins'] ?? [];
                if (!is_array($profile_settles))
                    $profile_settles = [strval($profile_settles)];
                foreach ($profile_settles as $coin) {
                    $coin = strtoupper(trim(strval($coin)));
                    if ($coin !== '' && !in_array($coin, $settles, true))
                        $settles []= $coin;
                }
            }
            if (count($settles) > 0)
                $this->settle_coins = $settles;

            $allow_spot = getenv('BYBIT_ALLOW_SPOT_FALLBACK');
            if (false === $allow_spot)
                $allow_spot = ($profile['allow_spot_fallback'] ?? false) ? '1' : '0';
            $this->allow_spot_fallback = in_array(strtolower(trim(strval($allow_spot))), ['1', 'true', 'yes', 'on'], true);

            $require_auth = getenv('BYBIT_REQUIRE_PRIVATE_AUTH_CHECK');
            if (false === $require_auth)
                $require_auth = ($profile['require_private_auth_check'] ?? true) ? '1' : '0';
            $this->require_private_auth_check = in_array(strtolower(trim(strval($require_auth))), ['1', 'true', 'yes', 'on'], true);

            $recv_window_env = getenv('BYBIT_RECV_WINDOW');
            $this->recv_window = intval(false !== $recv_window_env ? $recv_window_env : ($profile['recv_window'] ?? 5000));
            if ($this->recv_window <= 0)
                $this->recv_window = 5000;

            $this->public_api = $api_base;
            $this->private_api = $api_base;
            $profile_mark = !empty($profile['_profile_loaded']) ? strval($profile['_profile_name']) : 'legacy-env';
            $this->LogMsg('#ENV: Bybit API base %s, category %s, settle %s, spot_fallback %s, profile %s',
                $api_base,
                $this->category,
                implode(',', $this->settle_coins),
                $this->allow_spot_fallback ? 'on' : 'off',
                $profile_mark);
        }

        private function IsAllowedBySettle(string $symbol, string $category): bool {
            $category = strtolower(trim($category));
            if (!in_array($category, ['linear', 'inverse'], true))
                return true;
            if (!is_array($this->settle_coins) || count($this->settle_coins) === 0)
                return true;

            foreach ($this->settle_coins as $coin)
                if ($coin !== '' && str_ends_with(strtoupper($symbol), strtoupper($coin)))
                    return true;

            return false;
        }

        private function DecodeSecret(string $src): string {
            $src = trim($src);
            if (!strlen($src))
                return '';

            $packed = str_replace(["\r", "\n"], '', $src);
            $decoded = base64_decode($packed, true);
            if (false !== $decoded && strlen($decoded) > 0)
                return $decoded;
            return $src;
        }

        private function IsRequestFailed($obj): bool {
            if (!$obj || !is_object($obj))
                return true;
            if (!isset($obj->retCode))
                return true;
            return intval($obj->retCode) !== 0;
        }

        private function SetLastErrorEx($obj, string $text, int $code = -1): void {
            if (is_object($obj) && isset($obj->retCode)) {
                $msg = isset($obj->retMsg) ? strval($obj->retMsg) : $text;
                $this->SetLastError($msg . ' ' . $text, intval($obj->retCode));
            } else {
                $this->SetLastError($text, $code);
            }
        }

        private function IsOrderNotFound($obj): bool {
            if (!is_object($obj) || !isset($obj->retCode))
                return false;
            $code = intval($obj->retCode);
            return in_array($code, [110001, 170213], true);
        }

        private function SignRequest(string $payload): string {
            return hash_hmac('sha256', $payload, $this->api_secret_raw);
        }

        protected function EncodeParams(&$params, &$headers, $method) {
            if ('POST' === $method) {
                if (!is_string($params))
                    $params = json_encode($params, JSON_UNESCAPED_SLASHES);
                $headers []= 'Content-Type: application/json';
            }
            elseif (is_array($params)) {
                $params = http_build_query($params, '', '&');
            }
            return $params;
        }

        private function RequestPrivateAPI(string $rqs, $params, string $method = 'GET') {
            $method = strtoupper($method);

            $query = '';
            $body = '';
            if ('POST' === $method) {
                if (is_array($params))
                    $body = json_encode($params, JSON_UNESCAPED_SLASHES);
                else
                    $body = strval($params);
            } else {
                if (is_array($params))
                    $query = http_build_query($params, '', '&');
                else
                    $query = strval($params);
            }

            $ts = strval(time_ms() - $this->time_bias);
            $recv = strval($this->recv_window);
            $to_sign = $ts . $this->apiKey . $recv . ('POST' === $method ? $body : $query);
            $sign = $this->SignRequest($to_sign);

            $headers = [
                'X-BAPI-API-KEY: ' . $this->apiKey,
                'X-BAPI-TIMESTAMP: ' . $ts,
                'X-BAPI-RECV-WINDOW: ' . $recv,
                'X-BAPI-SIGN-TYPE: 2',
                'X-BAPI-SIGN: ' . $sign,
            ];

            if ('POST' === $method)
                $headers []= 'Content-Type: application/json';

            $payload = 'POST' === $method ? $body : $query;
            return $this->RequestPublicAPI($rqs, $payload, $method, $headers, $this->private_api);
        }

        public function Initialize() {
            parent::Initialize();
            $this->PlatformStatus();
            $core      = $this->TradeCore();
            $cfg       = $core->configuration;
            $cfg_table = trim($cfg->config_table, '`');
            if ($cfg_table !== '') {
                $core->Engine()->sqli()->try_query(
                    "INSERT IGNORE INTO `$cfg_table` (param, value) VALUES ('exchange_profile', 'main')"
                );
            }
            $profile_name = $cfg->GetValue('exchange_profile', null) ?? 'main';
            $this->active_profile = $profile_name;
            $profile_file = trim((string)(getenv('BYBIT_PROFILE_FILE') ?: getenv('EXCHANGE_PROFILE_FILE') ?: 'config/exchanges/bybit.yml'));
            $profile = $this->LoadExchangeProfile($profile_file, $profile_name, [
                'api_base' => self::API_BASE_MAIN,
            ]);
            $api_base = trim((string)(getenv('BYBIT_API_BASE_URL') ?: ($profile['api_base'] ?? '')));
            if (!strlen($api_base)) $api_base = self::API_BASE_MAIN;
            if ('/' !== substr($api_base, -1)) $api_base .= '/';
            $this->public_api  = $api_base;
            $this->private_api = $api_base;
            $this->LogMsg('#CFG: Bybit profile=%s, api=%s', $profile_name, $api_base);
            $this->wsLoadConfig($profile);
            if ($this->ws_active)
                $this->wsReconnect('initial');
        }

        public function CheckAPIKeyRights(): bool {
            $core = $this->TradeCore();
            $ctx = $this->AuthContext();
            $core->LogMsg('~C93#AUTH_CHECK:~C00 %s source=%s', $ctx, $this->auth_source);

            $json = $this->RequestPrivateAPI('/v5/user/query-api', []);
            $obj = json_decode($json);
            if ($this->IsRequestFailed($obj)) {
                $msg = strval($obj->retMsg ?? $json);
                $this->SetLastErrorEx($obj, sprintf('Bybit private auth check failed [%s]: %s', $ctx, $msg), -1);
                $this->ThrowInvalidBybitApiKey(sprintf('#FATAL: Bybit private auth check failed [%s]: %s', $ctx, $msg));
            }

            $permissions = [];
            if (isset($obj->result) && isset($obj->result->permissions) && is_object($obj->result->permissions)) {
                foreach (get_object_vars($obj->result->permissions) as $scope => $vals) {
                    if (is_array($vals)) {
                        foreach ($vals as $v) {
                            $v = trim(strval($v));
                            if ($v !== '')
                                $permissions []= $scope . ':' . $v;
                        }
                    } elseif (is_string($vals) && trim($vals) !== '') {
                        $permissions []= $scope . ':' . trim($vals);
                    }
                }
            }

            $perm_text = count($permissions) > 0 ? implode(', ', $permissions) : 'n/a';
            $readonly = isset($obj->result->readOnly) ? strval($obj->result->readOnly) : 'n/a';
            $readonly_flag = in_array(strtolower(trim($readonly)), ['1', 'true', 'yes', 'on'], true);

            $core->LogMsg('~C92#AUTH_OK:~C00 Bybit validated [%s], readOnly=%s, permissions: %s', $ctx, $readonly, $perm_text);

            if ($readonly_flag)
                return false;

            return true;
        }

        private function IsBybitTestnetMode(): bool {
            return $this->UrlLooksLikeTestnet($this->public_api) || $this->UrlLooksLikeTestnet($this->private_api);
        }

        private function ThrowInvalidBybitApiKey(string $message): void {
            if ($this->IsBybitTestnetMode())
                $this->DumpSecretForAuthDebug($this->api_secret_raw, 'assembled Bybit secret');
            throw new Exception($message);
        }

        public function PlatformStatus() {
            $json = $this->RequestPublicAPI('/v5/market/time', '');
            $obj = json_decode($json);
            if ($this->IsRequestFailed($obj)) {
                $this->SetLastErrorEx($obj, 'Bybit /v5/market/time failed', -1);
                $this->LogMsg('~C91#WARN:~C00 Bybit platform check failed: %s', $json);
                return 0;
            }

            $server_ms = 0;
            if (isset($obj->result) && isset($obj->result->timeSecond))
                $server_ms = intval($obj->result->timeSecond) * 1000;
            if (isset($obj->time) && intval($obj->time) > 0)
                $server_ms = intval($obj->time);

            if ($server_ms > 0)
                $this->time_bias = time_ms() - $server_ms;

            return 1;
        }

        private function LoadInstrumentsInfo(): void {
            if (isset($this->loaded_data['symbol_filters']))
                return;

            $applied = 0;
            $settle_filters = [''];
            if (in_array($this->category, ['linear', 'inverse'], true) && is_array($this->settle_coins) && count($this->settle_coins) > 0)
                $settle_filters = $this->settle_coins;

            foreach ($settle_filters as $settle_filter) {
                $cursor = '';
                while (true) {
                    $params = ['category' => $this->category, 'limit' => 1000];
                    if (strlen($cursor))
                        $params['cursor'] = $cursor;
                    if ($settle_filter !== '')
                        $params['settleCoin'] = $settle_filter;

                    $json = $this->RequestPublicAPI('/v5/market/instruments-info', $params);
                    $obj = json_decode($json);
                    if ($this->IsRequestFailed($obj)) {
                        $this->SetLastErrorEx($obj, 'LoadInstrumentsInfo failed', -1);
                        break;
                    }

                    $list = $obj->result->list ?? [];
                    if (!is_array($list) || count($list) == 0)
                        break;

                    foreach ($list as $rec) {
                        if (!is_object($rec) || !isset($rec->symbol))
                            continue;

                        $symbol = strval($rec->symbol);
                        if (!isset($this->pairs_map_rev[$symbol]))
                            continue;

                        $pair_id = $this->pairs_map_rev[$symbol];
                        $tinfo = &$this->pairs_info[$pair_id];
                        $tinfo->details = $rec;

                    $pf = $rec->priceFilter ?? null;
                    if (is_object($pf) && isset($pf->tickSize)) {
                        $tinfo->tick_size = floatval($pf->tickSize);
                        $tinfo->min_price = isset($pf->minPrice) ? floatval($pf->minPrice) : 0;
                        $tinfo->max_price = isset($pf->maxPrice) ? floatval($pf->maxPrice) : 0;
                        $tinfo->price_precision = ($tinfo->tick_size > 0 && $tinfo->tick_size < 1)
                            ? TickerInfo::CalcPP($tinfo->tick_size)
                            : 0;
                    }

                    $lf = $rec->lotSizeFilter ?? null;
                    if (is_object($lf)) {
                        $tinfo->lot_size = isset($lf->minOrderQty) ? floatval($lf->minOrderQty) : 0;
                        $tinfo->step_size = isset($lf->qtyStep) ? floatval($lf->qtyStep) : $tinfo->lot_size;
                        if ($tinfo->step_size > 0 && $tinfo->step_size < 1)
                            $tinfo->qty_precision = TickerInfo::CalcPP($tinfo->step_size);
                        elseif ($tinfo->lot_size > 0)
                            $tinfo->qty_precision = TickerInfo::CalcPP($tinfo->lot_size);

                        if (isset($lf->minNotionalValue)) {
                            $tinfo->min_notional = floatval($lf->minNotionalValue);
                            $tinfo->min_cost = floatval($lf->minNotionalValue);
                        }
                    }

                        $tinfo->iceberg_parts = 1;
                        $tinfo->OnUpdate();
                        $applied++;
                    }

                    $cursor = strval($obj->result->nextPageCursor ?? '');
                    if (!strlen($cursor))
                        break;
                }
            }

            if ($applied > 0)
                $this->loaded_data['symbol_filters'] = 1;
        }

        public function LoadTickers() {
            $core = $this->TradeCore();
            parent::LoadTickers();

            $updated = 0;

            $apply_ticker = function($rec) use (&$updated): bool {
                if (!is_object($rec) || !isset($rec->symbol))
                    return false;

                $symbol = strval($rec->symbol);
                if (!isset($this->pairs_map_rev[$symbol]))
                    return false;

                $pair_id = $this->pairs_map_rev[$symbol];
                $tinfo = &$this->pairs_info[$pair_id];
                $tinfo->last_price = floatval($rec->lastPrice ?? 0);
                $tinfo->ask_price = floatval($rec->ask1Price ?? 0);
                $tinfo->bid_price = floatval($rec->bid1Price ?? 0);
                $tinfo->fair_price = floatval($rec->markPrice ?? $tinfo->last_price);
                $tinfo->index_price = floatval($rec->indexPrice ?? $tinfo->last_price);
                $tinfo->daily_vol = floatval($rec->turnover24h ?? 0);

                if ($tinfo->bid_price > 0 && $tinfo->ask_price > $tinfo->bid_price)
                    $tinfo->mid_spread = ($tinfo->ask_price + $tinfo->bid_price) / 2;
                else
                    $tinfo->mid_spread = 0;

                $tinfo->OnUpdate();
                if ($symbol === 'BTCUSDT' || $symbol === 'BTCUSDC')
                    $this->set_btc_price($tinfo->last_price);
                $updated++;
                return true;
            };

            $categories = [];
            $fallback_categories = [$this->category, 'linear', 'inverse'];
            if ($this->allow_spot_fallback)
                $fallback_categories []= 'spot';

            foreach ($fallback_categories as $cat) {
                $cat = strtolower(trim(strval($cat)));
                if ($cat !== '' && !in_array($cat, $categories, true))
                    $categories []= $cat;
            }

            $last_error_payload = '';
            foreach ($categories as $cat) {
                $json = $this->RequestPublicAPI('/v5/market/tickers', ['category' => $cat]);
                if (!$json) {
                    $last_error_payload = sprintf('curl_error=%s, response=%s', $this->curl_last_error, json_encode($this->curl_response));
                    continue;
                }

                $obj = json_decode($json);
                if ($this->IsRequestFailed($obj)) {
                    $last_error_payload = $json;
                    continue;
                }

                $list = $obj->result->list ?? [];
                $matched = 0;
                $samples = [];
                if (is_array($list))
                    foreach ($list as $rec) {
                        if (is_object($rec) && isset($rec->symbol) && count($samples) < 8)
                            $samples []= strval($rec->symbol);
                        $symbol = is_object($rec) && isset($rec->symbol) ? strval($rec->symbol) : '';
                        if ($symbol !== '' && !$this->IsAllowedBySettle($symbol, $cat))
                            continue;

                        if ($apply_ticker($rec))
                            $matched++;
                    }

                if ($matched > 0) {
                    if ($cat !== $this->category) {
                        $core->LogMsg('~C93#INFO:~C00 Bybit tickers loaded using category=%s (configured=%s)', $cat, $this->category);
                        $this->category = $cat;
                    }
                    break;
                }

                if (is_array($list) && count($list) > 0)
                    $core->LogError('~C91#WARN:~C00 Bybit category=%s returned %d tickers but none match configured pairs. Sample: %s',
                        $cat, count($list), implode(',', $samples));
            }

            // Fallback: query tickers symbol-by-symbol for configured pairs.
            if ($updated <= 0) {
                foreach ($categories as $cat) {
                    $matched = 0;
                    foreach ($this->pairs_map_rev as $symbol => $_pair_id) {
                        if (!$this->IsAllowedBySettle($symbol, $cat))
                            continue;

                        $json = $this->RequestPublicAPI('/v5/market/tickers', ['category' => $cat, 'symbol' => $symbol]);
                        if (!$json)
                            continue;
                        $obj = json_decode($json);
                        if ($this->IsRequestFailed($obj))
                            continue;

                        $list = $obj->result->list ?? [];
                        if (is_array($list))
                            foreach ($list as $rec)
                                if ($apply_ticker($rec))
                                    $matched++;
                    }
                    if ($matched > 0) {
                        if ($cat !== $this->category)
                            $this->category = $cat;
                        $core->LogMsg('~C93#INFO:~C00 Bybit tickers loaded via per-symbol fallback, category=%s, matched=%d', $cat, $matched);
                        break;
                    }
                }
            }

            if ($updated <= 0) {
                $this->SetLastError('Bybit LoadTickers: no matching symbols for configured pairs', -1);
                $core->LogError('~C91#ERROR:~C00 Bybit LoadTickers failed for all categories. Last payload: %s', $last_error_payload);
                return 0;
            }

            $this->last_load['tickers'] = time();
            $this->LoadInstrumentsInfo();
            return $updated;
        }

        private function NormalizeOrderStatus(string $st): string {
            $st = strtolower(trim($st));
            $map = [
                'new' => 'active',
                'partiallyfilled' => 'partially_filled',
                'filled' => 'filled',
                'cancelled' => 'canceled',
                'canceled' => 'canceled',
                'rejected' => 'rejected',
                'deactivated' => 'canceled',
                'untriggered' => 'active',
                'triggered' => 'active',
                'partiallyfilledcanceled' => 'partially_filled',
            ];
            return $map[$st] ?? $st;
        }

        private function ParseOrderLink(string $order_link, &$acc_id, &$order_id): bool {
            $acc_id = -1;
            $order_id = -1;
            if (preg_match('/Account_(\d+)-IID_(\d+)/', $order_link, $m)) {
                $acc_id = intval($m[1]);
                $order_id = intval($m[2]);
                return true;
            }
            return false;
        }

        private function UpdateOrder($order, bool $force_active = false) {
            $core = $this->TradeCore();
            if (!is_object($order))
                return false;

            $symbol = strval($order->symbol ?? '');
            if (!isset($this->pairs_map_rev[$symbol]))
                return false;
            $pair_id = $this->pairs_map_rev[$symbol];

            $order_link = strval($order->orderLinkId ?? '');
            $acc_id = -1;
            $order_id = -1;
            if (!$this->ParseOrderLink($order_link, $acc_id, $order_id))
                return false;
            if ($acc_id != $this->account_id)
                return false;

            $info = $this->FindOrder($order_id, $pair_id);
            if (!$info) {
                if (!isset($this->external_orders[$order_id]))
                    $this->external_orders[$order_id] = 1;
                else
                    $this->external_orders[$order_id]++;
                return false;
            }

            $was_fixed = $info->IsFixed();
            unset($this->external_orders[$order_id]);

            $cur_status = $this->NormalizeOrderStatus(strval($order->orderStatus ?? 'active'));
            if ($force_active && $info->IsFixed()) {
                $core->LogOrder('~C91#WARN:~C00 fixed order %s restored to active state', $info);
                $info->Unregister(null, 'fixed-to-active');
                $info->flags &= ~OFLAG_FIXED;
                $info->status = 'active';
                $info->ts_fix = null;
            }

            if (in_array($cur_status, ['active', 'partially_filled'], true))
                $this->open_orders[$order_id] = true;

            $utime = intval($order->updatedTime ?? 0) - $this->time_bias;
            if ($utime <= 0)
                $utime = time_ms();

            $matched = floatval($order->cumExecQty ?? 0);
            $amount = floatval($order->qty ?? $info->amount);
            $is_changed = (strtotime_ms($info->updated) < $utime);
            $is_changed |= ($info->matched !== $matched);
            $is_changed |= ($info->status !== $cur_status);

            $info->amount = $amount;
            $info->matched = $matched;
            if (isset($order->orderId) && is_numeric($order->orderId))
                $info->order_no = $order->orderId;

            $this->updated_orders[$info->id] = $info;

            $avg_price = floatval($order->avgPrice ?? 0);
            if ($avg_price > 0 && $matched > 0)
                $info->avg_price = $avg_price;

            if ($is_changed)
                $info->updated = date_ms(SQL_TIMESTAMP_MS, $utime);

            $info->set_status($cur_status, true);
            if (!$this->DispatchOrder($info, 'DispatchOrder/update'))
                $core->LogError('~C91#FAILED_DISPATCH:~C00 order %s, source %s', $info, json_encode($order));

            if ($info->IsFixed() && !$was_fixed)
                $info->ts_fix = $info->updated;

            if ('filled' != $info->status)
                $info->OnUpdate();

            return $is_changed;
        }

        private function UpdateSingleOrder(OrderInfo $info) {
            $core = $this->TradeCore();
            if ($info->IsFixed())
                return true;

            $pair = $core->FindPair($info->pair_id);
            $code = sprintf('Account_%d-IID_%d', $this->account_id, $info->id);

            $params = [
                'category' => $this->category,
                'symbol' => $pair,
                'orderLinkId' => $code,
                'openOnly' => 0,
                'limit' => 1,
            ];

            $json = $this->RequestPrivateAPI('/v5/order/realtime', $params);
            $obj = json_decode($json);
            if ($this->IsRequestFailed($obj)) {
                $core->LogError('~C91#ERROR:~C00 Bybit GET order failed: %s', $json);
                $this->SetLastErrorEx($obj, $json, -1);
                $info->error_log []= "Bybit order GET failed: $json";
                if (count($info->error_log) < 5)
                    return false;

                if ($info->matched > 0)
                    $info->status = OST_TOUCHED;
                else
                    $info->status = OST_LOST;

                $info->Unregister(null, 'not-found/lost, errors = ' . count($info->error_log));
                if (0 == $info->matched)
                    $info->Register($this->archive_orders);
                else
                    $info->Register($this->matched_orders);
                return false;
            }

            $list = $obj->result->list ?? [];
            if (!is_array($list) || count($list) == 0) {
                $hist = $this->RequestPrivateAPI('/v5/order/history', [
                    'category' => $this->category,
                    'symbol' => $pair,
                    'orderLinkId' => $code,
                    'limit' => 1,
                ]);
                $hist_obj = json_decode($hist);
                if (!$this->IsRequestFailed($hist_obj)) {
                    $hlist = $hist_obj->result->list ?? [];
                    if (is_array($hlist) && count($hlist) > 0)
                        return $this->UpdateOrder($hlist[0]);
                }

                $info->error_log []= 'Bybit order GET returns empty list';
                // Bybit order endpoints can lag right after create; allow several retries before marking lost.
                if (count($info->error_log) < 5)
                    return false;

                if ($info->matched > 0)
                    $info->status = OST_TOUCHED;
                else
                    $info->status = OST_LOST;
                return false;
            }

            return $this->UpdateOrder($list[0]);
        }

        public function LoadOrders(bool $force_all) {
            $core = $this->TradeCore();
            $this->LogMsg('loading orders from Bybit...');
            $this->open_orders = [];
            $this->updated_orders = [];

            $params = ['category' => $this->category, 'openOnly' => 0, 'limit' => 50];
            if (in_array($this->category, ['linear', 'inverse'], true)) {
                $settle = strtoupper(trim((string)($this->settle_coins[0] ?? BYBIT_DEFAULT_SETTLE_COIN)));
                if ($settle !== '')
                    $params['settleCoin'] = $settle;
            }
            $json = $this->RequestPrivateAPI('/v5/order/realtime', $params);
            $obj = json_decode($json);
            if ($this->IsRequestFailed($obj)) {
                $this->SetLastErrorEx($obj, $json, -1);
                $core->LogError('~C91#ERROR:~C00 Bybit load orders failed: %s, %s', $this->curl_last_error, json_encode($this->curl_response));
                return false;
            }

            $updated = 0;
            $list = $obj->result->list ?? [];
            if (is_array($list))
                foreach ($list as $order)
                    if ($this->UpdateOrder($order, true))
                        $updated++;

            $this->last_load['orders'] = time();

            $plist = $this->MixOrderList('pending,market_maker');
            foreach ($plist as $info)
                if (!isset($this->open_orders[$info->id]))
                    $this->UpdateSingleOrder($info);

            $this->LogMsg('~C93#DBG:~C00 Bybit open orders updated = %d, mapped open = %d', $updated, count($this->open_orders));
            return true;
        }

        protected function DetectPair(string $asset, string &$symbol): int {
            $symbol = $asset . BYBIT_DEFAULT_SETTLE_COIN;
            if (isset($this->pairs_map_rev[$symbol]))
                return $this->pairs_map_rev[$symbol];
            $symbol = $asset . 'USD';
            if (isset($this->pairs_map_rev[$symbol]))
                return $this->pairs_map_rev[$symbol];
            $symbol = 'unknown?';
            return -1;
        }

        // Total notional value of all open positions (sum of positionValue from REST).
        // Updated in LoadPositions(); persists for WS-active cycles where REST is skipped.
        private float $last_pos_notional = 0.0;

        private function FetchAndSetWalletFunds(): void {
            $core = $this->TradeCore();
            // Try UNIFIED first (newer accounts), fall back to CONTRACT (classic derivatives).
            foreach (['UNIFIED', 'CONTRACT'] as $acc_type) {
                $wjson = $this->RequestPrivateAPI('/v5/account/wallet-balance', ['accountType' => $acc_type]);
                $wobj  = $wjson ? json_decode($wjson) : null;
                if ($wobj && !$this->IsRequestFailed($wobj)) {
                    $wlist = $wobj->result->list ?? [];
                    if (is_array($wlist) && count($wlist) > 0) {
                        $wa = $wlist[0];
                        $total_eq  = floatval($wa->totalEquity         ?? $wa->totalWalletBalance ?? 0);
                        $btc_price = $this->get_btc_price();
                        if ($total_eq > 0) {
                            $core->total_funds = $total_eq;
                            $core->total_btc   = $btc_price > 0 ? $total_eq / $btc_price : 0;
                            // funds_usage = position_notional / equity * 100 (>100% when leveraged).
                            // Use last_pos_notional from LoadPositions(); fallback to 0 on first cycle.
                            $core->used_funds = 100.0 * $this->last_pos_notional / $total_eq;
                            $core->LogMsg('~C94#WALLET:~C00 accountType=%s equity=%.2f pos_notional=%.2f usage=%.1f%%',
                                $acc_type, $total_eq, $this->last_pos_notional, $core->used_funds);
                        } else {
                            $core->LogMsg('~C93#WALLET_WARN:~C00 accountType=%s total_eq=0, raw=%s',
                                $acc_type, substr($wjson, 0, 200));
                        }
                        break;
                    } else {
                        $core->LogMsg('~C93#WALLET_WARN:~C00 accountType=%s empty list, raw=%s',
                            $acc_type, substr($wjson, 0, 200));
                    }
                } else {
                    $err = $wobj ? json_encode($wobj) : ('no response: ' . $this->curl_last_error);
                    $core->LogMsg('~C91#WALLET_ERR:~C00 accountType=%s failed: %s', $acc_type, substr($err, 0, 300));
                }
            }
        }

        public function LoadTrades(): int {
            $this->FetchAndSetWalletFunds();
            return parent::LoadTrades();
        }

        public function LoadPositions() {
            parent::LoadPositions();
            $core = $this->TradeCore();

            $params = [
                'category' => $this->category,
                'settleCoin' => (string)(getenv('BYBIT_SETTLE_COIN') ?: BYBIT_DEFAULT_SETTLE_COIN),
                'limit' => 200,
            ];

            $json = $this->RequestPrivateAPI('/v5/position/list', $params);
            if (!$json) {
                $core->LogError('~C91#FAILED:~C00 Bybit position request failed: %s, %s', $this->curl_last_error, json_encode($this->curl_response));
                return false;
            }

            $obj = json_decode($json);
            if ($this->IsRequestFailed($obj)) {
                $this->SetLastErrorEx($obj, 'LoadPositions failed', -1);
                $core->LogError('~C91#FAILED:~C00 Bybit position list failed: %s', $json);
                return false;
            }

            $current_pos = [];
            $total_pos_value = 0.0;
            $list = $obj->result->list ?? [];
            if (is_array($list))
                foreach ($list as $rec) {
                    if (!is_object($rec) || !isset($rec->symbol))
                        continue;
                    $symbol = strval($rec->symbol);
                    if (!isset($this->pairs_map_rev[$symbol]))
                        continue;

                    $size = floatval($rec->size ?? 0);
                    if ($size == 0)
                        continue;

                    // positionValue = |size| * markPrice (provided by Bybit for linear)
                    $total_pos_value += abs(floatval($rec->positionValue ?? 0));

                    $side = strtolower(strval($rec->side ?? 'buy'));
                    if ('sell' === $side)
                        $size *= -1;

                    $pair_id = $this->pairs_map_rev[$symbol];
                    $current_pos[$pair_id] = ($current_pos[$pair_id] ?? 0) + $size;
                }

            // Store for FetchAndSetWalletFunds() which runs in LoadTrades() each cycle.
            $this->last_pos_notional = $total_pos_value;

            $core->ImportPositions($current_pos);
            $this->last_load['positions'] = time();
            return true;
        }

        public function NewOrder(TickerInfo $ti, array $proto): ?OrderInfo {
            $pair_id = $ti->pair_id;
            $proto['pair_id'] = $pair_id;
            if (0 == $proto['amount'])
                throw new Exception('Attempt create order with amount == 0');

            $core = $this->TradeCore();
            $info = $this->CreateOrder();
            $info->Import($proto);
            if (!$this->DispatchOrder($info, 'DispatchOrder/new')) {
                $core->LogError('~C91#FAILED:~C00 order was not registered');
                $core->LogObj($proto, '  ', 'proto');
                return null;
            }
            if (0 == $info->id)
                throw new Exception('OrderInfo->id not initialized');

            $info->ticker_info = $ti;

            $symbol = $core->pairs_map[$pair_id];
            $side = $proto['buy'] ? 'Buy' : 'Sell';
            $code = sprintf('Account_%d-IID_%d', $this->account_id, $info->id);

            $amount = $ti->FormatAmount($proto['amount']);
            $price = $proto['price'];

            $params = [
                'category' => $this->category,
                'symbol' => $symbol,
                'side' => $side,
                'orderType' => 'Limit',
                'qty' => strval($amount),
                'price' => strval($ti->FormatPrice($price)),
                'timeInForce' => 'GTC',
                'orderLinkId' => $code,
            ];

            $this->SetLastErrorEx(false, '', 0);
            $json = $this->RequestPrivateAPI('/v5/order/create', $params, 'POST');
            $result = json_decode($json);
            $info->exec_attempts = $this->CountAttempts($info->batch_id);

            if ($this->IsRequestFailed($result)) {
                $core->LogMsg('~C91#FAILED:~C00 Bybit new order failed: %s, params: %s', $json, json_encode($params));
                $this->SetLastErrorEx($result, sprintf('for %s %s', strval($info), $info->comment), -404);
                $info->Unregister(null, 'failed-post-new');
                $info->error_log []= "Bybit failed post new order: $json";
                $info->status = 'rejected';
                $info->OnError('submit');
                $info->Register($this->archive_orders);
                return null;
            }

            $res = $result->result ?? null;
            if (is_object($res) && isset($res->orderId)) {
                $oid = strval($res->orderId);
                if (is_numeric($oid))
                    $info->order_no = $oid;
            }

            $info->status = 'active';
            $info->updated = date_ms(SQL_TIMESTAMP_MS, time_ms());
            $info->OnUpdate();

            // Sync with exchange state after placement.
            $this->UpdateSingleOrder($info);
            return $info;
        }

        public function CancelOrder($info): ?OrderInfo {
            $core = $this->TradeCore();
            if (!isset($info->pair_id)) {
                $core->LogError('~C97#ERROR: invalid OrderInfo object:~C00');
                throw new Exception('#FATAL: structure mismatch');
            }

            $this->UpdateSingleOrder($info);
            if ($info->IsFixed()) {
                $core->LogOrder('~C91#ERROR:~C00 attempt to cancel [%s] order #%d', $info->status, $info->id);
                $info->Unregister(null, 'cancel-blocked');
                $this->DispatchOrder($info, 'DispatchOrder/cancel-fail');
                return $info;
            }

            $pair = $info->pair;
            if (strlen($pair) <= 3)
                $pair = $core->pairs_map[$info->pair_id];

            $code = sprintf('Account_%d-IID_%d', $this->account_id, $info->id);
            $params = [
                'category' => $this->category,
                'symbol' => $pair,
                'orderLinkId' => $code,
            ];
            if ($info->order_no > 0)
                $params['orderId'] = strval($info->order_no);

            $this->SetLastErrorEx(false, '', 0);
            $json = $this->RequestPrivateAPI('/v5/order/cancel', $params, 'POST');
            $obj = json_decode($json);
            if ($this->IsRequestFailed($obj)) {
                if ($this->IsOrderNotFound($obj)) {
                    $info->status = $info->matched > 0 ? 'partially_filled' : 'lost';
                    $info->Unregister(null, 'cancel-not-found');
                    $this->DispatchOrder($info, 'DispatchOrder/cancel-not-found');
                    return $info;
                }

                $core->LogError('~C91#ERROR: Bybit cancel failed:~C97 %s~C00', $json);
                $this->SetLastErrorEx($obj, $json, -1);
                $info->OnError('cancel');
                $info->error_log []= "Bybit cancel failed: $json";

                if (count($info->error_log) > 3)
                    return null;
            } else {
                $info->comment .= ', cl';
                if ($info->matched > 0)
                    $info->status = 'partially_filled';
                else
                    $info->status = 'canceled';
                $info->Unregister(null, 'cancel-success');
            }

            $info->updated = date_ms(SQL_TIMESTAMP_MS, time_ms());
            $this->UpdateSingleOrder($info);

            if ($info->matched > 0) {
                if ($info->matched == $info->amount)
                    $info->status = 'filled';
                $info->Register($this->matched_orders);
            } else {
                $info->status = 'canceled';
                $info->Register($this->archive_orders);
            }

            return $info;
        }

        // -------------------------------------------------------------------------
        // WebsockAPIEngine transport — arthurkushman/php-wss (WSSC)
        // -------------------------------------------------------------------------

        protected function wsConnect(string $url): bool {
            try {
                $config = new \WSSC\Components\ClientConfig();
                $config->setTimeout(10); // connect timeout
                $client = new \WSSC\WebSocketClient($url, $config);
                if ($client->isConnected()) {
                    $this->ws_client = $client;
                    // WSSC does not expose the raw socket — use short read timeout
                    // instead of stream_set_blocking() to avoid starving the bot loop.
                    $this->ws_client->setTimeout(0, 10000); // 10 ms read timeout
                    $this->ws_last_had_data = false;
                    $this->wsOnConnected();

                    // Also connect the public market-data stream (tickers)
                    $pub_url = $this->wsNextEndpoint('public');
                    if ($pub_url !== null)
                        $this->wsConnectPublic($pub_url);

                    return true;
                }
            } catch (\Throwable $E) {
                $this->LogMsg("~C91#WS_CONNECT_FAIL:~C00 %s", $E->getMessage());
            }
            $this->ws_client = null;
            return false;
        }

        private function wsConnectPublic(string $url): void {
            try {
                $config = new \WSSC\Components\ClientConfig();
                $config->setTimeout(10);
                $client = new \WSSC\WebSocketClient($url, $config);
                if ($client->isConnected()) {
                    $this->ws_client_pub = $client;
                    $this->ws_client_pub->setTimeout(0, 10000);
                    $this->ws_pub_connected = true;
                    // Bybit public endpoint — no auth, subscribe immediately
                    $syms = array_keys($this->pairs_map_rev ?? []);
                    if (!empty($syms)) {
                        $args = array_map(fn($s) => 'tickers.' . $s, $syms);
                        $this->ws_client_pub->send(json_encode(['op' => 'subscribe', 'args' => $args]));
                        $this->LogMsg("~C93#WS_PUB_SUBSCRIBE:~C00 Bybit public tickers subscribed (%d symbols)", count($syms));
                    }
                    return;
                }
            } catch (\Throwable $E) {
                $this->LogMsg("~C91#WS_PUB_CONNECT_FAIL:~C00 %s", $E->getMessage());
            }
            $this->ws_client_pub   = null;
            $this->ws_pub_connected = false;
        }

        protected function wsClose(): void {
            $this->ws_client     = null;
            $this->ws_client_pub = null;
            $this->ws_pub_connected = false;
        }

        protected function wsSend(string $data): bool {
            if (!$this->ws_client) return false;
            try {
                $this->ws_client->send($data);
                return true;
            } catch (\Throwable $E) {
                $this->LogMsg("~C91#WS_SEND_FAIL:~C00 %s", $E->getMessage());
                return false;
            }
        }

        protected function wsReceive(): ?string {
            if (!$this->ws_client) return null;
            try {
                $raw = $this->ws_client->receive();
            } catch (\Throwable $E) {
                $this->ws_last_had_data = false;
                return null;
            }
            $this->ws_last_opcode = $this->ws_client->getLastOpcode() ?? 'text';
            $result = (is_string($raw) && $raw !== '') ? $raw : null;
            $this->ws_last_had_data = ($result !== null);
            return $result;
        }

        protected function wsLastOpcode(): string {
            return $this->ws_last_opcode;
        }

        protected function wsUnreaded(): int {
            // WSSC does not expose the raw socket for peeking.
            // Return 1 when the last receive returned data (more frames may follow);
            // the drainWsBuffer loop always attempts at least one read on its own.
            return ($this->ws_client && $this->ws_last_had_data) ? 1 : 0;
        }

        protected function wsIsConnected(): bool {
            return $this->ws_client !== null && $this->ws_client->isConnected();
        }

        protected function wsPing(): void {
            // Bybit v5: send {"op":"ping"} as heartbeat
            if ($this->ws_client)
                $this->ws_client->send(json_encode(['op' => 'ping']));
            if ($this->ws_client_pub) {
                try { $this->ws_client_pub->send(json_encode(['op' => 'ping'])); } catch (\Throwable $_) {}
            }
        }

        protected function wsPong(string $payload = ''): void {
            if ($this->ws_client)
                $this->ws_client->send(json_encode(['op' => 'pong']));
        }

        // -------------------------------------------------------------------------
        // WebsockAPIEngine protocol — Bybit v5 private stream
        // -------------------------------------------------------------------------

        protected function wsRequiresAuth(): bool {
            return true;
        }

        protected function wsBuildAuthMessage(): string {
            // Bybit v5 WS auth: HMAC-SHA256("GET/realtime" + expires)
            // expires = current_ms + 10_000 (10 s into the future)
            $expires = intval(round(microtime(true) * 1000)) + 10000;
            $sig_str = 'GET/realtime' . $expires;
            $sig = hash_hmac('sha256', $sig_str, $this->api_secret_raw);
            return json_encode([
                'op'   => 'auth',
                'args' => [$this->apiKey, $expires, $sig],
            ]);
        }

        protected function wsSubscribeAll(): void {
            if (!$this->ws_authenticated) {
                $auth_msg = $this->wsBuildAuthMessage();
                if ($this->wsSend($auth_msg))
                    $this->LogMsg("~C93#WS_AUTH:~C00 auth message sent");
                return;
            }
            $msg = json_encode(['op' => 'subscribe', 'args' => ['order']]);
            $this->wsSend($msg);
            $this->LogMsg("~C93#WS_SUBSCRIBE:~C00 subscribed to order topic");
            $this->ws_subscribe_after = 0;
        }

        protected function wsDispatch(mixed $data): void {
            // Pong response
            if (isset($data->op) && $data->op === 'pong') {
                $this->ws_last_ping = time();
                return;
            }

            // Server-initiated ping — respond on the correct connection
            if (isset($data->op) && $data->op === 'ping') {
                $this->ws_last_ping = time();
                $target = $this->ws_dispatch_is_pub ? $this->ws_client_pub : $this->ws_client;
                if ($target) try { $target->send(json_encode(['op' => 'pong'])); } catch (\Throwable $_) {}
                return;
            }

            // Auth response: {"op":"auth","success":true}
            if (isset($data->op) && $data->op === 'auth') {
                if (!empty($data->success)) {
                    $this->ws_authenticated = true;
                    $this->LogMsg("~C92#WS_AUTH_OK:~C00 Bybit WS authenticated");
                    // Immediately subscribe after successful auth
                    $msg = json_encode(['op' => 'subscribe', 'args' => ['order']]);
                    $this->wsSend($msg);
                    $this->ws_subscribe_after = 0;
                } else {
                    $ret = $data->ret_msg ?? json_encode($data);
                    $this->LogMsg("~C91#WS_AUTH_FAIL:~C00 %s", $ret);
                    $this->wsReconnect('auth rejected');
                }
                return;
            }

            // Subscribe confirmation
            if (isset($data->op) && $data->op === 'subscribe') {
                $this->LogMsg("~C93#WS_SUB_CONFIRM:~C00 %s", json_encode($data));
                return;
            }

            // Order push: {"topic":"order","data":[...]}
            if (isset($data->topic) && $data->topic === 'order' && isset($data->data)) {
                foreach ((array)$data->data as $order)
                    if (is_object($order))
                        $this->wsOnOrderUpdate($order);
                return;
            }

            // Public tickers push: {"topic":"tickers.BTCUSDT","data":{"bid1Price":"...","ask1Price":"...","lastPrice":"...",...}}
            if (isset($data->topic) && str_starts_with($data->topic, 'tickers.') && isset($data->data)) {
                $symbol = substr($data->topic, 8); // strip "tickers."
                if (isset($this->pairs_map_rev[$symbol])) {
                    $pair_id = $this->pairs_map_rev[$symbol];
                    $d   = $data->data;
                    $bid = floatval($d->bid1Price ?? 0);
                    $ask = floatval($d->ask1Price ?? 0);
                    if ($bid > 0 && $ask > 0 && $ask >= $bid) {
                        $tinfo = &$this->pairs_info[$pair_id];
                        $tinfo->bid_price  = $bid;
                        $tinfo->ask_price  = $ask;
                        $tinfo->last_price = floatval($d->lastPrice ?? 0) ?: ($bid + $ask) / 2;
                        $tinfo->OnUpdate();
                        $this->ws_ticker_t = time();
                    }
                }
                return;
            }

            $topic = $data->topic ?? ($data->op ?? '');
            if ($topic !== '')
                $this->LogMsg("~C93#WS_UNKNOWN:~C00 topic=%s", $topic);
        }

        protected function wsOnExtraCycle(): void {
            if (!$this->ws_client_pub) return;

            // Drain public client frames (tickers)
            $t_start = microtime(true);
            do {
                try {
                    $raw = $this->ws_client_pub->receive();
                } catch (\Throwable $_) {
                    // WSSC throws on read timeout (10 ms) — same as private client; just stop draining
                    break;
                }
                if (!is_string($raw) || $raw === '') break;

                $this->ws_recv_packets++;
                $this->ws_recv_bytes  += strlen($raw);
                $this->ws_pub_packets++;
                $this->ws_last_data_t  = time();

                if (isset($raw[0]) && ('{' === $raw[0] || '[' === $raw[0])) {
                    $data = json_decode($raw, false);
                    if (is_object($data) || is_array($data)) {
                        $this->ws_dispatch_is_pub = true;
                        $this->wsDispatch($data);
                        $this->ws_dispatch_is_pub = false;
                    }
                }
            } while ((microtime(true) - $t_start) < 0.5);

            // Reconnect if public client actually disconnected
            if (!$this->ws_client_pub->isConnected()) {
                $this->ws_pub_connected = false;
                $this->LogMsg("~C91#WS_PUB_DROPPED:~C00 public stream disconnected — reconnecting");
                $pub_url = $this->wsNextEndpoint('public');
                if ($pub_url !== null) $this->wsConnectPublic($pub_url);
            }
        }

        private function wsOnOrderUpdate(\stdClass $order): void {
            $changed = $this->UpdateOrder($order);
            $symbol  = strval($order->symbol ?? '');
            $status  = strval($order->orderStatus ?? '');
            $this->LogMsg("~C94#WS_ORDER:~C00 %s [%s] status=%s filled=%.6f changed=%s",
                $symbol,
                $order->orderLinkId ?? '?',
                $status,
                floatval($order->cumExecQty ?? 0),
                $changed ? 'yes' : 'no');

            if ($changed && ($status === 'Filled' || $status === 'PartiallyFilled')) {
                if (isset($this->pairs_map_rev[$symbol]))
                    $this->wsMarkFilledPair($this->pairs_map_rev[$symbol]);
            }
        }


    }; // BybitEngine


    $bot_impl_class = 'BybitBOT';


    final class BybitBOT extends TradingCore {
        public function __construct() {
            global $mysqli;
            $this->impl_name = 'bybit_bot';
            parent::__construct();
            $this->trade_engine = new BybitEngine($this);
            $this->Initialize($mysqli);
        }
    };

?>
