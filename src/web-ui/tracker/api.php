<?php
session_start();
require_once 'lib/common.php';
require_once 'lib/db_config.php';
require_once 'lib/db_tools.php';

$db = init_remote_db('trading');

// Создание нового портфеля
if ($name = rqs_param('name', false)) {
    $description = rqs_param('description', '');
    $db->safe_query("INSERT INTO portfolios__map (name, description) VALUES (?, ?)", [$name, $description]);
    exit;
}

// Добавление/редактирование позиции
if (($portfolio_id = rqs_param('portfolio_id', false)) && ($cm_id = rqs_param('cm_id', false)) && ($amount = rqs_param('amount', false)) && ($avg_price = rqs_param('avg_price_usd', false))) {
    $portfolio_id = (int)$portfolio_id;
    $cm_id = (int)$cm_id;
    $amount = (float)$amount;
    $avg_price = (float)$avg_price;
    $note = rqs_param('note', '');

    $existing = $db->select_row('id, amount, avg_price_usd', 'portfolios__positions', 
                                "WHERE portfolio_id=$portfolio_id AND cm_id=$cm_id", MYSQLI_OBJECT);

    if ($existing) {
        $db->safe_query("UPDATE portfolios__positions SET amount=?, avg_price_usd=?, note=? WHERE id=?", [$amount, $avg_price, $note, $existing->id]);
        $db->safe_query("INSERT INTO portfolios__history (portfolio_id, position_id, change_type, old_amount, new_amount, old_avg_price, new_avg_price) VALUES (?, ?, 'update', ?, ?, ?, ?)",
            [$portfolio_id, $existing->id, $existing->amount, $amount, $existing->avg_price_usd, $avg_price]);
    } else {
        $db->safe_query("INSERT INTO portfolios__positions (portfolio_id, cm_id, amount, avg_price_usd, note) VALUES (?, ?, ?, ?, ?)",
            [$portfolio_id, $cm_id, $amount, $avg_price, $note]);
        $position_id = $db->insert_id();
        $db->safe_query("INSERT INTO portfolios__history (portfolio_id, position_id, change_type, new_amount, new_avg_price) VALUES (?, ?, 'add', ?, ?)",
            [$portfolio_id, $position_id, $amount, $avg_price]);
    }
    exit;
}

// Получение позиций для активного портфеля
if ($portfolio_id = rqs_param('portfolio_id', false)) {
    $portfolio_id = (int)$portfolio_id;
    $positions = $db->select_rows(
        "pp.id, pp.cm_id, pp.amount, pp.avg_price_usd, pp.note, cs.symbol, cs.name, cs.last_price",
          "portfolios__positions as pp",
         "LEFT JOIN datafeed.cm__symbols cs ON pp.cm_id=cs.id
                    WHERE pp.portfolio_id=$portfolio_id ORDER BY cs.rank ASC, cs.name ASC", MYSQLI_OBJECT
    );

    $total_value = 0;
    foreach ($positions as $pos) {
        $pos->current_value = $pos->amount * $pos->last_price;
        $total_value += $pos->current_value;
    }

    echo '<table class="table table-dark table-striped">
        <thead>
            <tr>
                <th>Монета</th>
                <th>Тикер</th>
                <th>Количество</th>
                <th>Ср. цена USD</th>
                <th>Текущая цена USD</th>
                <th>Стоимость позиции</th>
                <th>Доля %</th>
                <th>Заметка</th>
            </tr>
        </thead>
        <tbody>';

    foreach ($positions as $p) {
        $percent = $total_value > 0 ? ($p->current_value / $total_value * 100) : 0;
        echo '<tr>
            <td>'.htmlspecialchars($p->name).'</td>
            <td>'.htmlspecialchars($p->symbol).'</td>
            <td>'.$p->amount.'</td>
            <td>'.$p->avg_price_usd.'</td>
            <td>'.$p->last_price.'</td>
            <td>'.number_format($p->current_value, 0).'</td>
            <td>'.number_format($percent, 2).'%</td>
            <td>'.htmlspecialchars($p->note).'</td>
        </tr>';
    }

    echo '</tbody></table>';
    echo '<p class="text-end fw-bold">Суммарная стоимость портфеля: '.number_format($total_value, 0).' USD</p>';
    exit;
}

$portfolios = $db->select_rows("SELECT id, name, description FROM portfolios__map ORDER BY name ASC", [], MYSQLI_OBJECT);
header('Content-Type: application/json');
echo json_encode($portfolios, JSON_PRETTY_PRINT);
exit;
