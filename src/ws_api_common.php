<?php
    require_once('rest_api_common.php');

    // Load Composer autoloader for WSSC (arthurkushman/php-wss) and other deps.
    // vendor/autoload.php is NOT loaded globally — must be explicit here.
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
    }

// =============================================================================
// WebSocket API engine base — Phase 0 infrastructure
// All transport methods are abstract; concrete engines supply the WS client.
// Fault-handling pattern ported from datafeed/src/proto_manager.php.
// =============================================================================

abstract class WebsockAPIEngine extends RestAPIEngine {

    // === Connection state ===
    protected bool   $ws_active         = false;
    protected bool   $ws_connected      = false;
    protected bool   $ws_authenticated  = false;
    protected bool   $ws_disabled       = false;

    // === Endpoint rotation (populated by wsLoadConfig from YAML profile) ===
    protected int    $ws_endpoint_index    = 0;
    protected array  $ws_endpoints         = ['public' => [], 'private' => []];
    protected string $ws_endpoint_strategy = 'failover';

    // === Timing / health ===
    protected int    $ws_last_ping      = 0;
    protected int    $ws_last_data_t    = 0;
    protected int    $ws_ping_interval  = 30;
    protected int    $ws_data_stall_sec = 300;
    protected int    $ws_ping_timeout   = 120;

    // === Reconnect control ===
    protected int    $ws_reconnect_t        = 0;
    protected int    $ws_reconnects         = 0;
    protected int    $ws_reconnect_cooldown = 100;
    protected int    $ws_fallback_t         = 0;    // timestamp when ws_active was cleared (REST fallback)
    protected int    $ws_subscribe_after    = 0;
    protected string $ws_connect_type       = 'private'; // endpoint type used by wsReconnect()

    // === Frame / exception counters ===
    protected int    $ws_empty_reads      = 0;
    protected int    $ws_exceptions       = 0;
    protected int    $ws_recv_packets     = 0;
    protected int    $ws_recv_bytes       = 0;
    protected int    $ws_pub_packets      = 0; // data frames routed to market/ticker handlers
    protected int    $ws_priv_packets     = 0; // data frames routed to order/account handlers
    protected bool   $ws_heartbeat_frame  = false; // set by wsDispatch() to skip counting JSON heartbeats
    protected int    $ws_ticker_t      = 0; // last time bookTicker/price data was updated via WS
    protected int    $ws_stats_t       = 0;
    protected int    $ws_stats_interval = 300; // seconds between periodic stats log
    protected array  $ws_subscribers   = [];
    protected array  $ws_message_queue = [];
    protected array  $ws_filled_pairs  = []; // pair_id => fill-count accumulated during drain, cleared after fast MM trigger

    // -------------------------------------------------------------------------
    // Abstract: transport layer — implement in each concrete engine class
    // -------------------------------------------------------------------------

    abstract protected function wsConnect(string $url): bool;
    abstract protected function wsClose(): void;
    abstract protected function wsSend(string $data): bool;
    abstract protected function wsReceive(): ?string;
    abstract protected function wsLastOpcode(): string;
    abstract protected function wsUnreaded(): int;
    abstract protected function wsIsConnected(): bool;
    abstract protected function wsPing(): void;
    abstract protected function wsPong(string $payload = ''): void;

    // -------------------------------------------------------------------------
    // Abstract: exchange protocol — implement in each concrete engine class
    // -------------------------------------------------------------------------

    abstract protected function wsRequiresAuth(): bool;
    abstract protected function wsBuildAuthMessage(): string;
    abstract protected function wsDispatch(mixed $data): void;
    abstract protected function wsSubscribeAll(): void;

    // -------------------------------------------------------------------------
    // State accessors
    // -------------------------------------------------------------------------

    public function isWsActive(): bool {
        return $this->ws_active;
    }

    public function isWsConnected(): bool {
        return $this->ws_active && $this->ws_connected;
    }

    public function isWsReady(): bool {
        return $this->ws_connected && $this->ws_authenticated;
    }

    public function wsTickersFresh(int $max_age = 30): bool {
        if (!$this->isWsConnected() || $this->ws_ticker_t <= 0)
            return false;
        return (time() - $this->ws_ticker_t) <= $max_age;
    }

    // -------------------------------------------------------------------------
    // Endpoint rotation
    // -------------------------------------------------------------------------

    protected function wsNextEndpoint(string $type = 'public'): ?string {
        $endpoints = $this->ws_endpoints[$type] ?? [];
        if (empty($endpoints)) return null;

        if ($this->ws_endpoint_strategy === 'round_robin') {
            $url = $endpoints[$this->ws_endpoint_index % count($endpoints)];
            $this->ws_endpoint_index++;
        } else {
            // failover: walk forward, hold on last entry when exhausted
            $idx = min($this->ws_endpoint_index, count($endpoints) - 1);
            $url = $endpoints[$idx];
            $this->ws_endpoint_index++;
        }
        return $url;
    }

    protected function wsResetEndpointRotation(): void {
        $this->ws_endpoint_index = 0;
    }

    protected function wsOnConnected(): void {
        $this->wsResetEndpointRotation();
        $this->ws_connected  = true;
        $this->ws_last_data_t = time(); // stall timer starts from connect, not first packet
    }

    // -------------------------------------------------------------------------
    // Config loading — reads ws_* keys from the pre-loaded YAML profile array
    // (returned by LoadExchangeProfile) plus per-bot overrides from bot__config
    // -------------------------------------------------------------------------

    protected function wsLoadConfig(array $profile): void {
        if (isset($profile['ws_public']) && is_array($profile['ws_public']))
            $this->ws_endpoints['public'] = $profile['ws_public'];
        if (isset($profile['ws_private']) && is_array($profile['ws_private']))
            $this->ws_endpoints['private'] = $profile['ws_private'];
        if (isset($profile['ws_endpoint_strategy']))
            $this->ws_endpoint_strategy = strval($profile['ws_endpoint_strategy']);
        if (isset($profile['ws_disabled']))
            $this->ws_disabled = (bool) $profile['ws_disabled'];

        $core  = $this->TradeCore();
        $ws_on = $core->ConfigValue('ws_enabled', '1');
        $this->ws_active = !$this->ws_disabled && (bool)(int)$ws_on;

        $ping_int = $core->ConfigValue('ws_ping_interval', '');
        if ($ping_int !== '') $this->ws_ping_interval = (int)$ping_int;

        $stall = $core->ConfigValue('ws_data_stall_sec', '');
        if ($stall !== '') $this->ws_data_stall_sec = (int)$stall;

        $pong_t = $core->ConfigValue('ws_ping_timeout', '');
        if ($pong_t !== '') $this->ws_ping_timeout = (int)$pong_t;
    }

    // -------------------------------------------------------------------------
    // Reconnect logic (datafeed-proven)
    // Never sleeps — reconnect fires on the next drainWsBuffer() call.
    // 100 s cooldown prevents reconnect thrashing.
    // -------------------------------------------------------------------------

    public function wsReconnect(string $reason): void {
        $elps = time() - $this->ws_reconnect_t;
        if ($elps < $this->ws_reconnect_cooldown) {
            // Inside cooldown: mark disconnected, but do not attempt yet
            $this->ws_connected     = false;
            $this->ws_authenticated = false;
            return;
        }

        $this->ws_reconnect_t   = time();
        $this->ws_reconnects++;
        $this->ws_connected     = false;
        $this->ws_authenticated = false;
        $this->ws_last_ping     = 0;
        $this->ws_empty_reads   = 0;
        $this->ws_exceptions    = 0;

        $this->LogMsg("~C91#WS_RECONNECT:~C00 reason: %s (attempt #%d)", $reason, $this->ws_reconnects);

        $this->wsClose();

        $url = $this->wsNextEndpoint($this->ws_connect_type);
        if (null === $url) {
            $this->LogMsg("~C91#WS_NO_ENDPOINTS:~C00 no endpoints configured, REST fallback active");
            $this->ws_active = false;
            return;
        }

        // Reset subscription state so wsSubscribeAll() re-registers everything
        foreach ($this->ws_subscribers as $pair => &$sub)
            $sub['confirmed'] = false;
        unset($sub);

        if ($this->wsConnect($url)) {
            $this->ws_subscribe_after = time() + 10;
            $this->wsResetEndpointRotation();
        } else {
            $this->ws_active     = false; // fall back to REST until next attempt
            $this->ws_fallback_t = 0;     // reset so 10-min timer starts fresh
        }
    }

    // -------------------------------------------------------------------------
    // drainWsBuffer — called once per Update() cycle
    // Drives all WS health checks without sleep() or pcntl signals.
    // -------------------------------------------------------------------------

    public function drainWsBuffer(): void {
        if (!$this->ws_active) {
            // Auto-recovery from REST fallback every 10 minutes
            if (!$this->ws_disabled) {
                $endpoints = array_merge(
                    $this->ws_endpoints['private'] ?? [],
                    $this->ws_endpoints['public']  ?? []
                );
                if ($endpoints) {
                    if ($this->ws_fallback_t === 0) {
                        $this->ws_fallback_t = time();
                        $this->SaveWsStats(); // persist fallback=1 immediately
                    }
                    elseif (time() - $this->ws_fallback_t >= 600) {
                        $this->LogMsg('~C93#WS_FALLBACK_RETRY:~C00 10 min elapsed, attempting WS reconnect');
                        $this->ws_active     = true;
                        $this->ws_fallback_t = 0;
                        $this->wsReconnect('fallback-retry');
                    }
                }
            }
            return;
        }
        if (!$this->ws_connected) {
            $this->wsReconnect('not-connected');
            return;
        }

        // 1. Ping keepalive ------------------------------------------------
        $ping_elps = time() - $this->ws_last_ping;
        if ($ping_elps > $this->ws_ping_interval) {
            $ping_err = null;
            set_error_handler(static function ($severity, $message) use (&$ping_err) {
                $ping_err = $message;
                return true;
            });
            try {
                $this->ws_last_ping = time() - 20; // write before send: prevent re-entry DDoS
                $this->wsPing();
            } catch (Throwable $E) {
                $ping_err = $E->getMessage();
            } finally {
                restore_error_handler();
            }
            if ($ping_err !== null) {
                $this->ws_exceptions++;
                $this->LogMsg("~C91#WS_PING_FAIL:~C00 %s (exceptions total: %d)", $ping_err, $this->ws_exceptions);
                if (str_contains(strtolower($ping_err), 'broken pipe') || $ping_elps >= 60 || $this->ws_exceptions > 5) {
                    $this->ws_exceptions = 0;
                    $this->wsReconnect('ping failed / exception storm');
                    return;
                }
            }
        }

        // 2. Data stall detection -----------------------------------------
        $data_elps = time() - $this->ws_last_data_t;
        if ($this->ws_last_data_t > 0 && $data_elps > $this->ws_data_stall_sec) {
            if ($ping_elps > $this->ws_ping_timeout || $data_elps > $this->ws_data_stall_sec + $this->ws_ping_timeout) {
                // No pong or timeout exhausted — full reconnect
                $this->wsReconnect("data stall {$data_elps}s + no pong");
                return;
            }
            // Ping alive but subscription likely dropped — re-subscribe only
            $this->LogMsg("~C31#WS_DATA_LAG:~C00 no data for %d s, ping ok — re-subscribing", $data_elps);
            foreach ($this->ws_subscribers as $pair => &$sub)
                $sub['confirmed'] = false;
            unset($sub);
            $this->wsSubscribeAll();
        }

        // 3. isConnected() sanity check -----------------------------------
        if (!$this->wsIsConnected()) {
            $this->wsReconnect('disconnect status');
            return;
        }

        // 4. Read available frames (1 s budget max) -------------------------
        // First iteration is unconditional so adapters without socket-peek
        // (e.g. WSSC) still drain incoming data; subsequent reads only when
        // the adapter signals more data pending (wsUnreaded() > 0).
        $t_start = microtime(true);
        $i = 0;
        do {
            $this->wsReadFrame();
            if (!$this->ws_connected || (microtime(true) - $t_start) >= 1.0) break;
            $i++;
        } while ($this->wsUnreaded() > 0);

        // 4a. Touch ticker checked timestamps — MM stays alive on quiet markets
        //     as long as the WS connection itself is healthy (prevents spurious #WARN_SKIP).
        if (!empty($this->pairs_info)) {
            $ts_now = time_ms();
            foreach ($this->pairs_info as $tinfo) {
                if ($tinfo->last_price > 0) {
                    $tinfo->checked = $ts_now;
                }
            }
        }

        // 4b. Fast MM reaction — fires for pairs that got fills during this drain pass,
        //     before the normal Update()-cycle ProcessMM() call.
        if (!empty($this->ws_filled_pairs)) {
            foreach (array_keys($this->ws_filled_pairs) as $fid)
                $this->ProcessMM($fid);
            $this->ws_filled_pairs = [];
        }

        // 5. Deferred re-subscribe after reconnect ------------------------
        if ($this->ws_subscribe_after > 0 && time() >= $this->ws_subscribe_after) {
            $this->ws_subscribe_after = time() + 5; // retry in 5 s if still not done
            if ($this->ws_active)
                $this->wsSubscribeAll();
        }

        // 6. Periodic stats log -------------------------------------------
        if ((time() - $this->ws_stats_t) >= $this->ws_stats_interval) {
            $this->ws_stats_t = time();
            $kb = round($this->ws_recv_bytes / 1024, 1);
            $this->LogMsg(
                "~C90#WS_STATS:~C00 packets=%d (pub=%d priv=%d) bytes=%.1f KB  reconnects=%d  exceptions=%d",
                $this->ws_recv_packets, $this->ws_pub_packets, $this->ws_priv_packets, $kb, $this->ws_reconnects, $this->ws_exceptions
            );
            $this->SaveWsStats();
        }

        // 7. Extra cycle hook (e.g. secondary public connection) -----------
        $this->wsOnExtraCycle();
    }

    protected function wsOnExtraCycle(): void {}

    // -------------------------------------------------------------------------
    // SaveWsStats — persist WS counters to {exchange}__ws_stats (UPSERT)
    // -------------------------------------------------------------------------

    private function SaveWsStats(): void {
        $mysqli = $this->sqli();
        if (!$mysqli) return;
        $table  = strtolower($this->exchange) . '__ws_stats';
        $acc    = (int)$this->account_id;
        $pkts   = (int)$this->ws_recv_packets;
        $bytes  = (int)$this->ws_recv_bytes;
        $reconn = (int)$this->ws_reconnects;
        $fb     = $this->ws_active ? 0 : 1;
        $pub    = (int)$this->ws_pub_packets;
        $priv   = (int)$this->ws_priv_packets;
        // Migrate: add public/private columns to existing tables
        $mysqli->try_query("ALTER TABLE `$table` ADD COLUMN IF NOT EXISTS `pub_packets`  BIGINT UNSIGNED NOT NULL DEFAULT 0");
        $mysqli->try_query("ALTER TABLE `$table` ADD COLUMN IF NOT EXISTS `priv_packets` BIGINT UNSIGNED NOT NULL DEFAULT 0");
        $mysqli->try_query(
            "INSERT INTO `$table` (account_id, ts, packets_total, recv_bytes, reconnects, fallback, pub_packets, priv_packets)
"
             . "VALUES ($acc, NOW(3), $pkts, $bytes, $reconn, $fb, $pub, $priv)
"
             . "ON DUPLICATE KEY UPDATE
"
             . "    ts = NOW(3), packets_total = $pkts, recv_bytes = $bytes,
"
             . "    reconnects = $reconn, fallback = $fb, pub_packets = $pub, priv_packets = $priv"
        );
    }

    // -------------------------------------------------------------------------
    // wsReadFrame — read and dispatch one WS frame (called from drainWsBuffer)
    // -------------------------------------------------------------------------

    protected function wsReadFrame(): void {
        try {
            if (!$this->ws_connected) return;

            $raw    = $this->wsReceive();
            $opcode = $this->wsLastOpcode();

            if (null === $raw || '' === $raw) return;

            $this->ws_empty_reads = 0;
            $this->ws_last_data_t = time();
            $len = strlen($raw);

            // WS protocol-level ping/pong — only maintain healthy-connection status, never counted.
            if ('ping' === $opcode) {
                $this->ws_last_ping = time();
                $this->wsPong($raw);
                return;
            }

            if ('pong' === $opcode) {
                $this->ws_last_ping = time();
                return;
            }

            if ('close' === $opcode) {
                $this->LogMsg("~C91#WS_CLOSE:~C00 server sent close: %s", $raw);
                $this->ws_connected  = false;
                $this->ws_active     = false;
                $this->ws_fallback_t = 0;
                return;
            }

            if (isset($raw[0]) && ('{' === $raw[0] || '[' === $raw[0])) {
                $data = json_decode($raw, false);
                if (is_object($data) || is_array($data)) {
                    // Log non-ticker frames for diagnostics (bookTicker suppressed to avoid noise)
                    $is_ticker = (isset($data->stream) && str_ends_with($data->stream, '@bookTicker'))
                              || (!isset($data->e) && !isset($data->stream) && isset($data->s) && isset($data->b));
                    if (!$is_ticker) {
                        $this->LogMsg("~C90#WS_FRAME:~C00 %s", $raw);
                    }
                    // JSON heartbeats (e.g. Bitfinex {"event":"pong"}) set ws_heartbeat_frame = true
                    // inside wsDispatch() to suppress counting.
                    $this->ws_heartbeat_frame = false;
                    $this->wsDispatch($data);
                    if ($this->ws_heartbeat_frame) return;
                }
                $this->ws_recv_packets++;
                $this->ws_recv_bytes += $len;
                // pub/priv counted in wsDispatch() after content-based routing
                return;
            }

            // Raw text heartbeats (e.g. BitMEX replies with literal "pong" text frame)
            if ($raw === 'pong' || $raw === 'ping') {
                $this->ws_last_ping = time();
                if ($raw === 'ping') $this->wsPong('');
                return;
            }

        } catch (Exception $E) {
            $msg    = $E->getMessage();
            $benign = str_contains($msg, 'Empty read')
                || str_contains($msg, 'Broken frame')
                || str_contains($msg, 'Bad opcode');
            if ($benign) {
                $this->ws_empty_reads++;
            } else {
                $this->ws_exceptions++;
                $this->LogMsg("~C91#WS_FRAME_ERR:~C00 %s", $msg);
            }
            $ping_elps = time() - $this->ws_last_ping;
            if ($this->ws_empty_reads > 4 && $ping_elps > $this->ws_ping_timeout) {
                $this->wsReconnect("too many empty/broken frames: $msg");
            }
        }
    }

    // -------------------------------------------------------------------------
    // Message queue — buffers outbound messages during authentication
    // -------------------------------------------------------------------------

    protected function wsEnqueueMessage(string $data): void {
        if ($this->ws_authenticated) {
            $this->wsSend($data);
        } else {
            $this->ws_message_queue[] = $data;
            $this->LogMsg("~C93#WS_QUEUE:~C00 message queued, waiting for auth");
        }
    }

    protected function wsProcessQueue(): void {
        while (!empty($this->ws_message_queue)) {
            $msg = array_shift($this->ws_message_queue);
            if (!$this->wsSend($msg)) {
                array_unshift($this->ws_message_queue, $msg);
                break;
            }
        }
    }

    // Mark pair as having fills during the current drain pass.
    // Called by concrete engine (e.g. wsOnOrderUpdate) when a fill is detected.
    protected function wsMarkFilledPair(int $pair_id): void {
        $this->ws_filled_pairs[$pair_id] = ($this->ws_filled_pairs[$pair_id] ?? 0) + 1;
    }

}

