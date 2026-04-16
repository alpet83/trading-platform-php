#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"

REQUIRED_SERVICES=(mariadb web bots-hive datafeed)
OPTIONAL_SERVICES=(gpg-agent phpmyadmin)
REQUIRED_DATABASES=(trading datafeed binance bitmex bitfinex bybit deribit)
DATAFEED_TABLES=(loader_control loader_activity)

ok_count=0
warn_count=0
fail_count=0

section() {
    printf '\n## %s\n' "$*"
}

ok() {
    printf '[OK] %s\n' "$*"
    ok_count=$((ok_count + 1))
}

warn() {
    printf '[WARN] %s\n' "$*"
    warn_count=$((warn_count + 1))
}

fail() {
    printf '[FAIL] %s\n' "$*"
    fail_count=$((fail_count + 1))
}

require_cmd() {
    command -v "$1" >/dev/null 2>&1 || {
        printf '[FAIL] required command not found: %s\n' "$1"
        exit 2
    }
}

compose() {
    docker-compose -f "$COMPOSE_FILE" "$@"
}

service_cid() {
    compose ps -q "$1" 2>/dev/null | head -n 1
}

service_running() {
    local cid
    cid="$(service_cid "$1")"
    [ -n "$cid" ] || return 1
    [ "$(docker inspect -f '{{.State.Running}}' "$cid" 2>/dev/null || true)" = "true" ]
}

service_health() {
    local cid
    cid="$(service_cid "$1")"
    [ -n "$cid" ] || return 1
    docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$cid" 2>/dev/null || true
}

check_file_required() {
    local path="$1"
    local label="$2"
    if [ -f "$path" ]; then
        ok "$label: $path"
    else
        fail "$label missing: $path"
    fi
}

check_dir_required() {
    local path="$1"
    local label="$2"
    if [ -d "$path" ]; then
        ok "$label: $path"
    else
        fail "$label missing: $path"
    fi
}

sql_scalar() {
    local sql="$1"
    compose exec -T web php -r '$sql=$argv[1]; include "/app/src/lib/db_config.php"; $cfg=$db_configs["trading"] ?? null; $host=$db_servers[0] ?? "mariadb"; if (!is_array($cfg)) { fwrite(STDERR, "db_config missing\n"); exit(1);} $db=@new mysqli($host, $cfg[0], $cfg[1], "information_schema"); if ($db->connect_errno) { fwrite(STDERR, $db->connect_error . "\n"); exit(2);} $res=$db->query($sql); if (!$res) { fwrite(STDERR, $db->error . "\n"); exit(3);} $row=$res->fetch_row(); if ($row && isset($row[0])) echo (string)$row[0];' -- "$sql"
}

check_db_exists() {
    local db="$1"
    local out
    out="$(sql_scalar "SELECT SCHEMA_NAME FROM SCHEMATA WHERE SCHEMA_NAME='${db}'" 2>/dev/null || true)"
    if [ "$out" = "$db" ]; then
        ok "database exists: $db"
    else
        fail "database missing: $db"
    fi
}

check_table_exists() {
    local db="$1"
    local table="$2"
    local out
    out="$(sql_scalar "SELECT TABLE_NAME FROM TABLES WHERE TABLE_SCHEMA='${db}' AND TABLE_NAME='${table}'" 2>/dev/null || true)"
    if [ "$out" = "$table" ]; then
        ok "table exists: ${db}.${table}"
    else
        fail "table missing: ${db}.${table}"
    fi
}

run_php_in_service() {
    local service="$1"
    local code="$2"
    printf '%s\n' "$code" | compose exec -T "$service" php
}

check_service_running() {
    local service="$1"
    if service_running "$service"; then
        ok "$service container is running"
    else
        fail "$service container is not running"
    fi
}

check_web_probe() {
    local label="$1"
    local url="$2"
    local min_len="$3"
    if compose exec -T web php -r '$u=$argv[1]; $min=(int)$argv[2]; $s=@file_get_contents($u); if ($s === false) { fwrite(STDERR, "probe failed\n"); exit(1);} if (strlen($s) < $min) { fwrite(STDERR, "response too short\n"); exit(2);} echo substr(trim($s),0,80), "\n";' -- "$url" "$min_len" >/tmp/verify-simple-web.out 2>/tmp/verify-simple-web.err; then
        ok "$label reachable: $url"
    else
        fail "$label probe failed: $url"
    fi
}


container_running_by_name() {
    local name="$1"
    [ "$(docker inspect -f '{{.State.Running}}' "$name" 2>/dev/null || true)" = "true" ]
}

main() {
    local compose_services core_tables_file
    local -a core_tables

    require_cmd docker
    require_cmd docker-compose
    require_cmd bash

    section 'Reference Compose Contract'
    if compose_services="$(compose config --services 2>/tmp/verify-simple-compose.err)"; then
        ok "docker-compose config resolves for $COMPOSE_FILE"
    else
        fail "docker-compose config failed for $COMPOSE_FILE"
        cat /tmp/verify-simple-compose.err >&2 || true
        exit 1
    fi

    for service in "${REQUIRED_SERVICES[@]}"; do
        if printf '%s\n' "$compose_services" | grep -qx "$service"; then
            ok "required service defined: $service"
        else
            fail "required service missing from compose: $service"
        fi
    done

    for service in "${OPTIONAL_SERVICES[@]}"; do
        if printf '%s\n' "$compose_services" | grep -qx "$service"; then
            ok "optional service defined: $service"
        else
            warn "optional service missing from compose: $service"
        fi
    done

    section 'Host Bootstrap Artifacts'
    check_file_required 'secrets/db_config.php' 'generated db config'
    check_file_required 'docker/mariadb-init/10-create-trading-role.sql' 'bootstrap role sql'
    check_file_required 'docker/mariadb-init/20-bootstrap-core.sql' 'bootstrap core sql'
    check_file_required 'docker/mariadb-init/40-bootstrap-datafeed-loader-manager.sql' 'bootstrap datafeed sql'
    check_file_required 'src/lib/hosts_cfg.php' 'generated signals url layer'
    check_file_required 'src/lib/.allowed_ip.lst' 'generated admin allowlist'
    check_file_required 'scripts/inject_apikey.sh' 'bot injector wrapper'
    check_file_required 'src/cli/inject_apikey.php' 'bot injector cli'
    check_file_required '../datafeed/src/datafeed_manager.php' 'external datafeed manager source'
    check_dir_required 'secrets' 'secrets directory'

    section 'Container Runtime State'
    for service in "${REQUIRED_SERVICES[@]}"; do
        check_service_running "$service"
    done

    for service in "${OPTIONAL_SERVICES[@]}"; do
        if service_running "$service"; then
            ok "$service container is running"
        else
            warn "$service container is not running"
        fi
    done

    if service_running 'mariadb'; then
        case "$(service_health mariadb)" in
            healthy) ok 'mariadb healthcheck is healthy' ;;
            starting) warn 'mariadb healthcheck still starting' ;;
            none) warn 'mariadb has no healthcheck status available' ;;
            *) fail "mariadb healthcheck status: $(service_health mariadb)" ;;
        esac
    fi

    if service_running 'gpg-agent'; then
        case "$(service_health gpg-agent)" in
            healthy|none) ok 'gpg-agent healthcheck is acceptable' ;;
            starting) warn 'gpg-agent healthcheck still starting' ;;
            *) warn "gpg-agent healthcheck status: $(service_health gpg-agent)" ;;
        esac
    fi

    section 'Database Bootstrap'
    if service_running 'mariadb'; then
        if [ "$(sql_scalar 'SELECT 1' 2>/dev/null || true)" = '1' ]; then
            ok 'mariadb accepts local SQL queries'
        else
            fail 'mariadb does not accept local SQL queries'
        fi

        for db in "${REQUIRED_DATABASES[@]}"; do
            check_db_exists "$db"
        done

        core_tables_file='shell/bootstrap-core-tables.txt'
        if [ -f "$core_tables_file" ]; then
            mapfile -t core_tables < <(grep -v '^[[:space:]]*#' "$core_tables_file" | sed '/^[[:space:]]*$/d')
            core_tables+=(config__bot_manager)
            for table in "${core_tables[@]}"; do
                check_table_exists 'trading' "$table"
            done
        else
            fail "core tables list missing: $core_tables_file"
        fi

        for table in "${DATAFEED_TABLES[@]}"; do
            check_table_exists 'datafeed' "$table"
        done
    else
        fail 'database bootstrap checks skipped because mariadb is not running'
    fi

    section 'Admin and API Reachability'
    if service_running 'web'; then
        check_web_probe 'basic admin page' 'http://127.0.0.1/basic-admin.php' 50
        check_web_probe 'api entrypoint' 'http://127.0.0.1/api/index.php' 1
        if compose exec -T web sh -lc '[ -f /app/src/lib/db_config.php ] && [ -f /app/src/lib/.allowed_ip.lst ]'; then
            ok 'web container has mounted db_config.php and allowlist'
        else
            fail 'web container is missing db_config.php or allowlist mount'
        fi
    else
        fail 'web reachability checks skipped because web is not running'
    fi

    section 'Bot Manager and Injector Readiness'
    if service_running 'bots-hive'; then
        if compose exec -T bots-hive sh -lc '[ -f /app/src/cli/inject_apikey.php ] && [ -f /app/src/lib/db_config.php ]'; then
            ok 'bots-hive has injector CLI and db_config mount'
        else
            fail 'bots-hive is missing injector CLI or db_config mount'
        fi

        if compose exec -T bots-hive sh -lc '[ "${BOT_CREDENTIAL_SOURCE:-}" = "db" ]'; then
            ok 'bots-hive uses BOT_CREDENTIAL_SOURCE=db'
        else
            fail 'bots-hive BOT_CREDENTIAL_SOURCE is not db'
        fi

        if compose exec -T bots-hive sh -lc '[ -n "${BOT_TRADER_PASSWORD:-}" ] && [ "${BOT_TRADER_PASSWORD}" != "placeholder" ]'; then
            ok 'bots-hive has non-placeholder BOT_TRADER_PASSWORD'
        else
            fail 'bots-hive still uses placeholder or empty BOT_TRADER_PASSWORD'
        fi

        if run_php_in_service 'bots-hive' '<?php include "/app/src/lib/db_config.php"; $cfg=$db_configs["trading"] ?? null; $host=$db_servers[0] ?? "mariadb"; if (!is_array($cfg)) { fwrite(STDERR, "cfg missing\n"); exit(1);} $db=@new mysqli($host, $cfg[0], $cfg[1], "trading"); if ($db->connect_errno) { fwrite(STDERR, $db->connect_error . "\n"); exit(2);} $rs=$db->query("SELECT COUNT(*) FROM config__bot_manager"); if (!$rs) { fwrite(STDERR, $db->error . "\n"); exit(3);} $row=$rs->fetch_row(); echo $row[0], "\n";' >/tmp/verify-simple-bots.out 2>/tmp/verify-simple-bots.err; then
            ok "injector prerequisites can query trading.config__bot_manager (rows=$(tr -d '\r\n' </tmp/verify-simple-bots.out))"
        else
            fail 'bots-hive cannot query trading.config__bot_manager through db_config'
        fi
    else
        fail 'bot-manager readiness checks skipped because bots-hive is not running'
    fi

    section 'Datafeed Wiring'
    if service_running 'datafeed'; then
        if compose exec -T datafeed sh -lc '[ -f /app/datafeed/src/datafeed_manager.php ] && [ -f /app/datafeed/lib/db_config.php ]'; then
            ok 'datafeed has manager source and db_config mount'
        else
            fail 'datafeed is missing manager source or db_config mount'
        fi

        if run_php_in_service 'datafeed' '<?php include "/app/datafeed/lib/db_config.php"; $cfg=$db_configs["datafeed"] ?? ($db_configs["trading"] ?? null); $host=$db_servers[0] ?? "mariadb"; if (!is_array($cfg)) { fwrite(STDERR, "cfg missing\n"); exit(1);} $db=@new mysqli($host, $cfg[0], $cfg[1], "datafeed"); if ($db->connect_errno) { fwrite(STDERR, $db->connect_error . "\n"); exit(2);} echo "ok\n";' >/tmp/verify-simple-datafeed.out 2>/tmp/verify-simple-datafeed.err; then
            ok 'datafeed container can connect to MariaDB using mounted db_config'
        else
            fail 'datafeed container cannot connect to MariaDB using mounted db_config'
        fi
    else
        fail 'datafeed wiring checks skipped because datafeed is not running'
    fi


    section 'DB Auth Consistency'
    if service_running 'bots-hive'; then
        if compose exec -T bots-hive sh -lc '[ -f /app/src/cli/check_db_auth.php ]'; then
            if compose exec -T bots-hive sh -lc 'php /app/src/cli/check_db_auth.php --context=bots-hive' >/tmp/verify-simple-auth-bots.out 2>/tmp/verify-simple-auth-bots.err; then
                ok 'bots-hive db auth validator passed'
            else
                fail 'bots-hive db auth validator detected conflicts'
            fi
        else
            fail 'bots-hive missing /app/src/cli/check_db_auth.php'
        fi
    else
        fail 'bots-hive db auth validator skipped because bots-hive is not running'
    fi

    if container_running_by_name 'sigsys'; then
        if docker exec sigsys sh -lc '[ -f /app/src/cli/check_db_auth.php ]'; then
            if docker exec sigsys sh -lc 'php /app/src/cli/check_db_auth.php --context=sigsys' >/tmp/verify-simple-auth-sigsys.out 2>/tmp/verify-simple-auth-sigsys.err; then
                ok 'sigsys db auth validator passed'
            else
                fail 'sigsys db auth validator detected conflicts'
            fi
        else
            fail 'sigsys missing /app/src/cli/check_db_auth.php'
        fi
    else
        warn 'sigsys container is not running; sigsys db auth validator skipped'
    fi

    section 'Summary'
    printf 'Checklist completed: ok=%d warn=%d fail=%d\n' "$ok_count" "$warn_count" "$fail_count"
    printf 'Reference note: clean deploy is expected to have fewer exchange-specific tables; this checklist validates only bootstrap tables and operational prerequisites for admin UI, injector, bot-manager, and datafeed wiring.\n'

    if [ "$fail_count" -gt 0 ]; then
        exit 1
    fi
}

main "$@"
