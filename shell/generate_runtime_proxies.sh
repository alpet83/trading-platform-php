#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
SRC_DIR="$ROOT_DIR/src"
LIB_DIR="$SRC_DIR/lib"

mkdir -p "$SRC_DIR" "$LIB_DIR"

write_proxy_file() {
  target="$1"
  lib_file="$2"
  fallback_rel="$3"

  cat > "$target" <<EOF
<?php
// AUTO-GENERATED RUNTIME PROXY. DO NOT COMMIT.

\$__tp_candidates = [];
\$__tp_candidates[] = __DIR__ . '/$fallback_rel';

\$__tp_runtime = getenv('ALPET_LIBS_RUNTIME');
if (is_string(\$__tp_runtime) && \$__tp_runtime !== '') {
    \$__tp_candidates[] = rtrim(\$__tp_runtime, "/\\\\") . DIRECTORY_SEPARATOR . '$lib_file';
}

\$__tp_repo = getenv('ALPET_LIBS_REPO');
if (is_string(\$__tp_repo) && \$__tp_repo !== '') {
    \$__tp_candidates[] = rtrim(\$__tp_repo, "/\\\\") . DIRECTORY_SEPARATOR . '$lib_file';
}

foreach (\$__tp_candidates as \$__tp_path) {
    if (is_string(\$__tp_path) && \$__tp_path !== '' && file_exists(\$__tp_path)) {
        require_once \$__tp_path;
        return;
    }
}

trigger_error('Missing runtime library: $lib_file', E_USER_ERROR);
EOF
}

write_wrapper_file() {
  target="$1"
  relative_target="$2"

  cat > "$target" <<EOF
<?php
// AUTO-GENERATED RUNTIME WRAPPER. DO NOT COMMIT.

require_once(__DIR__ . '/$relative_target');
EOF
}

write_proxy_file "$SRC_DIR/common.php" "common.php" "../lib/common.php"
write_proxy_file "$SRC_DIR/esctext.php" "esctext.php" "../lib/esctext.php"
write_proxy_file "$LIB_DIR/db_tools.php" "db_tools.php" "../../lib/db_tools.php"
write_proxy_file "$LIB_DIR/basic_html.php" "basic_html.php" "../../lib/basic_html.php"
write_proxy_file "$LIB_DIR/table_render.php" "table_render.php" "../../lib/table_render.php"
write_wrapper_file "$LIB_DIR/common.php" "../common.php"
write_wrapper_file "$LIB_DIR/esctext.php" "../esctext.php"

echo "#INFO: generated runtime proxy files in src/ and src/lib/"