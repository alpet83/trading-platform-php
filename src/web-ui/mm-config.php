<?php
/**
 * mm-config.php — Market Maker configurator.
 * Allows viewing and editing mm_config rows for a selected bot and pair.
 */

require_once('lib/common.php');
require_once('lib/db_config.php');
require_once('lib/db_tools.php');
require_once('lib/auth_check.php');

$is_admin  = str_in($user_rights, 'admin');
$is_trader = str_in($user_rights, 'trade');

if (!$is_admin && !$is_trader) {
    http_response_code(403);
    die('<h3>Access denied. Trader or admin rights required.</h3>');
}

$mysqli = init_remote_db('trading');

// ─── Bot list ─────────────────────────────────────────────────────────────────
$botsMap = $mysqli->select_map('applicant,table_name', 'config__table_map', 'ORDER BY applicant') ?? [];

// ─── Selected bot ─────────────────────────────────────────────────────────────
$selectedBot = trim($_GET['bot'] ?? '');
if ($selectedBot !== '' && !isset($botsMap[$selectedBot])) {
    $selectedBot = '';
}
if ($selectedBot === '' && count($botsMap) > 0) {
    $selectedBot = array_key_first($botsMap);
}

$cfgTable  = $botsMap[$selectedBot] ?? '';
$botPrefix = '';
$accId     = 0;

if ($cfgTable !== '') {
    $botPrefix = (strpos($cfgTable, 'config__') === 0) ? substr($cfgTable, 8) : '';
    // Fallback: derive prefix from exchange param
    if ($botPrefix === '') {
        $exch = strtolower((string)($mysqli->select_value('value', $cfgTable, "WHERE param = 'exchange' LIMIT 1") ?? ''));
        $botPrefix = $exch;
    }
    $accId = intval($mysqli->select_value('account_id', 'config__table_map', "WHERE table_name = '$cfgTable' LIMIT 1") ?? 0);
}

$mmTable     = $botPrefix !== '' ? "{$botPrefix}__mm_config" : '';
$tickerTable = $botPrefix !== '' ? "{$botPrefix}__tickers"   : '';

// ─── Tickers (pair_id → symbol) ───────────────────────────────────────────────
$tickersMap = [];  // pair_id => symbol
if ($tickerTable !== '') {
    $trows = $mysqli->select_rows('pair_id,symbol', $tickerTable, '', MYSQLI_ASSOC) ?? [];
    foreach ($trows as $tr) {
        $pid = intval($tr['pair_id'] ?? 0);
        if ($pid > 0) {
            $tickersMap[$pid] = strtoupper((string)($tr['symbol'] ?? ''));
        }
    }
}

// ─── Defaults ─────────────────────────────────────────────────────────────────
const MM_DEFAULTS = [
    'delta'         => 1.2,
    'step'          => 1.0,
    'max_orders'    => 1,
    'order_cost'    => 50.0,
    'max_exec_cost' => 1000.0,
    'enabled'       => 0,
];

// ─── POST: save row ───────────────────────────────────────────────────────────
$saveMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mmTable !== '' && $accId > 0) {
    $pairId      = intval($_POST['pair_id']      ?? 0);
    $enabled     = isset($_POST['enabled']) ? 1 : 0;
    $delta       = floatval($_POST['delta']       ?? MM_DEFAULTS['delta']);
    $step        = floatval($_POST['step']        ?? MM_DEFAULTS['step']);
    $maxOrders   = intval($_POST['max_orders']    ?? MM_DEFAULTS['max_orders']);
    $orderCost   = floatval($_POST['order_cost']  ?? MM_DEFAULTS['order_cost']);
    $maxExecCost = floatval($_POST['max_exec_cost'] ?? MM_DEFAULTS['max_exec_cost']);

    // Basic range validation
    if ($pairId <= 0) {
        $saveMsg = '<span style="color:#f88">Error: invalid pair.</span>';
    } elseif ($delta <= 0 || $step <= 0 || $maxOrders < 1 || $orderCost <= 0 || $maxExecCost <= 0) {
        $saveMsg = '<span style="color:#f88">Error: all numeric fields must be positive.</span>';
    } else {
        $stmt = $mysqli->prepare(
            "INSERT INTO `{$mmTable}` (pair_id, account_id, enabled, delta, step, max_orders, order_cost, max_exec_cost)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 enabled = VALUES(enabled),
                 delta = VALUES(delta),
                 step = VALUES(step),
                 max_orders = VALUES(max_orders),
                 order_cost = VALUES(order_cost),
                 max_exec_cost = VALUES(max_exec_cost)"
        );
        if ($stmt) {
            $stmt->bind_param('iiiddidd', $pairId, $accId, $enabled, $delta, $step, $maxOrders, $orderCost, $maxExecCost);
            if ($stmt->execute()) {
                $saveMsg = '<span style="color:#8f8">Saved successfully.</span>';
            } else {
                $saveMsg = '<span style="color:#f88">DB error: ' . htmlspecialchars($stmt->error) . '</span>';
            }
            $stmt->close();
        } else {
            $saveMsg = '<span style="color:#f88">Prepare error: ' . htmlspecialchars($mysqli->error) . '</span>';
        }
    }
}

// ─── Load current MM config rows ──────────────────────────────────────────────
$mmRows = [];
if ($mmTable !== '' && $accId > 0) {
    $mmRows = $mysqli->select_rows(
        'pair_id,enabled,delta,step,max_orders,order_cost,max_exec_cost',
        $mmTable,
        "WHERE account_id = {$accId} ORDER BY pair_id",
        MYSQLI_ASSOC
    ) ?? [];
}

// ─── Pre-fill edit form (from ?edit=pair_id or POST redirect via pair_id) ─────
$editPairId = intval($_GET['edit'] ?? 0);
$formData   = MM_DEFAULTS;
$formData['pair_id'] = $editPairId;

if ($editPairId > 0 && $mmTable !== '' && $accId > 0) {
    $stmt = $mysqli->prepare(
        "SELECT pair_id, enabled, delta, step, max_orders, order_cost, max_exec_cost
           FROM `{$mmTable}` WHERE pair_id = ? AND account_id = ? LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('ii', $editPairId, $accId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $formData = array_merge($formData, $row);
        }
        $stmt->close();
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pair_id'])) {
    // keep posted values in form on save so user sees what was submitted
    $formData = array_merge($formData, [
        'pair_id'       => intval($_POST['pair_id'] ?? 0),
        'enabled'       => isset($_POST['enabled']) ? 1 : 0,
        'delta'         => $_POST['delta']         ?? MM_DEFAULTS['delta'],
        'step'          => $_POST['step']          ?? MM_DEFAULTS['step'],
        'max_orders'    => $_POST['max_orders']    ?? MM_DEFAULTS['max_orders'],
        'order_cost'    => $_POST['order_cost']    ?? MM_DEFAULTS['order_cost'],
        'max_exec_cost' => $_POST['max_exec_cost'] ?? MM_DEFAULTS['max_exec_cost'],
    ]);
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function fmt_float(mixed $v, int $dec = 4): string {
    return number_format(floatval($v), $dec, '.', '');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MM Config — <?= htmlspecialchars($selectedBot) ?></title>
    <style>
        body { background: #1a1a2e; color: #e0e0e0; font-family: monospace; margin: 0; padding: 0; }
        .container { padding: 16px; }
        h2 { color: #aaddff; margin-top: 0; }
        label { display: inline-block; min-width: 120px; color: #aaa; }
        input[type=number], input[type=text], select {
            background: #1e1e3a; color: #e0e0e0; border: 1px solid #444;
            padding: 4px 8px; border-radius: 4px; width: 180px;
        }
        input[type=checkbox] { transform: scale(1.3); margin-left: 4px; vertical-align: middle; }
        .form-row { margin: 6px 0; }
        button, .btn {
            background: #2255aa; color: #fff; border: none; padding: 6px 16px;
            border-radius: 4px; cursor: pointer; font-family: monospace; text-decoration: none;
        }
        button:hover, .btn:hover { background: #3366cc; }
        .btn-edit { background: #336622; }
        .btn-edit:hover { background: #448833; }
        table { border-collapse: collapse; width: 100%; margin-top: 12px; }
        th { background: #2a2a4a; color: #aaddff; padding: 6px 10px; text-align: left; }
        td { padding: 5px 10px; border-bottom: 1px solid #2a2a3a; }
        tr:hover td { background: #1e1e3a; }
        .enabled-yes { color: #8f8; }
        .enabled-no  { color: #f88; }
        .bot-select-form { display: flex; gap: 12px; align-items: center; margin-bottom: 20px; }
        .bot-select-form select { width: auto; }
        .section-title { color: #88aaff; font-size: 1.1em; margin: 24px 0 8px; border-bottom: 1px solid #333; padding-bottom: 4px; }
        .save-msg { margin: 8px 0; font-weight: bold; }
        .form-card { background: #12122a; border: 1px solid #333; border-radius: 6px; padding: 16px; max-width: 520px; }
    </style>
</head>
<body>

<?php require_once 'nav.php'; ?>

<div class="container">
    <h2>Market Maker Configurator</h2>

    <!-- Bot selector -->
    <form method="get" class="bot-select-form">
        <label for="bot_sel">Bot:</label>
        <select id="bot_sel" name="bot" onchange="this.form.submit()">
            <?php foreach ($botsMap as $appName => $tbl): ?>
            <option value="<?= htmlspecialchars($appName) ?>"<?= ($appName === $selectedBot) ? ' selected' : '' ?>>
                <?= htmlspecialchars($appName) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit">Select</button></noscript>
    </form>

    <?php if ($selectedBot !== '' && $botPrefix !== ''): ?>

    <!-- Current config table -->
    <div class="section-title">Current settings — <?= htmlspecialchars($selectedBot) ?> (account_id: <?= $accId ?>)</div>

    <?php if (empty($mmRows)): ?>
        <p style="color:#aaa;">No MM config rows found. Use the form below to add one.</p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Pair</th>
                <th>Enabled</th>
                <th>Delta</th>
                <th>Step</th>
                <th>Max Orders</th>
                <th>Order Cost</th>
                <th>Max Exec Cost</th>
                <th>Edit</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($mmRows as $row):
            $pid    = intval($row['pair_id'] ?? 0);
            $sym    = $tickersMap[$pid] ?? "pair #{$pid}";
            $enbl   = intval($row['enabled'] ?? 0);
            $enCls  = $enbl ? 'enabled-yes' : 'enabled-no';
            $enTxt  = $enbl ? 'YES' : 'NO';
            $editUrl = '?bot=' . urlencode($selectedBot) . '&edit=' . $pid . '#edit-form';
        ?>
            <tr>
                <td><?= htmlspecialchars($sym) ?></td>
                <td class="<?= $enCls ?>"><?= $enTxt ?></td>
                <td><?= fmt_float($row['delta'] ?? 0, 4) ?></td>
                <td><?= fmt_float($row['step']  ?? 0, 4) ?></td>
                <td><?= intval($row['max_orders'] ?? 0) ?></td>
                <td><?= fmt_float($row['order_cost'] ?? 0, 2) ?></td>
                <td><?= fmt_float($row['max_exec_cost'] ?? 0, 2) ?></td>
                <td><a class="btn btn-edit" href="<?= htmlspecialchars($editUrl) ?>">Edit</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Edit / Add form -->
    <div class="section-title" id="edit-form">
        <?= ($editPairId > 0 || ($_SERVER['REQUEST_METHOD'] === 'POST' && intval($_POST['pair_id'] ?? 0) > 0))
            ? 'Edit MM Config'
            : 'Add / Update MM Config' ?>
    </div>

    <?php if ($saveMsg !== ''): ?>
    <div class="save-msg"><?= $saveMsg ?></div>
    <?php endif; ?>

    <div class="form-card">
        <form method="post" action="?bot=<?= urlencode($selectedBot) ?>#edit-form">
            <div class="form-row">
                <label for="mm_pair">Pair:</label>
                <select id="mm_pair" name="pair_id" required>
                    <option value="">— select —</option>
                    <?php foreach ($tickersMap as $pid => $sym): ?>
                    <option value="<?= $pid ?>"<?= (intval($formData['pair_id'] ?? 0) === $pid) ? ' selected' : '' ?>>
                        <?= htmlspecialchars($sym) ?> (id: <?= $pid ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="mm_enabled">MM enabled:</label>
                <input type="checkbox" id="mm_enabled" name="enabled" value="1"
                    <?= intval($formData['enabled'] ?? 0) ? 'checked' : '' ?>>
            </div>
            <div class="form-row">
                <label for="mm_delta">Delta:</label>
                <input type="number" id="mm_delta" name="delta" step="0.0001" min="0.0001"
                    value="<?= htmlspecialchars((string)($formData['delta'] ?? MM_DEFAULTS['delta'])) ?>" required>
            </div>
            <div class="form-row">
                <label for="mm_step">Step:</label>
                <input type="number" id="mm_step" name="step" step="0.0001" min="0.0001"
                    value="<?= htmlspecialchars((string)($formData['step'] ?? MM_DEFAULTS['step'])) ?>" required>
            </div>
            <div class="form-row">
                <label for="mm_max_orders">Max Orders:</label>
                <input type="number" id="mm_max_orders" name="max_orders" step="1" min="1"
                    value="<?= intval($formData['max_orders'] ?? MM_DEFAULTS['max_orders']) ?>" required>
            </div>
            <div class="form-row">
                <label for="mm_order_cost">Order Cost:</label>
                <input type="number" id="mm_order_cost" name="order_cost" step="0.01" min="0.01"
                    value="<?= htmlspecialchars((string)($formData['order_cost'] ?? MM_DEFAULTS['order_cost'])) ?>" required>
            </div>
            <div class="form-row">
                <label for="mm_max_exec">Max Exec Cost:</label>
                <input type="number" id="mm_max_exec" name="max_exec_cost" step="0.01" min="0.01"
                    value="<?= htmlspecialchars((string)($formData['max_exec_cost'] ?? MM_DEFAULTS['max_exec_cost'])) ?>" required>
            </div>
            <div class="form-row" style="margin-top: 12px;">
                <button type="submit">Save</button>
                <?php if ($editPairId > 0): ?>
                <a class="btn" style="margin-left:8px; background:#444;"
                   href="?bot=<?= urlencode($selectedBot) ?>#edit-form">New row</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php elseif (empty($botsMap)): ?>
    <p style="color:#f88;">No bots found in config__table_map.</p>
    <?php endif; ?>

</div>
</body>
</html>
