#!/bin/sh
set -eu

SINCE_MINUTES="${INTEGRITY_SINCE_MINUTES:-360}"
MAX_FILES="${INTEGRITY_MAX_FILES:-300}"
TAIL_LINES="${INTEGRITY_TAIL_LINES:-120}"
PHP_LOG_FILE="${PHP_ERROR_LOG_FILE:-/app/var/log/php_errors.log}"
PHP_LOG_DIR="$(dirname "$PHP_LOG_FILE")"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT
LIST_FILE="$TMP_DIR/files.lst"

if [ "$#" -gt 0 ]; then
    ROOTS="$*"
else
    ROOTS="/app/src /app/signals-server"
fi

for root in $ROOTS; do
    if [ -d "$root" ]; then
        find "$root" -type f -name '*.php' -mmin "-$SINCE_MINUTES" 2>/dev/null >> "$LIST_FILE" || true
    fi
done

if [ ! -s "$LIST_FILE" ]; then
    for root in $ROOTS; do
        if [ -d "$root" ]; then
            find "$root" -type f -name '*.php' 2>/dev/null >> "$LIST_FILE" || true
        fi
    done
fi

tail_php_logs() {
    if [ -f "$PHP_LOG_FILE" ]; then
        echo "#INTEGRITY: tail php error log ($PHP_LOG_FILE)"
        tail -n "$TAIL_LINES" "$PHP_LOG_FILE" || true
        return
    fi

    if [ -d "$PHP_LOG_DIR" ]; then
        CANDIDATES="$(find "$PHP_LOG_DIR" -maxdepth 2 -type f -name 'php_errors*.log' 2>/dev/null | sort || true)"
        if [ -n "$CANDIDATES" ]; then
            echo "#INTEGRITY: configured log missing ($PHP_LOG_FILE), tailing discovered php_errors*.log files"
            for f in $CANDIDATES; do
                echo "#INTEGRITY: tail $f"
                tail -n "$TAIL_LINES" "$f" || true
            done
            return
        fi
    fi

    echo "#INTEGRITY: log file not found: $PHP_LOG_FILE"
}

if [ ! -s "$LIST_FILE" ]; then
    echo "#INTEGRITY: no PHP files found in roots: $ROOTS"
    tail_php_logs
    exit 0
fi

sort -u "$LIST_FILE" | head -n "$MAX_FILES" > "$TMP_DIR/check.lst"
TOTAL="$(wc -l < "$TMP_DIR/check.lst" | tr -d ' ')"
FAILED=0

echo "#INTEGRITY: checking $TOTAL PHP files (roots: $ROOTS, since ${SINCE_MINUTES}m)"

while IFS= read -r file; do
    if ! php -l "$file" > "$TMP_DIR/lint.out" 2>&1; then
        FAILED=$((FAILED + 1))
        echo "#LINT_FAIL: $file"
        cat "$TMP_DIR/lint.out"
    fi
done < "$TMP_DIR/check.lst"

echo "#INTEGRITY: lint failures = $FAILED"

tail_php_logs

if [ "$FAILED" -gt 0 ]; then
    exit 1
fi
