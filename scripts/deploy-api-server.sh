#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
SECRETS_DIR="$ROOT_DIR/secrets"
SRC_DIR="$ROOT_DIR/src"
LIB_DIR="$SRC_DIR/lib"
DB_CONFIG_TARGET="$SECRETS_DIR/db_config.php"
DB_CONFIG_SOURCE_DEFAULT="$SECRETS_DIR/db_config.php.initialized"
DB_CONFIG_SOURCE="${DEPLOY_DB_CONFIG_SOURCE:-$DB_CONFIG_SOURCE_DEFAULT}"
ALPET_LIBS_REPO="${ALPET_LIBS_REPO:-$ROOT_DIR/../alpet-libs-php}"

need_file() {
  if [ ! -f "$1" ]; then
    echo "#ERROR: missing required file $1"
    exit 1
  fi
}

copy_missing_file() {
  src_file="$1"
  dst_file="$2"
  if [ -f "$dst_file" ]; then
    return 0
  fi
  cp "$src_file" "$dst_file"
  echo "#INFO: copied $(basename "$src_file") -> $dst_file"
}

prepare_runtime_libs() {
  need_common="$SRC_DIR/common.php"
  need_esctext="$SRC_DIR/esctext.php"
  need_db_tools="$LIB_DIR/db_tools.php"
  need_mini_core="$LIB_DIR/mini_core.php"

  if [ -f "$need_common" ] && [ -f "$need_esctext" ] && [ -f "$need_db_tools" ] && [ -f "$need_mini_core" ]; then
    echo "#INFO: runtime libs already available in src/ and src/lib/."
    return 0
  fi

  echo "#INFO: generating runtime proxy files"
  ALPET_LIBS_REPO="$ALPET_LIBS_REPO" sh "$ROOT_DIR/shell/generate_runtime_proxies.sh"

  if [ ! -f "$need_mini_core" ]; then
    if [ ! -d "$ALPET_LIBS_REPO" ]; then
      echo "#ERROR: ALPET_LIBS_REPO not found: $ALPET_LIBS_REPO"
      exit 1
    fi

    found="$(find "$ALPET_LIBS_REPO" -type f -name "mini_core.php" | head -n 1 || true)"
    if [ -z "$found" ]; then
      echo "#ERROR: cannot find mini_core.php in $ALPET_LIBS_REPO"
      exit 1
    fi
    copy_missing_file "$found" "$LIB_DIR/mini_core.php"
  fi
}

prepare_db_config() {
  mkdir -p "$SECRETS_DIR"

  if [ -f "$DB_CONFIG_TARGET" ]; then
    echo "#INFO: using existing $DB_CONFIG_TARGET"
    return 0
  fi

  if [ -n "$DB_CONFIG_SOURCE" ] && [ -f "$DB_CONFIG_SOURCE" ]; then
    cp "$DB_CONFIG_SOURCE" "$DB_CONFIG_TARGET"
    echo "#INFO: copied initialized db_config from $DB_CONFIG_SOURCE"
    return 0
  fi

  if [ -f "$SECRETS_DIR/db_config.php.example" ]; then
    cp "$SECRETS_DIR/db_config.php.example" "$DB_CONFIG_TARGET"
    echo "#WARN: copied template to $DB_CONFIG_TARGET (fill real credentials before production use)."
    return 0
  fi

  echo "#ERROR: cannot find initialized db_config.php source."
  echo "#HINT: put file in $SECRETS_DIR/db_config.php.initialized or set DEPLOY_DB_CONFIG_SOURCE=/path/to/db_config.php"
  exit 1
}

start_api_server() {
  cd "$ROOT_DIR"
  echo "#INFO: starting mariadb + web(api) services"
  docker-compose up -d mariadb web
  echo "#INFO: API/web-ui started. Endpoint: http://localhost:${WEB_PORT:-8088}/"
}

main() {
  echo "#INFO: prepare runtime for web-ui/api deploy"
  prepare_runtime_libs
  prepare_db_config
  start_api_server
}

main "$@"
