#!/usr/bin/env python3
import json
import sys
from pathlib import Path
from typing import Dict, List, Tuple


def load_json(path: Path) -> dict:
    with path.open("r", encoding="utf-8") as f:
        return json.load(f)


def unwrap_index(payload: dict) -> dict:
    # Sandwich-pack status endpoint wraps data under "index".
    if isinstance(payload, dict) and "index" in payload and isinstance(payload["index"], dict):
        return payload["index"]
    return payload


def split_entity(raw: str) -> Tuple[str, str, str, str, str, str, str]:
    parts = raw.split(",", 6)
    if len(parts) != 7:
        raise ValueError(f"Invalid entity format: {raw}")
    return tuple(parts)  # type: ignore[return-value]


def file_map(index: dict) -> Dict[str, str]:
    mapping: Dict[str, str] = {}
    files = index.get("files")
    if not isinstance(files, list):
        files = index.get("filelist", [])
    for raw in files:
        parts = raw.split(",", 4)
        if len(parts) != 5:
            continue
        file_id, file_name, _md5, _tokens, _timestamp = parts
        mapping[file_id] = file_name
    return mapping


def normalize_path(path: str) -> str:
    cleaned = path.strip().replace("\\", "/")
    if cleaned.startswith("/"):
        cleaned = cleaned[1:]
    if cleaned.startswith("sigsys-ts/"):
        return cleaned
    # Some payloads may prefix with '/sigsys-ts/'.
    if cleaned.startswith("/sigsys-ts/"):
        return cleaned[1:]
    return cleaned


def normalize_entities(index: dict) -> List[Tuple[str, str, str, str, str, str, str]]:
    id_to_path = file_map(index)
    normalized: List[Tuple[str, str, str, str, str, str, str]] = []
    for raw in index.get("entities", []):
        vis, kind, parent, name, file_id, line_range, tokens = split_entity(raw)
        path = normalize_path(id_to_path.get(file_id, f"<unknown:{file_id}>"))
        normalized.append((vis, kind, parent, name, path, line_range, tokens))
    return normalized


def summarize(label: str, idx: dict) -> None:
    files = idx.get("files")
    if not isinstance(files, list):
        files = idx.get("filelist", [])
    print(f"[{label}] context_date: {idx.get('context_date')}")
    print(f"[{label}] files: {len(files)}")
    print(f"[{label}] entities: {len(idx.get('entities', []))}")


def main() -> int:
    if len(sys.argv) != 3:
        print("Usage: compare_indexes.py <sandwich_pack_json> <cq_index_json>")
        return 2

    left_raw = load_json(Path(sys.argv[1]))
    right_raw = load_json(Path(sys.argv[2]))

    left = unwrap_index(left_raw)
    right = unwrap_index(right_raw)

    summarize("sandwich-pack", left)
    summarize("cq-index", right)

    left_entities = set(normalize_entities(left))
    right_entities = set(normalize_entities(right))

    left_signatures = {(vis, kind, parent, name, path) for vis, kind, parent, name, path, _lr, _tok in left_entities}
    right_signatures = {(vis, kind, parent, name, path) for vis, kind, parent, name, path, _lr, _tok in right_entities}

    only_left = sorted(left_entities - right_entities)
    only_right = sorted(right_entities - left_entities)
    only_left_sig = sorted(left_signatures - right_signatures)
    only_right_sig = sorted(right_signatures - left_signatures)

    print(f"\nSignature overlap (ignoring line/tokens): {len(left_signatures & right_signatures)}")
    print(f"Signatures only in sandwich-pack: {len(only_left_sig)}")
    print(f"Signatures only in cq-index: {len(only_right_sig)}")

    print(f"\nStrict entities only in sandwich-pack: {len(only_left)}")
    for item in only_left[:25]:
        print("  +", item)

    print(f"\nStrict entities only in cq-index: {len(only_right)}")
    for item in only_right[:25]:
        print("  -", item)

    if not only_left_sig and not only_right_sig:
        print("\nVERDICT: indexes are symbol-equivalent (line/token deltas may exist).")
        return 0

    print("\nVERDICT: indexes differ.")
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
