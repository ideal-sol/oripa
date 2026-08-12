#!/usr/bin/env python3
"""Create, verify, and load immutable Preview image artifacts."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tarfile
import tempfile


SCHEMA_VERSION = "oripa.preview-images.v1"
SOURCE_URL = "https://github.com/ideal-sol/oripa"
FULL_SHA = re.compile(r"^[0-9a-f]{40}$")
SHA256 = re.compile(r"^[0-9a-f]{64}$")
TASK_ID = re.compile(r"^[A-Z][A-Z0-9-]{2,31}$")
ISO_DATETIME = re.compile(
    r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$"
)
IMAGE_NAMES = ("api", "admin")
ARCHIVE_NAMES = {
    "api": "oripa-v2-api-linux-arm64.docker.tar.zst",
    "admin": "oripa-v2-admin-linux-arm64.docker.tar.zst",
}
REQUIRED_LABELS = {
    "org.opencontainers.image.revision",
    "org.opencontainers.image.source",
    "org.opencontainers.image.created",
    "org.opencontainers.image.version",
    "org.opencontainers.image.title",
}
MAX_ARCHIVE_BYTES = 2 * 1024 * 1024 * 1024
MAX_UNCOMPRESSED_BYTES = 4 * 1024 * 1024 * 1024


class ArtifactError(RuntimeError):
    pass


def fail(message: str) -> None:
    raise ArtifactError(message)


def run(command: list[str], *, capture: bool = False, stdin=None) -> str:
    result = subprocess.run(
        command,
        stdin=stdin,
        stdout=subprocess.PIPE if capture else None,
        stderr=subprocess.PIPE,
        text=True,
        check=False,
    )
    if result.returncode:
        fail(f"command_failed:{Path(command[0]).name}")
    return result.stdout.strip() if capture else ""


def canonical_json(value: object) -> bytes:
    return (json.dumps(value, sort_keys=True, indent=2) + "\n").encode("utf-8")


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def validate_identity(task_id: str, pr_number: int, source_sha: str) -> None:
    if not TASK_ID.fullmatch(task_id):
        fail("task_id_invalid")
    if pr_number <= 0:
        fail("pr_number_invalid")
    if not FULL_SHA.fullmatch(source_sha):
        fail("source_sha_invalid")


def docker_inspect(reference: str) -> dict:
    try:
        decoded = json.loads(run(["docker", "image", "inspect", reference], capture=True))
    except json.JSONDecodeError:
        fail("docker_inspect_invalid")
    if not isinstance(decoded, list) or len(decoded) != 1 or not isinstance(decoded[0], dict):
        fail("docker_inspect_invalid")
    return decoded[0]


def image_metadata(inspect: dict, source_sha: str) -> dict:
    labels = (inspect.get("Config") or {}).get("Labels") or {}
    if not isinstance(labels, dict) or not REQUIRED_LABELS.issubset(labels):
        fail("oci_labels_missing")
    if labels["org.opencontainers.image.revision"] != source_sha:
        fail("oci_revision_mismatch")
    if labels["org.opencontainers.image.source"] != SOURCE_URL:
        fail("oci_source_mismatch")
    if inspect.get("Architecture") != "arm64" or inspect.get("Os") != "linux":
        fail("image_platform_mismatch")
    image_id = inspect.get("Id")
    if not isinstance(image_id, str) or not re.fullmatch(r"sha256:[0-9a-f]{64}", image_id):
        fail("image_id_invalid")
    return {
        "image_id": image_id,
        "architecture": "arm64",
        "os": "linux",
        "labels": {key: labels[key] for key in sorted(REQUIRED_LABELS)},
    }


def package_images(arguments: argparse.Namespace) -> dict:
    validate_identity(arguments.task_id, arguments.pr_number, arguments.source_sha)
    if not ISO_DATETIME.fullmatch(arguments.created_at):
        fail("created_at_invalid")
    output = arguments.output.resolve()
    if output.exists() and any(output.iterdir()):
        fail("output_directory_not_empty")
    output.mkdir(parents=True, exist_ok=True)
    references = {"api": arguments.api_image, "admin": arguments.admin_image}
    images = []
    for name in IMAGE_NAMES:
        reference = references[name]
        inspected = docker_inspect(reference)
        metadata = image_metadata(inspected, arguments.source_sha)
        archive = output / ARCHIVE_NAMES[name]
        with tempfile.TemporaryDirectory(prefix="oripa-preview-save-") as temporary:
            raw = Path(temporary) / f"{name}.docker.tar"
            run(["docker", "image", "save", "--output", str(raw), reference])
            uncompressed_bytes = raw.stat().st_size
            run(["zstd", "-T0", "-19", "--quiet", "--force", str(raw), "-o", str(archive)])
        images.append(
            {
                "name": name,
                "reference": reference,
                "archive": archive.name,
                "archive_sha256": sha256_file(archive),
                "archive_bytes": archive.stat().st_size,
                "archive_uncompressed_bytes": uncompressed_bytes,
                **metadata,
            }
        )

    manifest = {
        "schema_version": SCHEMA_VERSION,
        "task_id": arguments.task_id,
        "repository": "ideal-sol/oripa",
        "pull_request": arguments.pr_number,
        "source_commit": arguments.source_sha,
        "created_at": arguments.created_at,
        "platform": "linux/arm64",
        "images": images,
    }
    manifest_path = output / "manifest.json"
    manifest_path.write_bytes(canonical_json(manifest))
    checksum_paths = [output / image["archive"] for image in images] + [manifest_path]
    lines = [f"{sha256_file(path)}  {path.name}" for path in checksum_paths]
    (output / "SHA256SUMS").write_text("\n".join(lines) + "\n", encoding="ascii")
    return manifest


def load_json(path: Path) -> dict:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError):
        fail(f"json_invalid:{path.name}")
    if not isinstance(value, dict):
        fail(f"json_invalid:{path.name}")
    return value


def parse_checksums(path: Path) -> dict[str, str]:
    try:
        lines = path.read_text(encoding="ascii").splitlines()
    except (OSError, UnicodeError):
        fail("checksums_invalid")
    values: dict[str, str] = {}
    for line in lines:
        match = re.fullmatch(r"([0-9a-f]{64})  ([A-Za-z0-9._-]+)", line)
        if not match or match.group(2) in values:
            fail("checksums_invalid")
        values[match.group(2)] = match.group(1)
    return values


def decompress_archive(archive: Path, destination: Path, expected_size: int) -> None:
    if not isinstance(expected_size, int) or expected_size <= 0 or expected_size > MAX_UNCOMPRESSED_BYTES:
        fail("archive_uncompressed_size_invalid")
    process = subprocess.Popen(
        ["zstd", "--decompress", "--stdout", "--quiet", str(archive)],
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
    )
    assert process.stdout is not None
    total = 0
    with destination.open("xb") as output:
        while chunk := process.stdout.read(1024 * 1024):
            total += len(chunk)
            if total > expected_size or total > MAX_UNCOMPRESSED_BYTES:
                process.kill()
                process.stdout.close()
                process.wait()
                fail("archive_uncompressed_size_mismatch")
            output.write(chunk)
    process.stdout.close()
    if process.wait() or total != expected_size:
        fail("archive_decompression_failed")


def docker_archive_metadata(archive: Path, expected_size: int) -> dict:
    if not archive.is_file() or archive.stat().st_size <= 0:
        fail("archive_missing")
    if archive.stat().st_size > MAX_ARCHIVE_BYTES:
        fail("archive_too_large")
    with tempfile.TemporaryDirectory(prefix="oripa-preview-verify-") as temporary:
        raw = Path(temporary) / "image.docker.tar"
        decompress_archive(archive, raw, expected_size)
        try:
            with tarfile.open(raw, mode="r:") as bundle:
                manifest_member = bundle.getmember("manifest.json")
                manifest_stream = bundle.extractfile(manifest_member)
                if manifest_stream is None:
                    fail("docker_archive_manifest_invalid")
                docker_manifest = json.loads(manifest_stream.read().decode("utf-8"))
                if not isinstance(docker_manifest, list) or len(docker_manifest) != 1:
                    fail("docker_archive_manifest_invalid")
                entry = docker_manifest[0]
                config_name = entry.get("Config") if isinstance(entry, dict) else None
                if not isinstance(config_name, str) or not re.fullmatch(r"[0-9a-f]{64}\.json", config_name):
                    fail("docker_archive_config_invalid")
                config_stream = bundle.extractfile(bundle.getmember(config_name))
                if config_stream is None:
                    fail("docker_archive_config_invalid")
                config_bytes = config_stream.read()
        except (KeyError, tarfile.TarError, UnicodeError, json.JSONDecodeError):
            fail("docker_archive_invalid")

    if hashlib.sha256(config_bytes).hexdigest() + ".json" != config_name:
        fail("docker_archive_image_id_mismatch")
    config = json.loads(config_bytes.decode("utf-8"))
    labels = (config.get("config") or {}).get("Labels") or {}
    if not isinstance(labels, dict):
        fail("docker_archive_labels_invalid")
    tags = entry.get("RepoTags")
    if not isinstance(tags, list) or len(tags) != 1 or not isinstance(tags[0], str):
        fail("docker_archive_tags_invalid")
    return {
        "image_id": "sha256:" + config_name[:-5],
        "architecture": config.get("architecture"),
        "os": config.get("os"),
        "labels": labels,
        "reference": tags[0],
    }


def verify_artifact(
    directory: Path, *, task_id: str, pr_number: int, source_sha: str
) -> dict:
    validate_identity(task_id, pr_number, source_sha)
    directory = directory.resolve()
    manifest_path = directory / "manifest.json"
    checksums_path = directory / "SHA256SUMS"
    manifest = load_json(manifest_path)
    if set(manifest) != {
        "schema_version",
        "task_id",
        "repository",
        "pull_request",
        "source_commit",
        "created_at",
        "platform",
        "images",
    }:
        fail("manifest_schema_invalid")
    if (
        manifest.get("schema_version") != SCHEMA_VERSION
        or manifest.get("task_id") != task_id
        or manifest.get("repository") != "ideal-sol/oripa"
        or manifest.get("pull_request") != pr_number
        or manifest.get("source_commit") != source_sha
        or manifest.get("platform") != "linux/arm64"
    ):
        fail("manifest_identity_mismatch")
    created_at = manifest.get("created_at")
    if not isinstance(created_at, str) or not ISO_DATETIME.fullmatch(created_at):
        fail("manifest_created_at_invalid")
    images = manifest.get("images")
    if not isinstance(images, list) or [item.get("name") for item in images if isinstance(item, dict)] != list(IMAGE_NAMES):
        fail("manifest_images_invalid")

    expected_files = {ARCHIVE_NAMES[name] for name in IMAGE_NAMES} | {"manifest.json"}
    checksums = parse_checksums(checksums_path)
    if set(checksums) != expected_files:
        fail("checksums_file_set_invalid")
    for filename, expected in checksums.items():
        if sha256_file(directory / filename) != expected:
            fail(f"checksum_mismatch:{filename}")

    verified_images = []
    for expected_name, image in zip(IMAGE_NAMES, images):
        if set(image) != {
            "name",
            "reference",
            "archive",
            "archive_sha256",
            "archive_bytes",
            "archive_uncompressed_bytes",
            "image_id",
            "architecture",
            "os",
            "labels",
        }:
            fail("manifest_image_schema_invalid")
        if image["name"] != expected_name or image["archive"] != ARCHIVE_NAMES[expected_name]:
            fail("manifest_image_identity_invalid")
        if image["archive_sha256"] != checksums[image["archive"]]:
            fail("manifest_archive_checksum_invalid")
        archive_path = directory / image["archive"]
        if image["archive_bytes"] != archive_path.stat().st_size:
            fail("manifest_archive_size_invalid")
        metadata = docker_archive_metadata(
            archive_path, image["archive_uncompressed_bytes"]
        )
        expected_reference = (
            f"oripa-v2-{expected_name}:preview-{task_id}-{source_sha[:12]}"
        )
        if (
            metadata["image_id"] != image["image_id"]
            or metadata["reference"] != image["reference"]
            or image["reference"] != expected_reference
            or metadata["architecture"] != "arm64"
            or metadata["os"] != "linux"
            or image["architecture"] != "arm64"
            or image["os"] != "linux"
        ):
            fail("image_metadata_mismatch")
        labels = image["labels"]
        if not isinstance(labels, dict) or set(labels) != REQUIRED_LABELS:
            fail("manifest_labels_invalid")
        if any(metadata["labels"].get(key) != value for key, value in labels.items()):
            fail("archive_labels_mismatch")
        if labels["org.opencontainers.image.revision"] != source_sha:
            fail("oci_revision_mismatch")
        if labels["org.opencontainers.image.source"] != SOURCE_URL:
            fail("oci_source_mismatch")
        if labels["org.opencontainers.image.version"] != f"preview-{task_id}":
            fail("oci_version_mismatch")
        if labels["org.opencontainers.image.created"] != created_at:
            fail("oci_created_mismatch")
        expected_title = "Oripa V2 API" if expected_name == "api" else "Oripa V2 Admin"
        if labels["org.opencontainers.image.title"] != expected_title:
            fail("oci_title_mismatch")
        verified_images.append({"name": expected_name, "image_id": image["image_id"], "reference": image["reference"]})

    return {
        "task_id": task_id,
        "pull_request": pr_number,
        "source_commit": source_sha,
        "platform": "linux/arm64",
        "manifest_sha256": checksums["manifest.json"],
        "images": verified_images,
        "status": "verified",
    }


def docker_host_architecture() -> str:
    value = run(["docker", "info", "--format", "{{.Architecture}}"], capture=True)
    return "arm64" if value in {"arm64", "aarch64"} else value


def load_artifact(arguments: argparse.Namespace) -> dict:
    verified = verify_artifact(
        arguments.directory,
        task_id=arguments.task_id,
        pr_number=arguments.pr_number,
        source_sha=arguments.source_sha,
    )
    if docker_host_architecture() != "arm64":
        fail("host_architecture_mismatch")
    manifest = load_json(arguments.directory / "manifest.json")
    for image in manifest["images"]:
        with tempfile.TemporaryDirectory(prefix="oripa-preview-load-") as temporary:
            raw = Path(temporary) / "image.docker.tar"
            decompress_archive(
                arguments.directory / image["archive"],
                raw,
                image["archive_uncompressed_bytes"],
            )
            run(["docker", "image", "load", "--input", str(raw)])
        metadata = image_metadata(docker_inspect(image["reference"]), arguments.source_sha)
        if metadata["image_id"] != image["image_id"]:
            fail("loaded_image_id_mismatch")
    verified["status"] = "loaded"
    return verified


def parser() -> argparse.ArgumentParser:
    value = argparse.ArgumentParser()
    commands = value.add_subparsers(dest="command", required=True)
    package = commands.add_parser("package")
    package.add_argument("--output", type=Path, required=True)
    package.add_argument("--task-id", required=True)
    package.add_argument("--pr-number", type=int, required=True)
    package.add_argument("--source-sha", required=True)
    package.add_argument("--created-at", required=True)
    package.add_argument("--api-image", required=True)
    package.add_argument("--admin-image", required=True)
    for name in ("verify", "load"):
        command = commands.add_parser(name)
        command.add_argument("--directory", type=Path, required=True)
        command.add_argument("--task-id", required=True)
        command.add_argument("--pr-number", type=int, required=True)
        command.add_argument("--source-sha", required=True)
    return value


def main() -> int:
    arguments = parser().parse_args()
    try:
        if arguments.command == "package":
            result = package_images(arguments)
        elif arguments.command == "verify":
            result = verify_artifact(
                arguments.directory,
                task_id=arguments.task_id,
                pr_number=arguments.pr_number,
                source_sha=arguments.source_sha,
            )
        else:
            result = load_artifact(arguments)
    except ArtifactError as error:
        print(f"preview_artifact_error:{error}", file=sys.stderr)
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
