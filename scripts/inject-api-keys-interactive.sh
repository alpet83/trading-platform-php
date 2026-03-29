#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

compose_file="${COMPOSE_FILE:-docker-compose.yml}"
mariadb_db="${MARIADB_DATABASE:-trading}"
mariadb_root_pwd="${MARIADB_ROOT_PASSWORD:-root_change_me}"

ask() {
  prompt="$1"
  default="${2:-}"
  if [ -n "$default" ]; then
    printf "%s [%s]: " "$prompt" "$default" >&2
  else
    printf "%s: " "$prompt" >&2
  fi
  IFS= read -r value
  if [ -z "$value" ]; then
    value="$default"
  fi
  printf "%s" "$value"
}

ask_secret() {
  prompt="$1"
  printf "%s: " "$prompt" >&2
  if [ -t 0 ]; then
    stty -echo
    IFS= read -r value
    stty echo
    printf "\n" >&2
  else
    IFS= read -r value
  fi
  printf "%s" "$value"
}

choose_number() {
  prompt="$1"
  min="$2"
  max="$3"
  def="$4"

  while :; do
    raw="$(ask "$prompt" "$def")"
    case "$raw" in
      ''|*[!0-9]*)
        echo "Invalid number: $raw" >&2
        continue
        ;;
    esac
    if [ "$raw" -ge "$min" ] && [ "$raw" -le "$max" ]; then
      printf "%s" "$raw"
      return 0
    fi
    echo "Out of range: $raw (expected $min..$max)" >&2
  done
}

source_mode="$(ask "Credential source (pass/db)" "pass")"
case "$source_mode" in
  pass|db) ;;
  *)
    echo "Unsupported source: $source_mode"
    exit 1
    ;;
esac

if [ "$source_mode" = "pass" ]; then
  account_id="$(ask "Account ID" "1")"
  api_key="$(ask_secret "API key")"
  exchange="$(ask "Exchange" "bitmex")"
  api_secret_s0="$(ask_secret "API secret part S0")"
  api_secret_s1="$(ask_secret "API secret part S1")"

  CREDENTIAL_SOURCE=pass \
  EXCHANGE="$exchange" \
  ACCOUNT_ID="$account_id" \
  API_KEY="$api_key" \
  API_SECRET_S0="$api_secret_s0" \
  API_SECRET_S1="$api_secret_s1" \
  sh scripts/inject-api-keys.sh
else
  bots_raw="$(docker-compose -f "$compose_file" exec -T mariadb mariadb -N -uroot -p"$mariadb_root_pwd" "$mariadb_db" -e "SELECT applicant, table_name FROM config__table_map ORDER BY applicant" </dev/null)"
  if [ -z "$bots_raw" ]; then
    echo "No bots found in config__table_map" >&2
    exit 1
  fi

  idx=0
  echo "Available bots:" >&2
  echo "$bots_raw" | while IFS="$(printf '\t')" read -r applicant table_name; do
    idx=$((idx + 1))
    printf '[%d] %s (%s)\n' "$idx" "$applicant" "$table_name" >&2
  done

  total="$(printf '%s\n' "$bots_raw" | grep -c .)"
  selected="$(choose_number "Choose bot number" 1 "$total" 1)"

  selected_line="$(printf '%s\n' "$bots_raw" | sed -n "${selected}p")"
  bot_name="$(printf '%s' "$selected_line" | cut -f1)"
  cfg_table="$(printf '%s' "$selected_line" | cut -f2)"

  accounts_raw="$(docker-compose -f "$compose_file" exec -T mariadb mariadb -N -uroot -p"$mariadb_root_pwd" "$mariadb_db" -e "SELECT DISTINCT account_id FROM $cfg_table ORDER BY account_id" </dev/null)"
  if [ -n "$accounts_raw" ]; then
    aidx=0
    echo "Available account_id values:" >&2
    printf '%s\n' "$accounts_raw" | while IFS= read -r aid; do
      [ -n "$aid" ] || continue
      aidx=$((aidx + 1))
      printf '[%d] %s\n' "$aidx" "$aid" >&2
    done
    atotal="$(printf '%s\n' "$accounts_raw" | grep -c .)"
    acc_sel="$(choose_number "Choose account number" 1 "$atotal" 1)"
    account_id="$(printf '%s\n' "$accounts_raw" | sed -n "${acc_sel}p")"
  else
    account_id="$(ask "Account ID" "1")"
  fi

  api_key="$(ask_secret "API key")"
  api_secret="$(ask_secret "API secret")"

  CREDENTIAL_SOURCE=db \
  BOT_NAME="$bot_name" \
  ACCOUNT_ID="$account_id" \
  API_KEY="$api_key" \
  API_SECRET="$api_secret" \
  sh scripts/inject-api-keys.sh
fi
