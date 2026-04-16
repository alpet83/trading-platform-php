<?php
require_once 'lib/db_config.php';
$mysqli = init_remote_db('trading');

echo "=== deribit__mm_exec ===\n";
$result = $mysqli->query("DESCRIBE deribit__mm_exec");
$mm_exec_cols = [];
while ($row = $result->fetch_assoc()) {
    $mm_exec_cols[] = $row['Field'];
    printf("%2d. %-20s %s\n", count($mm_exec_cols), $row['Field'], $row['Type']);
}

echo "\n=== deribit__pending_orders ===\n";
$result = $mysqli->query("DESCRIBE deribit__pending_orders");
$pending_cols = [];
while ($row = $result->fetch_assoc()) {
    $pending_cols[] = $row['Field'];
    printf("%2d. %-20s %s\n", count($pending_cols), $row['Field'], $row['Type']);
}

echo "\n=== DIFFERENCE ===\n";
$only_in_mm_exec = array_diff($mm_exec_cols, $pending_cols);
$only_in_pending = array_diff($pending_cols, $mm_exec_cols);

if (count($only_in_mm_exec) > 0)
    echo "Only in mm_exec: " . implode(", ", $only_in_mm_exec) . "\n";
if (count($only_in_pending) > 0)
    echo "Only in pending_orders: " . implode(", ", $only_in_pending) . "\n";
if (count($only_in_mm_exec) == 0 && count($only_in_pending) == 0)
    echo "Schemas are identical! Difference must be in column order.\n";
    
echo "\nColumn count: mm_exec=" . count($mm_exec_cols) . ", pending=" . count($pending_cols) . "\n";
?>
