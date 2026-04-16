<?php
/**
 * data-cfg.php — Exchange datafeed configurator.
 * Edit ticker_map and data_config for exchange databases.
 * trading.%__pairs_map is populated dynamically by bots — not used here.
 */

require_once('lib/common.php');
require_once('lib/db_config.php');
require_once('lib/db_tools.php');
require_once('lib/auth_check.php');

if (!str_in($user_rights, 'admin')) {
    http_response_code(403);
    die('<h3>Access denied. Admin rights required.</h3>');
}

const DATA_CFG_EXCHANGES = ['binance', 'bitfinex', 'bitmex', 'bybit', 'deribit'];
const DATA_CFG_MODE_LABELS = [
    0 => 'none',
    1 => 'historical',
    2 => 'realtime',
    3 => 'both',
];

function data_cfg_h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function data_cfg_mode_value($value): int {
    $mode = intval($value);
    if (!array_key_exists($mode, DATA_CFG_MODE_LABELS)) {
        return 0;
    }
    return $mode;
}

function data_cfg_exchange(): string {
    $exchange = strtolower(trim((string)($_REQUEST['exchange'] ?? 'binance')));
    if (!in_array($exchange, DATA_CFG_EXCHANGES, true)) {
        return 'binance';
    }
    return $exchange;
}

function data_cfg_ensure_tables(mysqli_ex $db): void {
    $db->query(
        "CREATE TABLE IF NOT EXISTS `ticker_map` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `ticker` varchar(16) NOT NULL,
            `symbol` varchar(32) NOT NULL,
            `pair_id` int(11) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `ticker` (`ticker`),
            UNIQUE KEY `symbol` (`symbol`),
            KEY `pair_id` (`pair_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin"
    );
    $db->query(
        "CREATE TABLE IF NOT EXISTS `data_config` (
            `id_ticker` int(11) NOT NULL,
            `load_candles` int(11) NOT NULL DEFAULT 0,
            `load_depth` int(11) NOT NULL DEFAULT 0,
            `load_ticks` int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id_ticker`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin"
    );
}

function data_cfg_rows(mysqli_ex $db): array {
    $sql = "SELECT tm.id, tm.ticker, tm.symbol, tm.pair_id,
                   COALESCE(dc.load_candles, 0) AS load_candles,
                   COALESCE(dc.load_depth, 0) AS load_depth,
                   COALESCE(dc.load_ticks, 0) AS load_ticks
            FROM ticker_map tm
            LEFT JOIN data_config dc ON dc.id_ticker = tm.id
            ORDER BY tm.id";
    $result = $db->query($sql);
    $rows = [];
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
    }
    return $rows;
}

function data_cfg_next_id(mysqli_ex $db): int {
    $result = $db->query("SELECT COALESCE(MAX(id), 0) + 1 FROM ticker_map");
    if ($result instanceof mysqli_result) {
        $row = $result->fetch_row();
        $result->free();
        return max(1, intval($row[0] ?? 1));
    }
    return 1;
}

function data_cfg_mode_options(int $selected): string {
    $html = '';
    foreach (DATA_CFG_MODE_LABELS as $value => $label) {
        $sel = $value === $selected ? ' selected' : '';
        $html .= '<option value="' . $value . '"' . $sel . '>' . data_cfg_h($label) . '</option>';
    }
    return $html;
}

function data_cfg_save_row(mysqli_ex $db, int $id, array $row): void {
    $pair_id = max(0, intval($row['pair_id'] ?? 0));
    $ticker  = strtolower(trim((string)($row['ticker'] ?? '')));
    $symbol  = trim((string)($row['symbol'] ?? ''));
    $candles = data_cfg_mode_value($row['load_candles'] ?? 0);
    $depth   = data_cfg_mode_value($row['load_depth'] ?? 0);
    $ticks   = data_cfg_mode_value($row['load_ticks'] ?? 0);

    if ($pair_id <= 0) {
        throw new RuntimeException("pair_id is required for row $id");
    }
    if ($ticker === '') {
        throw new RuntimeException("ticker is required for row $id");
    }
    if ($symbol === '') {
        throw new RuntimeException("symbol is required for row $id");
    }

    $ticker_q = $db->real_escape_string($ticker);
    $symbol_q = $db->real_escape_string($symbol);
    $db->query("UPDATE ticker_map SET ticker='$ticker_q', symbol='$symbol_q', pair_id=$pair_id WHERE id=$id");
    $db->query(
        "INSERT INTO data_config (id_ticker, load_candles, load_depth, load_ticks)
         VALUES ($id, $candles, $depth, $ticks)
         ON DUPLICATE KEY UPDATE
            load_candles=VALUES(load_candles),
            load_depth=VALUES(load_depth),
            load_ticks=VALUES(load_ticks)"
    );
}

$exchange = data_cfg_exchange();
$mysqli   = init_remote_db($exchange);

data_cfg_ensure_tables($mysqli);

$message      = '';
$message_type = 'ok';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    try {
        $mysqli->query('START TRANSACTION');

        $rows = $_POST['rows'] ?? [];
        if (is_array($rows)) {
            foreach ($rows as $raw_id => $row) {
                $id = intval($raw_id);
                if ($id <= 0 || !is_array($row)) {
                    continue;
                }

                $remove = isset($row['remove']) && strval($row['remove']) === '1';
                if ($remove) {
                    $mysqli->query("DELETE FROM data_config WHERE id_ticker = $id");
                    $mysqli->query("DELETE FROM ticker_map WHERE id = $id");
                    continue;
                }

                data_cfg_save_row($mysqli, $id, $row);
            }
        }

        $new_pair_id   = intval($_POST['new_pair_id'] ?? 0);
        $new_ticker    = strtolower(trim((string)($_POST['new_ticker'] ?? '')));
        $new_symbol    = trim((string)($_POST['new_symbol'] ?? ''));
        $new_has_payload = $new_pair_id > 0 || $new_ticker !== '' || $new_symbol !== '';
        if ($new_has_payload) {
            if ($new_pair_id <= 0 || $new_ticker === '' || $new_symbol === '') {
                throw new RuntimeException('new ticker requires pair_id, ticker and symbol');
            }
            $new_id = $new_pair_id;
            $exists_result = $mysqli->query("SELECT COUNT(*) FROM ticker_map WHERE id = $new_id");
            $exists_row = $exists_result instanceof mysqli_result ? $exists_result->fetch_row() : [0];
            if ($exists_result instanceof mysqli_result) {
                $exists_result->free();
            }
            if (intval($exists_row[0] ?? 0) > 0) {
                $new_id = data_cfg_next_id($mysqli);
            }

            $new_ticker_q = $mysqli->real_escape_string($new_ticker);
            $new_symbol_q = $mysqli->real_escape_string($new_symbol);
            $mysqli->query(
                "INSERT INTO ticker_map (id, ticker, symbol, pair_id)
                 VALUES ($new_id, '$new_ticker_q', '$new_symbol_q', $new_pair_id)"
            );
            $new_candles = data_cfg_mode_value($_POST['new_load_candles'] ?? 0);
            $new_depth   = data_cfg_mode_value($_POST['new_load_depth'] ?? 0);
            $new_ticks   = data_cfg_mode_value($_POST['new_load_ticks'] ?? 0);
            $mysqli->query(
                "INSERT INTO data_config (id_ticker, load_candles, load_depth, load_ticks)
                 VALUES ($new_id, $new_candles, $new_depth, $new_ticks)"
            );
        }

        $mysqli->query('COMMIT');
        $message = 'Configuration saved.';
    } catch (Throwable $e) {
        $mysqli->query('ROLLBACK');
        $message      = 'Save failed: ' . $e->getMessage();
        $message_type = 'err';
    }
}

$rows = data_cfg_rows($mysqli);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Data Config</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: Georgia, "Trebuchet MS", serif;
            margin: 0;
            background: linear-gradient(180deg, #f4f0e7 0%, #efe7d8 100%);
            color: #201a12;
        }
        .shell {
            max-width: 1480px;
            margin: 0 auto;
            padding: 20px;
        }
        h1 {
            margin: 0 0 8px;
            font-size: 34px;
        }
        .muted {
            color: #62584a;
        }
        .panel {
            background: rgba(255, 255, 255, 0.78);
            border: 1px solid #d8c8af;
            border-radius: 12px;
            padding: 16px 18px;
            margin: 16px 0;
            box-shadow: 0 10px 30px rgba(80, 60, 20, 0.08);
        }
        .flash-ok, .flash-err {
            border-radius: 10px;
            padding: 12px 14px;
            margin: 16px 0;
            font-weight: 700;
        }
        .flash-ok {
            background: #e5f6e9;
            border: 1px solid #78b487;
            color: #1e5c2c;
        }
        .flash-err {
            background: #fde8e3;
            border: 1px solid #d68068;
            color: #8a2416;
        }
        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: end;
        }
        .toolbar label, .add-grid label {
            display: flex;
            flex-direction: column;
            gap: 6px;
            font-size: 14px;
            font-weight: 700;
        }
        input[type="text"], input[type="number"], select {
            padding: 8px 10px;
            border: 1px solid #b9a78c;
            border-radius: 8px;
            background: #fffdf9;
            min-width: 120px;
        }
        button {
            padding: 10px 16px;
            border: 0;
            border-radius: 8px;
            background: #1f2a33;
            color: #fff;
            cursor: pointer;
            font-weight: 700;
        }
        button.secondary {
            background: #7c5f2f;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: #fffdf9;
        }
        th, td {
            border: 1px solid #d8c8af;
            padding: 8px;
            vertical-align: top;
        }
        th {
            background: #efe2c8;
            text-align: left;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        .table-wrap {
            overflow: auto;
            max-height: 68vh;
        }
        .id-cell {
            white-space: nowrap;
            font-weight: 700;
        }
        .note {
            background: #fbf3d5;
            border: 1px solid #d7c17a;
            border-radius: 10px;
            padding: 12px 14px;
        }
        .add-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            align-items: end;
        }
        .remove-box {
            display: flex;
            justify-content: center;
            padding-top: 6px;
        }
        .small {
            font-size: 12px;
            color: #675941;
        }
        @media (max-width: 900px) {
            h1 { font-size: 28px; }
            th, td { font-size: 13px; }
        }
    </style>
</head>
<body>
    <?php require_once 'nav.php'; ?>
    <div class="shell">
        <h1>Datafeed Configurator</h1>
        <p class="muted">Edit exchange <code>ticker_map</code> and <code>data_config</code> rows.
            <code>ticker</code> must stay universal across exchanges; <code>symbol</code> stores the real exchange symbol.</p>

        <?php if ($message !== ''): ?>
            <div class="flash-<?php echo $message_type === 'err' ? 'err' : 'ok'; ?>"><?php echo data_cfg_h($message); ?></div>
        <?php endif; ?>

        <div class="panel note">
            <strong>Important:</strong> use universal tickers like <code>btcusd</code>, <code>ethusd</code>,
            <code>xrpusd</code> in the <code>ticker</code> column.
            Put exchange-specific names like <code>BTCUSDT</code>, <code>tBTCUSD</code>, <code>XBTUSD</code>
            into <code>symbol</code>.
        </div>

        <div class="panel">
            <form method="get" action="data-cfg.php" class="toolbar">
                <label>
                    Exchange
                    <select name="exchange">
                        <?php foreach (DATA_CFG_EXCHANGES as $exchange_name): ?>
                            <option value="<?php echo data_cfg_h($exchange_name); ?>"<?php echo $exchange_name === $exchange ? ' selected' : ''; ?>><?php echo data_cfg_h($exchange_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit">Open Exchange</button>
            </form>
        </div>

        <form method="post" action="data-cfg.php?exchange=<?php echo urlencode($exchange); ?>">
            <input type="hidden" name="exchange" value="<?php echo data_cfg_h($exchange); ?>">

            <div class="panel">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Pair ID</th>
                                <th>Ticker</th>
                                <th>Symbol</th>
                                <th>Candles</th>
                                <th>Depth</th>
                                <th>Ticks</th>
                                <th>Remove</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$rows): ?>
                                <tr><td colspan="8">No configured rows yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($rows as $row): ?>
                                <?php $id = intval($row['id'] ?? 0); ?>
                                <tr>
                                    <td class="id-cell"><?php echo $id; ?></td>
                                    <td><input type="number" name="rows[<?php echo $id; ?>][pair_id]" value="<?php echo data_cfg_h((string)intval($row['pair_id'] ?? 0)); ?>" min="1"></td>
                                    <td><input type="text" name="rows[<?php echo $id; ?>][ticker]" value="<?php echo data_cfg_h((string)($row['ticker'] ?? '')); ?>" maxlength="16"></td>
                                    <td><input type="text" name="rows[<?php echo $id; ?>][symbol]" value="<?php echo data_cfg_h((string)($row['symbol'] ?? '')); ?>" maxlength="32"></td>
                                    <td><select name="rows[<?php echo $id; ?>][load_candles]"><?php echo data_cfg_mode_options(data_cfg_mode_value($row['load_candles'] ?? 0)); ?></select></td>
                                    <td><select name="rows[<?php echo $id; ?>][load_depth]"><?php echo data_cfg_mode_options(data_cfg_mode_value($row['load_depth'] ?? 0)); ?></select></td>
                                    <td><select name="rows[<?php echo $id; ?>][load_ticks]"><?php echo data_cfg_mode_options(data_cfg_mode_value($row['load_ticks'] ?? 0)); ?></select></td>
                                    <td class="remove-box"><input type="checkbox" name="rows[<?php echo $id; ?>][remove]" value="1"></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="small">Removing a row changes only <code>ticker_map</code> and <code>data_config</code>. Existing candle tables are not deleted automatically.</p>
            </div>

            <div class="panel">
                <h2>Add New Ticker</h2>
                <div class="add-grid">
                    <label>
                        Pair ID
                        <input type="number" id="new_pair_id" name="new_pair_id" min="1">
                    </label>
                    <label>
                        Exchange Symbol
                        <input type="text" id="new_symbol" name="new_symbol" maxlength="32" placeholder="BTCUSDT" oninput="autoFillTicker(this.value)">
                    </label>
                    <label>
                        Universal Ticker
                        <input type="text" id="new_ticker" name="new_ticker" maxlength="16" placeholder="btcusd">
                    </label>
                    <label>
                        Candles
                        <select name="new_load_candles"><?php echo data_cfg_mode_options(0); ?></select>
                    </label>
                    <label>
                        Depth
                        <select name="new_load_depth"><?php echo data_cfg_mode_options(0); ?></select>
                    </label>
                    <label>
                        Ticks
                        <select name="new_load_ticks"><?php echo data_cfg_mode_options(0); ?></select>
                    </label>
                </div>
                <p class="small">Type the exchange-specific <code>Symbol</code> (e.g. <code>BTCUSDT</code>, <code>tBTCUSD</code>, <code>XBTUSD</code>) — the <code>Ticker</code> field auto-fills with the universal form (e.g. <code>btcusd</code>). You can override it manually.</p>
            </div>

            <div class="panel toolbar">
                <button type="submit">Save Configuration</button>
            </div>
        </form>
    </div>
    <script>
        function suggestUniversalTicker(symbol) {
            if (!symbol) {
                return '';
            }
            let normalized = String(symbol).toUpperCase().replace(/^T/, '').replace(/[:\-]/g, '');
            normalized = normalized.replace(/^XBT/, 'BTC');
            const quotes = [
                ['USDT', 'usd'],
                ['USDC', 'usd'],
                ['USD',  'usd'],
                ['EUR',  'eur'],
                ['BTC',  'btc'],
                ['ETH',  'eth'],
            ];
            for (const [suffix, quote] of quotes) {
                if (normalized.endsWith(suffix) && normalized.length > suffix.length) {
                    const base = normalized.slice(0, -suffix.length).toLowerCase();
                    return base + quote;
                }
            }
            return normalized.toLowerCase();
        }

        function autoFillTicker(symbol) {
            const tickerInput = document.getElementById('new_ticker');
            if (tickerInput && !tickerInput.value) {
                tickerInput.value = suggestUniversalTicker(symbol);
            }
        }
    </script>
</body>
</html>
