#!/bin/sh
# ensure_external_repos.sh — Clone external dependency repositories if absent.
# Called by deploy scripts before docker compose build/up.
#
# Repos managed:
#   alpet-libs-php  — shared PHP runtime library (alpet83/alpet-libs-php)
#   datafeed        — exchange data feed loaders  (alpet83/datafeed)
#
# Usage:
#   sh shell/ensure_external_repos.sh            # clone if absent only
#   sh shell/ensure_external_repos.sh --update   # also git pull if already cloned
#
# Environment overrides:
#   EXTERNAL_REPOS_DIR   — directory that will contain repo siblings; default: project parent dir
#   ALPET_LIBS_REPO_URL  — git URL for alpet-libs-php
#   DATAFEED_REPO_URL    — git URL for datafeed
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
UPDATE_MODE=0

for arg in "$@"; do
    case "$arg" in
        --update) UPDATE_MODE=1 ;;
    esac
done

EXTERNAL_REPOS_DIR="${EXTERNAL_REPOS_DIR:-$(dirname "$ROOT_DIR")}"
ALPET_LIBS_REPO_URL="${ALPET_LIBS_REPO_URL:-https://github.com/alpet83/alpet-libs-php}"
DATAFEED_REPO_URL="${DATAFEED_REPO_URL:-https://github.com/alpet83/datafeed}"

ALPET_LIBS_DIR="$EXTERNAL_REPOS_DIR/alpet-libs-php"
DATAFEED_DIR="$EXTERNAL_REPOS_DIR/datafeed"

log() { printf '%s\n' "$*"; }
fail() { log "#ERROR: $*"; exit 1; }

require_git() {
    command -v git >/dev/null 2>&1 || fail "git is required but not found in PATH"
}

ensure_repo() {
    repo_dir="$1"
    repo_url="$2"
    label="$3"

    if [ -d "$repo_dir/.git" ]; then
        if [ "$UPDATE_MODE" -eq 1 ]; then
            log "#INFO: updating $label at $repo_dir"
            git -C "$repo_dir" pull --ff-only || log "#WARN: git pull failed for $label (continuing)"
        else
            log "#INFO: $label already present at $repo_dir"
        fi
        return 0
    fi

    if [ -d "$repo_dir" ] && [ "$(ls -A "$repo_dir" 2>/dev/null)" ]; then
        log "#WARN: $repo_dir exists but is not a git repo — skipping clone"
        return 0
    fi

    log "#INFO: cloning $label from $repo_url"
    git clone --depth=1 "$repo_url" "$repo_dir" \
        || fail "failed to clone $label from $repo_url"
    log "#INFO: $label cloned to $repo_dir"
}

require_git
ensure_repo "$ALPET_LIBS_DIR" "$ALPET_LIBS_REPO_URL" "alpet-libs-php"
ensure_repo "$DATAFEED_DIR"    "$DATAFEED_REPO_URL"    "datafeed"

log "#INFO: external repos ready (EXTERNAL_REPOS_DIR=$EXTERNAL_REPOS_DIR)"
