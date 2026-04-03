# Logging Architecture (Trading Platform PHP)

## Purpose

This document describes how logs are generated, where they are physically stored, how symlink aliases work (`logs.td`), and how stale logs should be cleaned up.

## Runtime Topology

Main service: `bots-hive` (`trd-bots-hive`)

Mounted paths:

- `./var/log` -> `/app/var/log`
- `./src` -> `/app/src`

Compatibility links created by entrypoints:

- `/app/src/logs` -> `/app/var/log` (canonical app-visible log root)
- legacy `/app/src/log` is removed/migrated by `run-bot-manager.sh`

## Core Logging Components

### 1) Application error log baseline (`common.php`)

In shared library `alpet-libs-php/common.php`:

- for CLI, script name is derived from `basename($argv[0])`
- base error log is `${script_name}.errors.log`
- if local `logs` dir exists, error log goes to `logs/${script_name}.errors.log`

Important implication:

- `php -r` produces script name like `Standard input code`
- if launched from a directory where `logs` exists, this can create a `logs/standard input code...` artifact
- mitigation already applied in `run-bot.sh`: `php -r` helper snippets are executed from `/`, not from `/app/src`

### 2) Structured bot logs (`BasicLogger`)

In shared library `alpet-libs-php/basic_logger.php`:

- logical root: `../log/` (relative to current working dir)
- physical per-bot subdir: `<impl>/<account>/<YYYY-MM-DD>/...`
- rolling aliases: `core.log`, `errors.log`, etc. point to current hourly files (`core_HH-MM.log`)
- working-directory alias symlink: `logs<idx>.td` (for quick access to current date directory)

### 3) Bot runtime working directory

`run-bot.sh` creates isolated runtime cwd:

- `/app/var/run/bot-runtime/<impl>/<account>`

And logger path compatibility:

- `/app/var/run/bot-runtime/<impl>/log` -> `/app/var/log`

This keeps runtime internals separate from persistent log storage while preserving legacy relative logger paths.

## Physical Log Layout

Current canonical physical storage under host path `var/log`:

- `var/log/bot_manager.log`
- `var/log/bot_manager.errors.log`
- `var/log/<impl>/<account>/<YYYY-MM-DD>/core_HH-MM.log`
- `var/log/<impl>/<account>/<YYYY-MM-DD>/errors_HH-MM.log`
- `var/log/<impl>/<account>/<YYYY-MM-DD>/engine_HH-MM.log`
- `var/log/<impl>/<account>/<YYYY-MM-DD>/order_HH-MM.log`
- `var/log/<impl>/<account>/<YYYY-MM-DD>/market_maker_HH-MM.log`

For BitMEX example:

- `var/log/bitmex_bot/425992/2026-04-02/core_15-41.log`

## Why Date Is Part of the Path

Date-based folders are created by `BasicLogger` in `log_filename()`:

- target dir is `<base>/<YYYY-MM-DD>/`
- this avoids name collisions across days
- this naturally accumulates multiple daily folders per bot/account

## Symlink Behavior and Docker Caveats

Two classes of symlinks are used:

1. CWD alias symlink (`logs.td` / `logs<idx>.td`) inside runtime workspace.
2. Current-file alias symlink (`core.log`, `errors.log`, etc.) inside day folder.

Operational caveat:

- symlinks are created inside Linux container filesystem semantics
- when viewed from Windows host, some links can appear opaque or not resolve as expected in explorer tools
- always treat physical `*_HH-MM.log` files under dated directories as the source of truth

## Collision Model

Current behavior prevents cross-account collisions for same implementation:

- logger subdir is now `<impl>/<account>` (not just `<impl>`)

So:

- `bitmex_bot/425992/...` and `bitmex_bot/777777/...` are separated
- bybit and bitmex are also separated by implementation root

## Rotation and Compression

From `BasicLogger`:

- hourly filename convention (`*_HH-MM.log`)
- on size threshold (`size_limit`, default 300 MiB) and special minute/line-count condition:
  - closes active log
  - compresses with bzip2
  - maintains alias links
- destructor attempts previous-day folder archive:
  - packs `<YYYY-MM-DD>` into `<YYYY-MM-DD>.tar.bz2`
  - removes original folder (`tar --bzip --remove-files`)

## Retention / Cleanup Status

Current state:

- no dedicated global retention policy for app logs is enforced in runtime
- compression/archiving exists, but long-term pruning is not centrally managed

Risk:

- dozens of day folders (and archives) can accumulate per bot/account over time

## Recommended Retention Policy

Suggested default policy:

- keep raw day folders for 7 days
- keep compressed daily archives for 30 days
- delete older artifacts beyond retention window

Recommended implementation options:

1. Add a dedicated `log_cleanup.php` invoked by bot-manager daily.
2. Add a small host-side cron/task using `find` over `var/log/<impl>/<account>`.
3. Keep cleanup idempotent and append summary to a dedicated `var/log/log_cleanup.log`.

## Operator Guidance

To inspect latest core logs reliably, use physical files, not symlink aliases:

- `var/log/<impl>/<account>/<YYYY-MM-DD>/core_*.log`

Use symlink aliases (`core.log`, `logs.td`) only as convenience within container shell.

## Change Log Context

Recent hardening included:

- migration away from legacy `src/log`
- canonical use of `src/logs` -> `/app/var/log`
- prevention of `standard input code` artifact creation from `php -r` helper calls
- account-aware bot log subdirectories to prevent same-impl multi-account collisions
