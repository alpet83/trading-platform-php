#!/usr/bin/env bash
set -euo pipefail

ROOT_PASSWORD="${MARIADB_ROOT_PASSWORD:-}"
DB_NAME="${MARIADB_DATABASE:-trading}"
BACKUP_DIR="${MARIADB_BACKUP_DIR:-/var/backup/mysql}"
BACKUP_ON_START="${MARIADB_BACKUP_ON_START:-1}"
BACKUP_RETENTION_DAYS="${MARIADB_BACKUP_RETENTION_DAYS:-7}"
PING_TIMEOUT_SECONDS="${MARIADB_BACKUP_PING_TIMEOUT_SECONDS:-60}"
ROOT_SOCKET_AUTH="${MARIADB_ROOT_SOCKET_AUTH:-1}"
ROOT_PASSWORD_FILE="${MARIADB_ROOT_PASSWORD_FILE:-/var/secrets/mariadb_root_password}"

log() {
  printf '[mariadb-wrapper] %s\n' "$*"
}

cleanup_old_backups() {
  if [[ -d "$BACKUP_DIR" ]]; then
    find "$BACKUP_DIR" -type f -name '*.sql.gz' -mtime "+${BACKUP_RETENTION_DAYS}" -delete || true
  fi
}

wait_until_ready() {
  local elapsed=0
  while [[ "$elapsed" -lt "$PING_TIMEOUT_SECONDS" ]]; do
    if mariadb -uroot -e 'SELECT 1' >/dev/null 2>&1; then
      return 0
    fi
    if [[ -n "$ROOT_PASSWORD" ]] && mariadb -uroot -p"$ROOT_PASSWORD" -e 'SELECT 1' >/dev/null 2>&1; then
      return 0
    fi
    if [[ -n "$ROOT_PASSWORD" ]] && mariadb -h127.0.0.1 -uroot -p"$ROOT_PASSWORD" -e 'SELECT 1' >/dev/null 2>&1; then
      return 0
    fi
    sleep 2
    elapsed=$((elapsed + 2))
  done
  return 1
}

persist_root_password() {
  local dir
  dir="$(dirname "$ROOT_PASSWORD_FILE")"
  mkdir -p "$dir"
  umask 077
  printf '%s\n' "$ROOT_PASSWORD" > "$ROOT_PASSWORD_FILE"
}

create_root_client_defaults() {
  umask 077
  cat > /root/.my.cnf <<EOF
[client]
user=root
password=$ROOT_PASSWORD
EOF
}

ensure_root_password() {
  if [[ -z "$ROOT_PASSWORD" ]]; then
    ROOT_PASSWORD="$(head -c 24 /dev/urandom | base64 | tr -d '\n' | cut -c1-32)"
    export MARIADB_ROOT_PASSWORD="$ROOT_PASSWORD"
    log "MARIADB_ROOT_PASSWORD was empty; generated runtime password and saved to $ROOT_PASSWORD_FILE"
  fi
  persist_root_password
  create_root_client_defaults
}

run_root_sql() {
  local sql="$1"
  if mariadb -uroot -e "$sql" >/dev/null 2>&1; then
    return 0
  fi
  if [[ -n "$ROOT_PASSWORD" ]] && mariadb -uroot -p"$ROOT_PASSWORD" -e "$sql" >/dev/null 2>&1; then
    return 0
  fi
  mariadb -h127.0.0.1 -uroot -p"$ROOT_PASSWORD" -e "$sql" >/dev/null 2>&1
}

configure_root_socket_auth() {
  if [[ "$ROOT_SOCKET_AUTH" != "1" ]]; then
    return 0
  fi
  if run_root_sql "ALTER USER 'root'@'localhost' IDENTIFIED VIA unix_socket"; then
    log "Enabled unix_socket auth for root@localhost"
  else
    log "WARN: failed to enable unix_socket auth for root@localhost"
  fi
}

run_startup_backup() {
  if [[ "$BACKUP_ON_START" != "1" ]]; then
    log "Startup backup disabled by MARIADB_BACKUP_ON_START=$BACKUP_ON_START"
    return 0
  fi

  mkdir -p "$BACKUP_DIR"

  if ! wait_until_ready; then
    log "Skipping startup backup: server did not become ready in ${PING_TIMEOUT_SECONDS}s"
    return 0
  fi

  local ts
  ts="$(date +%Y%m%d-%H%M%S)"
  local backup_file
  backup_file="${BACKUP_DIR}/${DB_NAME}-${ts}-startup_backup.sql.gz"
  local tmp_backup_file
  tmp_backup_file="${backup_file}.tmp"

  log "Creating startup backup: ${backup_file}"
  if ! (mariadb-dump -uroot --single-transaction --routines --events --databases "$DB_NAME" \
      || mariadb-dump -uroot -p"$ROOT_PASSWORD" --single-transaction --routines --events --databases "$DB_NAME" \
      || mariadb-dump -h127.0.0.1 -uroot -p"$ROOT_PASSWORD" --single-transaction --routines --events --databases "$DB_NAME") \
    | gzip -1 > "$tmp_backup_file"; then
    rm -f "$tmp_backup_file"
    log "Startup backup failed (non-fatal), continuing MariaDB startup"
    return 0
  fi

  mv "$tmp_backup_file" "$backup_file"

  cleanup_old_backups
  log "Startup backup completed"
}

main() {
  ensure_root_password

  # Start official entrypoint in the background so first-boot init scripts still work.
  /usr/local/bin/docker-entrypoint.sh mariadbd "$@" &
  local mariadb_pid=$!

  trap 'kill "$mariadb_pid" 2>/dev/null || true' INT TERM

  if wait_until_ready; then
    configure_root_socket_auth
  fi

  run_startup_backup

  wait "$mariadb_pid"
}

main "$@"
