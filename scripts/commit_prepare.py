#!/usr/bin/env python3
"""Prepare commit for trading-platform repository from deployed runtime tree.

Main goals:
- sync meaningful source changes from deployed tree to git clone
- prevent runtime garbage from entering repository
- detect risky hardcoded deployment references in changed text files

Default mode is dry-run for safety.
"""

from __future__ import annotations

import argparse
import fnmatch
import hashlib
import json
import os
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
import re
import shutil
import subprocess
import sys
from typing import Iterable

IGNORE_DIRS = {
    ".git",
    "__pycache__",
    ".venv",
    "node_modules",
    "vendor",
    ".pytest_cache",
    ".mypy_cache",
    ".idea",
    ".vscode",
}

# Runtime-only or sensitive artifacts that must not be synced to git clone.
EXCLUDE_GLOBS = (
    "var/**",
    "secrets/**",
    ".envs/**",
    ".env",
    ".env.*",
    "src/data/**",
    "src/log/**",
    "src/logs/**",
    "src/logs.td/**",
    "src/logs2.td/**",
    "scripts/commit_prepare_datafeed.ps1",
    "scripts/commit_prepare_datafeed.cmd",
    "scripts/commit_prepare.py",
    "scripts/commit_prepare_report.json",
    "src/.bitmex.api_key",
    "src/.bitmex.key",
    "src/lib/.allowed_ip.lst",
    "src/lib/db_config.php",
    "src/lib/hosts_cfg.php",
    "src/[0-9]*/**",
    "tests/**",
    "utils/**",
    "signals-server.ts/test/**",
    "signals-server.ts/tests/**",
    "signals-server.ts/sandwiches/**",
    # Third-party SDK/vendor drops must never be synced from runtime.
    "signals-server/telegram-bot/**",
    "misc/**",
    "trading-structure.sql",
    # Runtime-generated compatibility files sourced from alpet-libs-php.
    "src/common.php",
    "src/esctext.php",
    "src/lib/common.php",
    "src/lib/esctext.php",
    "src/lib/db_tools.php",
    "src/lib/basic_html.php",
    "src/lib/table_render.php",
)

# File-level runtime artifacts to ignore regardless of location.
IGNORE_FILE_PATTERNS = (
    "*.log",
    "*.pid",
    "*.pyc",
    "*.pyo",
    "*.sqlite",
    "*.sqlite3",
    "*.dump",
    "*.tmp",
    "*.bak",
    "*.swp",
    "*.secret",
)

TEXT_EXTENSIONS = {
    ".py",
    ".md",
    ".txt",
    ".json",
    ".yaml",
    ".yml",
    ".toml",
    ".ini",
    ".cfg",
    ".env",
    ".sh",
    ".ps1",
    ".sql",
    ".js",
    ".ts",
    ".tsx",
    ".jsx",
    ".css",
    ".scss",
    ".html",
    ".vue",
    ".php",
}

DEFAULT_FORBIDDEN_PATTERNS = (
    r"(?i)\\bP:[\\\\/]",
    r"(?i)\\bvps\\.vpn\\b",
)

SUSPICIOUS_NEW_PATH_PATTERNS = (
    r"^src/[0-9]{3,}/",
    r"(^|/)\.bitmex\.(api_key|key)$",
    r"(^|/)db_config\.php$",
)

DEFAULT_TEST_COMMANDS = (
    "docker compose -f docker-compose.yml config",
    "docker compose -f docker-compose.signals-legacy.yml config",
)


@dataclass
class FileEvent:
    relative_path: str
    event: str
    src_mtime_utc: str
    dst_mtime_utc: str | None


@dataclass
class PatternHit:
    relative_path: str
    line: int
    pattern: str
    snippet: str


@dataclass
class TestResult:
    command: str
    returncode: int
    elapsed_sec: float
    stdout_tail: str
    stderr_tail: str


def iso_utc(ts: float | None) -> str | None:
    if ts is None:
        return None
    return datetime.fromtimestamp(ts, timezone.utc).isoformat()


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def path_matches_any(rel_posix: str, globs: tuple[str, ...]) -> bool:
    for pattern in globs:
        if fnmatch.fnmatch(rel_posix, pattern):
            return True
    return False


def is_ignored_file_name(name: str) -> bool:
    return any(fnmatch.fnmatch(name, pat) for pat in IGNORE_FILE_PATTERNS)


def iter_source_files(root: Path) -> Iterable[Path]:
    for dirpath, dirnames, filenames in os.walk(root):
        filtered_dirs: list[str] = []
        for d in dirnames:
            if d in IGNORE_DIRS:
                continue
            p = Path(dirpath) / d
            if p.is_symlink():
                continue
            filtered_dirs.append(d)
        dirnames[:] = filtered_dirs
        for filename in filenames:
            if is_ignored_file_name(filename):
                continue
            p = Path(dirpath) / filename
            if p.is_symlink():
                continue
            yield p


def compare_and_copy(
    src_file: Path,
    dst_file: Path,
    apply_changes: bool,
    strict_hash: bool,
) -> FileEvent | None:
    try:
        src_stat = src_file.stat()
    except OSError:
        return None
    src_mtime_iso = iso_utc(src_stat.st_mtime) or ""

    if not dst_file.exists():
        if apply_changes:
            dst_file.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(src_file, dst_file)
        return FileEvent(
            relative_path="",
            event="new",
            src_mtime_utc=src_mtime_iso,
            dst_mtime_utc=None,
        )

    dst_stat = dst_file.stat()
    dst_mtime_iso = iso_utc(dst_stat.st_mtime)

    if src_stat.st_mtime > (dst_stat.st_mtime + 1e-6) or src_stat.st_size != dst_stat.st_size:
        if apply_changes:
            dst_file.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(src_file, dst_file)
        return FileEvent(
            relative_path="",
            event="updated",
            src_mtime_utc=src_mtime_iso,
            dst_mtime_utc=dst_mtime_iso,
        )

    if strict_hash and sha256_file(src_file) != sha256_file(dst_file):
        if apply_changes:
            dst_file.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(src_file, dst_file)
        return FileEvent(
            relative_path="",
            event="updated_content_drift",
            src_mtime_utc=src_mtime_iso,
            dst_mtime_utc=dst_mtime_iso,
        )

    return None


def scan_forbidden_refs(path: Path, rel_posix: str, patterns: list[re.Pattern[str]]) -> list[PatternHit]:
    if path.suffix.lower() not in TEXT_EXTENSIONS:
        return []

    try:
        text = path.read_text(encoding="utf-8", errors="replace")
    except OSError:
        return []

    result: list[PatternHit] = []
    lines = text.splitlines()
    for i, line in enumerate(lines, start=1):
        for p in patterns:
            if p.search(line):
                result.append(
                    PatternHit(
                        relative_path=rel_posix,
                        line=i,
                        pattern=p.pattern,
                        snippet=line.strip()[:240],
                    )
                )
    return result


def maybe_delete_stale_files(src_root: Path, dst_root: Path, dry_run: bool) -> list[str]:
    deleted: list[str] = []
    for dst_file in iter_source_files(dst_root):
        rel = dst_file.relative_to(dst_root)
        rel_posix = str(rel).replace("\\", "/")
        if path_matches_any(rel_posix, EXCLUDE_GLOBS):
            continue
        src_file = src_root / rel
        if src_file.exists():
            continue
        deleted.append(rel_posix)
        if not dry_run:
            dst_file.unlink(missing_ok=True)
    return deleted


def git_status_short(repo_root: Path) -> str:
    try:
        proc = subprocess.run(
            ["git", "-C", str(repo_root), "status", "--short"],
            check=False,
            capture_output=True,
            text=True,
        )
    except OSError as exc:
        return f"git not available: {exc}"

    if proc.returncode != 0:
        return (proc.stderr or proc.stdout or "git status failed").strip()
    return proc.stdout.strip()


def git_pending_files(repo_root: Path) -> list[Path]:
    try:
        proc = subprocess.run(
            ["git", "-C", str(repo_root), "status", "--porcelain"],
            check=False,
            capture_output=True,
            text=True,
        )
    except OSError:
        return []

    if proc.returncode != 0:
        return []

    result: list[Path] = []
    for raw_line in proc.stdout.splitlines():
        if len(raw_line) < 4:
            continue
        path_part = raw_line[3:]
        if " -> " in path_part:
            path_part = path_part.split(" -> ", 1)[1]
        rel = Path(path_part.strip())
        full = repo_root / rel
        if full.exists() and full.is_file():
            result.append(full)
    return result


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Prepare trading-platform commit from deployed runtime tree")
    parser.add_argument("--source", default=r"p:\opt\docker\trading-platform-php", help="Deployed runtime tree")
    parser.add_argument("--target", default=r"p:\GitHub\trading-platform-php", help="Git clone destination")
    parser.add_argument("--apply", action="store_true", help="Copy changes into target (default is dry-run)")
    parser.add_argument("--strict-hash", action="store_true", help="Also detect content drift with hash checks")
    parser.add_argument("--mirror-delete", action="store_true", help="Delete files in target absent in source")
    parser.add_argument(
        "--forbidden",
        nargs="*",
        default=list(DEFAULT_FORBIDDEN_PATTERNS),
        help="Regex patterns to scan changed text files",
    )
    parser.add_argument(
        "--report",
        default=r"p:\opt\docker\trading-platform-php\scripts\commit_prepare_report.json",
        help="Path to JSON report",
    )
    parser.add_argument(
        "--run-tests",
        action="store_true",
        default=True,
        help="Run test commands as a commit gate (default: enabled)",
    )
    parser.add_argument(
        "--skip-tests",
        action="store_true",
        help="Skip test command execution",
    )
    parser.add_argument(
        "--test-cmd",
        action="append",
        default=[],
        help="Custom test command (can be provided multiple times)",
    )
    parser.add_argument(
        "--test-timeout",
        type=int,
        default=900,
        help="Per-test timeout in seconds",
    )
    return parser.parse_args()


def run_test_commands(commands: list[str], cwd: Path, timeout_sec: int) -> tuple[list[TestResult], list[TestResult]]:
    passed: list[TestResult] = []
    failed: list[TestResult] = []

    for cmd in commands:
        t0 = datetime.now(timezone.utc).timestamp()
        try:
            proc = subprocess.run(
                cmd,
                cwd=str(cwd),
                shell=True,
                check=False,
                capture_output=True,
                text=True,
                timeout=timeout_sec,
            )
            elapsed = datetime.now(timezone.utc).timestamp() - t0
            result = TestResult(
                command=cmd,
                returncode=proc.returncode,
                elapsed_sec=round(elapsed, 3),
                stdout_tail="\n".join((proc.stdout or "").splitlines()[-40:]),
                stderr_tail="\n".join((proc.stderr or "").splitlines()[-40:]),
            )
        except subprocess.TimeoutExpired as exc:
            elapsed = datetime.now(timezone.utc).timestamp() - t0
            result = TestResult(
                command=cmd,
                returncode=124,
                elapsed_sec=round(elapsed, 3),
                stdout_tail="\n".join(((exc.stdout or "") if isinstance(exc.stdout, str) else "").splitlines()[-40:]),
                stderr_tail=("timeout expired" if not exc.stderr else str(exc.stderr)[-2000:]),
            )

        if result.returncode == 0:
            passed.append(result)
        else:
            failed.append(result)

    return passed, failed


def main() -> int:
    args = parse_args()
    source = Path(args.source).resolve()
    target = Path(args.target).resolve()
    report_path = Path(args.report).resolve()

    if not source.exists():
        print(f"ERROR: source path not found: {source}", file=sys.stderr)
        return 1
    if not target.exists():
        print(f"ERROR: target path not found: {target}", file=sys.stderr)
        return 1
    if not (target / ".git").exists():
        print(f"ERROR: target is not a git repository: {target}", file=sys.stderr)
        return 1

    apply_changes = bool(args.apply)
    compiled = [re.compile(pat) for pat in args.forbidden]

    events: list[FileEvent] = []
    hits: list[PatternHit] = []
    suspicious_new_paths: list[str] = []
    suspicious_regex = [re.compile(p) for p in SUSPICIOUS_NEW_PATH_PATTERNS]

    for src_file in iter_source_files(source):
        rel = src_file.relative_to(source)
        rel_posix = str(rel).replace("\\", "/")

        if path_matches_any(rel_posix, EXCLUDE_GLOBS):
            continue

        dst_file = target / rel
        event = compare_and_copy(src_file, dst_file, apply_changes=apply_changes, strict_hash=bool(args.strict_hash))
        if not event:
            continue

        event.relative_path = rel_posix
        events.append(event)
        hits.extend(scan_forbidden_refs(src_file, rel_posix, compiled))
        if event.event == "new":
            if any(rx.search(rel_posix) for rx in suspicious_regex):
                suspicious_new_paths.append(rel_posix)

    deleted: list[str] = []
    if args.mirror_delete:
        deleted = maybe_delete_stale_files(source, target, dry_run=not apply_changes)

    pending_hits: list[PatternHit] = []
    pending_files = git_pending_files(target)
    for f in pending_files:
        rel_posix = str(f.relative_to(target)).replace("\\", "/")
        pending_hits.extend(scan_forbidden_refs(f, rel_posix, compiled))

    tests_enabled = bool(args.run_tests) and not bool(args.skip_tests)
    test_commands = list(args.test_cmd) if args.test_cmd else list(DEFAULT_TEST_COMMANDS)
    test_passed: list[TestResult] = []
    test_failed: list[TestResult] = []
    if tests_enabled:
        test_passed, test_failed = run_test_commands(test_commands, source, max(30, int(args.test_timeout)))

    summary = {
        "timestamp_utc": datetime.now(timezone.utc).isoformat(),
        "mode": "apply" if apply_changes else "dry-run",
        "source": str(source),
        "target": str(target),
        "strict_hash": bool(args.strict_hash),
        "mirror_delete": bool(args.mirror_delete),
        "changed_total": len(events),
        "new_files": sum(1 for e in events if e.event == "new"),
        "updated_files": sum(1 for e in events if e.event == "updated"),
        "updated_content_drift": sum(1 for e in events if e.event == "updated_content_drift"),
        "forbidden_hits": len(hits),
        "deleted_candidates": len(deleted),
        "suspicious_new_paths": len(suspicious_new_paths),
        "pending_files_scanned": len(pending_files),
        "pending_forbidden_hits": len(pending_hits),
        "tests_enabled": tests_enabled,
        "tests_total": len(test_commands) if tests_enabled else 0,
        "tests_passed": len(test_passed),
        "tests_failed": len(test_failed),
    }

    report = {
        "summary": summary,
        "changes": [e.__dict__ for e in events],
        "forbidden_hits": [h.__dict__ for h in hits],
        "deleted_candidates": deleted,
        "suspicious_new_paths": suspicious_new_paths,
        "pending_forbidden_hits": [h.__dict__ for h in pending_hits],
        "test_commands": test_commands if tests_enabled else [],
        "test_passed": [t.__dict__ for t in test_passed],
        "test_failed": [t.__dict__ for t in test_failed],
        "git_status_short": git_status_short(target),
    }

    report_path.parent.mkdir(parents=True, exist_ok=True)
    report_path.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")

    print(json.dumps(summary, ensure_ascii=False, indent=2))
    print(f"Report: {report_path}")
    if hits:
        print("Forbidden deployment refs found in changed files:")
        for hit in hits[:25]:
            print(f"  - {hit.relative_path}:{hit.line} :: {hit.snippet}")
        if len(hits) > 25:
            print(f"  ... and {len(hits) - 25} more")

    if suspicious_new_paths:
        print("Suspicious new paths detected:")
        for p in suspicious_new_paths[:25]:
            print(f"  - {p}")
        if len(suspicious_new_paths) > 25:
            print(f"  ... and {len(suspicious_new_paths) - 25} more")

    if pending_hits:
        print("Forbidden deployment refs in current git pending set:")
        for hit in pending_hits[:25]:
            print(f"  - {hit.relative_path}:{hit.line} :: {hit.snippet}")
        if len(pending_hits) > 25:
            print(f"  ... and {len(pending_hits) - 25} more")

    if tests_enabled:
        print("Test gate results:")
        for t in test_passed:
            print(f"  + PASS ({t.elapsed_sec:.2f}s): {t.command}")
        for t in test_failed:
            print(f"  - FAIL ({t.elapsed_sec:.2f}s, rc={t.returncode}): {t.command}")
            if t.stdout_tail:
                print("    stdout tail:")
                for ln in t.stdout_tail.splitlines()[-10:]:
                    print(f"      {ln}")
            if t.stderr_tail:
                print("    stderr tail:")
                for ln in t.stderr_tail.splitlines()[-10:]:
                    print(f"      {ln}")

    print("Target git status:")
    print(report["git_status_short"] or "(clean)")

    if test_failed:
        print("commit_prepare guard: tests failed, refusing commit preparation until fixed.")
        return 2

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
