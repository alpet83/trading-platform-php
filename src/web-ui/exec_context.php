<?php
    require_once('lib/common.php');
    require_once('lib/db_tools.php');
    require_once('lib/db_config.php');
    require_once('lib/admin_ip.php');
    require_once('lib/auth_check.php');
    require_once('lib/mini_core.php');

    if (!function_exists('esc')) {
        function esc(string $s): string {
            return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        }
    }

    if (!str_in($user_rights, 'view'))
        error_exit("Rights restricted to %s", $user_rights);

    $mysqli = init_remote_db('trading');
    if (!$mysqli)
        die("#FATAL: DB inaccessible!\n");

    $bot = rqs_param('bot', null) ?? ($_SESSION['bot'] ?? null);
    $bots = $mysqli->select_map('applicant,table_name', 'config__table_map');

    // Auto-select bot if only one configured
    if (!$bot && count($bots) == 1)
        $bot = array_key_first($bots);

    $contexts  = [];
    $bad_rows  = [];
    $table_name = '';
    if ($bot && isset($bots[$bot])) {
        $cfg_table  = $bots[$bot];
        $bot_prefix = (strpos($cfg_table, 'config__') === 0) ? substr($cfg_table, 8) : $cfg_table;
        $table_name = "{$bot_prefix}__exec_context";
        if ($mysqli->table_exists($table_name)) {
            $rows = $mysqli->select_rows('*', $table_name, 'ORDER BY ts DESC', MYSQLI_ASSOC);
            foreach ($rows as $row) {
                $snap = json_decode($row['context_json']);
                if ($snap !== null) {
                    $snap->_account_id = (int)$row['account_id'];
                    $snap->_pair_id    = (int)$row['pair_id'];
                    $snap->_ts_db      = $row['ts'];
                    $contexts[]        = $snap;
                } else {
                    $bad_rows[] = [
                        'account_id'   => (int)$row['account_id'],
                        'pair_id'      => (int)$row['pair_id'],
                        'ts'           => $row['ts'],
                        'json_preview' => mb_substr($row['context_json'], 0, 300),
                        'json_error'   => json_last_error_msg(),
                    ];
                }
            }
        }
    }

    // Sort by pair name for display stability
    usort($contexts, fn($a, $b) => strcmp($a->pair ?? '', $b->pair ?? ''));

    $action_colors = [
        'trade'  => '#2ecc71',
        'ignore' => '#95a5a6',
        'block'  => '#e74c3c',
        'wait'   => '#f39c12',
        'skip'   => '#7f8c8d',
    ];

    function fmt_amount(float $v, int $prec = 6): string {
        if ($v == 0) return '0';
        return number_format($v, $prec, '.', '');
    }

    function fmt_cost(float $v): string {
        return '$' . number_format($v, 2, '.', ',');
    }

    function action_badge(string $action, array $colors): string {
        $color = $colors[$action] ?? '#888';
        $label = strtoupper($action);
        return "<span style=\"background:{$color};color:#fff;padding:2px 8px;border-radius:3px;font-weight:bold;font-size:9pt\">{$label}</span>";
    }

    function dir_label(int $dir): string {
        if ($dir > 0) return "<span style='color:#2ecc71'>▲ BUY</span>";
        if ($dir < 0) return "<span style='color:#e74c3c'>▼ SELL</span>";
        return "<span style='color:#888'>— HOLD</span>";
    }
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Exec Context Viewer</title>
    <link rel="stylesheet" href="dark-theme.css">
    <link rel="stylesheet" href="apply-theme.css">
    <style>
        body { font-family: monospace; font-size: 10pt; margin: 8px; }
        h1   { font-size: 14pt; margin-bottom: 4px; }
        .ctx-card {
            border: 1px solid #444;
            border-radius: 4px;
            margin-bottom: 10px;
            padding: 8px 12px;
            background: #1a1a2e;
        }
        .ctx-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 6px;
            font-size: 11pt;
        }
        .ctx-pair   { font-weight: bold; font-size: 13pt; color: #e0e0e0; min-width: 90px; }
        .ctx-ts     { color: #888; font-size: 9pt; }
        .section    { display: flex; flex-wrap: wrap; gap: 24px; margin-top: 4px; }
        .block      { min-width: 160px; }
        .block h3   { font-size: 9pt; color: #aaa; margin: 0 0 2px 0; text-transform: uppercase; letter-spacing: 1px; }
        .kv         { display: flex; gap: 6px; margin: 1px 0; }
        .kv .k      { color: #9b9b9b; min-width: 90px; }
        .kv .v      { color: #e0e0e0; font-weight: bold; }
        .ignore-list { color: #e74c3c; font-size: 9pt; margin-top: 4px; }
        .detail     { color: #f39c12; font-size: 9pt; }
        .no-table   { color: #e74c3c; }
        .topbar     { display: flex; align-items: center; gap: 16px; margin-bottom: 12px; }
        .topbar h1  { margin: 0; font-size: 14pt; }
    </style>
    <script>
        function autoRefresh(sec) {
            setTimeout(() => location.reload(), sec * 1000);
        }
        document.addEventListener('DOMContentLoaded', () => {
            const el = document.getElementById('refresh-val');
            const v = parseInt(localStorage.getItem('ctx_refresh') || '30');
            el.value = v;
            if (v > 0) autoRefresh(v);
            el.addEventListener('change', () => {
                localStorage.setItem('ctx_refresh', el.value);
                location.reload();
            });
        });
    </script>
</head>
<body>
<div class="topbar">
    <a href="/index.php"><button>&#8592; Home</button></a>
    <h1>Exec Context<?= $bot ? ' &mdash; ' . esc($bot) : '' ?></h1>
    <span style="color:#888;font-size:9pt"><?= $table_name ? esc($table_name) : '' ?></span>
    <span style="margin-left:auto">
        Обновление: <select id="refresh-val">
            <option value="0">нет</option>
            <option value="10">10 с</option>
            <option value="30">30 с</option>
            <option value="60">1 мин</option>
        </select>
    </span>
</div>

<?php if (!$bot): ?>
    <p class="no-table">No bot specified. Use <code>?bot=name</code> or follow the [ctx] link from the Home page.</p>
<?php elseif (!$table_name || !$mysqli->table_exists($table_name)): ?>
    <p class="no-table">Table <b><?= esc($table_name ?: "{bot_prefix}__exec_context") ?></b> does not exist.
    Run the bot at least once or create tables via bot_creator.</p>
<?php elseif (empty($contexts) && empty($bad_rows)): ?>
    <p style="color:#888">Yet no activity.</p>
<?php else: ?>
    <?php foreach ($bad_rows as $br): ?>
    <div class="ctx-card" style="border-color:#e74c3c">
        <div class="ctx-header">
            <span class="ctx-pair" style="color:#e74c3c">pair#<?= (int)$br['pair_id'] ?></span>
            <span style="background:#e74c3c;color:#fff;padding:2px 8px;border-radius:3px;font-weight:bold;font-size:9pt">BAD JSON</span>
            <span class="ctx-ts"><?= esc($br['ts']) ?></span>
            <span class="detail">acc=<?= (int)$br['account_id'] ?> &mdash; <?= esc($br['json_error']) ?></span>
        </div>
        <pre style="color:#f39c12;font-size:8pt;white-space:pre-wrap;word-break:break-all;margin:4px 0 0"><?= esc($br['json_preview']) ?><?= mb_strlen($br['json_preview']) >= 300 ? '…' : '' ?></pre>
    </div>
    <?php endforeach; ?>
    <?php foreach ($contexts as $snap): ?>
    <?php
        $action  = $snap->action ?? 'unknown';
        $detail  = $snap->detail ?? '';
        $acolor  = $action_colors[$action] ?? '#888';
        $ts      = $snap->ts ?? $snap->_ts_db ?? '';
        $dir     = (int)($snap->trade_dir ?? 0);
        $curr    = (float)($snap->curr_pos ?? 0);
        $target  = (float)($snap->target_pos ?? 0);
        $bias    = (float)($snap->bias ?? 0);
        $cost    = (float)($snap->cost ?? 0);
        $price   = (float)($snap->price ?? 0);
        $sig     = $snap->signal ?? null;
        $batch   = $snap->batch ?? null;
        $ignores = $snap->ignore_by ?? [];
    ?>
    <div class="ctx-card">
        <div class="ctx-header">
            <span class="ctx-pair"><?= esc($snap->pair ?? "pair#{$snap->_pair_id}") ?></span>
            <?= action_badge($action, $action_colors) ?>
            <?= dir_label($dir) ?>
            <?php if ($detail): ?>
                <span class="detail"><?= esc($detail) ?></span>
            <?php endif; ?>
            <span class="ctx-ts"><?= esc($ts) ?></span>
        </div>

        <div class="section">
            <div class="block">
                <h3>Позиция</h3>
                <div class="kv"><span class="k">текущая</span><span class="v"><?= fmt_amount($curr) ?></span></div>
                <div class="kv"><span class="k">целевая</span><span class="v"><?= fmt_amount($target) ?></span></div>
                <div class="kv"><span class="k">delta</span><span class="v"><?= fmt_amount($target - $curr) ?></span></div>
                <div class="kv"><span class="k">bias</span><span class="v"><?= fmt_amount($bias) ?></span></div>
                <div class="kv"><span class="k">offset</span><span class="v"><?= fmt_amount((float)($snap->pos_offset ?? 0)) ?></span></div>
            </div>

            <div class="block">
                <h3>Заявка</h3>
                <div class="kv"><span class="k">amount</span><span class="v"><?= fmt_amount((float)($snap->amount ?? 0)) ?></span></div>
                <div class="kv"><span class="k">cost</span><span class="v"><?= fmt_cost($cost) ?></span></div>
                <div class="kv"><span class="k">price</span><span class="v"><?= fmt_amount($price, 2) ?></span></div>
            </div>

            <div class="block">
                <h3>Лимиты</h3>
                <div class="kv"><span class="k">min_cost</span><span class="v"><?= fmt_cost((float)($snap->min_cost ?? 0)) ?></span></div>
                <div class="kv"><span class="k">max_cost</span><span class="v"><?= fmt_cost((float)($snap->max_cost ?? 0)) ?></span></div>
                <div class="kv"><span class="k">cost_limit</span><span class="v"><?= fmt_cost((float)($snap->cost_limit ?? 0)) ?></span></div>
                <div class="kv"><span class="k">min_amount</span><span class="v"><?= fmt_amount((float)($snap->min_amount ?? 0)) ?></span></div>
            </div>

            <?php if ($sig): ?>
            <div class="block">
                <h3>Сигнал #<?= (int)$sig->id ?></h3>
                <div class="kv"><span class="k">cur_delta</span><span class="v"><?= fmt_amount((float)$sig->cur_delta) ?></span></div>
                <div class="kv"><span class="k">tgt_delta</span><span class="v"><?= fmt_amount((float)$sig->tgt_delta) ?></span></div>
                <div class="kv"><span class="k">alt_bias</span><span class="v"><?= fmt_amount((float)$sig->alt_bias) ?></span></div>
            </div>
            <?php endif; ?>

            <?php if ($batch): ?>
            <div class="block">
                <h3>Батч #<?= (int)$batch->id ?></h3>
                <div class="kv"><span class="k">lock</span>
                    <span class="v" style="color:<?= $batch->lock > 0 ? '#e74c3c' : '#2ecc71' ?>">
                        <?= (int)$batch->lock ?>
                    </span>
                </div>
                <div class="kv"><span class="k">start</span><span class="v"><?= fmt_amount((float)$batch->start_pos) ?></span></div>
                <div class="kv"><span class="k">target</span><span class="v"><?= fmt_amount((float)$batch->target_pos) ?></span></div>
                <div class="kv"><span class="k">exec_amount</span><span class="v"><?= fmt_amount((float)$batch->exec_amount) ?></span></div>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($ignores)): ?>
        <div class="ignore-list">
            Причины игнора:
            <?php foreach ($ignores as $ig): ?>
                <b>[<?= (int)$ig->code ?>]</b> <?= esc($ig->reason ?? '') ?> &nbsp;
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

</body>
</html>
