# Tradebot Legacy Repo Handoff

Date: 2026-03-28
Legacy source repo: P:\GitHub\Tradebot
Current runtime remnants: P:\Trade\Tradebot
Current migration target: P:\opt\docker\trading-platform-php\src

## Why this file exists

`P:\GitHub\Tradebot` is an old repository copy that risked getting in the way during the new containerized migration.
Before removing it from disk, this file preserves:
- commit history summary
- uncommitted working tree state that is not represented in `git log`
- migration-relevant observations

Status update:
- legacy repo has since been moved out of the active workspace path
- the PHP application code now lives under `P:\opt\docker\trading-platform-php\src`
- `P:\Trade\Tradebot` no longer represents the main source tree and should be treated as runtime residue only

## Git state at capture time

- Git repo: yes
- Current branch: `main`
- Working tree: dirty

### Uncommitted / unstaged state

Observed from `git status --short` / `git diff --name-status`:
- Modified: `README.md`
- Modified: `tech_doc.txt`
- Deleted from tracked tree:
  - `signals-server/get_signals.php`
  - `signals-server/grid_edit.php`
  - `signals-server/lastpos.php`
  - `signals-server/pairs_map.php`
  - `signals-server/sig_edit.js`
  - `signals-server/sig_edit.php`
  - `signals-server/trade_ctrl_bot.php`
  - `signals-server/trade_ctrl_bot.sh`
  - `signals-server/trade_event.php`
- New untracked directory: `docs/`

### Diff stat snapshot

- `README.md`: 72 changed lines
- `tech_doc.txt`: 103 changed lines
- `signals-server/*`: large deletions totaling 2239 removed lines across 9 files
- Aggregate: 11 files changed, 152 insertions, 2239 deletions

Interpretation:
- This repo contains local restructuring work not preserved in commits.
- Deleting the repo without this note would discard evidence of a partial migration around `signals-server/`.

## Commit history summary

Chronological commits from `git log --reverse`:
- 2025-03-02 | `04f408b` | Initial commit
- 2025-03-02 | `ca37174` | Initial upload
- 2025-03-03 | `6b32a6a` | Bugfixes and improvements
- 2025-03-05 | `76ff7f9` | Many bugfixes, some improvements
- 2025-03-05 | `48999f4` | Visual improvements for WebUI
- 2025-03-06 | `d223cc8` | Another bug fixes
- 2025-03-21 | `bef183e` | Significant refactoring
- 2025-03-21 | `4e07659` | Unification and bug-fixes

## Structure observations

Legacy repo `P:\GitHub\Tradebot` is relatively flat and looks like an older published snapshot.
At capture time, `P:\Trade\Tradebot` was the richer runtime tree and contained the migration source.
That is no longer true.

Current observed layout:
- `P:\opt\docker\trading-platform-php\src` contains the PHP codebase, including:
  - `composer.json`
  - `web-ui/`
  - `tele-bot/`
  - bot core entrypoints such as `bot_instance.php`, `trading_core.php`, `impl_*.php`
- `P:\opt\docker\trading-platform-php` contains supporting project folders around `src`:
  - `docs/`
  - `shell/`
  - `tests/`
  - `utils/`
  - `misc/`
- `P:\Trade\Tradebot` currently contains only residual runtime files such as `authorized_keys`, `secret.ref`, and `tmp/`

This means `P:\opt\docker\trading-platform-php\src` is now the authoritative application source for containerization, while `P:\GitHub\Tradebot` remains only a historical reference.

## Migration implication

For the ongoing containerized refactor, use:
- `P:\opt\docker\trading-platform-php\src` as the live application source tree
- `P:\opt\docker\trading-platform-php` as the container project root
- `P:\Trade\Tradebot` only as a place to mine leftover runtime configuration if something was not copied yet
- `P:\GitHub\Tradebot` only as a legacy history checkpoint

If removal is still desired after this handoff, deletion is now low-risk from a history perspective, but the uncommitted deletions listed above should be considered intentionally abandoned unless manually ported first.
