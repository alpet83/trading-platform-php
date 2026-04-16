<?php

include_once(__DIR__.'/lib/common.php');
include_once(__DIR__.'/lib/db_tools.php');
require_once('trading_core.php');
require_once('rest_api_common.php');
require_once('ws_api_common.php');

define('SATOSHI_MULT', 0.00000001);
define('BITCOIN_TICKER', 'BTC-PERPETUAL');
define('ETHER_TICKER', 'ETH-PERPETUAL');

final class DeribitEngine extends WebsockAPIEngine {
    public const API_BASE_MAIN    = 'https://www.deribit.com';
    public const API_BASE_TESTNET = 'https://test.deribit.com';

    private $open_orders = array();
    private $loaded_data = array();
    private $clientId =  '';
    private $api_params = array();
    private $oauth_token = false;
    private $oauth_refresh_token = false;
    private $oauth_expires = 0;
    private $oauth_scope = '';
    private $instruments = array();

    private $history_by_instr = []; // map by pair
    private $open_by_instr = [];  // map by pair

    // -------------------------------------------------------------------------
    // WebSocket transport state (WSSC)
    // -------------------------------------------------------------------------
    private ?\WSSC\WebSocketClient $ws_client       = null;
    private string                  $ws_last_opcode  = 'text';
    private bool                    $ws_last_had_data = false;
    private int                     $ws_rpc_id       = 0;  // auto-incrementing JSON-RPC id

    public $eth_price = 0;
    public $platform_version = 'n/a';
    public $server_time  =  0;
    public $diver_time   = 0; // server to local time divergence

    public function __construct(object $core) {
        parent::__construct($core);
        $this->exchange = 'Deribit';
        $auth = $this->InitializeAPIKey([
            'env_prefix' => 'DBT',
            'file_key_path' => '.deribit.clid',
        ]);
        $this->clientId = strval($auth['key'] ?? '');
        $this->secretKey = $auth['secret'] ?? [];
        if (strval($auth['source'] ?? '') === 'env') {
            $this->LogMsg("#ENV: Used clientId {$this->clientId}");
        }

        $profile_name = trim((string)(getenv('DBT_PROFILE') ?: getenv('EXCHANGE_PROFILE_NAME') ?: 'testnet'));

        $profile_file = trim((string)(getenv('DBT_PROFILE_FILE') ?: getenv('EXCHANGE_PROFILE_FILE') ?: 'config/exchanges/deribit.yml'));
        $profile = $this->LoadExchangeProfile($profile_file, $profile_name, [
            'api_base' => self::API_BASE_MAIN,
        ]);

        $api_base = rtrim(trim((string)(getenv('DBT_API_BASE_URL') ?: ($profile['api_base'] ?? ''))), '/');
        if (!strlen($api_base)) {
            $api_base = self::API_BASE_MAIN;
        }

        $this->public_api  = $api_base;
        $this->private_api = $api_base;
        $profile_mark = !empty($profile['_profile_loaded']) ? strval($profile['_profile_name']) : 'legacy-env';
        $this->LogMsg('#ENV: Deribit API base %s, profile %s', $api_base, $profile_mark);

        $this->last_nonce = time_ms() * 11;
        $this->oauth_refresh_token = file_get_contents('.oauth_refresh_token');

        $this->ws_connect_type = 'private';
        $this->wsLoadConfig($profile);
    }

    public function Initialize() {
        parent::Initialize();
        $core      = $this->TradeCore();
        $cfg       = $core->configuration;
        $cfg_table = trim($cfg->config_table, '`');
        $env_profile = trim((string)(getenv('DBT_PROFILE') ?: getenv('EXCHANGE_PROFILE_NAME') ?: 'testnet'));
        if ($cfg_table !== '') {
            $core->Engine()->sqli()->try_query(
                "INSERT IGNORE INTO `$cfg_table` (param, value) VALUES ('exchange_profile', '$env_profile')"
            );
        }
        $profile_name = $cfg->GetValue('exchange_profile', null) ?? $env_profile;
        $this->active_profile = $profile_name;
        $profile_file = trim((string)(getenv('DBT_PROFILE_FILE') ?: getenv('EXCHANGE_PROFILE_FILE') ?: 'config/exchanges/deribit.yml'));
        $profile = $this->LoadExchangeProfile($profile_file, $profile_name, [
            'api_base' => self::API_BASE_MAIN,
        ]);
        $api_base = rtrim(trim((string)(getenv('DBT_API_BASE_URL') ?: ($profile['api_base'] ?? ''))), '/');
        if (!strlen($api_base)) $api_base = self::API_BASE_MAIN;
        $this->public_api  = $api_base;
        $this->private_api = $api_base;
        $this->LogMsg('#CFG: Deribit profile=%s, api=%s', $profile_name, $api_base);
        $this->wsLoadConfig($profile);
        if ($this->ws_active)
            $this->wsReconnect('initial');

        // Upgrade any remaining tables with integer order_no (e.g. mm_limit, mm_exec)
        // created by MarketMaker via OrdersBlock which bypasses CreateOrderList.
        $prefix = strtolower($this->exchange) . '__';
        foreach (['mm_limit', 'mm_exec', 'mm_asks', 'mm_bids', 'archive_orders', 'lost_orders',
                  'matched_orders', 'pending_orders', 'mixed_orders', 'other_orders'] as $tbl) {
            $this->UpgradeOrderNoColumnIfNeeded($prefix . $tbl);
        }
    }

    protected function WarmupOptionalOrderTables(): void {
        // Force-create optional MM order tables even when MM is disabled,
        // so schema patching remains deterministic across deployments.
        foreach (['mm_exec', 'mm_limit', 'mm_asks', 'mm_bids'] as $name) {
            try {
                $probe = new OrdersBlock($this, $name, false);
                unset($probe);
            } catch (Throwable $e) {
                $this->LogError("~C91#WARN(WarmupOptionalOrderTables):~C00 failed warmup %s: %s", $name, $e->getMessage());
            }
        }
    }

    public function Cleanup() {
        if ($this->oauth_token) {
            $this->RequestPrivateAPI('/api/v2/private/logout', '');
        }
        $this->wsClose();
        parent::Cleanup();
    }

    public function CreateOrderList(string $name, bool $fixed = false): OrderList {
        $list = parent::CreateOrderList($name, $fixed);
        $this->UpgradeOrderNoColumnIfNeeded($list->TableName());
        return $list;
    }

    private function UpgradeOrderNoColumnIfNeeded(string $table_name): void {
        $order_tables = ['archive_orders', 'lost_orders', 'matched_orders', 'pending_orders',
                         'mixed_orders', 'other_orders', 'mm_limit', 'mm_exec', 'mm_asks', 'mm_bids'];
        $base_name = strtolower((string)preg_replace('/^[a-z0-9]+__/', '', $table_name));
        if (!in_array($base_name, $order_tables, true)) {
            return;
        }

        $mysqli = $this->sqli();
        $res = $mysqli->try_query("SHOW COLUMNS FROM `$table_name` LIKE 'order_no';");
        if (!$res) {
            $this->LogError("~C91#WARN(OrderNoUpgrade):~C00 can't inspect order_no in %s: %s", $table_name, $mysqli->error);
            return;
        }

        $row = $res->fetch_array(MYSQLI_ASSOC);
        if (!is_array($row) || !isset($row['Type'])) {
            return;
        }

        $ctype = strtolower((string)$row['Type']);
        if (false !== strpos($ctype, 'varchar(40)')) {
            return;
        }

        if (false === strpos($ctype, 'bigint') && false === strpos($ctype, 'int')) {
            $this->LogMsg("~C93#INFO(OrderNoUpgrade):~C00 skip %s.order_no type %s", $table_name, $ctype);
            return;
        }

        $this->LogMsg("~C91#WARN(OrderNoUpgrade):~C00 upgrading %s.order_no from %s to VARCHAR(40)", $table_name, $ctype);
        if (!$mysqli->try_query("ALTER TABLE `$table_name` MODIFY COLUMN `order_no` VARCHAR(40) NOT NULL DEFAULT '';")) {
            $this->LogError("~C91#ERROR(OrderNoUpgrade):~C00 failed to alter %s.order_no: %s", $table_name, $mysqli->error);
            return;
        }

        $mysqli->try_query("ALTER TABLE `$table_name` DROP INDEX `order_no`;");
        $mysqli->try_query("ALTER TABLE `$table_name` ADD INDEX `order_no` (`order_no`);");
    }

    // =========================================================================
    // WebSocket transport — WSSC (arthurkushman/php-wss)
    // =========================================================================

    protected function wsConnect(string $url): bool {
        try {
            $config = new \WSSC\Components\ClientConfig();
            $config->setTimeout(10);
            $client = new \WSSC\WebSocketClient($url, $config);
            if ($client->isConnected()) {
                $this->ws_client = $client;
                $this->ws_client->setTimeout(0, 10000); // 10 ms read timeout
                $this->ws_last_had_data = false;
                $this->wsOnConnected();
                return true;
            }
        } catch (\Throwable $E) {
            $this->LogMsg("~C91#WS_CONNECT_FAIL:~C00 %s", $E->getMessage());
        }
        $this->ws_client = null;
        return false;
    }

    protected function wsClose(): void {
        $this->ws_client = null;
    }

    protected function wsSend(string $data): bool {
        if (!$this->ws_client) {
            return false;
        }
        try {
            $this->ws_client->send($data);
            return true;
        } catch (\Throwable $E) {
            $this->LogMsg("~C91#WS_SEND_FAIL:~C00 %s", $E->getMessage());
            return false;
        }
    }

    protected function wsReceive(): ?string {
        if (!$this->ws_client) {
            return null;
        }
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
        return ($this->ws_client && $this->ws_last_had_data) ? 1 : 0;
    }

    protected function wsIsConnected(): bool {
        return $this->ws_client !== null && $this->ws_client->isConnected();
    }

    protected function wsPing(): void {
        // Deribit application-layer ping via JSON-RPC public/test
        $this->wsSendRpc('public/test', [], false);
    }

    protected function wsPong(string $payload = ''): void {
        // WS-level pong is handled by WSSC automatically; nothing extra needed
    }

    // =========================================================================
    // WebSocket protocol — Deribit JSON-RPC 2.0
    // =========================================================================

    protected function wsRequiresAuth(): bool {
        return true;
    }

    /**
     * Build JSON-RPC auth message using client_signature method (same as REST).
     */
    protected function wsBuildAuthMessage(): string {
        $mts = time_ms();
        $nonce = strval(++$this->ws_rpc_id) . '_auth';
        $sign_msg = "$mts\n$nonce\n\n";
        $secret = str_replace("\n", '', implode('', $this->secretKey));
        $secret = trim(base64_decode($secret));
        $signature = hash_hmac('sha256', $sign_msg, $secret);
        return json_encode([
            'jsonrpc' => '2.0',
            'id'      => ++$this->ws_rpc_id,
            'method'  => 'public/auth',
            'params'  => [
                'grant_type' => 'client_signature',
                'client_id'  => $this->clientId,
                'timestamp'  => $mts,
                'nonce'      => $nonce,
                'signature'  => $signature,
            ],
        ]);
    }

    protected function wsSubscribeAll(): void {
        if (!$this->ws_authenticated) {
            $auth_msg = $this->wsBuildAuthMessage();
            if ($this->wsSend($auth_msg)) {
                $this->LogMsg("~C93#WS_AUTH:~C00 Deribit WS auth message sent");
            }
            return;
        }

        // Set server-side heartbeat (30 s interval)
        $this->wsSendRpc('public/set_heartbeat', ['interval' => 30], false);

        // Subscribe to all private order updates (any instrument)
        $this->wsSendRpc('private/subscribe', [
            'channels' => ['user.orders.any.any.raw'],
        ]);

        // Subscribe to ticker for each known instrument
        $ticker_channels = [];
        foreach ($this->instruments as $pair_id => $instr) {
            $ticker_channels[] = 'ticker.' . $instr->instrument_name . '.100ms';
        }
        if (!empty($ticker_channels)) {
            $this->wsSendRpc('public/subscribe', ['channels' => $ticker_channels], false);
        }

        $this->LogMsg("~C93#WS_SUBSCRIBE:~C00 Deribit WS subscribed — orders + %d tickers", count($ticker_channels));
        $this->ws_subscribe_after = 0;
    }

    /**
     * Helper: send a JSON-RPC 2.0 request over WebSocket.
     * @param bool $private  If true, sends as private method (auth required).
     */
    private function wsSendRpc(string $method, array $params = [], bool $private = true): bool {
        if ($private && !$this->ws_authenticated) {
            $this->wsEnqueueMessage(json_encode([
                'jsonrpc' => '2.0',
                'id'      => ++$this->ws_rpc_id,
                'method'  => $method,
                'params'  => (object)$params,
            ]));
            return false;
        }
        return $this->wsSend(json_encode([
            'jsonrpc' => '2.0',
            'id'      => ++$this->ws_rpc_id,
            'method'  => $method,
            'params'  => (object)$params,
        ]));
    }

    protected function wsDispatch(mixed $data): void {
        if (!is_object($data)) {
            return;
        }

        // JSON-RPC response frame (has 'id')
        if (isset($data->id)) {
            $this->wsHandleRpcResponse($data);
            return;
        }

        // Push notification frame (has 'method')
        if (isset($data->method)) {
            $this->wsHandleNotification($data);
        }
    }

    private function wsHandleRpcResponse(\stdClass $data): void {
        // Error response
        if (isset($data->error)) {
            $code = $data->error->code ?? 0;
            $msg  = $data->error->message ?? json_encode($data->error);
            // Auth failure
            if (in_array($code, [13004, 13010, 13011], true)) {
                $this->LogMsg("~C91#WS_AUTH_FAIL:~C00 [%d] %s", $code, $msg);
                $this->wsReconnect('auth rejected');
                return;
            }
            $this->LogMsg("~C91#WS_RPC_ERR:~C00 id=%s [%d] %s", $data->id ?? '?', $code, $msg);
            return;
        }

        if (!isset($data->result)) {
            return;
        }
        $result = $data->result;

        // Auth success: result has access_token
        if (is_object($result) && isset($result->access_token)) {
            $this->ws_authenticated = true;
            $this->oauth_token = $result->access_token;  // reuse REST token field
            $token_short = substr($this->oauth_token, 0, 10) . '...';
            $this->LogMsg("~C92#WS_AUTH_OK:~C00 Deribit WS authenticated, token [%s]", $token_short);
            // Start processing pending outbound messages and subscribe
            $this->wsProcessQueue();
            $msg = json_encode([
                'jsonrpc' => '2.0', 'id' => ++$this->ws_rpc_id,
                'method'  => 'private/subscribe',
                'params'  => (object)['channels' => ['user.orders.any.any.raw']],
            ]);
            $this->wsSend($msg);
            // Set heartbeat and subscribe tickers
            $this->wsSendRpc('public/set_heartbeat', ['interval' => 30], false);
            $ticker_channels = [];
            foreach ($this->instruments as $pair_id => $instr) {
                $ticker_channels[] = 'ticker.' . $instr->instrument_name . '.100ms';
            }
            if (!empty($ticker_channels)) {
                $this->wsSendRpc('public/subscribe', ['channels' => $ticker_channels], false);
            }
            $this->ws_subscribe_after = 0;
            $this->LogMsg("~C93#WS_SUBSCRIBE:~C00 Deribit WS subscribed — orders + %d tickers", count($ticker_channels));
            return;
        }

        // Subscribe confirmation: result is array of channel names
        if (is_array($result)) {
            $this->LogMsg("~C93#WS_SUB_CONFIRM:~C00 channels: %s", implode(', ', $result));
            return;
        }

        // set_heartbeat / test confirm: result == 'ok' or similar
        if (is_string($result)) {
            // silently ok
            return;
        }
    }

    private function wsHandleNotification(\stdClass $data): void {
        $method = strval($data->method);
        $params = $data->params ?? null;

        // Server heartbeat — must respond with public/test
        if ($method === 'heartbeat') {
            $type = is_object($params) ? ($params->type ?? '') : '';
            if ($type === 'test_request') {
                $this->wsSend(json_encode([
                    'jsonrpc' => '2.0',
                    'id'      => ++$this->ws_rpc_id,
                    'method'  => 'public/test',
                    'params'  => (object)[],
                ]));
                $this->ws_last_ping = time();
            }
            return;
        }

        // Subscription push: {"method":"subscription","params":{"channel":"...","data":{...}}}
        if ($method === 'subscription' && is_object($params) && isset($params->channel)) {
            $channel = strval($params->channel);
            $channel_data = $params->data ?? null;

            // Order update
            if (str_starts_with($channel, 'user.orders.')) {
                if (is_array($channel_data)) {
                    foreach ($channel_data as $order) {
                        if (is_object($order)) {
                            $this->wsOnOrderUpdate($order);
                        }
                    }
                } elseif (is_object($channel_data)) {
                    $this->wsOnOrderUpdate($channel_data);
                }
                return;
            }

            // Ticker update
            if (str_starts_with($channel, 'ticker.') && is_object($channel_data)) {
                $this->wsOnTicker($channel_data);
                return;
            }
        }

        $this->LogMsg("~C93#WS_UNKNOWN:~C00 method=%s", $method);
    }

    private function wsOnOrderUpdate(\stdClass $order): void {
        if (!isset($order->order_id)) {
            $this->LogMsg("~C91#WS_ORDER_SKIP:~C00 order frame missing order_id");
            return;
        }
        if (!isset($order->order_state)) {
            return;  // partial field update, not actionable
        }

        $changed = $this->UpdateOrder($order->order_id, $order);
        $instr   = strval($order->instrument_name ?? '');
        $status  = $this->DecodeOrderStatus(strval($order->order_state));
        $filled  = floatval($order->filled_amount ?? 0);

        $this->LogMsg(
            "~C94#WS_ORDER:~C00 %s [%s] status=%s filled=%.6f changed=%s",
            $instr,
            $order->order_id,
            $status,
            $filled,
            $changed ? 'yes' : 'no'
        );

        if ($changed && ($status === 'filled' || $status === 'partially_filled')) {
            $pmap = $this->pairs_map_rev ?? [];
            if (isset($pmap[$instr])) {
                $this->wsMarkFilledPair($pmap[$instr]);
            }
        }
    }

    private function wsOnTicker(\stdClass $obj): void {
        $instr = strval($obj->instrument_name ?? '');
        if (!$instr) {
            return;
        }

        $pmap = $this->pairs_map_rev ?? [];
        if (!isset($pmap[$instr])) {
            return;
        }
        $pair_id = $pmap[$instr];

        $tinfo = $this->TickerInfo($pair_id);
        if (!$tinfo) {
            return;
        }

        if (isset($obj->best_bid_price)) {
            $tinfo->bid_price  = (float)$obj->best_bid_price;
        }
        if (isset($obj->best_ask_price)) {
            $tinfo->ask_price  = (float)$obj->best_ask_price;
        }
        if (isset($obj->last_price)) {
            $tinfo->last_price = (float)$obj->last_price;
        }
        if (isset($obj->index_price)) {
            $tinfo->fair_price = $tinfo->index_price = (float)$obj->index_price;
        }
        if (isset($obj->stats->volume_usd)) {
            $tinfo->daily_vol = (float)$obj->stats->volume_usd;
        }

        $ts = isset($obj->timestamp) ? (int)$obj->timestamp : 0;
        $tinfo->OnUpdate($ts);

        if ($instr === BITCOIN_TICKER && $tinfo->last_price > 0) {
            $this->set_btc_price($tinfo->last_price);
        }
        if ($instr === ETHER_TICKER   && $tinfo->last_price > 0) {
            $this->eth_price = $tinfo->last_price;
        }
    }

    public function CheckAPIKeyRights(): bool {
        if ($this->oauth_refresh_token && time() + 60 >= $this->oauth_expires) {
            $this->AuthRefresh();
        }

        if (!$this->oauth_token) {
            $this->AuthSession();
        }

        $scope = strtolower(trim(strval($this->oauth_scope)));
        if (!strlen($scope)) {
            return true;
        }

        if (false !== strpos($scope, 'trade:read_write') || false !== strpos($scope, 'trade:readwrite')) {
            return true;
        }

        if (false !== strpos($scope, 'trade:read') || false !== strpos($scope, 'trade:none')) {
            $this->LogMsg('~C93#AUTH_LIMIT:~C00 Deribit token scope is read-only: %s', $this->oauth_scope);
            return false;
        }

        return true;
    }

    private function ProcessError($context, $json, $res): bool {
        $core = $this->TradeCore();
        if (is_object($res) && isset($res->error)) {
            $this->last_error = $res->error->message;
            $this->last_error_code = $res->error->code;

            if (13009 == $res->error->code && isset($res->error->data) && isset($res->error->data->reason) && $res->error->data->reason == 'invalid_token') {
                $core->LogError("~C91#OAUTH_BREAKS:~C00 [%s] token %s is not valid (expired or revoked)", $this->AuthContext(), $this->oauth_token);
                $this->oauth_token = false;
            }
            return true;
        } else {
            $this->last_error = sprintf($context, $json);
            $this->last_error_code = -324;
            return false;
        }
    }

    public function LoadInstruments($currency = false, $kind = 'future') {
        $core = $this->TradeCore();
        $this->last_error = '';
        $params ['kind'] = $kind;
        if ($currency) {
            $params['currency'] = $currency;
        }

        $json = $this->RequestPublicAPI('/api/v2/public/get_instruments', $params); // TODO: for all currencies
        $res = json_decode($json);


        $ignored = array();

        $pmap = $this->pairs_map_rev;
        if (!is_array($pmap) || 0 == count($pmap)) {
            $core->LogError("~C91#ERROR:~C00 pairs_map_rev not initialized, LoadTickers can't work");
            $this->last_error = "pairs_map_rev not init.";
            return false;
        }

        $this->LogMsg(" acceptable instruments: %s ", implode(',', array_keys($pmap)));

        $list = array();
        if (is_object($res) && is_array($res->result)) {
            $list = $res->result;
        } else {
            $core->LogError("~C91#FAILED:~C00 public API instruments/active returned %s,\n list: %s", $json, var_export($res));
            $this->ProcessError("instrument/active returned %s", $json, $list);
            return false;
        }

        $loaded = 0;
        foreach ($list as $obj) {
            $sym = $obj->instrument_name;
            if (!isset($pmap[$sym])) {
                $ignored [] = $sym;
                continue;
            }
            $pair_id = $pmap[$sym];
            $this->instruments[$pair_id] = $obj; // save full info
            $tinfo = $this->TickerInfo($pair_id);


            $tinfo->lot_size   = $obj->min_trade_amount;
            $tinfo->tick_size  = $obj->tick_size;
            $tinfo->base_currency = $obj->base_currency;
            $pp = -log10($tinfo->tick_size);
            if ($pp - floor($pp) > 0) {
                $pp = floor($pp) + 1;
            } // round up always

            $tinfo->price_precision = $tinfo->tick_size < 1 ? $pp : 0;
            $tinfo->contract_size = $obj->contract_size;
            if (isset($obj->future_type)) {
                $tinfo->is_inverse = ('reversed' == $obj->future_type);
                $tinfo->is_linear = ('linear' == $obj->future_type);
            }

            if (tp_debug_mode_enabled()) {
                file_put_contents("data/$sym.info", print_r($obj, true));
            }

            $loaded++;
        } // foreach

        $this->LogMsg(" ignored instruments: %s ", implode(',', $ignored));
    }

    public function LoadTickers() {
        parent::LoadTickers();

        // Drain WS buffer first — avoids redundant REST calls when WS is healthy
        $this->drainWsBuffer();
        if ($this->wsTickersFresh(30)) {
            return count($this->instruments);
        }

        if (0 == $this->PlatformStatus()) {
            return 0;
        }

        $core = $this->TradeCore();

        $this->LogMsg(" trying acquire active instrument list from public API...");
        if (0 == count($this->instruments)) {
            $this->LoadInstruments();
        }
        $updated = 0;

        // Loading real-time data
        foreach ($this->instruments as $pair_id => $instr_info) {
            $instr_name = $instr_info->instrument_name;
            $json = $this->RequestPublicAPI('/api/v2/public/ticker', "instrument_name=$instr_name");
            $obj = json_decode($json);
            if (!is_object($obj) || !isset($obj->result)) {
                $core->LogError("API request `ticker`, failed decode response %s ", $json);
                continue;
            }
            if (tp_debug_mode_enabled()) {
                file_put_contents("data/$instr_name.last", print_r($obj, true));
            }
            $obj = $obj->result;
            $tinfo = $this->TickerInfo($pair_id);

            $tinfo->bid_price  = $obj->best_bid_price;
            $tinfo->ask_price  = $obj->best_ask_price;
            $tinfo->last_price = $obj->last_price;
            $tinfo->fair_price = $tinfo->index_price = $obj->index_price;

            if (isset($obj->quote_currency)) {
                $tinfo->quote_currency = $obj->quote_currency;
            }

            if (isset($obj->settlement_currency)) {
                $tinfo->settl_currency = $obj->settlement_currency;
            }

            if (isset($obj->settlement_price)) {
                $tinfo->settl_price = $obj->settlement_price;
            }
            if (isset($obj->stats) && isset($obj->stats->volume_usd)) {
                $tinfo->daily_vol = $obj->stats->volume_usd;
            }

            $tinfo->OnUpdate($obj->timestamp);
            if (BITCOIN_TICKER === $instr_name) {
                $this->set_btc_price($obj->last_price);
            }
            if (ETHER_TICKER == $instr_name) {
                $this->eth_price = $obj->last_price;
            }

            $updated++;
        }

        return $updated;
    } // LoadTicker

    public function AmountToQty(int $pair_id, float $price, float $btc_price, float $value) { // from Amount to real ammount
        if (0 == $value || 0 == $price) {
            return $value;
        }

        $this->last_error = '';
        $tinfo = $this->TickerInfo($pair_id);

        if (!$tinfo) {
            $this->last_error = "no ticker info for $pair_id";
            return 0;
        }
        if ($tinfo->is_linear) {
            return $value;
        }

        $core = $this->TradeCore();
        if ($tinfo->is_inverse && $price > 0) {
            return $value / $price;
        }  // 1900 / 1900 = 1

        if (0 == $price) {
            $this->last_error = " price == 0, pair_id = $pair_id";
            return 0;
        }
        $this->last_error = sprintf('not available method for %s ', $tinfo->pair);
        return 0.000000001;
    }
    public function QtyToAmount(int $pair_id, float $price, float $btc_price, float $value) { // from real amount to Amount
        $tinfo = $this->TickerInfo($pair_id);
        if (!$tinfo) {
            return 0;
        }

        if ($tinfo->is_linear) {
            return $value;
        }

        if ($tinfo->is_inverse) {
            return floor($value * $price);
        }
        return $value;
    }

    public function LimitAmountByCost(int $pair_id, float $amount, float $max_cost, bool $notify = true) {
        $tinfo = $this->TickerInfo($pair_id);
        $core = $this->TradeCore();
        if (!$tinfo || 0 == $tinfo->last_price) {
            $pair = $core->FindPair($pair_id);
            $this->last_error = "#FATAL: no ticker for pair_id $pair_id [$pair]";
            $this->LogMsg("~C91#WARN(LimitAmountByCost):~C00 Failed get info for pair %d %s", $pair_id, $pair);
            return 0;
        }

        $price = $tinfo->last_price;
        $qty = $this->AmountToQty($pair_id, $price, $this->get_btc_price(), $amount);
        $cost = $qty * $price;
        $result = $amount;

        if ($cost > $max_cost) {
            $limited = $max_cost / $price;
            if (is_infinite($limited)) {
                throw new Exception("LimitAmountByCost failed calculate amount from $max_cost / $price");
            }

            $result = $this->QtyToAmount($pair_id, $price, $this->get_btc_price(), $limited);
            $result = $tinfo->FormatAmount($result);
            $this->LogMsg("~C91#WARN:~C00 for %s due cost %.1f$ > max limit %d, quantity reduced from %s to %s, price = %10f, amount = %.5f, limited = %.5f", $tinfo->pair, $cost, $max_cost, $qty, $result, $price, $amount, $limited);
        }
        return $result;
    }

    private function NormalizeOrderAmount(TickerInfo $tinfo, float $amount): float {
        $step = floatval($tinfo->lot_size);
        if ($step <= 0) {
            return floatval($tinfo->FormatAmount($amount));
        }

        $sign = $amount < 0 ? -1 : 1;
        $abs_amount = abs($amount);
        $eps = $step / 1000000;
        $normalized = floor(($abs_amount + $eps) / $step) * $step;
        if ($normalized < $step) {
            return 0;
        }

        return floatval($tinfo->FormatAmount($normalized * $sign));
    }

    private function NormalizeDisplayAmount(TickerInfo $tinfo, mixed $hidden, float $amount): ?float {
        if (is_bool($hidden)) {
            return null;
        }

        if (!is_numeric($hidden)) {
            return null;
        }

        $hidden_amount = floatval($hidden);
        if ($hidden_amount <= 1) {
            return null;
        }

        $display_amount = $this->NormalizeOrderAmount($tinfo, $hidden_amount);
        $order_amount = $this->NormalizeOrderAmount($tinfo, $amount);
        if ($display_amount <= 0 || $order_amount <= 0 || $display_amount >= $order_amount) {
            return null;
        }
        return $display_amount;
    }

    public function NativeAmount(int $pair_id, float $amount, float $ref_price, float $btc_price = 0) {
        return $this->QtyToAmount($pair_id, $ref_price, $btc_price, $amount); // used values fixed after position feed changes
    }

    private function DecodeOrderStatus($st) {
        $st = strtolower($st);
        $st = str_ireplace('partiallyfilled', 'partially_filled', $st);
        $st = str_ireplace('open', 'active', $st);
        return $st;
    }

    private function FormatOrderNo(string $order_no, string $base_currency) {
        if ('-' == $order_no[0] || is_numeric($order_no) ||
            !str_contains($order_no, $base_currency)) {
            $order_no = ltrim($order_no, '-'); // remove excess -
            return "{$base_currency}-$order_no";
        }
        return $order_no;
    }

    private function UpdateOrder($target, $order = false) {
        $core = $this->TradeCore();
        if (!$target) {
            throw new Exception("FATAL: UpdateOrder(Target == NULL)!");
        }

        $info = $target;
        $pending = $this->MixOrderList('pending,market_maker');
        if (is_numeric($target)) {
            if (isset($pending[$target])) {
                $info = $pending[$target];
            } else {
                $info = $this->FindOrder($target, 0, true);
            }

            if (is_null($info)) {
                $this->LogMsg("~C91#WARN(UpdateOrder):~C00 order not found for #%d", $target);
                return null;
            }
        }


        if (!is_object($info)) {
            $core->LogError("~C91#ERROR(UpdateOrder):~C00 invalid 'target' parameter used %s", var_export($target));
            var_dump($info);
            return null;
        }


        if (!is_object($order)) {
            $order = $this->LoadSingleOrder($info);
            if (!is_object($order) || !property_exists($order, 'order_id')) {
                return null;
            }
        } // if false == order

        // filling dynamic fields here
        $info->price = $order->price;
        $info->avg_price = $order->average_price;
        $ti = $info->ticker_info;
        $info->order_no = $this->FormatOrderNo($order->order_id, $ti->base_currency);

        $st = $this->DecodeOrderStatus($order->order_state);
        $mlast = ('filled' == $st) ? $order->amount : $order->filled_amount; // may filled_amount can be incorrect?
        $info->matched = max($info->matched, $mlast); // prevent set status as partally_filled
        $info->status = $st;
        $info->checked = date_ms(SQL_TIMESTAMP3);
        $utm = $order->last_update_timestamp;
        $uts = date_ms(SQL_TIMESTAMP3, $utm);
        if ($uts > $info->updated) {
            $info->updated = $uts;
            $this->updated_orders[$info->id] = $info;
            if ($info->batch_id > 0) {
                $core->LogOrder("~C93#DBG(UpdateOrder):~C00 order %s updated time %s from %s", strval($info), $uts, json_encode($order));
            }
        }
        // $core->LogOrder("~C95#DBG(UpdateOrder):~C00 order %s time not updated, %s <= %s", strval($info), $uts, $info->updated);
        $this->DispatchOrder($info);
        $info->OnUpdate();
        return $info;
    } // UpdateOrder

    private function LoadSingleOrder(OrderInfo $info): mixed {
        $core = $this->TradeCore();
        $json = '';
        if (!isset($core->pairs_map[$info->pair_id])) {
            $core->LogError("#FAILED(LoadSingleOrder): not entry in pairs_map for %s", strval($info));
            return null;
        }
        $pair = $core->pairs_map[$info->pair_id];
        $tinfo = $this->TickerInfo($info->pair_id);
        $label = $this->OrderLabel($info);
        $params = [];
        if (strlen($info->order_no) >= 5) {
            if (is_numeric($info->order_no)) {
                $params['order_id'] = "{$info->quote_currency}-{$info->order_no}";
            } else {
                $params['order_id'] = $info->order_no;
            }
            $json = $this->RequestPrivateAPI('/api/v2/private/get_order_state', $params, 'GET');
        } else {
            $params['label'] = $label;
            $params['currency'] = $tinfo->quote_currency;
            $json = $this->RequestPrivateAPI('/api/v2/private/get_order_state_by_label', $params, 'GET');
        }
        $res = json_decode($json);

        if (!is_object($res) || !property_exists($res, 'result')) {
            $params = ['instrument_name' => $pair, 'count' => 100];
            $eps = ['get_open_orders' => 'open_by_instr', 'get_order_history' => 'history_by_instr'];
            $this->LogMsg("~C91#PERF_WARN:~C00 trying scan for %s, currency %s in history by instrument", strval($info), $tinfo->quote_currency);
            foreach ($eps as $ep => $map_name) {
                $json = '';
                if (!isset($this->$map_name[$pair])) { // lookup cache
                    $json = $this->RequestPrivateAPI("/api/v2/private/{$ep}_by_instrument", $params, 'GET');
                    $res = json_decode($json);
                    $this->$map_name[$pair] = $res;
                } else {
                    $res = $this->$map_name[$pair];
                }

                if (is_object($res) && property_exists($res, 'result') && is_array($res->result)) {
                    foreach ($res->result as $rec) {
                        if ($rec->label == $label) {
                            $this->LogMsg(" ~C94 #FOUND:~C00 source for order in %s: %s", $map_name, json_encode($rec));
                            return $rec;
                        }
                    }
                    $this->LogMsg("  ~C94 #NOT_FOUND:~C00 by API retrieved %d orders for $ep  %s ", count($res->result), $pair);
                } elseif (property_exists($res, 'error') && strlen($json) > 0) {
                    $this->ProcessError('LoadSingleOrder', $json, $res);
                }
            }
            if ('lost' == $info->status) {
                if ($info->OnError('load') > 3) {
                    $info->status = 'rejected';
                    $this->DispatchOrder($info);
                }
                return null;
            }
            if (property_exists($res, 'error')) {
                $this->ProcessError('LoadSingleOrder', $json, $res);
            }
            $core->LogError("~C91#FAILED(LoadSingleOrder):~C00 get_order_history[by_instrument] returned %s, request %s", $json, $this->last_request);
            $info->status = 'lost';
            $info->Register($this->archive_orders);
            return null;
        }

        if (!is_object($res) || !property_exists($res, 'result')) {
            if ('lost' == $info->status) {
                return null;
            }
            $core->LogError("~C91#FAILED(LoadSingleOrder):~C00 get_order_state[by_label] returned %s, request %s", $json, $this->last_request);
            if (property_exists($res, 'error')) {
                $this->ProcessError('LoadSingleOrder', $json, $res);
            } elseif (is_array($res->result) && 0 == count($res->result)) {
                $info->status = 'lost';
                $info->Register($this->archive_orders);
            }
            return null;
        }
        $order = $res->result;
        if (is_array($res->result) && count($res->result) > 0) {
            $order = $res->result[0];
        }

        if (is_array($order)) {
            $sp = json_encode($params);
            if (count($order) > 0) {
                $core->LogError("~C91#FAILED(LoadSingleOrder):~C00 Returned for $sp: $json");
            } else {
                $core->LogError("~C91#FAILED(LoadSingleOrder):~C00 order lost $sp, result: $json");
                $info->Register($this->archive_orders);
            }
            return null;
        }
        if (!property_exists($order, 'order_state')) {
            $core->LogError("~C91#FAILED(LoadSingleOrder):~C00 invalid order record %s", $json);
            return null;
        }
        return $order;
    }


    private function LoadOpenOrders(mixed $currency = false, string $kind = 'future') {
        $json = '?';
        $params = ['kind' => $kind];
        $path = '/api/v2/private/get_open_orders';
        if ($currency) {
            $path = '/api/v2/private/get_open_orders_by_currency';
            $params['currency'] = $currency;
        }
        $json = $this->RequestPrivateAPI($path, $params, 'GET');

        $res = json_decode($json);
        if (is_object($res) && isset($res->result) && is_array($res->result)) {
            $res = $res->result;
        } else {
            $this->last_error = 'API private/orders returned $json';
            return -1;
        }

        if (false === $currency) {
            $currency = 'all';
        }

        if (tp_debug_mode_enabled()) {
            file_put_contents("data/$kind-open_orders-$currency.json", $json);
        }

        $active = $this->MixOrderList('pending,market_maker');
        $loaded = 0;
        $pmap = $this->pairs_map_rev;

        foreach ($res as $order) {
            if (is_object($order)) {
                $instr_name = $order->instrument_name;
                if (!isset($pmap[$instr_name])) {
                    continue;
                }
                $m = array();
                list($account, $id) = sscanf($order->label, "%d:%d");
                if ($account == $this->account_id && $id > 0 && isset($active[$id])) {
                    $loaded++;
                    $this->open_orders [$id] = $this->UpdateOrder($id, $order);
                }
            }
        }
        return $loaded;
    }

    private function OrderLabel($info) {
        return "{$this->account_id}:{$info->id}:{$info->batch_id}";
    }

    public function LoadOrders(bool $force_all = false) {
        $this->open_orders = [];
        $loaded = 0;
        $this->history_by_instr = [];
        $this->open_by_instr = [];
        $active = $this->MixOrderList('pending,market_maker,lost');

        $this->LoadOpenOrders('BTC');
        $this->LoadOpenOrders('ETH');
        // TODO: processing completed orders
        $core = $this->TradeCore();

        $lost_count = 0;
        foreach ($active as $oinfo) {
            if (isset($this->open_orders[$oinfo->id])) {
                continue;
            } // OK, is updated
            if ($oinfo->error_map['load'] >= 10) {
                continue;
            }

            if ($oinfo->status == 'lost' && strlen($oinfo->order_no) < 5) {
                $lost_count++;
                if ($lost_count >= 5) {
                    continue;
                } // не пытаться все потеряшки обновить за раз
            }

            if ($this->UpdateOrder($oinfo, false)) { // update with request
                $loaded++;
            } else {
                $elps = time_ms() - strtotime_ms($oinfo->checked);
                $elps /= 1000;
                if (!$oinfo->IsFixed() && 'lost' !== $oinfo->status) {
                    $core->LogOrder("~C91#ORDER_LOST:~C00 %s was not checked %.1f seconds, not retrieved from API. Marked as lost", strval($oinfo), $elps);
                    $oinfo->status = 'lost';
                }
                $this->DispatchOrder($oinfo);
            }
        }

        return $loaded;
    }

    private function GetPositions($currency, $kind = 'future') {
        $core = $this->TradeCore();
        $json = $this->RequestPrivateAPI('/api/v2/private/get_positions', array('currency' => $currency, 'kind' => $kind), 'GET');
        $obj = json_decode($json);
        $pmap = $this->pairs_map_rev;

        $file_prefix = "{$this->exchange}@{$this->account_id} $currency $kind ";

        if (is_object($obj) && isset($obj->result) && is_array($obj->result)) {
            $dump = $obj->result;
            if (1 == count($dump)) {
                $dump = $dump[0];
            } // single is typical
            if (tp_debug_mode_enabled()) {
                file_put_contents('data/'.$file_prefix."positions.json", json_encode($dump));
            }
            $result = $core->current_pos;

            foreach ($obj->result as $pos) {
                if (is_object($pos) && isset($pos->instrument_name)) {
                    $iname = $pos->instrument_name;
                    if (!isset($pmap[$iname])) {
                        continue;
                    }
                    $pair_id = $pmap[$iname];
                    //
                    $rpos = $result[$pair_id];
                    if ($rpos->amount == $pos->size) {
                        continue;
                    }
                    $rpos->avg_price = $pos->average_price;
                    $rpos->set_amount($pos->size, $rpos->avg_price, $this->btc_price);
                    $rpos->ref_qty = $pos->size_currency;
                    $this->LogMsg("~C97#POS_CHANGED:~C00 for $iname %s", strval($rpos));
                    if (tp_debug_mode_enabled()) {
                        file_add_contents("data/$file_prefix-$iname-pos.log", strval($rpos)."\n");
                    }
                    // $result[$pair_id] = $rpos;
                }
            }
            $core->current_pos = $result;
            return count($result);
        } else {
            $core->LogError("~C91#FALED:~C00 private API positions returned %s", $json);
            return -1;
        }
    }

    private function GetAccountSummary($currency, &$map) {
        $this->last_error = '';
        $core = $this->TradeCore();
        $file_prefix = "{$this->exchange}@{$this->account_id} $currency";
        $json = $this->RequestPrivateAPI('/api/v2/private/get_account_summary', array('currency' => $currency), 'GET');
        $obj = json_decode($json);
        $out = array();

        if ($obj && isset($obj->result) && isset($obj->result->balance)) {
            if (tp_debug_mode_enabled()) {
                file_put_contents('data/'.$file_prefix."_margin.json", $json);
            }
            $out['balance'] = $obj->result->margin_balance;
            $out['avail_funds']  = $obj->result->available_funds;
            $out['used_balance'] = $obj->result->balance;
            $out['init_margin'] = $obj->result->initial_margin;
            $out['session_rpl'] = $obj->result->session_rpl;
        } else {
            $this->ProcessError("#FAILED: get_account_summary %s", $json, $obj);
            $core->LogError("~C91#FAILED:~C00 private API %s returned %s", $this->last_request, $json);
        }
        $map[$currency] = $out;
    }

    protected function ProcessBalance(array $map, string $coin) {
        $key = strtoupper($coin);
        $total_key = strtolower("total_$coin");
        $price_key = strtolower("{$coin}_price");
        $core = $this->TradeCore();
        if (!isset($map[$key])) {
            $core->LogError("~C91 #FAILED:~C00 ProcessBalance no info for %s:  %s", $key, json_encode($map));
            return 0;
        }

        $price = $this->$price_key;
        $map = $map[$key];
        if (is_array($map) && isset($map['balance'])) {
            $core->$total_key = $map['balance'];
            $core->total_funds = $core->$total_key * $price;
            return $map['init_margin'] * $price;
        } else {
            $core->LogError("~C91 #FAILED:~C00 ProcessBalance no info for %s balances: %s", $key, var_export($map, true));
        }

        return 0;
    }

    public function LoadPositions() {
        parent::LoadPositions();

        $core = $this->TradeCore();
        $map = [];
        $this->GetAccountSummary('BTC', $map);
        $this->GetAccountSummary('ETH', $map);
        $btc_usage = $this->ProcessBalance($map, 'BTC');
        $eth_usage = $this->ProcessBalance($map, 'ETH');

        $funds_locked = $btc_usage + $eth_usage;
        if ($core->total_funds > 0) {
            $core->used_funds = 100 * $funds_locked  / $core->total_funds;
        }
        $this->GetPositions('BTC');
        $this->GetPositions('ETH');
    }

    public function PlatformStatus() {
        $json = $this->RequestPublicAPI('/api/v2/public/test', array());
        $core = $this->TradeCore();
        $res = json_decode($json);
        if (is_object($res) && isset($res->result) && isset($res->result->version)) {
            $this->platform_version = $res->result->version;
        } else {
            $this->LogMsg("~C91#WARN:~C00 {$this->last_request} failed: $json");
            return 0;
        }
        $json = $this->RequestPublicAPI('/api/v2/public/get_time', array());
        $res = json_decode($json);
        if (is_object($res) && isset($res->result)) {
            $this->server_time = $res->result;
            $this->diver_time = time_ms() - $this->server_time;
            if (abs($this->diver_time) > 100) {
                $this->LogMsg("#SERVER_TIME: diff = {$this->diver_time} ms");
            }
        } else {
            $this->LogMsg("~C91#WARN:~C00 {$this->last_request}  method failed: $json");
            return 0;
        }

        return 1;
    }

    protected function EncodeParams(&$params, &$headers, $method) {
        if ('POST' === $method) {
            if (!is_string($params)) {
                $params = json_encode($params);
            }
            $headers [] = 'Content-Type: application/json';
        } elseif (is_array($params)) {
            $params = http_build_query($params, '', '&');
        }
        return $params;
    }


    public function NewOrder(TickerInfo $tinfo, array $proto): ?OrderInfo {
        $this->last_error = '';
        $core = $this->TradeCore();
        $pair_id = $tinfo->pair_id;
        $proto['pair_id'] = $pair_id;
        $proto['amount'] = $this->NormalizeOrderAmount($tinfo, floatval($proto['amount'] ?? 0));


        if (0 == $proto['amount']) {
            throw new Exception("Attempt create order with amount == 0");
        }

        $active = $this->MixOrderList('pending,market_maker');
        if (count($active) >= 40) {
            $core->LogError("~C91#FAILED(NewOrder):~C00 too many active orders %d", count($active));
            return null;
        }

        $info = $this->CreateOrder();  // OrderInfo instance simple
        $info->Import($proto);
        if (!$this->DispatchOrder($info)) {
            $core->LogError("#FAILED: order was not registered");
            $core->LogObj($proto, '  ', 'proto', 'error_logger');
            return null;
        }
        $info->ticker_info = $tinfo;
        $side = $info->buy ? 'Buy' : 'Sell';
        $instr_name = $core->pairs_map[$pair_id];
        $hidden = array_value($proto, 'hidden', false);
        // $ttl = gmdate('Y-m-d H:i:s', time() + 300);  // for debug purpose
        // $acc_id = $this->account_id;
        $label = $this->OrderLabel($info);
        $info->amount = $this->NormalizeOrderAmount($tinfo, floatval($info->amount));
        $params = array('label' => $label, 'type' => 'limit', 'instrument_name' => $instr_name,
                        'price' => strval($info->price), 'amount' => $tinfo->FormatAmount($info->amount));
        $display_amount = $this->NormalizeDisplayAmount($tinfo, $hidden, floatval($info->amount));
        if (null !== $display_amount) {
            $params['display_amount'] = $tinfo->FormatAmount($display_amount);
        }

        $core->LogObj($params, '   ', '~C93 order submit params:~C97');
        $json = '';
        if ($info->buy) {
            $json = $this->RequestPrivateAPI('/api/v2/private/buy', $params, 'GET');
        } else {
            $json = $this->RequestPrivateAPI('/api/v2/private/sell', $params, 'GET');
        }
        $res = json_decode($json);
        if (is_object($res) && isset($res->result) && isset($res->result->order)) {
            $order = $res->result->order;
            $this->LogMsg("~C97 #NEW_ORDER_SUCCESS:~C00 result %s ", json_encode($order));
            $obj = $this->UpdateOrder($info, $order);
            $this->last_error = '~'.gettype($obj);
            if ($obj && is_object($obj)) {
                return $obj;
            } else {
                $this->last_error = 'UpdateOrder returned '.var_export($obj);
            }
            return $info;
        } else {
            $core->LogOrder("~C91#FAILED(NewOrder)~C00: API returned %s", $json);
            $this->ProcessError("API fails with result %s", $json, $res);
            $info->status = 'rejected';
            $info->OnError('submit');
            $info->Register($this->archive_orders);
            return null;
        }
    }

    public function CancelOrder(OrderInfo $info): ?OrderInfo {
        $this->last_error = '';
        $core = $this->TradeCore();
        $params = array();
        $json = '';
        $tinfo = $this->TickerInfo($info->pair_id);
        if (strlen($info->order_no) >= 5) {
            $params['order_id'] = $info->order_no;
            $json = $this->RequestPrivateAPI('/api/v2/private/cancel', $params, 'GET');
        } else {
            $params['label'] = $this->OrderLabel($info);
            $params['currency'] = $tinfo->quote_currency;
            $json = $this->RequestPrivateAPI('/api/v2/private/cancel_by_label', $params, 'GET');
        }

        $fixed_st = $info->matched > 0 ? 'filled' : 'canceled';
        if ($info->matched > 0 && $info->matched < $info->amount) {
            $fixed_st = 'partially_filled';
        }

        if ($info->error_map['cancel'] > 1) {
            $info->status = $fixed_st;
            $info->flags |= OFLAG_FIXED;
            return $info;
        }
        $res = json_decode($json);
        if (is_object($res) && isset($res->result)) {
            return $this->UpdateOrder($info);
        } else {
            if (is_object($res) && isset($res->error) && $res->error->code == 11044) {
                $info->status = $fixed_st;
                $info->flags |= OFLAG_FIXED;
                $this->UpdateOrder($info);
            } else {
                $core->LogOrder("~C91#FAILED:~C00 API cancel/cancel_by_label returned %s", $json);
                $this->ProcessError("API cancel/cancel_by label fails with result %s", $json, $res);
            }
            $info->OnError('cancel');
            return null;
        }
    }

    public function MoveOrder(OrderInfo $info, float $price, float $amount = 0): ?OrderInfo {
        $this->last_error = '';
        $core = $this->TradeCore();
        $tinfo = $this->TickerInfo($info->pair_id);
        if (!$tinfo) {
            $core->LogOrder("~C91#FAILED(MoveOrder)~C00: ticker info not exists for pair %d", $info->pair_id);
            return null;
        }
        $rest = $info->amount - $info->matched;
        if ($rest <= 0) {
            $core->LogOrder("~C91#WARN(MoveOrder)~C00: order %s already filled", strval($info));
            $this->UpdateOrder($info);
            return $info;
        }
        $fails = $info->ErrCount('move');
        if ($fails >= 3) {
            $core->LogOrder("~C91#WARN(MoveOrder)~C00: order %s may be lost/filled, have %d move fails", strval($info), $fails);
            $this->UpdateOrder($info);
            return $info;
        }
        if ($amount > 0) {
            $info->amount = $this->NormalizeOrderAmount($tinfo, $amount);
        }

        if ($info->amount <= 0) {
            $core->LogOrder("~C91#FAILED(MoveOrder)~C00: normalized amount became zero for order %s", strval($info));
            return null;
        }

        $params = ['price' => $price, 'amount' => $tinfo->FormatAmount($this->NormalizeOrderAmount($tinfo, floatval($info->amount))), 'instrument_name' => $tinfo->pair];

        $json = '';
        if (strlen($info->order_no) >= 5) {
            $params['order_id'] = $this->FormatOrderNo($info->order_no, $tinfo->base_currency);
            ;
            $json = $this->RequestPrivateAPI('/api/v2/private/edit', $params, 'GET');
        } else {
            $params['label'] = $this->OrderLabel($info);
            $json = $this->RequestPrivateAPI('/api/v2/private/edit_by_label', $params, 'GET');
        }

        $res = json_decode($json);
        $info->comment = str_replace(', mv, mv', ', mv', $info->comment . ', mv');
        $info->was_moved = 0;
        if (is_object($res) && isset($res->result) && isset($res->result->order)) {
            $info->was_moved++;
            return $this->UpdateOrder($info->id, $res->result->order);
        } else {
            $fails = $info->OnError('move');
            $core->LogOrder("~C91#FAILED(MoveOrder)~C00: API returned %s, for order %s, request = %s, fails = %d", $json, strval($info), $this->last_request, $fails);
            $this->ProcessError("API edit/edit_by_label fails with result %s", $json, $res);
            $info->status = 'lost';
            $info->updated = date_ms(SQL_TIMESTAMP3);
            $this->UpdateOrder($info);
            return null;
        }
    }

    private function AuthCheck($obj, $context = 'auth') {
        $core = $this->TradeCore();
        if (is_object($obj) && isset($obj->result) && isset($obj->result->access_token)) {
            $this->last_error = '';
            $this->last_error_code = 0;
            $this->oauth_token = $obj->result->access_token;
            $this->oauth_refresh_token = $obj->result->refresh_token;
            $this->oauth_scope = strval($obj->result->scope ?? '');
            file_put_contents('.oauth_refresh_token', $this->oauth_refresh_token);
            chmod('.oauth_refresh_token', 0640);
            $exp_sec = $obj->result->expires_in;
            $this->oauth_expires = time() + $exp_sec;
            $token = substr($this->oauth_token, 0, 10).'...';
            $this->LogMsg("~C92#SUCCES:~C00 new $context token [%s], expires at %s (since %d sec) ", $token, date(SQL_TIMESTAMP, $this->oauth_expires), $exp_sec);
            return true;
        } else {
            return false;
        }
    }

    private function AuthRefresh() {
        $headers = array('Content-Type: application/json');
        $core = $this->TradeCore();
        $json = $this->RequestPublicAPI("/api/v2/public/auth", "grant_type=refresh_token&refresh_token={$this->oauth_refresh_token}", 'GET', $headers);
        $obj = json_decode($json);
        if ($this->AuthCheck($obj, 'auth/refresh')) {
            return true;
        } else {
            $this->ProcessError("#FAILED(AuthRefresh): %s", $json, $obj);
            $core->LogError("~C91#FAILED(AuthRefresh):~C00 API returned %s,\n\t request %s", $json, $this->last_request);
            $this->oauth_token = false;
            $this->oauth_refresh_token = false;
        }

    }

    private function AuthSession() {
        $nonce = (++$this->last_nonce);
        $nonce = substr($nonce, 0, 8);
        $this->last_error = '';
        if (0 == $this->PlatformStatus()) {
            throw new Exception("Attempt auth, while platform is down");
        }

        $mts = $this->server_time;

        // $headers = array('Content-Type: application/json');
        $headers = array('Content-Type: application/x-www-form-urlencoded');

        $core = $this->TradeCore();
        $secret = implode('', $this->secretKey);
        if (strlen($secret) < 10) {
            throw new Exception("Secret key proto to small");
        }

        $clid = $this->clientId;
        $path = "/api/v2/public/auth";
        $body = '';
        $sign_msg = "$mts\n$nonce\n$body\n";
        $this->LogMsg(" signaturing request [%s]", $sign_msg);
        $secret = str_replace("\n", '', $secret);
        $secret = base64_decode($secret);
        $secret = trim($secret);
        $signature = hash_hmac('sha256', $sign_msg, $secret);
        $params = array('client_id' => $clid, 'grant_type' => 'client_signature', 'timestamp' => $mts, 'nonce' => $nonce, 'signature' => $signature);

        $headers [] = "Authorization: deri-hmac-sha256 id=$clid,ts=$mts,nonce=$nonce,sig=$signature";
        $json = $this->RequestPublicAPI($path, $params, 'GET', $headers, $this->public_api); //
        $obj = json_decode($json);

        if ($this->AuthCheck($obj)) {
            return true;
        } else {
            $this->ProcessError("Auth fails %s", $json, $obj);
            $core->LogError("~C91#ERROR:~C00 client_signature auth rejected by %s,\n\t request %s ", $this->last_error, $this->last_request);
        }
        // TODO: little unsecured auth method
        $params = "client_id=$clid&client_secret=$secret&grant_type=client_credentials&";
        $json = $this->RequestPublicAPI($path, $params, 'GET', $headers, $this->public_api); //
        $obj = json_decode($json);
        if ($this->AuthCheck($obj)) {
            return true;
        } else {
            $this->ProcessError("Auth fails %s", $json, $obj);
            $core->LogError("#FATAL: auth rejected [%s] reason: %s, params: %s", $this->AuthContext(), $this->last_error, $params);
            $core->auth_errors [] = $json;
            if ($this->IsDeribitTestnetMode()) {
                $this->DumpSecretForAuthDebug($this->SecretToString($this->secretKey), 'assembled Deribit secret');
            }
            throw new Exception("Authorization $clid with credentials failed, response: $json");
        }
    }

    private function IsDeribitTestnetMode(): bool {
        return $this->UrlLooksLikeTestnet($this->public_api) || $this->UrlLooksLikeTestnet($this->private_api);
    }

    private function RequestPrivateAPI($path, $params, $method = 'POST', $sign = true) {
        // $core = $this->TradeCore();
        $this->api_params = $params; // save for debug
        $this->EncodeParams($params, $headers, $method);
        $soon = time() + 60;
        if ($this->oauth_refresh_token && $soon >= $this->oauth_expires) {
            $this->AuthRefresh();
        }

        if (!$this->oauth_token) {
            $this->AuthSession();
        }

        if ($sign) {
            $headers [] = "Authorization: Bearer ".$this->oauth_token;
        }

        $result = $this->RequestPublicAPI($path, $params, $method, $headers, $this->private_api); // using separated API endpoint
        // if (error_test($result, '')
        return $result;
    }
}

$bot_impl_class = 'DeribitBOT';

final class DeribitBOT extends TradingCore {
    public function __construct() {
        global $mysqli, $impl_name;
        $this->impl_name = $impl_name;
        parent::__construct();
        $this->trade_engine = new DeribitEngine($this);
        $this->trade_engine->account_id = 0;
        $this->Initialize($mysqli);
        $this->configuration->max_order_cost = 3000;  // to big Amount...
    }

};
