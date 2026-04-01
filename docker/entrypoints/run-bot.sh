#!/bin/sh
set -eu

BOT_IMPL_NAME="${BOT_IMPL_NAME:-bitmex_bot}"
EXCHANGE_NAME="${BOT_IMPL_NAME%_bot}"

require_file() {
  if [ ! -f "$1" ]; then
    echo "Missing required file: $1"
    return 1
  fi
  return 0
}

mkdir -p /app/var/log /app/var/tmp /app/var/data

if [ ! -e /app/src/logs ]; then
  ln -s /app/var/log /app/src/logs
fi

if [ ! -e /app/src/data ]; then
  ln -s /app/var/data /app/src/data
fi

require_file /app/src/bot_instance.php
require_file /app/src/trading_core.php
require_file /app/src/orders_lib.php
require_file "/app/src/impl_${EXCHANGE_NAME}.php"
require_file /app/src/lib/db_config.php
require_file /app/src/common.php
require_file /app/src/esctext.php
require_file /app/src/lib/db_tools.php

prepare_log_layout() {
  log_root="/app/var/log/logs.td"
  day_dir="$log_root/by-day/$(date -u +%F)"
  day_alias="/$(date -u +%F)"

  mkdir -p "$day_dir"

  # Some runtime relink paths may collapse to "/YYYY-MM-DD".
  # Keep that alias pointed to the mounted day directory to avoid writing logs outside /app/var/log.
  ln -sfn "$day_dir" "$day_alias"

  # Keep logger-managed symlinks (core.log, engine.log, etc.) in place,
  # but archive historical timestamped files out of the flat root.
  for pattern in core_*.log engine_*.log errors_*.log order_*.log market_maker_*.log; do
    for f in "$log_root"/$pattern; do
      [ -e "$f" ] || continue
      [ -L "$f" ] && continue

      base="$(basename "$f")"
      dst="$day_dir/$base"
      if [ -e "$dst" ]; then
        dst="$day_dir/${base%.log}-$(date -u +%H%M%S).log"
      fi
      mv "$f" "$dst"
    done
  done

  ln -sfn "$day_dir" "$log_root/current"
  ln -sfn "$day_dir" /app/src/logs.td
}

prepare_log_layout

prepare_runtime_workspace() {
  runtime_data_subdir="workers/${BOT_IMPL_NAME}"
  if [ -n "${BOT_ACCOUNT_ID:-}" ]; then
    runtime_data_subdir="${runtime_data_subdir}/${BOT_ACCOUNT_ID}"
  fi

  runtime_root="/app/var/run/bot-runtime/${BOT_IMPL_NAME}"
  if [ -n "${BOT_ACCOUNT_ID:-}" ]; then
    runtime_root="${runtime_root}/${BOT_ACCOUNT_ID}"
  fi

  mkdir -p "$runtime_root"
  mkdir -p "/app/var/data/${runtime_data_subdir}"

  ln -sfn "/app/var/data/${runtime_data_subdir}" "$runtime_root/data"
  ln -sfn /app/src/logs.td "$runtime_root/logs.td"
  ln -sfn /app/var/log "$runtime_root/logs"

  export BOT_DATA_SUBDIR="$runtime_data_subdir"
  export BOT_RUNTIME_DIR="$runtime_root"
}

resolve_bot_account_id() {
  php -d display_errors=0 -d error_reporting=0 -r '
error_reporting(0);
ini_set("display_errors", "0");
require_once "/app/src/lib/db_config.php";
require_once "/app/src/lib/db_tools.php";

mysqli_report(MYSQLI_REPORT_OFF);
$bot = getenv("BOT_IMPL_NAME") ?: "bitmex_bot";
$db = init_remote_db("trading");
if (!$db) {
  exit(0);
}

$cfgTable = $db->select_value("table_name", "config__table_map", "WHERE applicant = \"$bot\"");
if (!is_string($cfgTable) || "" === trim($cfgTable)) {
  exit(0);
}

$accountId = intval($db->select_value("account_id", $cfgTable, ""));
if ($accountId > 0) {
  echo $accountId;
}
'
}

bootstrap_bitmex_credentials_from_db() {
  php -d display_errors=0 -d error_reporting=0 -r '
error_reporting(0);
ini_set("display_errors", "0");
require_once "/app/src/lib/db_config.php";
require_once "/app/src/lib/db_tools.php";

mysqli_report(MYSQLI_REPORT_OFF);
$bot = getenv("BOT_IMPL_NAME") ?: "bitmex_bot";
$db = init_remote_db("trading");
if (!$db) {
  fwrite(STDERR, "#WARN: DB unavailable, skip BitMEX credential bootstrap\\n");
  exit(0);
}

$cfgTable = $db->select_value("table_name", "config__table_map", "WHERE applicant = \"$bot\"");
if (!is_string($cfgTable) || "" === trim($cfgTable)) {
  fwrite(STDERR, "#WARN: bot config table not found for $bot\\n");
  exit(0);
}

$accountId = intval($db->select_value("account_id", $cfgTable, ""));
if ($accountId <= 0) {
  fwrite(STDERR, "#WARN: account_id missing in $cfgTable\\n");
  exit(0);
}

$readCfg = function(string $param) use ($db, $cfgTable, $accountId) {
  $v = $db->select_value("value", $cfgTable, "WHERE (account_id = $accountId) AND (param = \"$param\")");
  return is_string($v) ? trim($v) : "";
};

$apiKey = $readCfg("api_key");
$secret = $readCfg("api_secret");
if ("" === $secret) {
  $s0 = $readCfg("api_secret_s0");
  $s1 = $readCfg("api_secret_s1");
  if ("" !== $s0 || "" !== $s1) {
    $sep = $readCfg("api_secret_sep");
    if ("" === $sep) {
      $sep = "--";
    }
    $secret = $s0 . $sep . $s1;
  }
}

if ("" === $apiKey || "" === $secret) {
  fwrite(STDERR, "#WARN: api_key/api_secret absent in DB config for $bot@$accountId\\n");
  exit(0);
}

$secretEncoded = base64_encode($secret);

$isTestnet = strtolower(trim((string)(getenv("BMX_TESTNET") ?: "0")));
if (in_array($isTestnet, ["1", "true", "yes", "on"], true)) {
  fwrite(STDERR, "#TESTNET: assembled BitMEX secret = $secret\n");
}

file_put_contents("/app/src/.bitmex.api_key", $apiKey);
file_put_contents("/app/src/.bitmex.key", $secretEncoded . "\n");
fwrite(STDERR, "#INFO: BitMEX credentials bootstrapped from DB for $bot@$accountId\n");
'
}

if [ "$EXCHANGE_NAME" = "bitmex" ] && [ -z "${BMX_API_KEY:-}" ]; then
  bootstrap_bitmex_credentials_from_db
fi

BOT_ACCOUNT_ID_RAW="$(resolve_bot_account_id || true)"
BOT_ACCOUNT_ID="$(printf '%s' "$BOT_ACCOUNT_ID_RAW" | tr -cd '0-9')"
if [ -n "$BOT_ACCOUNT_ID" ]; then
  export BOT_ACCOUNT_ID
  export BOT_LOG_SUBDIR="${BOT_IMPL_NAME}/${BOT_ACCOUNT_ID}"
  mkdir -p "/app/var/log/${BOT_LOG_SUBDIR}"
fi

prepare_runtime_workspace

if [ "$EXCHANGE_NAME" = "bitmex" ]; then
  ln -sfn /app/src/.bitmex.api_key "$BOT_RUNTIME_DIR/.bitmex.api_key"
  ln -sfn /app/src/.bitmex.key "$BOT_RUNTIME_DIR/.bitmex.key"
fi

if [ "$EXCHANGE_NAME" = "bitmex" ]; then
  case "$(printf '%s' "${BMX_TESTNET:-0}" | tr '[:upper:]' '[:lower:]')" in
    1|true|yes|on)
      if [ -s /app/src/.bitmex.key ]; then
        # Use PHP to decode base64 since shell base64 -d may not work reliably
        assembled_secret="$(php -r "echo base64_decode(trim(file_get_contents('/app/src/.bitmex.key')));" 2>/dev/null || true)"
        if [ -z "$assembled_secret" ]; then
          encoded_secret="$(tr -d '\r\n' < /app/src/.bitmex.key)"
          assembled_secret="$encoded_secret"
        fi
        echo "#TESTNET: assembled BitMEX secret ${assembled_secret}"
      fi
      ;;
  esac
fi

export PHP_INCLUDE_PATH=".:./lib:/app/src:/app:/usr/share/php:/usr/share/php/lib:/usr/sbin/lib"
cd "$BOT_RUNTIME_DIR"
exec php /app/src/bot_instance.php "$BOT_IMPL_NAME"
