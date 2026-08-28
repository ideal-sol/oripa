#!/usr/bin/env python3
"""Canonicalize Shared Preview API and fincode webhook Nginx routes."""

from __future__ import annotations

import argparse
import ipaddress
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import tempfile
from urllib.parse import urlsplit


PREVIEW_SERVER_NAME = "test.luxe-pack.biz"
WEBHOOK_PATH = "/webhooks/v2/fincode"
STABLE_API_UPSTREAM = "http://127.0.0.1:8611"
NGINX_TEST_COMMAND = ("/usr/sbin/nginx", "-t")
NGINX_RELOAD_COMMAND = ("/usr/bin/systemctl", "reload", "nginx")
RFC1918_NETWORKS = tuple(
    ipaddress.ip_network(network)
    for network in ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16")
)
SERVER_PATTERN = re.compile(r"^\s*server\s*\{\s*$")
API_EXACT_LOCATION_PATTERN = re.compile(
    r"^\s*location\s+=\s+/api/v2\s*\{\s*$"
)
API_PREFIX_LOCATION_PATTERN = re.compile(
    r"^\s*location\s+\^~\s+/api/v2/\s*\{\s*$"
)
WEBHOOK_LOCATION_PATTERN = re.compile(
    r"^\s*location\s+=\s+/webhooks/v2/fincode\s*\{\s*$"
)
ADMIN_LOCATION_PATTERN = re.compile(r"^\s*location\s+\^~\s+/admin/api/\s*\{\s*$")
LOCATION_PATTERN = re.compile(r"^\s*location\b")
PROXY_PATTERN = re.compile(r"^\s*proxy_pass\s+(http://[^;\s]+);\s*$")
PROXY_CONTRACT_PATTERNS = (
    re.compile(r"^\s*proxy_http_version\s+1\.1;\s*$"),
    re.compile(r"^\s*proxy_set_header\s+Host\s+\$host;\s*$"),
    re.compile(r"^\s*proxy_set_header\s+X-Real-IP\s+\$remote_addr;\s*$"),
    re.compile(
        r"^\s*proxy_set_header\s+X-Forwarded-For\s+"
        r"\$proxy_add_x_forwarded_for;\s*$"
    ),
    re.compile(r"^\s*proxy_set_header\s+X-Forwarded-Proto\s+\$scheme;\s*$"),
)


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


def proxy_upstream(lines: list[str], start: int, end: int) -> tuple[int, str]:
    matches = []
    for index in range(start, end + 1):
        line = lines[index]
        match = PROXY_PATTERN.fullmatch(line.rstrip("\n"))
        if match:
            matches.append((index, match.group(1)))
    if len(matches) != 1:
        fail("preview_api_upstream_not_unique")
    return matches[0]


def validate_proxy_contract(lines: list[str], start: int, end: int) -> None:
    block = [line.rstrip("\n") for line in lines[start : end + 1]]
    if any(
        sum(bool(pattern.fullmatch(line)) for line in block) != 1
        for pattern in PROXY_CONTRACT_PATTERNS
    ):
        fail("preview_proxy_header_contract_invalid")
    _, upstream = proxy_upstream(lines, start, end)
    parsed = urlsplit(upstream)
    if (
        parsed.path
        or parsed.query
        or parsed.fragment
        or parsed.username
        or parsed.password
    ):
        fail("preview_proxy_uri_semantics_invalid")


def replace_proxy_upstream(
    lines: list[str], start: int, end: int, upstream: str
) -> None:
    index, _ = proxy_upstream(lines, start, end)
    indentation = re.match(r"^\s*", lines[index]).group(0)
    newline = "\n" if lines[index].endswith("\n") else ""
    lines[index] = f"{indentation}proxy_pass {upstream};{newline}"


def is_container_specific_upstream(upstream: str) -> bool:
    hostname = urlsplit(upstream).hostname
    if hostname is None:
        return False
    try:
        address = ipaddress.ip_address(hostname)
    except ValueError:
        return False
    return any(address in network for network in RFC1918_NETWORKS)


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
    api_exact = location_range(
        lines, server_start, server_end, API_EXACT_LOCATION_PATTERN
    )
    api_prefix = location_range(
        lines, server_start, server_end, API_PREFIX_LOCATION_PATTERN
    )
    admin = location_range(lines, server_start, server_end, ADMIN_LOCATION_PATTERN)
    webhook = location_range(lines, server_start, server_end, WEBHOOK_LOCATION_PATTERN)
    if api_exact is None or api_prefix is None or admin is None:
        fail("preview_route_boundary_missing")
    for index in range(server_start + 1, server_end):
        line = lines[index].rstrip("\n")
        if LOCATION_PATTERN.match(line) and "/webhooks" in line:
            if not WEBHOOK_LOCATION_PATTERN.fullmatch(line):
                fail("broad_webhook_location_rejected")

    for route in (api_exact, api_prefix):
        validate_proxy_contract(lines, *route)
        replace_proxy_upstream(lines, *route, STABLE_API_UPSTREAM)

    indentation = re.match(r"^\s*", lines[api_prefix[0]]).group(0)
    if webhook is not None:
        validate_proxy_contract(lines, *webhook)
        _, webhook_upstream = proxy_upstream(lines, *webhook)
        expected = canonical_location(indentation, webhook_upstream)
        actual = "".join(lines[webhook[0] : webhook[1] + 1])
        if actual != expected:
            fail("fincode_webhook_location_mismatch")
        replace_proxy_upstream(lines, *webhook, STABLE_API_UPSTREAM)
        return "".join(lines)

    insertion = admin[0]
    prefix = "" if insertion == 0 or lines[insertion - 1].strip() == "" else "\n"
    expected = canonical_location(indentation, STABLE_API_UPSTREAM)
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
    verify_content(rendered.decode("utf-8"))
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
    directory = os.open(backup.parent, os.O_RDONLY)
    try:
        os.fsync(directory)
    finally:
        os.close(directory)
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
    render_config(original.decode("utf-8"))
    atomic_replace(path, original, metadata)
    return {"changed": True, "status": "restored"}


def verify_content(content: str) -> None:
    rendered = render_config(content)
    if rendered == content:
        return
    lines = content.splitlines(keepends=True)
    server_start, server_end = preview_server(lines)
    upstreams = []
    for pattern in (
        API_EXACT_LOCATION_PATTERN,
        API_PREFIX_LOCATION_PATTERN,
        WEBHOOK_LOCATION_PATTERN,
    ):
        route = location_range(lines, server_start, server_end, pattern)
        if route is not None:
            upstreams.append(proxy_upstream(lines, *route)[1])
    if any(is_container_specific_upstream(upstream) for upstream in upstreams):
        fail("container_specific_upstream_rejected")
    fail("preview_routes_not_canonical")


def verify_config(path: Path) -> dict[str, object]:
    secure_file(path)
    verify_content(path.read_text(encoding="utf-8"))
    return {"changed": False, "status": "canonical"}


def run_operational_command(runner, command: tuple[str, ...]) -> bool:
    try:
        result = runner(
            command,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            check=False,
        )
    except OSError:
        return False
    return result.returncode == 0


def activate_config(
    path: Path, backup: Path, runner=subprocess.run
) -> dict[str, object]:
    result = apply_config(path, backup)
    if not result["changed"]:
        return {
            **result,
            "config_test": "not_run",
            "reload": "not_run",
        }
    if not run_operational_command(runner, NGINX_TEST_COMMAND):
        restore_config(path, backup)
        fail("nginx_config_test_failed_rolled_back")
    if not run_operational_command(runner, NGINX_RELOAD_COMMAND):
        restore_config(path, backup)
        fail("nginx_reload_failed_rolled_back")
    return {
        **result,
        "config_test": "passed",
        "reload": "completed",
    }


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "operation", choices=("activate", "apply", "restore", "verify")
    )
    parser.add_argument("--input", required=True, type=Path)
    parser.add_argument("--backup", type=Path)
    arguments = parser.parse_args()
    if (
        arguments.operation in {"activate", "apply", "restore"}
        and arguments.backup is None
    ):
        fail("backup_required")
    if arguments.operation == "activate":
        result = activate_config(arguments.input, arguments.backup)
    elif arguments.operation == "apply":
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
