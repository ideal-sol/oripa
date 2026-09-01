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
FULL_SHA = re.compile(r"[0-9a-f]{40}")
EXPECTED_SOURCE = "infrastructure/github-app/oripa-github-app-git"
EXPECTED_MANIFEST = f"infrastructure/github-app/{MANIFEST_NAME}"
EXPECTED_HELPER = "infrastructure/github-app/provision_git_wrapper.py"
EXPECTED_DESTINATION = "/usr/local/bin/oripa-github-app-git"
EXPECTED_BACKUP_DIRECTORY = "/var/lib/oripa-github-app-wrapper-backups"
EXPECTED_REPOSITORY_ROOT = Path("/var/www/oripa")
GIT = "/usr/bin/git"
REPOSITORY_URL = "https://github.com/ideal-sol/oripa.git"
CANONICAL_ORIGIN_URLS = {
    "git@github.com:ideal-sol/oripa.git",
    "git@github.com:ideal-sol/oripa",
    "https://github.com/ideal-sol/oripa.git",
    "https://github.com/ideal-sol/oripa",
    "ssh://git@github.com/ideal-sol/oripa.git",
    "ssh://git@github.com/ideal-sol/oripa",
}


class ProvisionFailure(ValueError):
    pass


def fail(message: str) -> None:
    raise ProvisionFailure(message)


def sha256(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def repository_root() -> Path:
    return Path(__file__).resolve().parents[2]


def git_environment() -> dict[str, str]:
    environment = {
        key: value
        for key, value in os.environ.items()
        if not key.startswith("GIT_")
    }
    environment.update(
        {
            "GIT_CONFIG_GLOBAL": os.devnull,
            "GIT_CONFIG_NOSYSTEM": "1",
            "GIT_OPTIONAL_LOCKS": "0",
            "GIT_TERMINAL_PROMPT": "0",
        }
    )
    return environment


def git_command(arguments: list[str]) -> list[str]:
    return [
        GIT,
        "--no-optional-locks",
        "-c",
        "core.fsmonitor=false",
        "-c",
        "core.hooksPath=/dev/null",
        *arguments,
    ]


def run_git(
    arguments: list[str],
    classification: str,
    *,
    text: bool = True,
) -> str | bytes:
    result = subprocess.run(
        git_command(arguments),
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        check=False,
        text=text,
        env=git_environment(),
    )
    if result.returncode != 0:
        fail(classification)
    return result.stdout


def validate_repository_root(path: Path) -> Path:
    if not path.is_absolute():
        fail("repository_root_not_absolute")
    try:
        metadata = os.lstat(path)
        resolved = path.resolve(strict=True)
    except OSError:
        fail("repository_root_unavailable")
    if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISDIR(metadata.st_mode):
        fail("repository_root_invalid")
    if resolved != path:
        fail("repository_root_not_canonical")
    return resolved


def repository_file(root: Path, relative: str, classification: str) -> Path:
    parsed = PurePosixPath(relative)
    if parsed.is_absolute() or ".." in parsed.parts or str(parsed) != relative:
        fail(f"{classification}_path_invalid")
    path = root.joinpath(*parsed.parts)
    try:
        resolved = path.resolve(strict=True)
    except FileNotFoundError:
        fail(f"{classification}_missing")
    except OSError:
        fail(f"{classification}_unavailable")
    if (
        resolved != path
        or os.path.commonpath((str(root), str(resolved))) != str(root)
    ):
        fail(f"{classification}_path_invalid")
    return path


def require_same_checkout(root: Path) -> Path:
    helper_path = repository_file(root, EXPECTED_HELPER, "helper")
    invoked_path = Path(__file__)
    if not invoked_path.is_absolute():
        invoked_path = Path.cwd() / invoked_path
    try:
        invoked_metadata = os.lstat(invoked_path)
        invoked_resolved = invoked_path.resolve(strict=True)
    except OSError:
        fail("helper_unavailable")
    if stat.S_ISLNK(invoked_metadata.st_mode) or not stat.S_ISREG(
        invoked_metadata.st_mode
    ):
        fail("helper_not_regular")
    if invoked_resolved != helper_path:
        fail("helper_checkout_mismatch")
    return helper_path


def live_main_sha() -> str:
    live_output = run_git(
        ["ls-remote", REPOSITORY_URL, "refs/heads/main"],
        "repository_live_main_check_failed",
    ).strip().split()
    if (
        len(live_output) != 2
        or live_output[1] != "refs/heads/main"
        or not FULL_SHA.fullmatch(live_output[0])
    ):
        fail("repository_live_main_invalid")
    return live_output[0]


def require_commit_ancestor(root: Path, ancestor: str, descendant: str) -> None:
    result = subprocess.run(
        git_command(
            [
                "-C",
                str(root),
                "merge-base",
                "--is-ancestor",
                ancestor,
                descendant,
            ]
        ),
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        check=False,
        env=git_environment(),
    )
    if result.returncode == 1:
        fail("repository_head_not_merged")
    if result.returncode != 0:
        fail("repository_ancestry_check_failed")


def require_head_file(
    root: Path,
    head_sha: str,
    relative: str,
    classification: str,
) -> None:
    path = repository_file(root, relative, classification)
    payload, metadata = read_regular_file(path, classification)
    if stat.S_IMODE(metadata.st_mode) & 0o022:
        fail(f"{classification}_mode_invalid")
    if payload != head_blob_payload(root, head_sha, relative, classification):
        fail(f"{classification}_head_mismatch")


def head_blob_payload(
    root: Path,
    head_sha: str,
    relative: str,
    classification: str,
) -> bytes:
    entry = run_git(
        ["-C", str(root), "ls-tree", "-z", head_sha, "--", relative],
        f"{classification}_head_check_failed",
        text=False,
    )
    entries = [value for value in entry.split(b"\0") if value]
    if len(entries) != 1 or b"\t" not in entries[0]:
        fail(f"{classification}_head_mismatch")
    details, tracked_path = entries[0].split(b"\t", 1)
    fields = details.split()
    if (
        len(fields) != 3
        or fields[0] not in {b"100644", b"100755"}
        or fields[1] != b"blob"
        or tracked_path.decode("utf-8", errors="strict") != relative
    ):
        fail(f"{classification}_head_mismatch")
    head_payload = run_git(
        ["-C", str(root), "show", f"{head_sha}:{relative}"],
        f"{classification}_head_read_failed",
        text=False,
    )
    return head_payload


def require_checkout_files(root: Path, head_sha: str) -> None:
    require_same_checkout(root)
    require_head_file(root, head_sha, EXPECTED_HELPER, "helper")
    require_head_file(root, head_sha, EXPECTED_MANIFEST, "manifest")
    require_head_file(root, head_sha, EXPECTED_SOURCE, "source")


def require_repository_authority(
    root: Path,
    expected_head: str | None,
) -> dict:
    root = validate_repository_root(root)
    top_level = run_git(
        ["-C", str(root), "rev-parse", "--show-toplevel"],
        "repository_git_worktree_invalid",
    ).strip()
    try:
        top_level_path = Path(top_level).resolve(strict=True)
    except OSError:
        fail("repository_git_worktree_invalid")
    if top_level_path != root:
        fail("repository_git_worktree_invalid")
    origin_urls = run_git(
        [
            "-C",
            str(root),
            "config",
            "--local",
            "--no-includes",
            "--get-all",
            "remote.origin.url",
        ],
        "repository_origin_check_failed",
    ).splitlines()
    if len(origin_urls) != 1 or origin_urls[0] not in CANONICAL_ORIGIN_URLS:
        fail("repository_origin_invalid")
    if run_git(
        [
            "-C",
            str(root),
            "status",
            "--porcelain=v1",
            "--untracked-files=all",
        ],
        "repository_status_check_failed",
        text=False,
    ):
        fail("repository_not_clean")
    branch = run_git(
        ["-C", str(root), "branch", "--show-current"],
        "repository_branch_check_failed",
    ).strip()
    head_sha = run_git(
        ["-C", str(root), "rev-parse", "--verify", "HEAD^{commit}"],
        "repository_head_check_failed",
    ).strip()
    if not FULL_SHA.fullmatch(head_sha):
        fail("repository_head_invalid")
    if expected_head is None:
        if root != EXPECTED_REPOSITORY_ROOT:
            fail("repository_root_invalid")
        if branch != "main":
            fail("repository_branch_invalid")
    elif not FULL_SHA.fullmatch(expected_head) or head_sha != expected_head:
        fail("repository_head_mismatch")
    origin_sha = run_git(
        [
            "-C",
            str(root),
            "rev-parse",
            "--verify",
            "refs/remotes/origin/main^{commit}",
        ],
        "repository_origin_check_failed",
    ).strip()
    live_sha = live_main_sha()
    if origin_sha != live_sha:
        fail("repository_origin_main_mismatch")
    if expected_head is None and head_sha != live_sha:
        fail("repository_main_mismatch")
    require_commit_ancestor(root, head_sha, live_sha)
    require_checkout_files(root, head_sha)
    return {
        "head_sha": head_sha,
        "live_main_sha": live_sha,
        "repository_root": str(root),
    }


def require_merged_main(root: Path) -> str:
    try:
        resolved = root.resolve(strict=True)
    except OSError:
        fail("repository_root_invalid")
    if resolved != EXPECTED_REPOSITORY_ROOT:
        fail("repository_root_invalid")
    return require_repository_authority(resolved, None)["head_sha"]


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


def load_manifest(
    root: Path | None = None,
    head_sha: str | None = None,
) -> tuple[dict, Path, bytes]:
    selected_root = validate_repository_root(root or repository_root())
    require_same_checkout(selected_root)
    manifest_path = repository_file(
        selected_root,
        EXPECTED_MANIFEST,
        "manifest",
    )
    manifest_payload, _ = read_regular_file(manifest_path, "manifest")
    try:
        manifest = validate_manifest(json.loads(manifest_payload.decode("utf-8")))
    except (UnicodeError, json.JSONDecodeError):
        fail("manifest_json_invalid")
    source_path = repository_file(
        selected_root,
        manifest["repository_source"]["path"],
        "source",
    )
    source_payload, _ = read_regular_file(source_path, "source")
    expected = manifest["repository_source"]["sha256"]
    if sha256(source_payload) != expected:
        fail("source_sha_mismatch")
    try:
        compile(source_payload, str(source_path), "exec")
    except SyntaxError:
        fail("source_syntax_invalid")
    if head_sha is not None:
        if not FULL_SHA.fullmatch(head_sha):
            fail("repository_head_invalid")
        require_head_file(selected_root, head_sha, EXPECTED_HELPER, "helper")
        if manifest_payload != head_blob_payload(
            selected_root,
            head_sha,
            EXPECTED_MANIFEST,
            "manifest",
        ):
            fail("manifest_head_mismatch")
        if source_payload != head_blob_payload(
            selected_root,
            head_sha,
            EXPECTED_SOURCE,
            "source",
        ):
            fail("source_head_mismatch")
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
    root: Path,
    expected_head: str | None,
) -> dict:
    require_root()
    authority = require_repository_authority(root, expected_head)
    authorized_manifest, _, authorized_source_payload = load_manifest(
        root,
        authority["head_sha"],
    )
    if manifest != authorized_manifest or source_payload != authorized_source_payload:
        fail("provision_source_changed")
    destination, backup_directory, uid, gid, mode = current_contract(manifest)

    def revalidate_authority() -> None:
        current = require_repository_authority(root, expected_head)
        if current["head_sha"] != authority["head_sha"]:
            fail("repository_main_changed")

    result = install_payload(
        destination,
        source_payload,
        manifest["repository_source"]["sha256"],
        backup_directory,
        uid=uid,
        gid=gid,
        mode=mode,
        expected_pre_sha256=expected_current_sha256,
        post_install_validation=revalidate_authority,
    )
    return {
        "action": "install",
        "live_main_sha": authority["live_main_sha"],
        "repository_head": authority["head_sha"],
        "repository_root": authority["repository_root"],
        **result,
    }


def rollback(
    manifest: dict,
    expected_sha256: str,
    expected_current_sha256: str,
    root: Path,
    expected_head: str | None,
) -> dict:
    require_root()
    authority = require_repository_authority(root, expected_head)
    authorized_manifest, _, _ = load_manifest(root, authority["head_sha"])
    if manifest != authorized_manifest:
        fail("provision_source_changed")
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

    def revalidate_authority() -> None:
        current = require_repository_authority(root, expected_head)
        if current["head_sha"] != authority["head_sha"]:
            fail("repository_main_changed")

    result = install_payload(
        destination,
        payload,
        expected_sha256,
        backup_directory,
        uid=uid,
        gid=gid,
        mode=mode,
        expected_pre_sha256=expected_current_sha256,
        post_install_validation=revalidate_authority,
    )
    return {
        "action": "rollback",
        "live_main_sha": authority["live_main_sha"],
        "repository_head": authority["head_sha"],
        "repository_root": authority["repository_root"],
        **result,
    }


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
    parser.add_argument("--repo-root")
    parser.add_argument("--expected-head")
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

    explicit_authority = (
        arguments.repo_root is not None or arguments.expected_head is not None
    )
    if explicit_authority and (
        arguments.repo_root is None or arguments.expected_head is None
    ):
        fail("explicit_authority_arguments_required")
    if explicit_authority:
        if not FULL_SHA.fullmatch(arguments.expected_head):
            fail("expected_head_invalid")
        selected_root = validate_repository_root(Path(arguments.repo_root))
        authority = require_repository_authority(
            selected_root,
            arguments.expected_head,
        )
    else:
        selected_root = validate_repository_root(EXPECTED_REPOSITORY_ROOT)
        authority = None

    manifest, source_path, source_payload = load_manifest(
        selected_root,
        authority["head_sha"] if authority else None,
    )
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
            selected_root,
            arguments.expected_head,
        )
    else:
        result = rollback(
            manifest,
            arguments.expected_sha256,
            arguments.expected_current_sha256,
            selected_root,
            arguments.expected_head,
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
