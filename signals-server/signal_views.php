<?php

    function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // html РѕС‚РѕР±СЂР°Р¶РµРЅРёРµ
    function render_signal_form($script) {
    $script_attr = h($script);
    return "<form name='signals' method='POST' action='$script_attr'>\n" .
        "<input type='text' name='signal' id='signal' value='' placeholder=\"[NOW] [NOPUSD]: BUY.X1#1\" style='width:590pt;'/> " .
        "<input type='submit' value='Post'/>\n" .
        "</form>\n";
    }

    function render_table_header() {
    return "<table border=1 style='border-collapse:collapse;'>\n" .
        "<tr><th>Time<th>#Sig / ID<th>Side<th>Pair<th>Mult<th>Accum. pos<th>Limit price</th><th>Take profit<th colspan=2>Stop loss<th>SL endless</th><th>Last price<th>Comment<th>Action</tr>\n";
    }

    function render_signal_row($row_data, $colors, $id_added, $mysqli, $setup, $grid_flag) {
    extract($row_data);

    $pair_label = h($pair);
    $comment_js = json_encode((string)($comment ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    $comment_label = $comment === null ? '' : h($comment);

    $bg_color = 'white';
    if(isset($colors[$pair_id]))
        $bg_color = $colors[$pair_id];

    $style = '';
    if ($id == $id_added)
        $style .= 'font-weight: bold;';

    $chk_lp = $flags & SIG_FLAG_LP ? 'checked' : '';
    $chk_tp = $flags & SIG_FLAG_TP ? 'checked' : '';
    $chk_sl = $flags & SIG_FLAG_SL ? 'checked' : '';
    $chk_se = $flags & SIG_FLAG_SE ? 'checked' : '';

    $font_color = 'black';
    if (false !== strpos($bg_color, '#')) {
        $hx = substr($bg_color, 1);
        list($r, $g, $b) = sscanf($hx, '%2x%2x%2x');
        $lt = max ($r, max($g, $b));
        if ($lt < 145)
            $font_color = 'white';
    }

    $pair_cell = '';
    $strict = "(pair_id = $pair_id) AND (setup = $setup) AND (flags & $grid_flag = 0)";
    $mno = $mysqli->select_value('MIN(signal_no)', 'signals', "WHERE $strict");
    if ($mno == $signal_no) {
        $sigcnt = $mysqli->select_value('COUNT(id)', 'signals', "WHERE $strict");
        $pair_cell = sprintf("<td rowspan=%d><a href='?filter=%s' style='color:$font_color'>%s</a>", $sigcnt, rawurlencode((string)$pair), $pair_label);
    }

    $t = strtotime($ts);
    $ts_rounded = date('Y-m-d H:i', $t);

    $html = sprintf("\t<tr style='background-color: $bg_color;color: $font_color; $style'><td>%s<td>%d / %d<td>%s%s",
        $ts_rounded, $signal_no, $id, $side, $pair_cell);

    $html .= sprintf("\n\t\t<td><u onClick='EditAmount($id, $mult)'>%.1f</u><td>%.1f", $mult, $accum_pos);
    $html .= sprintf("\n\t\t<td><input type='checkbox' onClick='ToggleLP($id)' $chk_lp /><u onClick='EditLP($id, $limit_price)'>$curr_ch%s</u>", $limit_price);
    $html .= sprintf("\n\t\t<td><input type='checkbox' onClick='ToggleTP($id)' $chk_tp /><u onClick='EditTP($id, $take_profit)'>$curr_ch%s</u>", $take_profit);

    $sl_text = $curr_ch.$stop_loss;
    $diff = $price > 0 ? 100 * ($stop_loss - $price) / $price : 0;
    $cell_params = '';
    if (abs($diff) >= 5 && $stop_loss > 0 && $chk_sl != '')
        $sl_text = str_pad($curr_ch . $stop_loss, 10) . sprintf('<td>%.1f%%', $diff);
    else
        $cell_params = 'colspan=2';

    $html .= "\n\t\t<td $cell_params>";
    $html .= "<input type='checkbox' onClick='ToggleSL($id)' $chk_sl />";
    $html .= sprintf("<u onClick='EditSL($id, $stop_loss)'>%s</u>", $sl_text);
    $html .= "\n\t\t<td><input type='checkbox' onClick='ToggleSE($id)' $chk_se />";

    $price_text = "$curr_ch$price";
    if ($price < 0.0001)
        $price_text = sprintf("$curr_ch%.3f/M", $price * 1e6);
    elseif ($price < 0.01)
        $price_text = sprintf("$curr_ch%.3f/1K", $price * 1000);

    $html .= "\t\t<td>$price_text\n";

    if (is_null($comment))
        $html .= sprintf("<td><input type='submit' onClick='EditComment(%d, \"\")' value='Add' />\n", $id);
    else
        $html .= sprintf("<td><span onClick='EditComment(%d, %s)'>%s</span>\n", $id, $comment_js, $comment_label);

    $html .= "<td><input type='submit' name='remove' onclick='DeleteSignal($id)' value='Delete'/>\n";
    $html .= "</tr>\n";

    return $html;
    }

    function render_debug_info($buys, $shorts, $sym_errs, $cm_symbols) {
    $html = "<pre>\n";
    $html .= "BUYS: ".json_encode($buys)."\n";
    $html .= "SHORTS: ".json_encode($shorts)."\n";
    $html .= "</pre>\n";

    if ($sym_errs > 0) {
        $html .= sprintf("ERROR: cm_symbols loaded incomplete, errors = %d</br><!-- \n",  $sym_errs);
        foreach ($cm_symbols as $pair_id => $rec)
            $html .= sprintf("$pair_id => %f, ", $rec->last_price);
        $html .= "\n--></br>\n";
    }

    return $html;
    }

    function render_navigation($filter) {
    if ($filter)
        return "<input type='submit' onClick='GoHome()' value='Home' />";
    return '';
    }

    function render_full_html_page($table_data, $colors, $id_added, $mysqli, $buys, $shorts, $sym_errs, $cm_symbols, $filter, $touched, $id_map, $setup, $script, $btc_pair_id, $eth_pair_id, $grid_flag, $debug_content = '') {
    ob_start();
?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Signal Editor <?php echo $setup; ?></title>
        <style type='text/css'>
            td { padding-left: 4pt;
                padding-right: 4pt; }
        </style>
        <script type='text/javascript'>
<?php
            $js_script = file_get_contents(__DIR__.'/sig_edit.js');
            $js_script = str_replace('script', $script, $js_script);
            echo $js_script;
?>

            function Startup() {
<?php
                if ($touched) {
                    if (isset($id_map[$touched]))
                        $touched = $id_map[$touched];
                    echo "document.location = '$script?filter=$touched';\n";
                }
?>
            }
        </script>
    </head>
    <body onLoad="Startup()">
<?php
    // Р’С‹РІРѕРґРёРј РѕС‚Р»Р°РґРѕС‡РЅСѓСЋ РёРЅС„РѕСЂРјР°С†РёСЋ
    if ($debug_content) {
        echo "<!-- Debug output:\n$debug_content\n-->\n";
    }

    echo render_signal_form($script);
?>
    <h4>Edit setup <?php echo $setup ?></h4>
<?php
    if (count($table_data) == 0) {
        printf("<h4>No signals yet for setup %d</h4>\n", $setup);
    } else {
        echo render_table_header();

        echo "<!-- btc_id = $btc_pair_id, eth_id = $eth_pair_id -->\n";

        foreach ($table_data as $row_data) {
            echo render_signal_row($row_data, $colors, $id_added, $mysqli, $setup, $grid_flag);
            printf("<!-- coef = %.1f, amount = %d, TTL = %d  --> \n",
                $row_data['coef'], $row_data['amount'], $row_data['ttl']);
        }

        echo "</table>\n";
    }

    echo render_debug_info($buys, $shorts, $sym_errs, $cm_symbols);
    echo render_navigation($filter);
?>
    </body>
    </html>
<?php
    return ob_get_clean();
    }

    // json РѕС‚РѕР±СЂР°Р¶РµРЅРёРµ

    function prepare_row_data_for_json($row_data, $colors) {
    extract($row_data);

    $bg_color = isset($colors[$pair_id]) ? $colors[$pair_id] : 'white';

    $font_color = '#000000';
    if (false !== strpos($bg_color, '#')) {
        $hx = substr($bg_color, 1);
        list($r, $g, $b) = sscanf($hx, '%2x%2x%2x');
        $lt = max ($r, max($g, $b));
        if ($lt < 145)
            $font_color = '#FFFFFF';
    }

    $sl_diff_percent = $price > 0 ? 100 * ($stop_loss - $price) / $price : 0;

    return [
        'id' => $id,
        'timestamp' => strtotime($ts),
        'signal_no' => $signal_no,
        'side' => $side,
        'pair' => $pair,
        'pair_id' => $pair_id,
        'multiplier' => $mult,
        'accumulated_position' => $accum_pos,
        'limit_price' => $limit_price,
        'take_profit' => $take_profit,
        'stop_loss' => $stop_loss,
        'stop_loss_diff_percent' => round($sl_diff_percent, 2),
        'ttl' => $ttl,
        'flags' => [
            'limit_price' => boolval($flags & SIG_FLAG_LP),
            'take_profit' => boolval($flags & SIG_FLAG_TP),
            'stop_loss' => boolval($flags & SIG_FLAG_SL),
            'stop_endless' => boolval($flags & SIG_FLAG_SE),
        ],
        'comment' => $comment,
        'currency_symbol' => $curr_ch,
        'last_price' => $price,
        'coefficient' => $coef,
        'amount' => $amount,
        'colors' => [
            'background' => $bg_color,
            'font' => $font_color
        ]
    ];
    }

    function get_sort_params() {
    $sort_by = rqs_param('sort', '');
    $order = strtolower(rqs_param('order', 'asc')) === 'desc' ? 'desc' : 'asc';

    $allowed_sort_fields = [
        'timestamp', 'id', 'side', 'pair', 'multiplier',
        'accumulated_position', 'limit_price', 'take_profit', 'stop_loss'
    ];

    if (!in_array($sort_by, $allowed_sort_fields)) {
        $sort_by = '';
    }

    return [$sort_by, $order];
    }

    function sort_signals($table_data, $sort_by, $order, $colors = []) {
    if (empty($sort_by)) {
        return $table_data;
    }

    $converted_data = [];
    foreach ($table_data as $row) {
        $converted_data[] = prepare_row_data_for_json($row, $colors);
    }

    usort($converted_data, function($a, $b) use ($sort_by, $order) {
        $val_a = $a[$sort_by] ?? 0;
        $val_b = $b[$sort_by] ?? 0;

        if (is_string($val_a) && is_string($val_b)) {
            $result = strcasecmp($val_a, $val_b);
        } else {
            $result = $val_a <=> $val_b;
        }

        return $order === 'desc' ? -$result : $result;
    });

    return $converted_data;
    }

    function render_json($table_data, $colors, $buys, $shorts, $sym_errs, $setup, $setup_info = [], $filter = null) {
    list($sort_by, $order) = get_sort_params();
    $has_sorting = !empty($sort_by);

    $json_data = [
        'status' => 'success',
        'setup' => $setup,
        'timestamp' => date('c'),
        'data' => [
            'summary' => [
                'total_signals' => count($table_data),
                'buys' => $buys,
                'shorts' => $shorts,
                'symbol_errors' => $sym_errs
            ]
        ]
    ];

    if (!empty($setup_info)) {
        $json_data['setup_info'] = $setup_info;
    }

    if ($has_sorting) {
        $json_data['data']['sorting'] = [
            'field' => $sort_by,
            'order' => $order
        ];
    }

    if ($filter || $has_sorting) {
        $json_data['data']['signals'] = [];

        if ($has_sorting) {
            $sorted_signals = sort_signals($table_data, $sort_by, $order, $colors);
            $json_data['data']['signals'] = $sorted_signals;
        } else {
            foreach ($table_data as $row_data) {
                $json_data['data']['signals'][] = prepare_row_data_for_json($row_data, $colors);
            }
        }

        if ($filter) {
            $json_data['data']['filter'] = $filter;
        }
    } else {
        $grouped_data = [];
        foreach ($table_data as $row_data) {
            $pair = $row_data['pair'];
            $pair_id = $row_data['pair_id'];

            if (!isset($grouped_data[$pair])) {
                $grouped_data[$pair] = [
                    'pair' => $pair,
                    'pair_id' => $pair_id,
                    'signals' => []
                ];
            }

            $grouped_data[$pair]['signals'][] = prepare_row_data_for_json($row_data, $colors);
        }

        $json_data['data']['pairs'] = [];
        foreach ($grouped_data as $pair => $pair_data) {
            $signals = $pair_data['signals'];
            $pair_info = [
                'pair' => $pair,
                'pair_id' => $pair_data['pair_id'],
                'signal_count' => count($signals),
                'background_color' => isset($colors[$pair_data['pair_id']]) ? $colors[$pair_data['pair_id']] : 'white',
                'signals' => $signals
            ];

            $json_data['data']['pairs'][] = $pair_info;
        }

        $json_data['data']['summary']['total_pairs'] = count($grouped_data);
    }

    return json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    function render_json_success($message) {
    $json_data = [
        'status' => 'success',
        'message' => $message
    ];

    return json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    function render_json_success_with_data($message, $data = []) {
    $json_data = [
        'status' => 'success',
        'message' => $message,
        'data' => $data
    ];

    return json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    function render_json_error($message, $code = 500) {
    $json_data = [
        'status' => 'error',
        'error' => [
            'code' => $code,
            'message' => $message
        ]
    ];

    return json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    function render_output($format, $table_data, $colors, $id_added, $mysqli, $buys, $shorts, $sym_errs, $cm_symbols, $filter, $touched, $id_map, $setup, $script, $btc_pair_id, $eth_pair_id, $grid_flag, $setup_info = [], $debug_content = '') {
    switch (strtolower($format)) {
        case 'json':
            return render_json($table_data, $colors, $buys, $shorts, $sym_errs, $setup, $setup_info, $filter);

        case 'html':
        default:
            return render_full_html_page($table_data, $colors, $id_added, $mysqli, $buys, $shorts, $sym_errs, $cm_symbols, $filter, $touched, $id_map, $setup, $script, $btc_pair_id, $eth_pair_id, $grid_flag, $debug_content);
    }
    }

    function detect_output_format() {
    if (isset($_GET['format'])) {
        return strtolower($_GET['format']);
    }

    if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
        return 'json';
    }

    return 'html';
    }