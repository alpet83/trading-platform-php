#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
STRUCTURE_SQL="${1:-$ROOT_DIR/trading-structure.sql}"
OUTPUT_SQL="${2:-$ROOT_DIR/docker/mariadb-init/20-bootstrap-core.sql}"
TABLE_LIST="${3:-$ROOT_DIR/shell/bootstrap-core-tables.txt}"
DB_NAME="${MARIADB_DATABASE:-trading}"

if [ ! -f "$STRUCTURE_SQL" ]; then
  echo "#ERROR: structure dump not found: $STRUCTURE_SQL"
  exit 1
fi

if [ ! -f "$TABLE_LIST" ]; then
  echo "#ERROR: table list not found: $TABLE_LIST"
  exit 1
fi

mkdir -p "$(dirname "$OUTPUT_SQL")"

TMP_FILE="$(mktemp 2>/dev/null || mktemp -t tpphp)"
cleanup() {
  rm -f "$TMP_FILE"
}
trap cleanup EXIT INT TERM

{
  echo "-- Auto-generated from trading-structure.sql"
  echo "-- Source: $STRUCTURE_SQL"
  echo "-- Table list: $TABLE_LIST"
  echo
  echo "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`;"
  echo "USE \`$DB_NAME\`;"
  echo "SET FOREIGN_KEY_CHECKS = 0;"
  echo
} > "$TMP_FILE"

while IFS= read -r table || [ -n "$table" ]; do
  table="$(printf "%s" "$table" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')"
  if [ -z "$table" ]; then
    continue
  fi
  case "$table" in
    \#*)
      continue
      ;;
  esac

  create_stmt="$(awk -v tbl="$table" '
    BEGIN { capture = 0 }
    $0 ~ "^[[:space:]]*CREATE TABLE `" tbl "`" { capture = 1 }
    capture { print }
    capture && /;[[:space:]]*$/ { exit }
  ' "$STRUCTURE_SQL")"

  if [ -z "$create_stmt" ]; then
    echo "#WARN: CREATE TABLE not found for $table" >&2
    continue
  fi

  printf "-- %s\n" "$table" >> "$TMP_FILE"
  printf "%s\n\n" "$create_stmt" | sed '0,/CREATE TABLE /s//CREATE TABLE IF NOT EXISTS /' >> "$TMP_FILE"

  awk -v tbl="$table" '
    BEGIN { capture = 0 }
    $0 ~ "^[[:space:]]*ALTER TABLE `" tbl "`" { capture = 1 }
    capture { print }
    capture && /;[[:space:]]*$/ { print ""; capture = 0 }
  ' "$STRUCTURE_SQL" >> "$TMP_FILE"

done < "$TABLE_LIST"

{
  echo
  echo "SET FOREIGN_KEY_CHECKS = 1;"
} >> "$TMP_FILE"

mv "$TMP_FILE" "$OUTPUT_SQL"
echo "#INFO: generated bootstrap schema: $OUTPUT_SQL"
