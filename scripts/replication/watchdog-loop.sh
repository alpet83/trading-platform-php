#!/usr/bin/env bash
set -euo pipefail

INTERVAL_SECONDS="${WATCHDOG_INTERVAL_SECONDS:-15}"

echo "[watchdog] role=${ROLE:-unset} mysql=${MYSQL_HOST:-127.0.0.1}:${MYSQL_PORT:-3306} primary=${PRIMARY_HOST:-unset}:${PRIMARY_PORT:-3306} interval=${INTERVAL_SECONDS}s"

while true; do
  TS="$(date +'%Y-%m-%d %H:%M:%S')"
  echo "[watchdog][$TS] tick"

  if ! "$(dirname "$0")/ensure-single-writer.sh"; then
    echo "[watchdog][$TS] ensure-single-writer failed"
  fi

  if [[ "${ROLE:-}" == "standby" ]]; then
    if ! "$(dirname "$0")/auto-rejoin.sh"; then
      echo "[watchdog][$TS] auto-rejoin failed"
    fi
  fi

  sleep "$INTERVAL_SECONDS"
done
