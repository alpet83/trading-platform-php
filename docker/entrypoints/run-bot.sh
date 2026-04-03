#!/bin/sh
set -eu

# bot_manager passes implementation in lower-case env var `impl_name`.
# Respect it when BOT_IMPL_NAME is not explicitly provided.
BOT_IMPL_NAME="${BOT_IMPL_NAME:-${impl_name:-bitmex_bot}}"
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

if [ -d "/app/src/logs/standard input code" ] && [ -z "$(ls -A "/app/src/logs/standard input code" 2>/dev/null)" ]; then
  rmdir "/app/src/logs/standard input code" || true
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

prepare_runtime_workspace() {
  runtime_data_subdir="workers/${BOT_IMPL_NAME}"
  if [ -n "${BOT_ACCOUNT_ID:-}" ]; then
    runtime_data_subdir="${runtime_data_subdir}/${BOT_ACCOUNT_ID}"
  fi

  runtime_parent="/app/var/run/bot-runtime/${BOT_IMPL_NAME}"
  runtime_root="$runtime_parent"
  if [ -n "${BOT_ACCOUNT_ID:-}" ]; then
    runtime_root="${runtime_parent}/${BOT_ACCOUNT_ID}"
  fi

  # Keep bot logs isolated by implementation/account under /app/var/log/<impl>/<account>/
  # while preserving legacy relative logger expectations around ../log.
  bot_log_root="/app/var/log/${BOT_IMPL_NAME}"
  if [ -n "${BOT_ACCOUNT_ID:-}" ]; then
    bot_log_root="${bot_log_root}/${BOT_ACCOUNT_ID}"
  fi

  mkdir -p "$runtime_parent"
  mkdir -p "$runtime_root"
  mkdir -p "$bot_log_root"
  mkdir -p "/app/var/data/${runtime_data_subdir}"

  # BasicLogger uses ../log/<subdir>; point ../log to /app/var/log root.
  ln -sfn /app/var/log "$runtime_parent/log"

  ln -sfn "/app/var/data/${runtime_data_subdir}" "$runtime_root/data"
  ln -sfn /app/var/log "$runtime_root/logs"

  # Remove stale shared logs.td symlink if present; BasicLogger will recreate
  # per-instance logs.td in runtime cwd and point it to bot-scoped log directory.
  if [ -e "$runtime_root/logs.td" ] || [ -L "$runtime_root/logs.td" ]; then
    rm -rf "$runtime_root/logs.td"
  fi

  export BOT_DATA_SUBDIR="$runtime_data_subdir"
  export BOT_RUNTIME_DIR="$runtime_root"
}

resolve_bot_account_id() {
  (
    cd /
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
  )
}

bootstrap_bitmex_credentials_from_db() {
  (
    cd /
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

if (strpos($secret, "v1:") === 0) {
  $masterKey = trim((string)(getenv("BOT_MANAGER_SECRET_KEY") ?: ""));
  if (!strlen($masterKey)) {
    fwrite(STDERR, "#WARN: BitMEX DB secret is encrypted but BOT_MANAGER_SECRET_KEY is not set, using raw payload\\n");
  } else {
    $raw = base64_decode(substr($secret, 3), true);
    if ($raw !== false && strlen($raw) >= 29) {
      $iv = substr($raw, 0, 12);
      $tag = substr($raw, 12, 16);
      $cipher = substr($raw, 28);
      $dec = openssl_decrypt($cipher, "aes-256-gcm", hash("sha256", $masterKey, true), OPENSSL_RAW_DATA, $iv, $tag);
      if ($dec !== false && strlen(trim((string)$dec)) > 0) {
        $secret = trim((string)$dec);
        fwrite(STDERR, "#INFO: BitMEX DB secret decrypted successfully\\n");
      } else {
        fwrite(STDERR, "#WARN: BitMEX DB secret decryption failed, using raw payload\\n");
      }
    }
  }
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
  )
}

bootstrap_bybit_credentials_from_db() {
  (
    cd /
    php -d display_errors=0 -d error_reporting=0 -r '
error_reporting(0);
ini_set("display_errors", "0");
require_once "/app/src/lib/db_config.php";
require_once "/app/src/lib/db_tools.php";

mysqli_report(MYSQLI_REPORT_OFF);
$bot = getenv("BOT_IMPL_NAME") ?: "bybit_bot";
$db = init_remote_db("trading");
if (!$db) {
  fwrite(STDERR, "#WARN: DB unavailable, skip Bybit credential bootstrap\\n");
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

if (strpos($secret, "v1:") === 0) {
  $masterKey = trim((string)(getenv("BOT_MANAGER_SECRET_KEY") ?: ""));
  if (!strlen($masterKey)) {
    fwrite(STDERR, "#WARN: Bybit DB secret is encrypted but BOT_MANAGER_SECRET_KEY is not set, using raw payload\\n");
  } else {
    $raw = base64_decode(substr($secret, 3), true);
    if ($raw !== false && strlen($raw) >= 29) {
      $iv = substr($raw, 0, 12);
      $tag = substr($raw, 12, 16);
      $cipher = substr($raw, 28);
      $dec = openssl_decrypt($cipher, "aes-256-gcm", hash("sha256", $masterKey, true), OPENSSL_RAW_DATA, $iv, $tag);
      if ($dec !== false && strlen(trim((string)$dec)) > 0) {
        $secret = trim((string)$dec);
        fwrite(STDERR, "#INFO: Bybit DB secret decrypted successfully\\n");
      } else {
        fwrite(STDERR, "#WARN: Bybit DB secret decryption failed, using raw payload\\n");
      }
    }
  }
}
$secretEncoded = base64_encode($secret);

$isTestnet = strtolower(trim((string)(getenv("BYBIT_TESTNET") ?: "0")));
if (in_array($isTestnet, ["1", "true", "yes", "on"], true)) {
  $maskKey = $apiKey;
  if (strlen($apiKey) > 8) {
    $maskKey = substr($apiKey, 0, 4) . "..." . substr($apiKey, -4);
  }
  $secretLen = strlen($secret);
  $secretSig = substr(hash("sha256", $secret), 0, 10);
  fwrite(STDERR, "#TESTNET: Bybit creds key=$maskKey secret_len=$secretLen secret_sha256_10=$secretSig\\n");
}

file_put_contents("/app/src/.bybit.api_key", $apiKey);
file_put_contents("/app/src/.bybit.key", $secretEncoded . "\n");
fwrite(STDERR, "#INFO: Bybit credentials bootstrapped from DB for $bot@$accountId\\n");
'
  )
}

if [ "$EXCHANGE_NAME" = "bitmex" ] && [ -z "${BMX_API_KEY:-}" ]; then
  # DEPRECATED: credentials now passed via ENV from bot_manager; skipping bootstrap
  true
fi

if [ "$EXCHANGE_NAME" = "bybit" ] && [ -z "${BYBIT_API_KEY:-}" ]; then
  # DEPRECATED: credentials now passed via ENV from bot_manager; skipping bootstrap
  true
fi

BOT_ACCOUNT_ID="$(printf '%s' "${BOT_ACCOUNT_ID:-}" | tr -cd '0-9')"
if [ -z "$BOT_ACCOUNT_ID" ]; then
  BOT_ACCOUNT_ID_RAW="$(resolve_bot_account_id || true)"
  BOT_ACCOUNT_ID="$(printf '%s' "$BOT_ACCOUNT_ID_RAW" | tr -cd '0-9')"
fi
if [ -n "$BOT_ACCOUNT_ID" ]; then
  export BOT_ACCOUNT_ID
  export BOT_LOG_SUBDIR="${BOT_IMPL_NAME}/${BOT_ACCOUNT_ID}"
  mkdir -p "/app/var/log/${BOT_LOG_SUBDIR}"
fi

prepare_runtime_workspace

# Credential files are no longer created; secrets come via ENV from bot_manager
# Keeping symlink creation for backward compatibility only (no-op if files don't exist)
# if [ "$EXCHANGE_NAME" = "bitmex" ]; then
#   ln -sfn /app/src/.bitmex.api_key "$BOT_RUNTIME_DIR/.bitmex.api_key"
#   ln -sfn /app/src/.bitmex.key "$BOT_RUNTIME_DIR/.bitmex.key"
# fi
# 
# if [ "$EXCHANGE_NAME" = "bybit" ]; then
#   ln -sfn /app/src/.bybit.api_key "$BOT_RUNTIME_DIR/.bybit.api_key"
#   ln -sfn /app/src/.bybit.key "$BOT_RUNTIME_DIR/.bybit.key"
# fi

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
