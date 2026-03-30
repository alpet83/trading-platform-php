<?php
require_once('lib/common.php');
require_once('lib/db_tools.php');
include_once('/usr/local/etc/php/db_config.php');

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function esc_xml(string $v): string {
  return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function ensure_signals_stats_table(mysqli_ex $db): void {
  $sql = "CREATE TABLE IF NOT EXISTS `signals_stats` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `endpoint` VARCHAR(64) NOT NULL,
    `remote_ip` VARCHAR(64) NOT NULL,
    `remote_host` VARCHAR(255) NOT NULL DEFAULT '',
    `src_account` INT NOT NULL DEFAULT 0,
    `setup_raw` VARCHAR(255) NOT NULL DEFAULT '',
    `view_name` VARCHAR(32) NOT NULL DEFAULT 'json',
    `out_format` VARCHAR(32) NOT NULL DEFAULT 'json',
    `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
    `hits` INT UNSIGNED NOT NULL DEFAULT 1,
    `first_seen` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_endpoint_ip` (`endpoint`,`remote_ip`),
    KEY `idx_endpoint_last_seen` (`endpoint`,`last_seen`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
  $db->query($sql);
}

function fetch_recent_subscribers(mysqli_ex $db): array {
  $sql = "SELECT `remote_ip`, `remote_host`, DATE_FORMAT(`last_seen`, '%Y-%m-%d %H:%i:%s') AS `last_seen`, `hits`
    FROM `signals_stats`
    WHERE `endpoint` = ?
      AND `last_seen` >= (UTC_TIMESTAMP() - INTERVAL 1 DAY)
    ORDER BY `last_seen` DESC
    LIMIT 24";

  $stmt = $db->prepare($sql);
  if (!$stmt) {
    return [];
  }

  $endpoint = 'get_signals.php';
  $stmt->bind_param('s', $endpoint);
  if (!$stmt->execute()) {
    $stmt->close();
    return [];
  }

  $result = $stmt->get_result();
  $rows = [];
  while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
  }

  $stmt->close();
  return $rows;
}

mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = init_remote_db('trading');

$db_ok = false;
$db_note = 'db disconnected';
$subs = [];

if ($mysqli) {
  $db_ok = true;
  $db_note = 'db connected';
  ensure_signals_stats_table($mysqli);
  $ping = $mysqli->query('SELECT 1');
  if (!$ping) {
    $db_ok = false;
    $db_note = 'db query failed';
  }
  if ($db_ok) {
    $subs = fetch_recent_subscribers($mysqli);
  }
}

$web_ok = true;
$web_note = 'http endpoint online';

$width = 1200;
$base_h = 250;
$sub_h = max(0, count($subs) * 46);
$height = $base_h + $sub_h;

$db_color = $db_ok ? '#22c55e' : '#ef4444';
$web_color = $web_ok ? '#22c55e' : '#ef4444';
$link_color = ($db_ok && $web_ok) ? '#0ea5e9' : '#ef4444';

$svg = [];
$svg[] = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>";
$svg[] = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"$width\" height=\"$height\" viewBox=\"0 0 $width $height\">";
$svg[] = "<defs>";
$svg[] = "  <style><![CDATA[";
$svg[] = "    .title { font: 700 26px Arial, sans-serif; fill: #0f172a; }";
$svg[] = "    .subtitle { font: 400 14px Arial, sans-serif; fill: #334155; }";
$svg[] = "    .node-title { font: 700 18px Arial, sans-serif; fill: #0f172a; }";
$svg[] = "    .node-text { font: 400 13px Arial, sans-serif; fill: #1f2937; }";
$svg[] = "    .sub-title { font: 700 17px Arial, sans-serif; fill: #0f172a; }";
$svg[] = "    .sub-text { font: 400 13px Arial, sans-serif; fill: #111827; }";
$svg[] = "  ]]></style>";
$svg[] = "  <marker id=\"arrow\" markerWidth=\"10\" markerHeight=\"7\" refX=\"9\" refY=\"3.5\" orient=\"auto\">";
$svg[] = "    <polygon points=\"0 0, 10 3.5, 0 7\" fill=\"$link_color\"/>";
$svg[] = "  </marker>";
$svg[] = "</defs>";

$svg[] = "<rect x=\"0\" y=\"0\" width=\"$width\" height=\"$height\" fill=\"#f8fafc\"/>";
$svg[] = "<text x=\"28\" y=\"42\" class=\"title\">signals-system legacy health</text>";
$svg[] = "<text x=\"28\" y=\"66\" class=\"subtitle\">Rendered at " . esc_xml(gmdate('Y-m-d H:i:s')) . " UTC</text>";

$svg[] = "<line x1=\"330\" y1=\"132\" x2=\"470\" y2=\"132\" stroke=\"$link_color\" stroke-width=\"4\" marker-end=\"url(#arrow)\"/>";

$svg[] = "<g transform=\"translate(80,88)\">";
$svg[] = "  <rect x=\"0\" y=\"0\" width=\"250\" height=\"92\" rx=\"14\" fill=\"#ffffff\" stroke=\"#cbd5e1\"/>";
$svg[] = "  <circle cx=\"26\" cy=\"26\" r=\"10\" fill=\"$db_color\"/>";
$svg[] = "  <text x=\"46\" y=\"32\" class=\"node-title\">MariaDB</text>";
$svg[] = "  <text x=\"18\" y=\"58\" class=\"node-text\">Container: signals-legacy-db</text>";
$svg[] = "  <text x=\"18\" y=\"78\" class=\"node-text\">Status: " . esc_xml($db_note) . "</text>";
$svg[] = "</g>";

$svg[] = "<g transform=\"translate(470,88)\">";
$svg[] = "  <rect x=\"0\" y=\"0\" width=\"290\" height=\"92\" rx=\"14\" fill=\"#ffffff\" stroke=\"#cbd5e1\"/>";
$svg[] = "  <circle cx=\"26\" cy=\"26\" r=\"10\" fill=\"$web_color\"/>";
$svg[] = "  <text x=\"46\" y=\"32\" class=\"node-title\">Legacy Web/API</text>";
$svg[] = "  <text x=\"18\" y=\"58\" class=\"node-text\">Container: signals-legacy</text>";
$svg[] = "  <text x=\"18\" y=\"78\" class=\"node-text\">Status: " . esc_xml($web_note) . "</text>";
$svg[] = "</g>";

$subs_y = 220;
$svg[] = "<text x=\"28\" y=\"$subs_y\" class=\"sub-title\">get_signals subscribers (last 24h)</text>";

if (!count($subs)) {
  $svg[] = "<text x=\"28\" y=\"" . ($subs_y + 28) . "\" class=\"sub-text\">No requests recorded in the last 24 hours.</text>";
} else {
  $y = $subs_y + 20;
  foreach ($subs as $idx => $row) {
    $y += 32;
    $ip = (string)$row['remote_ip'];
    $host = (string)$row['remote_host'];
    $last = (string)$row['last_seen'];
    $hits = (int)$row['hits'];
    $label = $host !== '' ? ($ip . ' / ' . $host) : $ip;

    $svg[] = "<circle cx=\"36\" cy=\"" . ($y - 6) . "\" r=\"9\" fill=\"#0ea5e9\"/>";
    $svg[] = "<text x=\"56\" y=\"" . ($y - 2) . "\" class=\"sub-text\">" . esc_xml($label) . "</text>";
    $svg[] = "<text x=\"720\" y=\"" . ($y - 2) . "\" class=\"sub-text\">last: " . esc_xml($last) . " UTC; hits: " . esc_xml((string)$hits) . "</text>";
  }
}

$svg[] = "</svg>";

echo implode("\n", $svg);
