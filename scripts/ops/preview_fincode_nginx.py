#!/usr/bin/env python3
"""Canonicalize the exact Shared Preview fincode webhook Nginx route."""

from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import stat
import tempfile


PREVIEW_SERVER_NAME = "test.luxe-pack.biz"
WEBHOOK_PATH = "/webhooks/v2/fincode"
SERVER_PATTERN = re.compile(r"^\s*server\s*\{\s*$")
API_LOCATION_PATTERN = re.compile(r"^\s*location\s+\^~\s+/api/v2/\s*\{\s*$")
WEBHOOK_LOCATION_PATTERN = re.compile(
    r"^\s*location\s+=\s+/webhooks/v2/fincode\s*\{\s*$"
)
ADMIN_LOCATION_PATTERN = re.compile(r"^\s*location\s+\^~\s+/admin/api/\s*\{\s*$")
LOCATION_PATTERN = re.compile(r"^\s*location\b")
PROXY_PATTERN = re.compile(r"^\s*proxy_pass\s+(http://[^;\s]+);\s*$")


class PreviewNginxError(RuntimeError):
    pass


def fail(message: str) -> None:
    raise PreviewNginxError(message)


def brace_delta(line: str) -> int:
    delta = 0
    quote = None
    escaped = False
    for character in line:
        if escaped:
            escaped = False
            continue
        if character == "\\":
            escaped = True
            continue
        if quote is not None:
            if character == quote:
                quote = None
            continue
        if character in {"'", '"'}:
            quote = character
        elif character == "{":
            delta += 1
        elif character == "}":
            delta -= 1
    return delta


def block_end(lines: list[str], start: int) -> int:
    depth = 0
    for index in range(start, len(lines)):
        depth += brace_delta(lines[index])
        if depth == 0:
            return index
        if depth < 0:
            fail("nginx_braces_invalid")
    fail("nginx_block_unterminated")


def preview_server(lines: list[str]) -> tuple[int, int]:
    candidates = []
    for index, line in enumerate(lines):
        if not SERVER_PATTERN.fullmatch(line.rstrip("\n")):
            continue
        end = block_end(lines, index)
        block = "".join(lines[index : end + 1])
        if (
            f"server_name {PREVIEW_SERVER_NAME};" in block
            and re.search(r"^\s*listen(?:\s+\[::\])?:443\s+ssl", block, re.MULTILINE)
        ):
            candidates.append((index, end))
    if len(candidates) != 1:
        fail("preview_tls_server_not_unique")
    return candidates[0]


def location_range(
    lines: list[str], server_start: int, server_end: int, pattern: re.Pattern[str]
) -> tuple[int, int] | None:
    matches = []
    for index in range(server_start + 1, server_end):
        if pattern.fullmatch(lines[index].rstrip("\n")):
            matches.append((index, block_end(lines, index)))
    if len(matches) > 1:
        fail("nginx_location_not_unique")
    return matches[0] if matches else None


def api_upstream(lines: list[str], start: int, end: int) -> str:
    matches = []
    for line in lines[start : end + 1]:
        match = PROXY_PATTERN.fullmatch(line.rstrip("\n"))
        if match:
            matches.append(match.group(1))
    if len(matches) != 1:
        fail("preview_api_upstream_not_unique")
    return matches[0]


def canonical_location(indentation: str, upstream: str) -> str:
    child = indentation + "    "
    grandchild = child + "    "
    return "".join(
        [
            f"{indentation}location = {WEBHOOK_PATH} {{\n",
            f"{child}limit_except POST {{\n",
            f"{grandchild}deny all;\n",
            f"{child}}}\n",
            f"{child}proxy_pass {upstream};\n",
            f"{child}proxy_http_version 1.1;\n",
            f"{child}proxy_set_header Host $host;\n",
            f"{child}proxy_set_header X-Real-IP $remote_addr;\n",
            f"{child}proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;\n",
            f"{child}proxy_set_header X-Forwarded-Proto $scheme;\n",
            f"{indentation}}}\n",
        ]
    )


def render_config(content: str) -> str:
    lines = content.splitlines(keepends=True)
    if not lines or any(not line.endswith("\n") for line in lines[:-1]):
        fail("nginx_content_invalid")
    server_start, server_end = preview_server(lines)
    api = location_range(lines, server_start, server_end, API_LOCATION_PATTERN)
    admin = location_range(lines, server_start, server_end, ADMIN_LOCATION_PATTERN)
    webhook = location_range(lines, server_start, server_end, WEBHOOK_LOCATION_PATTERN)
    if api is None or admin is None:
        fail("preview_route_boundary_missing")
    for index in range(server_start + 1, server_end):
        line = lines[index].rstrip("\n")
        if LOCATION_PATTERN.match(line) and "/webhooks" in line:
            if not WEBHOOK_LOCATION_PATTERN.fullmatch(line):
                fail("broad_webhook_location_rejected")

    upstream = api_upstream(lines, *api)
    indentation = re.match(r"^\s*", lines[api[0]]).group(0)
    expected = canonical_location(indentation, upstream)
    if webhook is not None:
        actual = "".join(lines[webhook[0] : webhook[1] + 1])
        if actual != expected:
            fail("fincode_webhook_location_mismatch")
        return content

    insertion = admin[0]
    prefix = "" if insertion == 0 or lines[insertion - 1].strip() == "" else "\n"
    lines[insertion:insertion] = [prefix, expected, "\n"]
    return "".join(lines)


def secure_file(path: Path, expected_mode: int | None = None) -> os.stat_result:
    metadata = os.lstat(path)
    if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISREG(metadata.st_mode):
        fail("nginx_file_invalid")
    if expected_mode is not None and stat.S_IMODE(metadata.st_mode) != expected_mode:
        fail("nginx_file_mode_invalid")
    if metadata.st_mode & 0o022:
        fail("nginx_file_writable_by_non_owner")
    return metadata


def atomic_replace(path: Path, payload: bytes, metadata: os.stat_result) -> None:
    descriptor, temporary_name = tempfile.mkstemp(prefix=f".{path.name}.", dir=path.parent)
    temporary = Path(temporary_name)
    try:
        os.fchmod(descriptor, stat.S_IMODE(metadata.st_mode))
        os.fchown(descriptor, metadata.st_uid, metadata.st_gid)
        write_all(descriptor, payload)
        os.fsync(descriptor)
        os.close(descriptor)
        descriptor = -1
        os.replace(temporary, path)
        directory = os.open(path.parent, os.O_RDONLY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if descriptor >= 0:
            os.close(descriptor)
        temporary.unlink(missing_ok=True)


def apply_config(path: Path, backup: Path) -> dict[str, object]:
    metadata = secure_file(path)
    original = path.read_bytes()
    rendered = render_config(original.decode("utf-8")).encode("utf-8")
    if rendered == original:
        return {"changed": False, "status": "already_canonical"}
    if backup.exists():
        fail("backup_already_exists")
    descriptor = os.open(backup, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        write_all(descriptor, original)
        os.fsync(descriptor)
    finally:
        os.close(descriptor)
    atomic_replace(path, rendered, metadata)
    return {"changed": True, "status": "updated"}


def write_all(descriptor: int, payload: bytes) -> None:
    offset = 0
    while offset < len(payload):
        written = os.write(descriptor, payload[offset:])
        if written <= 0:
            fail("file_write_failed")
        offset += written


def restore_config(path: Path, backup: Path) -> dict[str, object]:
    metadata = secure_file(path)
    secure_file(backup, 0o600)
    original = backup.read_bytes()
    render_config(path.read_text(encoding="utf-8"))
    atomic_replace(path, original, metadata)
    return {"changed": True, "status": "restored"}


def verify_config(path: Path) -> dict[str, object]:
    secure_file(path)
    content = path.read_text(encoding="utf-8")
    if render_config(content) != content:
        fail("fincode_webhook_location_missing")
    return {"changed": False, "status": "canonical"}


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("operation", choices=("apply", "restore", "verify"))
    parser.add_argument("--input", required=True, type=Path)
    parser.add_argument("--backup", type=Path)
    arguments = parser.parse_args()
    if arguments.operation in {"apply", "restore"} and arguments.backup is None:
        fail("backup_required")
    if arguments.operation == "apply":
        result = apply_config(arguments.input, arguments.backup)
    elif arguments.operation == "restore":
        result = restore_config(arguments.input, arguments.backup)
    else:
        result = verify_config(arguments.input)
    print(json.dumps(result, sort_keys=True))


if __name__ == "__main__":
    try:
        main()
    except (OSError, UnicodeError, PreviewNginxError) as error:
        classification = str(error) if isinstance(error, PreviewNginxError) else "io_failure"
        print(f"preview_fincode_nginx_error:{classification}", file=__import__("sys").stderr)
        raise SystemExit(1)
