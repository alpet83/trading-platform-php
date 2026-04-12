#!/usr/bin/env bash
# Supplemental healthcheck: verifies the target schema exists.
# Primary liveness check is handled by the stock /usr/local/bin/healthcheck.sh
# via the healthcheck MariaDB user + /var/lib/mysql/.my-healthcheck.cnf.
set -euo pipefail

DB_NAME="${MARIADB_DATABASE:-trading}"
HC_CNF="/var/lib/mysql/.my-healthcheck.cnf"

if [[ -r "$HC_CNF" ]]; then
    # Use the official healthcheck user credentials (no password in process list)
    mariadb \
        --defaults-extra-file="$HC_CNF" \
        --protocol=TCP \
        -h127.0.0.1 \
        -Nse "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='${DB_NAME}' LIMIT 1" \
        2>/dev/null | grep -Fxq "$DB_NAME"
else
    # Fallback: unix-socket root (works from inside container only)
    mariadb \
        -uroot \
        --protocol=socket \
        -Nse "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='${DB_NAME}' LIMIT 1" \
        2>/dev/null | grep -Fxq "$DB_NAME"
fi
