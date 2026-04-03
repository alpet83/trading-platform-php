#!/bin/sh
set -eu

CREDENTIAL_SOURCE="${BOT_CREDENTIAL_SOURCE:-pass}"
PASS_ENCRYPTION_MODE="${BOT_PASS_ENCRYPTION_MODE:-none}"
GPG_WAIT_ENABLED="${BOT_GPG_WAIT_ENABLED:-1}"
GPG_WAIT_TIMEOUT_SECONDS="${BOT_GPG_WAIT_TIMEOUT_SECONDS:-180}"
GPG_WAIT_INTERVAL_SECONDS="${BOT_GPG_WAIT_INTERVAL_SECONDS:-2}"
GPG_PROBE_PATH="${BOT_GPG_PROBE_PATH:-users/trader}"
GPG_PROBE_PATHS="${BOT_GPG_PROBE_PATHS:-users/trader}"

probe_pass_paths() {
  old_ifs="$IFS"
  IFS=','
  for path in $GPG_PROBE_PATHS; do
    probe="$(printf '%s' "$path" | tr -d '[:space:]')"
    if [ -n "$probe" ]; then
      if ! pass "$probe" >/dev/null 2>&1; then
        IFS="$old_ifs"
        return 1
      fi
    fi
  done
  IFS="$old_ifs"
  return 0
}

wait_for_encrypted_pass() {
  if [ "${GPG_WAIT_ENABLED}" != "1" ]; then
    return 0
  fi

  if ! command -v gpg-connect-agent >/dev/null 2>&1; then
    echo "gpg-connect-agent is required for encrypted pass mode."
    return 1
  fi

  echo "Waiting for encrypted pass store availability (probes: ${GPG_PROBE_PATHS})..."
  elapsed=0
  while [ "${elapsed}" -lt "${GPG_WAIT_TIMEOUT_SECONDS}" ]; do
    if gpg-connect-agent /bye >/dev/null 2>&1 && probe_pass_paths; then
      echo "Encrypted pass store is available."
      return 0
    fi
    sleep "${GPG_WAIT_INTERVAL_SECONDS}"
    elapsed=$((elapsed + GPG_WAIT_INTERVAL_SECONDS))
  done

  echo "Timed out waiting for encrypted pass store."
  echo "If using gpg-agent sidecar, ensure it is running and unlocked before bot-manager start."
  return 1
}

require_file() {
  if [ ! -f "$1" ]; then
    echo "Missing required file: $1"
    return 1
  fi
  return 0
}

if [ ! -f /app/src/lib/db_config.php ]; then
  echo "Missing /app/src/lib/db_config.php. Provide ./secrets/db_config.php on host."
  exit 1
fi

if [ "${CREDENTIAL_SOURCE}" = "pass" ]; then
  if ! command -v pass >/dev/null 2>&1; then
    echo "pass is not available in container image."
    exit 1
  fi
fi

require_file /app/src/bot_manager.php
require_file /app/src/common.php
require_file /app/src/esctext.php
require_file /app/src/lib/db_tools.php

mkdir -p /app/var/log /app/var/tmp /app/var/data

if [ -L /app/src/log ]; then
  rm -f /app/src/log
elif [ -d /app/src/log ]; then
  mkdir -p /app/src/logs
  cp -a /app/src/log/. /app/src/logs/ 2>/dev/null || true
  rm -rf /app/src/log
fi

if [ ! -e /app/src/logs ]; then
  ln -s /app/var/log /app/src/logs
fi

if [ ! -e /app/src/data ]; then
  ln -s /app/var/data /app/src/data
fi

cd /app/src

if [ "${CREDENTIAL_SOURCE}" = "pass" ]; then
  if [ "${PASS_ENCRYPTION_MODE}" = "encrypted" ]; then
    wait_for_encrypted_pass
  elif ! probe_pass_paths; then
    echo "Pass probe failed for one of BOT_GPG_PROBE_PATHS=${GPG_PROBE_PATHS}. Check PASS_STORE_DIR and entries."
    exit 1
  fi
fi

exec php bot_manager.php
