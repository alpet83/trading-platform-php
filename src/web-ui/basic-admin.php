<?php
require_once('../lib/admin_ip.php');
require_once('../lib/db_tools.php');
require_once('../lib/db_config.php');
require_once('../lib/admin_otp.php');
require_once('../lib/bot_creator.php');

function is_local_client(string $remote): bool {
    if ($remote === '127.0.0.1' || $remote === '::1' || $remote === 'localhost') {
        return true;
    }

    if (is_admin_ip($remote)) {
        return true;
    }

    // Local/private network ranges for bootstrap-only access.
    foreach (['10.', '192.168.', '172.16.', '172.17.', '172.18.', '172.19.', '172.20.', '172.21.', '172.22.', '172.23.', '172.24.', '172.25.', '172.26.', '172.27.', '172.28.', '172.29.', '172.30.', '172.31.'] as $prefix) {
        if (strpos($remote, $prefix) === 0) {
            return true;
        }
    }

    return false;
}

function normalize_signals_setup_cfg(array $cfg, string $fallback): array {
    $setupId = 0;
    if (isset($cfg['setup_id'])) {
    $setupId = intval($cfg['setup_id']);
    }

    if ($setupId >= 0) {
    $cfg['signals_setup'] = strval($setupId);
    } elseif (isset($cfg['signals_setup'])) {
    $raw = trim((string)$cfg['signals_setup']);
    if (preg_match('/^\d+$/', $raw)) {
      $cfg['signals_setup'] = strval(intval($raw));
    } elseif ($raw === '') {
      $cfg['signals_setup'] = $fallback;
    }
    }

    unset($cfg['setup_id']);
    return $cfg;
}

function extract_setup_id_from_cfg(string $signalsSetup): int {
    $raw = trim($signalsSetup);
    if ($raw === '') {
    return 0;
    }
    if (preg_match('/^\d+$/', $raw)) {
    return intval($raw);
    }
    // Strict mode: setup in config is numeric-only.
    return 0;
}

$remote = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$allowed = is_local_client($remote);
$defaultSignalsSetup = '0';
$defaultSignalsFeedUrl = trim((string)(getenv('SIGNALS_FEED_URL') ?: getenv('BOT_SIGNALS_FEED_URL') ?: 'http://signals-legacy/'));
if ($defaultSignalsFeedUrl === '') {
    $defaultSignalsFeedUrl = 'http://signals-legacy/';
}

$otpInfo = '';
$otpErr = '';
$otpState = admin_otp_load_state();
$botOk = '';
$botErr = '';
$botsList = [];
$firstBot = '';
$selectedBot = trim((string)($_GET['bot_select'] ?? $_POST['bot_select'] ?? ''));

if ($allowed && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'otp_generate') {
    $res = admin_otp_activate_once($remote);
    if (!empty($res['ok'])) {
      $token = (string)$res['token'];
      $expires = (string)$res['expires_at'];
      $otpInfo = 'One-time OTP generated: ' . $token . ($expires !== '' ? (' (expires ' . $expires . ' UTC)') : '');
      $otpState = admin_otp_load_state();
    } else {
      $otpErr = (string)($res['error'] ?? 'OTP generation failed');
    }
    } elseif ($action === 'otp_set_mode') {
    $newMode = strtolower(trim((string)($_POST['otp_mode'] ?? '')));
    if (in_array($newMode, ['off', 'on', 'required'], true)) {
      $modeState = admin_otp_load_state();
      $modeState['mode'] = $newMode;
      if (admin_otp_save_state($modeState)) {
        $otpInfo = 'OTP mode set to: ' . $newMode;
        $otpState = admin_otp_load_state();
      } else {
        $otpErr = 'Failed to save OTP mode (cannot write config file)';
      }
    } else {
      $otpErr = 'Invalid mode value';
    }
    } elseif ($action === 'create_bot') {
    mysqli_report(MYSQLI_REPORT_OFF);
    $botMysqli = init_remote_db('trading');
    if ($botMysqli) {
      $cfgIn = normalize_signals_setup_cfg((array)($_POST['config'] ?? []), $defaultSignalsSetup);
      $res = bot_create(
        $botMysqli,
        (string)($_POST['bot_name'] ?? ''),
        intval($_POST['account_id'] ?? 0),
        $cfgIn
      );
      @$botMysqli->close();
      if ($res['ok']) {
        $botOk = 'Bot created successfully: ' . htmlspecialchars($res['applicant'], ENT_QUOTES, 'UTF-8');
      } else {
        $botErr = $res['error'];
      }
    } else {
      $botErr = 'Database not available';
    }
    } elseif ($action === 'update_bot_cfg') {
    $applicant = trim((string)($_POST['applicant'] ?? ''));
    $cfg = normalize_signals_setup_cfg((array)($_POST['cfg'] ?? $_POST['config'] ?? []), $defaultSignalsSetup);
    mysqli_report(MYSQLI_REPORT_OFF);
    $botMysqli = init_remote_db('trading');
    if (!$botMysqli) {
      $botErr = 'Database not available';
    } elseif ($applicant === '') {
      $botErr = 'Applicant is required';
      @$botMysqli->close();
    } else {
      $appEsc = $botMysqli->real_escape_string($applicant);
      $tableName = $botMysqli->select_value('table_name', 'config__table_map', "WHERE applicant = '$appEsc'");
      if (!$tableName) {
        $botErr = 'Bot not found: ' . $applicant;
        @$botMysqli->close();
      } else {
        $accountId = intval($botMysqli->select_value('account_id', $tableName) ?: 0);
        if ($accountId <= 0) {
          $botErr = 'Invalid account for bot: ' . $applicant;
          @$botMysqli->close();
        } else {
          $allowedCfgKeys = [
            'exchange',
            'trade_enabled',
            'position_coef',
            'monitor_enabled',
            'min_order_cost',
            'max_order_cost',
            'max_limit_distance',
            'signals_setup',
            'signals_feed_url',
            'report_color',
            'debug_pair',
            'api_secret_sep',
            'secret_key_encrypted',
          ];
          foreach ($cfg as $k => $v) {
            if (!in_array($k, $allowedCfgKeys, true)) {
              continue;
            }
            $kEsc = $botMysqli->real_escape_string((string)$k);
            $vEsc = $botMysqli->real_escape_string((string)$v);
            $exists = $botMysqli->select_value('param', $tableName, "WHERE account_id = $accountId AND param = '$kEsc'");
            if ($exists) {
              $botMysqli->try_query("UPDATE `$tableName` SET `value` = '$vEsc' WHERE account_id = $accountId AND param = '$kEsc'");
            } else {
              $botMysqli->try_query("INSERT INTO `$tableName` (account_id, param, value) VALUES ($accountId, '$kEsc', '$vEsc')");
            }
          }
          if ($botMysqli->error !== '') {
            $botErr = 'Update failed: ' . $botMysqli->error;
          } else {
            $botOk = 'Bot config updated: ' . htmlspecialchars($applicant, ENT_QUOTES, 'UTF-8');
          }
          @$botMysqli->close();
        }
      }
    }
    }
}

$dbState = [
    'connected' => false,
    'error' => '',
    'role' => 'unknown',
    'replication_ok' => false,
    'replication_note' => 'n/a',
    'seconds_behind' => null,
    'binlog_bytes' => 0,
    'relay_bytes' => 0,
    'binlog_warn' => false,
    'relay_warn' => false,
];

$mysqlLogTail = '';
$mysqlLogPath = trim((string)(getenv('MYSQL_ERROR_LOG_PATH') ?: '/app/var/log/mysql/mysql.log'));

mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = init_remote_db('trading');
if ($mysqli) {
    $dbState['connected'] = true;

    $replica = null;
    $replicaRes = @$mysqli->query('SHOW REPLICA STATUS');
    if ($replicaRes instanceof mysqli_result) {
    $replica = $replicaRes->fetch_assoc();
    $replicaRes->free();
    } else {
    $slaveRes = @$mysqli->query('SHOW SLAVE STATUS');
    if ($slaveRes instanceof mysqli_result) {
      $replica = $slaveRes->fetch_assoc();
      $slaveRes->free();
    }
    }

    if (is_array($replica) && count($replica) > 0) {
    $dbState['role'] = 'replica';
    $io = strtolower((string)($replica['Replica_IO_Running'] ?? $replica['Slave_IO_Running'] ?? 'no'));
    $sql = strtolower((string)($replica['Replica_SQL_Running'] ?? $replica['Slave_SQL_Running'] ?? 'no'));
    $dbState['replication_ok'] = ($io === 'yes' && $sql === 'yes');
    $dbState['seconds_behind'] = $replica['Seconds_Behind_Master'] ?? null;
    $dbState['replication_note'] = sprintf('io=%s, sql=%s', $io, $sql);
    } else {
    $masterRes = @$mysqli->query('SHOW MASTER STATUS');
    if ($masterRes instanceof mysqli_result) {
      $row = $masterRes->fetch_assoc();
      $masterRes->free();
      $dbState['role'] = is_array($row) ? 'primary' : 'standalone';
      $dbState['replication_ok'] = true;
      $dbState['replication_note'] = ($dbState['role'] === 'primary') ? 'master binlog active' : 'no replica configured';
    }
    }

    $binlogRes = @$mysqli->query('SHOW BINARY LOGS');
    if ($binlogRes instanceof mysqli_result) {
    $sum = 0;
    while ($r = $binlogRes->fetch_assoc()) {
      $sum += intval($r['File_size'] ?? 0);
    }
    $binlogRes->free();
    $dbState['binlog_bytes'] = $sum;
    }

    $relayRes = @$mysqli->query("SHOW GLOBAL STATUS LIKE 'Relay_log_space'");
    if ($relayRes instanceof mysqli_result) {
    $relay = $relayRes->fetch_assoc();
    $relayRes->free();
    $dbState['relay_bytes'] = intval($relay['Value'] ?? 0);
    }

    $binWarn = intval(getenv('BINLOG_WARN_BYTES') ?: '1073741824');
    $relWarn = intval(getenv('RELAY_WARN_BYTES') ?: '1073741824');
    $dbState['binlog_warn'] = ($dbState['binlog_bytes'] >= $binWarn && $binWarn > 0);
    $dbState['relay_warn'] = ($dbState['relay_bytes'] >= $relWarn && $relWarn > 0);

    $tableMap = $mysqli->select_map('applicant,table_name', 'config__table_map', 'ORDER BY applicant');
    if (is_array($tableMap)) {
    foreach ($tableMap as $applicant => $cfgTable) {
      if ($firstBot === '') {
        $firstBot = (string)$applicant;
      }
      $accountId = intval($mysqli->select_value('account_id', $cfgTable) ?: 0);
      $cfg = $mysqli->select_map('param,value', $cfgTable, $accountId > 0 ? "WHERE account_id = $accountId" : '');
      if (!is_array($cfg)) {
        $cfg = [];
      }
      $prefix = (strpos((string)$cfgTable, 'config__') === 0) ? substr((string)$cfgTable, 8) : (string)$cfgTable;
      $required = ['positions', 'pairs_map', 'tickers', 'ticker_map', 'matched_orders', 'last_errors', 'events'];
      $missing = [];
      foreach ($required as $suffix) {
        $tbl = $prefix . '__' . $suffix;
        $exists = $mysqli->select_value('TABLE_NAME', 'INFORMATION_SCHEMA.TABLES', "WHERE TABLE_SCHEMA = 'trading' AND TABLE_NAME = '$tbl'");
        if (!$exists) {
          $missing[] = $tbl;
        }
      }

      $botsList[] = [
        'applicant' => (string)$applicant,
        'cfg_table' => (string)$cfgTable,
        'prefix' => (string)$prefix,
        'account_id' => $accountId,
        'config' => $cfg,
        'exchange' => (string)($cfg['exchange'] ?? ''),
        'trade_enabled' => (string)($cfg['trade_enabled'] ?? ''),
        'debug_pair' => (string)($cfg['debug_pair'] ?? ''),
        'position_coef' => (string)($cfg['position_coef'] ?? ''),
        'max_order_cost' => (string)($cfg['max_order_cost'] ?? ''),
        'api_secret_sep' => (string)($cfg['api_secret_sep'] ?? '-'),
        'secret_key_encrypted' => (string)($cfg['secret_key_encrypted'] ?? '0'),
        'missing' => $missing,
      ];
    }
    }

    // Keep create mode as default when no bot is explicitly selected.
    // Auto-selecting the first bot forces update mode and locks key fields.

    @$mysqli->close();
} else {
    $dbState['error'] = 'DB inaccessible';
    if (is_file($mysqlLogPath) && is_readable($mysqlLogPath)) {
    $lines = @file($mysqlLogPath, FILE_IGNORE_NEW_LINES);
    if (is_array($lines) && count($lines) > 0) {
      $slice = array_slice($lines, -60);
      $mysqlLogTail = implode("\n", $slice);
    }
    }
}

function fmt_bytes(int $v): string {
    if ($v <= 0) return '0 B';
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $x = (float)$v;
    while ($x >= 1024.0 && $i < count($u) - 1) {
    $x /= 1024.0;
    $i++;
    }
    return sprintf('%.2f %s', $x, $u[$i]);
}

$selectedBotData = null;
foreach ($botsList as $b) {
    if ($selectedBot !== '' && $b['applicant'] === $selectedBot) {
    $selectedBotData = $b;
    break;
    }
}

$formBotName = $selectedBotData ? preg_replace('/_bot$/', '', (string)$selectedBotData['applicant']) : 'bitmex';
$formAccountId = $selectedBotData ? intval($selectedBotData['account_id']) : 1;
$formCfg = [
    'exchange' => $selectedBotData['config']['exchange'] ?? 'bitmex',
    'trade_enabled' => $selectedBotData['config']['trade_enabled'] ?? '0',
    'position_coef' => $selectedBotData['config']['position_coef'] ?? '0.010000',
    'monitor_enabled' => $selectedBotData['config']['monitor_enabled'] ?? '1',
    'min_order_cost' => $selectedBotData['config']['min_order_cost'] ?? '20',
    'max_order_cost' => $selectedBotData['config']['max_order_cost'] ?? '100',
    'max_limit_distance' => $selectedBotData['config']['max_limit_distance'] ?? '0.003',
    'signals_setup' => $selectedBotData['config']['signals_setup'] ?? $defaultSignalsSetup,
    'signals_feed_url' => $selectedBotData['config']['signals_feed_url'] ?? $defaultSignalsFeedUrl,
    'setup_id' => extract_setup_id_from_cfg((string)($selectedBotData['config']['signals_setup'] ?? $defaultSignalsSetup)),
    'report_color' => $selectedBotData['config']['report_color'] ?? '32,42,56',
    'debug_pair' => $selectedBotData['config']['debug_pair'] ?? 'XBTUSD',
    'api_secret_sep' => $selectedBotData['config']['api_secret_sep'] ?? '-',
    'secret_key_encrypted' => $selectedBotData['config']['secret_key_encrypted'] ?? '0',
];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TradeBot Basic Admin</title>
    <link rel="stylesheet" href="dark-theme.css">
    <link rel="stylesheet" href="apply-theme.css">
    <link rel="stylesheet" href="colors.css">
    <style>
    body { margin: 0; padding: 24px; font-family: Arial, sans-serif; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; }
    .card { border: 1px solid #4a4a4a; border-radius: 8px; background: rgba(20, 20, 20, 0.92); padding: 16px; }
    .card h2 { margin: 0 0 10px 0; font-size: 18px; }
    .muted { color: #b0b0b0; font-size: 12px; }
    .ok { color: #84e184; }
    .warn { color: #ffcb6b; }
    .err { color: #ff7b7b; }
    .actions a { display: inline-block; margin-right: 8px; margin-bottom: 8px; padding: 8px 12px; border: 1px solid #5f5f5f; border-radius: 6px; text-decoration: none; color: #f0f0f0; }
    .actions a:hover { background: rgba(255, 255, 255, 0.08); }
    form label { display: block; margin-top: 10px; font-size: 13px; }
    input, textarea, select { width: 100%; box-sizing: border-box; margin-top: 4px; padding: 8px; border: 1px solid #666; border-radius: 4px; background: #111; color: #f0f0f0; }
    button { margin-top: 12px; padding: 9px 14px; border: 1px solid #6a6a6a; border-radius: 6px; background: #1f1f1f; color: #fff; cursor: pointer; }
    button:hover { background: #2c2c2c; }
    .mono { font-family: Consolas, monospace; font-size: 12px; }
    .mono-pre { font-family: Consolas, monospace; font-size: 12px; white-space: pre-wrap; word-break: break-word; overflow-wrap: anywhere; max-height: 240px; overflow: auto; }
    .otp { font-size: 16px; letter-spacing: 1px; font-weight: bold; }
    </style>
</head>
<body>
    <h1>TradeBot Basic Admin</h1>
    <p class="muted">Bootstrap interface for local deployment when TS admin is not deployed on the same machine.</p>

    <div class="card" style="margin-bottom:16px;">
    <div><b>Client:</b> <span class="mono"><?php echo htmlspecialchars($remote, ENT_QUOTES, 'UTF-8'); ?></span></div>
    <?php if ($allowed): ?>
      <div class="ok">Local access enabled (passwordless bootstrap mode).</div>
    <?php else: ?>
      <div class="err">Access is not local/private. Bootstrap actions are disabled.</div>
    <?php endif; ?>
    </div>

    <div class="grid">
    <section class="card">
      <h2>Admin OTP (Single-Use)</h2>
      <div class="muted">Mode: <span class="mono"><?php echo htmlspecialchars(admin_otp_mode(), ENT_QUOTES, 'UTF-8'); ?></span></div>
      <div class="muted">Config file: <span class="mono"><?php echo htmlspecialchars(admin_otp_config_path(), ENT_QUOTES, 'UTF-8'); ?></span></div>
      <?php if ($otpInfo !== ''): ?>
        <div class="ok otp"><?php echo htmlspecialchars($otpInfo, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="warn">Copy now: plaintext OTP is shown only once. Stored config keeps only hash.</div>
      <?php endif; ?>
      <?php if ($otpErr !== ''): ?>
        <div class="err"><?php echo htmlspecialchars($otpErr, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>
      <div class="muted">Active: <span class="mono"><?php echo !empty($otpState['enabled']) ? 'yes' : 'no'; ?></span></div>
      <div class="muted">Generated: <span class="mono"><?php echo htmlspecialchars((string)($otpState['generated_at'] ?: '-'), ENT_QUOTES, 'UTF-8'); ?></span></div>
      <div class="muted">Expires: <span class="mono"><?php echo htmlspecialchars((string)($otpState['expires_at'] ?: '-'), ENT_QUOTES, 'UTF-8'); ?></span></div>
      <div class="muted">Used: <span class="mono"><?php echo htmlspecialchars((string)($otpState['used_at'] ?: '-'), ENT_QUOTES, 'UTF-8'); ?></span></div>
      <form method="post" style="display:inline-block; margin-right:8px;">
        <input type="hidden" name="action" value="otp_generate">
        <button type="submit" <?php echo $allowed ? '' : 'disabled'; ?>>Generate One-Time OTP</button>
      </form>
      <form method="post" style="display:inline-block; margin-top:10px;">
        <input type="hidden" name="action" value="otp_set_mode">
        <select name="otp_mode" <?php echo $allowed ? '' : 'disabled'; ?> style="width:auto; display:inline-block; margin:0;">
          <?php foreach (['off', 'on', 'required'] as $m): ?>
            <option value="<?php echo $m; ?>" <?php echo (admin_otp_mode() === $m) ? 'selected' : ''; ?>><?php echo $m; ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" <?php echo $allowed ? '' : 'disabled'; ?>>Set Mode</button>
      </form>
      <p class="muted">Where to see actual value: it is printed once above and also logged in API container logs as <span class="mono">[ADMIN_OTP_TOKEN]</span>.</p>
      <p class="muted">Example: <span class="mono">docker logs trd-web | findstr ADMIN_OTP_TOKEN</span></p>
    </section>

    <section class="card">
      <h2>DB and Replication State</h2>
      <?php if (!$dbState['connected']): ?>
        <div class="err">Database: down (<?php echo htmlspecialchars($dbState['error'], ENT_QUOTES, 'UTF-8'); ?>)</div>
        <div class="muted">MySQL error log path: <span class="mono"><?php echo htmlspecialchars($mysqlLogPath, ENT_QUOTES, 'UTF-8'); ?></span></div>
        <?php if ($mysqlLogTail !== ''): ?>
          <p class="warn">Tail (last ~60 lines):</p>
          <pre class="mono-pre"><?php echo htmlspecialchars($mysqlLogTail, ENT_QUOTES, 'UTF-8'); ?></pre>
        <?php else: ?>
          <p class="warn">MySQL log not found/readable yet. If needed, ensure mariadb writes error log and host folder is shared.</p>
        <?php endif; ?>
      <?php else: ?>
        <div class="ok">Database: connected</div>
        <div>Role: <span class="mono"><?php echo htmlspecialchars($dbState['role'], ENT_QUOTES, 'UTF-8'); ?></span></div>
        <div>Replication: <span class="mono <?php echo $dbState['replication_ok'] ? 'ok' : 'err'; ?>"><?php echo $dbState['replication_ok'] ? 'ok' : 'problem'; ?></span></div>
        <div class="muted">Details: <span class="mono"><?php echo htmlspecialchars($dbState['replication_note'], ENT_QUOTES, 'UTF-8'); ?></span></div>
        <div class="muted">Seconds behind master: <span class="mono"><?php echo htmlspecialchars((string)($dbState['seconds_behind'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></div>
        <div>Binary logs size: <span class="mono <?php echo $dbState['binlog_warn'] ? 'warn' : 'ok'; ?>"><?php echo htmlspecialchars(fmt_bytes((int)$dbState['binlog_bytes']), ENT_QUOTES, 'UTF-8'); ?></span></div>
        <div>Relay logs size: <span class="mono <?php echo $dbState['relay_warn'] ? 'warn' : 'ok'; ?>"><?php echo htmlspecialchars(fmt_bytes((int)$dbState['relay_bytes']), ENT_QUOTES, 'UTF-8'); ?></span></div>
      <?php endif; ?>
    </section>

    <?php if ($dbState['connected']): ?>
      <section class="card">
        <h2>Bot Form (Create / Update)</h2>
        <p class="muted">Select an existing bot to load fields and update it, or keep New Bot to create.</p>
        <?php if ($botOk !== ''): ?>
          <div class="ok"><?php echo htmlspecialchars($botOk, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($botErr !== ''): ?>
          <div class="err"><?php echo htmlspecialchars($botErr, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form method="get" action="basic-admin.php">
          <label>Existing Bot
            <select name="bot_select" onchange="this.form.submit()">
              <option value="">-- New Bot --</option>
              <?php foreach ($botsList as $b): ?>
                <option value="<?php echo htmlspecialchars($b['applicant'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($selectedBot === $b['applicant']) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($b['applicant'], ENT_QUOTES, 'UTF-8'); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
        </form>

        <?php if ($selectedBotData): ?>
          <div class="muted">Health:
            <?php if (count($selectedBotData['missing']) === 0): ?>
              <span class="ok">ok</span>
            <?php else: ?>
              <span class="warn">missing <?php echo htmlspecialchars(implode(', ', $selectedBotData['missing']), ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
          </div>
          <div class="actions" style="margin-top:8px;">
            <a href="/dashboard.php?bot=<?php echo urlencode($selectedBotData['applicant']); ?>&account=<?php echo intval($selectedBotData['account_id']); ?>" target="_blank" rel="noopener">Open Dashboard</a>
          </div>
        <?php endif; ?>

        <form method="post" action="basic-admin.php">
          <input type="hidden" name="action" value="<?php echo $selectedBotData ? 'update_bot_cfg' : 'create_bot'; ?>">
          <input type="hidden" name="bot_select" value="<?php echo htmlspecialchars($selectedBot, ENT_QUOTES, 'UTF-8'); ?>">
          <?php if ($selectedBotData): ?>
            <input type="hidden" name="applicant" value="<?php echo htmlspecialchars($selectedBotData['applicant'], ENT_QUOTES, 'UTF-8'); ?>">
          <?php endif; ?>
          <label>Bot Name
            <input name="bot_name" value="<?php echo htmlspecialchars($formBotName, ENT_QUOTES, 'UTF-8'); ?>" required <?php echo $allowed ? '' : 'disabled'; ?> <?php echo $selectedBotData ? 'readonly' : ''; ?>>
          </label>
          <label>Account ID
            <input name="account_id" type="number" value="<?php echo intval($formAccountId); ?>" min="1" required <?php echo $allowed ? '' : 'disabled'; ?> <?php echo $selectedBotData ? 'readonly' : ''; ?>>
          </label>
          <label>Exchange
            <select name="config[exchange]" <?php echo $allowed ? '' : 'disabled'; ?>>
              <?php foreach (['bitmex','binance','bitfinex','deribit','bybit'] as $x): ?>
                <option value="<?php echo $x; ?>" <?php echo ($formCfg['exchange'] === $x) ? 'selected' : ''; ?>><?php echo $x; ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Trade Enabled
            <input name="config[trade_enabled]" value="<?php echo htmlspecialchars((string)$formCfg['trade_enabled'], ENT_QUOTES, 'UTF-8'); ?>" required <?php echo $allowed ? '' : 'disabled'; ?>>
          </label>
          <label>Position Coef
            <input name="config[position_coef]" value="<?php echo htmlspecialchars((string)$formCfg['position_coef'], ENT_QUOTES, 'UTF-8'); ?>" required <?php echo $allowed ? '' : 'disabled'; ?>>
          </label>
          <label>Monitor Enabled
            <input name="config[monitor_enabled]" value="<?php echo htmlspecialchars((string)$formCfg['monitor_enabled'], ENT_QUOTES, 'UTF-8'); ?>" required <?php echo $allowed ? '' : 'disabled'; ?>>
          </label>
          <label>Min Order Cost
            <input name="config[min_order_cost]" value="<?php echo htmlspecialchars((string)$formCfg['min_order_cost'], ENT_QUOTES, 'UTF-8'); ?>" required <?php echo $allowed ? '' : 'disabled'; ?>>
          </label>
          <label>Max Order Cost
            <input name="config[max_order_cost]" value="<?php echo htmlspecialchars((string)$formCfg['max_order_cost'], ENT_QUOTES, 'UTF-8'); ?>" required <?php echo $allowed ? '' : 'disabled'; ?>>
          </label>
          <label>Max Limit Distance
            <input name="config[max_limit_distance]" value="<?php echo htmlspecialchars((string)$formCfg['max_limit_distance'], ENT_QUOTES, 'UTF-8'); ?>" required <?php echo $allowed ? '' : 'disabled'; ?>>
          </label>
          <label>Signals Setup ID <span class="muted">(simple mode, auto-syncs JSON)</span>
            <input name="config[setup_id]" type="number" min="0" value="<?php echo intval($formCfg['setup_id']); ?>" <?php echo $allowed ? '' : 'disabled'; ?>>
          </label>
          <label>Signals Feed URL <span class="muted">(defaults from env SIGNALS_FEED_URL)</span>
            <input name="config[signals_feed_url]" value="<?php echo htmlspecialchars((string)$formCfg['signals_feed_url'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="http://signals-legacy/" <?php echo $allowed ? '' : 'disabled'; ?>>
          </label>
          <label>Report Color
            <input name="config[report_color]" value="<?php echo htmlspecialchars((string)$formCfg['report_color'], ENT_QUOTES, 'UTF-8'); ?>" required <?php echo $allowed ? '' : 'disabled'; ?>>
          </label>
          <label>Debug Pair
            <input name="config[debug_pair]" value="<?php echo htmlspecialchars((string)$formCfg['debug_pair'], ENT_QUOTES, 'UTF-8'); ?>" required <?php echo $allowed ? '' : 'disabled'; ?>>
          </label>
          <label>Secret Key Separator <span class="muted">(optional, default: -)</span>
            <input name="config[api_secret_sep]" value="<?php echo htmlspecialchars((string)$formCfg['api_secret_sep'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="-" <?php echo $allowed ? '' : 'disabled'; ?>>
          </label>
          <label>DB Secret Encrypted <span class="muted">(0 = plain/split, 1 = encrypted api_secret)</span>
            <select name="config[secret_key_encrypted]" <?php echo $allowed ? '' : 'disabled'; ?>>
              <option value="0" <?php echo ((string)$formCfg['secret_key_encrypted'] === '0') ? 'selected' : ''; ?>>0</option>
              <option value="1" <?php echo ((string)$formCfg['secret_key_encrypted'] === '1') ? 'selected' : ''; ?>>1</option>
            </select>
          </label>
          <button type="submit" <?php echo $allowed ? '' : 'disabled'; ?>><?php echo $selectedBotData ? 'Update Bot' : 'Create Bot'; ?></button>
        </form>
      </section>
    <?php else: ?>
      <section class="card">
        <h2>Create Bot (Basic)</h2>
        <div class="warn">Form hidden because DB is currently unavailable.</div>
      </section>
    <?php endif; ?>

    <section class="card">
      <h2>Next Step: Inject API Keys</h2>
      <p class="muted">Recommended interactive helper (asks all fields step-by-step):</p>
      <pre class="mono-pre">PowerShell: ./scripts/inject-api-keys-interactive.ps1
    Shell:      sh scripts/inject-api-keys-interactive.sh</pre>
      <p class="muted">Non-interactive mode is still available:</p>
      <pre class="mono-pre">CREDENTIAL_SOURCE=pass EXCHANGE=bitmex ACCOUNT_ID=1 API_KEY='k' API_SECRET_S0='s0' API_SECRET_S1='s1' sh scripts/inject-api-keys.sh
    CREDENTIAL_SOURCE=db BOT_NAME=bitmex_bot ACCOUNT_ID=1 API_KEY='k' API_SECRET='s' sh scripts/inject-api-keys.sh</pre>
      <p class="warn">For production, keep this bootstrap page reachable only from local/private network.</p>
    </section>

    <section class="card">
      <h2>Bridge with Legacy UI</h2>
      <p class="muted">Use these links to hop between bootstrap admin and legacy pages:</p>
      <div class="actions">
        <a href="/index.php" rel="noopener">Open Legacy Index</a>
        <a href="/dashboard.php?bot=<?php echo urlencode($firstBot !== '' ? $firstBot : 'bitmex_bot'); ?>&account=1" rel="noopener">Open Legacy Dashboard</a>
        <a href="/basic-admin.php" rel="noopener">Reload Basic Admin</a>
      </div>
    </section>
    </div>
</body>
</html>
