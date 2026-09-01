#!/usr/bin/env python3
"""Provision the repository-managed GitHub App Git wrapper atomically."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import sys
import tempfile


MANIFEST_NAME = "oripa-github-app-git.manifest.json"
MAX_WRAPPER_BYTES = 128 * 1024
SHA256 = re.compile(r"[0-9a-f]{64}")
EXPECTED_SOURCE = "infrastructure/github-app/oripa-github-app-git"
EXPECTED_DESTINATION = "/usr/local/bin/oripa-github-app-git"
EXPECTED_BACKUP_DIRECTORY = "/var/lib/oripa-github-app-wrapper-backups"
EXPECTED_REPOSITORY_ROOT = Path("/var/www/oripa")
GIT = "/usr/bin/git"
REPOSITORY_URL = "https://github.com/ideal-sol/oripa.git"


class ProvisionFailure(ValueError):
    pass


def fail(message: str) -> None:
    raise ProvisionFailure(message)


def sha256(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def repository_root() -> Path:
    return Path(__file__).resolve().parents[2]


def run_git(arguments: list[str], classification: str) -> str:
    result = subprocess.run(
        [GIT] + arguments,
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        check=False,
        text=True,
    )
    if result.returncode != 0:
        fail(classification)
    return result.stdout


def require_merged_main(root: Path) -> str:
    if root.resolve() != EXPECTED_REPOSITORY_ROOT:
        fail("repository_root_invalid")
    branch = run_git(
        ["-C", str(root), "branch", "--show-current"],
        "repository_branch_check_failed",
    ).strip()
    if branch != "main":
        fail("repository_branch_invalid")
    head_sha = run_git(
        ["-C", str(root), "rev-parse", "HEAD"],
        "repository_head_check_failed",
    ).strip()
    origin_sha = run_git(
        ["-C", str(root), "rev-parse", "origin/main"],
        "repository_origin_check_failed",
    ).strip()
    live_output = run_git(
        ["ls-remote", REPOSITORY_URL, "refs/heads/main"],
        "repository_live_main_check_failed",
    ).strip().split()
    if (
        len(live_output) != 2
        or live_output[1] != "refs/heads/main"
        or not re.fullmatch(r"[0-9a-f]{40}", live_output[0])
    ):
        fail("repository_live_main_invalid")
    if head_sha != origin_sha or head_sha != live_output[0]:
        fail("repository_main_mismatch")
    if run_git(
        ["-C", str(root), "status", "--porcelain"],
        "repository_status_check_failed",
    ):
        fail("repository_not_clean")
    return head_sha


def read_regular_file(path: Path, classification: str) -> tuple[bytes, os.stat_result]:
    try:
        metadata = os.lstat(path)
    except FileNotFoundError:
        fail(f"{classification}_missing")
    except OSError:
        fail(f"{classification}_unavailable")
    if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISREG(metadata.st_mode):
        fail(f"{classification}_not_regular")
    if metadata.st_size <= 0 or metadata.st_size > MAX_WRAPPER_BYTES:
        fail(f"{classification}_size_invalid")
    flags = os.O_RDONLY
    if hasattr(os, "O_NOFOLLOW"):
        flags |= os.O_NOFOLLOW
    try:
        descriptor = os.open(path, flags)
    except OSError:
        fail(f"{classification}_unavailable")
    try:
        opened = os.fstat(descriptor)
        if opened.st_dev != metadata.st_dev or opened.st_ino != metadata.st_ino:
            fail(f"{classification}_changed")
        chunks = []
        remaining = MAX_WRAPPER_BYTES + 1
        while remaining:
            chunk = os.read(descriptor, min(65536, remaining))
            if not chunk:
                break
            chunks.append(chunk)
            remaining -= len(chunk)
    finally:
        os.close(descriptor)
    payload = b"".join(chunks)
    if len(payload) != metadata.st_size or len(payload) > MAX_WRAPPER_BYTES:
        fail(f"{classification}_changed")
    return payload, metadata


def validate_manifest(manifest: object) -> dict:
    if not isinstance(manifest, dict) or set(manifest) != {
        "component",
        "repository_source",
        "role",
        "rollback",
        "runtime",
        "schema_version",
    }:
        fail("manifest_schema_invalid")
    source = manifest.get("repository_source")
    runtime = manifest.get("runtime")
    rollback = manifest.get("rollback")
    if manifest.get("schema_version") != "1.0":
        fail("manifest_version_invalid")
    if manifest.get("component") != "oripa-github-app-git":
        fail("manifest_component_invalid")
    if manifest.get("role") != "GitHub App Git delivery wrapper":
        fail("manifest_role_invalid")
    if not isinstance(source, dict) or set(source) != {
        "imported_sha256",
        "path",
        "sha256",
    }:
        fail("manifest_source_invalid")
    if source.get("path") != EXPECTED_SOURCE:
        fail("manifest_source_path_invalid")
    parsed_source = PurePosixPath(str(source.get("path", "")))
    if parsed_source.is_absolute() or ".." in parsed_source.parts:
        fail("manifest_source_path_invalid")
    if not SHA256.fullmatch(str(source.get("sha256", ""))):
        fail("manifest_source_sha_invalid")
    if not SHA256.fullmatch(str(source.get("imported_sha256", ""))):
        fail("manifest_import_sha_invalid")
    if not isinstance(runtime, dict) or set(runtime) != {
        "destination",
        "gid",
        "group",
        "mode",
        "owner",
        "uid",
    }:
        fail("manifest_runtime_invalid")
    if runtime != {
        "destination": EXPECTED_DESTINATION,
        "gid": 0,
        "group": "root",
        "mode": "0700",
        "owner": "root",
        "uid": 0,
    }:
        fail("manifest_runtime_invalid")
    if rollback != {"directory": EXPECTED_BACKUP_DIRECTORY}:
        fail("manifest_rollback_invalid")
    return manifest


def load_manifest(root: Path | None = None) -> tuple[dict, Path, bytes]:
    selected_root = (root or repository_root()).resolve()
    manifest_path = selected_root / "infrastructure/github-app" / MANIFEST_NAME
    manifest_payload, _ = read_regular_file(manifest_path, "manifest")
    try:
        manifest = validate_manifest(json.loads(manifest_payload.decode("utf-8")))
    except (UnicodeError, json.JSONDecodeError):
        fail("manifest_json_invalid")
    source_path = selected_root / manifest["repository_source"]["path"]
    source_payload, _ = read_regular_file(source_path, "source")
    expected = manifest["repository_source"]["sha256"]
    if sha256(source_payload) != expected:
        fail("source_sha_mismatch")
    try:
        compile(source_payload, str(source_path), "exec")
    except SyntaxError:
        fail("source_syntax_invalid")
    return manifest, source_path, source_payload


def validate_directory(
    path: Path,
    *,
    uid: int,
    gid: int,
    private: bool,
) -> os.stat_result:
    try:
        metadata = os.lstat(path)
    except OSError:
        fail("destination_directory_unavailable")
    if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISDIR(metadata.st_mode):
        fail("destination_directory_invalid")
    if metadata.st_uid != uid or metadata.st_gid != gid:
        fail("destination_directory_owner_invalid")
    mode = stat.S_IMODE(metadata.st_mode)
    if (private and mode != 0o700) or (not private and mode & 0o022):
        fail("destination_directory_mode_invalid")
    return metadata


def ensure_backup_directory(path: Path, *, uid: int, gid: int) -> None:
    validate_directory(path.parent, uid=uid, gid=gid, private=False)
    try:
        os.mkdir(path, 0o700)
    except FileExistsError:
        pass
    except OSError:
        fail("backup_directory_create_failed")
    validate_directory(path, uid=uid, gid=gid, private=True)


def fsync_directory(path: Path) -> None:
    flags = os.O_RDONLY
    if hasattr(os, "O_DIRECTORY"):
        flags |= os.O_DIRECTORY
    descriptor = os.open(path, flags)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def stage_file(
    directory: Path,
    prefix: str,
    payload: bytes,
    *,
    uid: int,
    gid: int,
    mode: int,
) -> Path:
    descriptor, temporary_name = tempfile.mkstemp(prefix=prefix, dir=directory)
    temporary_path = Path(temporary_name)
    try:
        os.fchown(descriptor, uid, gid)
        os.fchmod(descriptor, mode)
        remaining = memoryview(payload)
        while remaining:
            written = os.write(descriptor, remaining)
            if written <= 0:
                fail("temporary_write_failed")
            remaining = remaining[written:]
        os.fsync(descriptor)
    except Exception:
        temporary_path.unlink(missing_ok=True)
        raise
    finally:
        os.close(descriptor)
    try:
        staged_payload, staged_metadata = read_regular_file(temporary_path, "temporary")
        valid = (
            sha256(staged_payload) == sha256(payload)
            and staged_metadata.st_uid == uid
            and staged_metadata.st_gid == gid
            and stat.S_IMODE(staged_metadata.st_mode) == mode
        )
    except Exception:
        temporary_path.unlink(missing_ok=True)
        raise
    if not valid:
        temporary_path.unlink(missing_ok=True)
        fail("temporary_verification_failed")
    return temporary_path


def destination_snapshot(path: Path) -> dict | None:
    try:
        payload, metadata = read_regular_file(path, "destination")
    except ProvisionFailure as error:
        if str(error) == "destination_missing":
            return None
        raise
    return {
        "payload": payload,
        "sha256": sha256(payload),
        "uid": metadata.st_uid,
        "gid": metadata.st_gid,
        "mode": stat.S_IMODE(metadata.st_mode),
        "device": metadata.st_dev,
        "inode": metadata.st_ino,
    }


def assert_destination_unchanged(path: Path, snapshot: dict | None) -> None:
    current = destination_snapshot(path)
    if snapshot is None:
        if current is not None:
            fail("destination_changed")
        return
    if current is None or any(
        current[key] != snapshot[key]
        for key in ("sha256", "uid", "gid", "mode", "device", "inode")
    ):
        fail("destination_changed")


def verify_managed_file(
    path: Path,
    expected_sha256: str,
    *,
    uid: int,
    gid: int,
    mode: int,
) -> dict:
    payload, metadata = read_regular_file(path, "installed")
    actual_sha256 = sha256(payload)
    if actual_sha256 != expected_sha256:
        fail("installed_sha_mismatch")
    if metadata.st_uid != uid or metadata.st_gid != gid:
        fail("installed_owner_mismatch")
    if stat.S_IMODE(metadata.st_mode) != mode:
        fail("installed_mode_mismatch")
    try:
        compile(payload, str(path), "exec")
    except SyntaxError:
        fail("installed_syntax_invalid")
    return {
        "destination": str(path),
        "gid": metadata.st_gid,
        "mode": f"{mode:04o}",
        "sha256": actual_sha256,
        "uid": metadata.st_uid,
    }


def persist_backup(
    snapshot: dict | None,
    backup_directory: Path,
    *,
    uid: int,
    gid: int,
    mode: int,
) -> str | None:
    if snapshot is None:
        return None
    ensure_backup_directory(backup_directory, uid=uid, gid=gid)
    backup = backup_directory / f"oripa-github-app-git.{snapshot['sha256']}"
    try:
        existing = verify_managed_file(
            backup,
            snapshot["sha256"],
            uid=uid,
            gid=gid,
            mode=mode,
        )
        return existing["sha256"]
    except ProvisionFailure as error:
        if str(error) != "installed_missing":
            raise
    staged = stage_file(
        backup_directory,
        ".oripa-github-app-git.backup.",
        snapshot["payload"],
        uid=uid,
        gid=gid,
        mode=mode,
    )
    try:
        try:
            os.link(staged, backup, follow_symlinks=False)
        except FileExistsError:
            pass
        fsync_directory(backup_directory)
    finally:
        staged.unlink(missing_ok=True)
    verified = verify_managed_file(
        backup,
        snapshot["sha256"],
        uid=uid,
        gid=gid,
        mode=mode,
    )
    return verified["sha256"]


def restore_snapshot(path: Path, snapshot: dict | None) -> None:
    if snapshot is None:
        path.unlink(missing_ok=True)
        fsync_directory(path.parent)
        return
    staged = stage_file(
        path.parent,
        f".{path.name}.restore.",
        snapshot["payload"],
        uid=snapshot["uid"],
        gid=snapshot["gid"],
        mode=snapshot["mode"],
    )
    try:
        os.replace(staged, path)
        fsync_directory(path.parent)
    finally:
        staged.unlink(missing_ok=True)
    restored = destination_snapshot(path)
    if restored is None or any(
        restored[key] != snapshot[key] for key in ("sha256", "uid", "gid", "mode")
    ):
        fail("rollback_verification_failed")


def install_payload(
    destination: Path,
    payload: bytes,
    expected_sha256: str,
    backup_directory: Path,
    *,
    uid: int,
    gid: int,
    mode: int,
    expected_pre_sha256: str | None = None,
    post_install_validation=None,
) -> dict:
    if sha256(payload) != expected_sha256:
        fail("install_payload_sha_mismatch")
    validate_directory(destination.parent, uid=uid, gid=gid, private=False)
    previous = destination_snapshot(destination)
    if expected_pre_sha256 is not None and (
        not SHA256.fullmatch(expected_pre_sha256)
        or previous is None
        or previous["sha256"] != expected_pre_sha256
    ):
        fail("preinstall_sha_mismatch")
    if previous is not None and (
        previous["uid"] != uid
        or previous["gid"] != gid
        or previous["mode"] != mode
    ):
        fail("preinstall_metadata_mismatch")
    backup_sha256 = persist_backup(
        previous,
        backup_directory,
        uid=uid,
        gid=gid,
        mode=mode,
    )
    staged = stage_file(
        destination.parent,
        f".{destination.name}.install.",
        payload,
        uid=uid,
        gid=gid,
        mode=mode,
    )
    replaced = False
    try:
        assert_destination_unchanged(destination, previous)
        os.replace(staged, destination)
        replaced = True
        fsync_directory(destination.parent)
        installed = verify_managed_file(
            destination,
            expected_sha256,
            uid=uid,
            gid=gid,
            mode=mode,
        )
        if post_install_validation is not None:
            post_install_validation()
    except Exception:
        if replaced:
            try:
                restore_snapshot(destination, previous)
            except Exception as rollback_error:
                raise ProvisionFailure("install_failed_rollback_failed") from rollback_error
        raise
    finally:
        staged.unlink(missing_ok=True)
    return {
        "backup_sha256": backup_sha256,
        "destination": str(destination),
        "post_sha256": installed["sha256"],
        "pre_sha256": previous["sha256"] if previous else None,
    }


def require_root() -> None:
    if os.geteuid() != 0:
        fail("root_required")


def current_contract(manifest: dict) -> tuple[Path, Path, int, int, int]:
    runtime = manifest["runtime"]
    return (
        Path(runtime["destination"]),
        Path(manifest["rollback"]["directory"]),
        runtime["uid"],
        runtime["gid"],
        int(runtime["mode"], 8),
    )


def install_current(
    manifest: dict,
    source_payload: bytes,
    expected_current_sha256: str,
) -> dict:
    require_root()
    root = repository_root()
    authority_sha = require_merged_main(root)
    destination, backup_directory, uid, gid, mode = current_contract(manifest)
    result = install_payload(
        destination,
        source_payload,
        manifest["repository_source"]["sha256"],
        backup_directory,
        uid=uid,
        gid=gid,
        mode=mode,
        expected_pre_sha256=expected_current_sha256,
        post_install_validation=lambda: require_merged_main(root) == authority_sha
        or fail("repository_main_changed"),
    )
    return {"action": "install", **result}


def rollback(
    manifest: dict,
    expected_sha256: str,
    expected_current_sha256: str,
) -> dict:
    require_root()
    root = repository_root()
    authority_sha = require_merged_main(root)
    if not SHA256.fullmatch(expected_sha256):
        fail("rollback_sha_invalid")
    destination, backup_directory, uid, gid, mode = current_contract(manifest)
    backup_path = backup_directory / f"oripa-github-app-git.{expected_sha256}"
    payload, metadata = read_regular_file(backup_path, "rollback_source")
    if (
        sha256(payload) != expected_sha256
        or metadata.st_uid != 0
        or metadata.st_gid != 0
        or stat.S_IMODE(metadata.st_mode) != 0o700
    ):
        fail("rollback_source_invalid")
    result = install_payload(
        destination,
        payload,
        expected_sha256,
        backup_directory,
        uid=uid,
        gid=gid,
        mode=mode,
        expected_pre_sha256=expected_current_sha256,
        post_install_validation=lambda: require_merged_main(root) == authority_sha
        or fail("repository_main_changed"),
    )
    return {"action": "rollback", **result}


def status(manifest: dict, source_path: Path, source_payload: bytes) -> dict:
    destination, _, uid, gid, mode = current_contract(manifest)
    installed = destination_snapshot(destination)
    return {
        "destination": str(destination),
        "expected_gid": gid,
        "expected_mode": f"{mode:04o}",
        "expected_sha256": manifest["repository_source"]["sha256"],
        "expected_uid": uid,
        "installed_gid": installed["gid"] if installed else None,
        "installed_mode": f"{installed['mode']:04o}" if installed else None,
        "installed_sha256": installed["sha256"] if installed else None,
        "installed_uid": installed["uid"] if installed else None,
        "source": str(source_path),
        "source_sha256": sha256(source_payload),
    }


def main() -> None:
    parser = argparse.ArgumentParser()
    subparsers = parser.add_subparsers(dest="operation", required=True)
    subparsers.add_parser("status")
    subparsers.add_parser("verify-source")
    verify_installed_parser = subparsers.add_parser("verify-installed")
    verify_installed_parser.add_argument("expected_sha256", nargs="?")
    install_parser = subparsers.add_parser("install")
    install_parser.add_argument("expected_current_sha256")
    rollback_parser = subparsers.add_parser("rollback")
    rollback_parser.add_argument("expected_sha256")
    rollback_parser.add_argument("expected_current_sha256")
    arguments = parser.parse_args()

    manifest, source_path, source_payload = load_manifest()
    if arguments.operation == "status":
        result = status(manifest, source_path, source_payload)
    elif arguments.operation == "verify-source":
        result = {
            "source": str(source_path),
            "source_sha256": sha256(source_payload),
            "status": "verified",
        }
    elif arguments.operation == "verify-installed":
        require_root()
        destination, _, uid, gid, mode = current_contract(manifest)
        expected_sha256 = (
            arguments.expected_sha256
            or manifest["repository_source"]["sha256"]
        )
        if not SHA256.fullmatch(expected_sha256):
            fail("installed_expected_sha_invalid")
        result = verify_managed_file(
            destination,
            expected_sha256,
            uid=uid,
            gid=gid,
            mode=mode,
        )
        result["status"] = "verified"
    elif arguments.operation == "install":
        result = install_current(
            manifest,
            source_payload,
            arguments.expected_current_sha256,
        )
    else:
        result = rollback(
            manifest,
            arguments.expected_sha256,
            arguments.expected_current_sha256,
        )
    print(json.dumps(result, sort_keys=True))


if __name__ == "__main__":
    try:
        main()
    except ProvisionFailure as error:
        print(f"provision_error:{error}", file=sys.stderr)
        raise SystemExit(1)
    except Exception:
        print("provision_error:unexpected_failure", file=sys.stderr)
        raise SystemExit(1)
