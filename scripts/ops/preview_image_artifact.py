#!/usr/bin/env python3
"""Create, verify, and load immutable Platform image artifacts."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import platform
import re
import subprocess
import sys
import tarfile
import tempfile


SCHEMA_VERSION = "oripa.platform-images.v2"
LEGACY_SCHEMA_VERSION = "oripa.preview-images.v1"
SOURCE_URL = "https://github.com/ideal-sol/oripa"
FULL_SHA = re.compile(r"^[0-9a-f]{40}$")
SHA256 = re.compile(r"^[0-9a-f]{64}$")
TASK_ID = re.compile(r"^[A-Z][A-Z0-9-]{2,31}$")
ISO_DATETIME = re.compile(
    r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$"
)
IMAGE_NAMES = ("api", "admin")
IMAGE_MODES = {
    "normal": IMAGE_NAMES,
    "api-only": ("api",),
}
TARGET_OS = "linux"
TARGET_ARCHITECTURE = "amd64"
TARGET_PLATFORM = f"{TARGET_OS}/{TARGET_ARCHITECTURE}"
SUPPORTED_ARCHITECTURES = ("amd64", "arm64")
ARTIFACT_KINDS = ("preview", "production-candidate")
ARCHIVE_NAMES = {
    "api": "oripa-v2-api-linux-amd64.docker.tar.zst",
    "admin": "oripa-v2-admin-linux-amd64.docker.tar.zst",
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


def validate_target(artifact_kind: str, architecture: str) -> None:
    if artifact_kind not in ARTIFACT_KINDS:
        fail("artifact_kind_invalid")
    if architecture not in SUPPORTED_ARCHITECTURES:
        fail("architecture_invalid")
    if artifact_kind == "preview" and architecture != TARGET_ARCHITECTURE:
        fail("preview_architecture_invalid")


def target_platform(architecture: str) -> str:
    return f"{TARGET_OS}/{architecture}"


def archive_names(architecture: str) -> dict[str, str]:
    if architecture not in SUPPORTED_ARCHITECTURES:
        fail("architecture_invalid")
    return {
        name: f"oripa-v2-{name}-linux-{architecture}.docker.tar.zst"
        for name in IMAGE_NAMES
    }


def image_identity(
    name: str,
    *,
    artifact_kind: str,
    architecture: str,
    task_id: str,
    source_sha: str,
) -> tuple[str, str]:
    version = f"{artifact_kind}-{task_id}"
    if artifact_kind == "preview":
        reference = f"oripa-v2-{name}:preview-{task_id}-{source_sha[:12]}"
    else:
        reference = (
            f"oripa-v2-{name}:production-candidate-{task_id}-"
            f"{source_sha[:12]}-{architecture}"
        )
    return reference, version


def docker_inspect(reference: str) -> dict:
    try:
        decoded = json.loads(run(["docker", "image", "inspect", reference], capture=True))
    except json.JSONDecodeError:
        fail("docker_inspect_invalid")
    if not isinstance(decoded, list) or len(decoded) != 1 or not isinstance(decoded[0], dict):
        fail("docker_inspect_invalid")
    return decoded[0]


def image_metadata(inspect: dict, source_sha: str, architecture: str) -> dict:
    labels = (inspect.get("Config") or {}).get("Labels") or {}
    if not isinstance(labels, dict) or not REQUIRED_LABELS.issubset(labels):
        fail("oci_labels_missing")
    if labels["org.opencontainers.image.revision"] != source_sha:
        fail("oci_revision_mismatch")
    if labels["org.opencontainers.image.source"] != SOURCE_URL:
        fail("oci_source_mismatch")
    if (
        normalise_architecture(inspect.get("Architecture")) != architecture
        or inspect.get("Os") != TARGET_OS
    ):
        fail("image_platform_mismatch")
    image_id = inspect.get("Id")
    if not isinstance(image_id, str) or not re.fullmatch(r"sha256:[0-9a-f]{64}", image_id):
        fail("image_id_invalid")
    return {
        "image_id": image_id,
        "architecture": architecture,
        "os": TARGET_OS,
        "labels": {key: labels[key] for key in sorted(REQUIRED_LABELS)},
    }


def package_images(arguments: argparse.Namespace) -> dict:
    validate_identity(arguments.task_id, arguments.pr_number, arguments.source_sha)
    validate_target(arguments.artifact_kind, arguments.architecture)
    if not ISO_DATETIME.fullmatch(arguments.created_at):
        fail("created_at_invalid")
    output = arguments.output.resolve()
    if output.exists() and any(output.iterdir()):
        fail("output_directory_not_empty")
    output.mkdir(parents=True, exist_ok=True)
    image_names = IMAGE_MODES[arguments.image_mode]
    archives = archive_names(arguments.architecture)
    references = {"api": arguments.api_image, "admin": arguments.admin_image}
    if (arguments.image_mode == "normal") != (arguments.admin_image is not None):
        fail("admin_image_mode_mismatch")
    images = []
    for name in image_names:
        reference = references[name]
        inspected = docker_inspect(reference)
        metadata = image_metadata(inspected, arguments.source_sha, arguments.architecture)
        expected_reference, expected_version = image_identity(
            name,
            artifact_kind=arguments.artifact_kind,
            architecture=arguments.architecture,
            task_id=arguments.task_id,
            source_sha=arguments.source_sha,
        )
        if reference != expected_reference:
            fail("image_reference_mismatch")
        if metadata["labels"]["org.opencontainers.image.version"] != expected_version:
            fail("oci_version_mismatch")
        archive = output / archives[name]
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
        "artifact_kind": arguments.artifact_kind,
        "architecture": arguments.architecture,
        "task_id": arguments.task_id,
        "repository": "ideal-sol/oripa",
        "pull_request": arguments.pr_number,
        "source_commit": arguments.source_sha,
        "created_at": arguments.created_at,
        "platform": target_platform(arguments.architecture),
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
                config_match = (
                    re.fullmatch(r"([0-9a-f]{64})\.json", config_name)
                    if isinstance(config_name, str)
                    else None
                ) or (
                    re.fullmatch(r"blobs/sha256/([0-9a-f]{64})", config_name)
                    if isinstance(config_name, str)
                    else None
                )
                if config_match is None:
                    fail("docker_archive_config_invalid")
                config_stream = bundle.extractfile(bundle.getmember(config_name))
                if config_stream is None:
                    fail("docker_archive_config_invalid")
                config_bytes = config_stream.read()
        except (KeyError, tarfile.TarError, UnicodeError, json.JSONDecodeError):
            fail("docker_archive_invalid")

    config_digest = config_match.group(1)
    if hashlib.sha256(config_bytes).hexdigest() != config_digest:
        fail("docker_archive_image_id_mismatch")
    config = json.loads(config_bytes.decode("utf-8"))
    labels = (config.get("config") or {}).get("Labels") or {}
    if not isinstance(labels, dict):
        fail("docker_archive_labels_invalid")
    tags = entry.get("RepoTags")
    if not isinstance(tags, list) or len(tags) != 1 or not isinstance(tags[0], str):
        fail("docker_archive_tags_invalid")
    return {
        "image_id": "sha256:" + config_digest,
        "architecture": config.get("architecture"),
        "os": config.get("os"),
        "labels": labels,
        "reference": tags[0],
    }


def verify_artifact(
    directory: Path,
    *,
    task_id: str,
    pr_number: int,
    source_sha: str,
    artifact_kind: str = "preview",
    architecture: str = TARGET_ARCHITECTURE,
) -> dict:
    validate_identity(task_id, pr_number, source_sha)
    validate_target(artifact_kind, architecture)
    directory = directory.resolve()
    manifest_path = directory / "manifest.json"
    checksums_path = directory / "SHA256SUMS"
    manifest = load_json(manifest_path)
    schema_version = manifest.get("schema_version")
    legacy_preview = schema_version == LEGACY_SCHEMA_VERSION
    expected_manifest_fields = {
        "schema_version",
        "task_id",
        "repository",
        "pull_request",
        "source_commit",
        "created_at",
        "platform",
        "images",
    }
    if not legacy_preview:
        expected_manifest_fields |= {"artifact_kind", "architecture"}
    if set(manifest) != expected_manifest_fields:
        fail("manifest_schema_invalid")
    if legacy_preview and (artifact_kind != "preview" or architecture != TARGET_ARCHITECTURE):
        fail("legacy_artifact_target_invalid")
    if (
        schema_version not in {SCHEMA_VERSION, LEGACY_SCHEMA_VERSION}
        or manifest.get("task_id") != task_id
        or manifest.get("repository") != "ideal-sol/oripa"
        or manifest.get("pull_request") != pr_number
        or manifest.get("source_commit") != source_sha
        or manifest.get("platform") != target_platform(architecture)
        or (not legacy_preview and manifest.get("artifact_kind") != artifact_kind)
        or (not legacy_preview and manifest.get("architecture") != architecture)
    ):
        fail("manifest_identity_mismatch")
    created_at = manifest.get("created_at")
    if not isinstance(created_at, str) or not ISO_DATETIME.fullmatch(created_at):
        fail("manifest_created_at_invalid")
    images = manifest.get("images")
    image_names = tuple(
        item.get("name") for item in images if isinstance(item, dict)
    ) if isinstance(images, list) else ()
    if image_names not in IMAGE_MODES.values() or len(image_names) != len(images):
        fail("manifest_images_invalid")

    archives = archive_names(architecture)
    expected_files = {archives[name] for name in image_names} | {"manifest.json"}
    checksums = parse_checksums(checksums_path)
    if set(checksums) != expected_files:
        fail("checksums_file_set_invalid")
    for filename, expected in checksums.items():
        if sha256_file(directory / filename) != expected:
            fail(f"checksum_mismatch:{filename}")

    verified_images = []
    for expected_name, image in zip(image_names, images):
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
        if image["name"] != expected_name or image["archive"] != archives[expected_name]:
            fail("manifest_image_identity_invalid")
        if image["archive_sha256"] != checksums[image["archive"]]:
            fail("manifest_archive_checksum_invalid")
        archive_path = directory / image["archive"]
        if image["archive_bytes"] != archive_path.stat().st_size:
            fail("manifest_archive_size_invalid")
        metadata = docker_archive_metadata(
            archive_path, image["archive_uncompressed_bytes"]
        )
        expected_reference, expected_version = image_identity(
            expected_name,
            artifact_kind=artifact_kind,
            architecture=architecture,
            task_id=task_id,
            source_sha=source_sha,
        )
        if (
            metadata["image_id"] != image["image_id"]
            or metadata["reference"] != image["reference"]
            or image["reference"] != expected_reference
            or normalise_architecture(metadata["architecture"]) != architecture
            or metadata["os"] != TARGET_OS
            or image["architecture"] != architecture
            or image["os"] != TARGET_OS
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
        if labels["org.opencontainers.image.version"] != expected_version:
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
        "artifact_kind": artifact_kind,
        "architecture": architecture,
        "platform": target_platform(architecture),
        "manifest_sha256": checksums["manifest.json"],
        "images": verified_images,
        "status": "verified",
    }


def normalise_architecture(value: object) -> str:
    if value in {"x86_64", "amd64"}:
        return "amd64"
    if value in {"aarch64", "arm64"}:
        return "arm64"
    return value if isinstance(value, str) else ""


def host_architectures() -> dict[str, str]:
    machine = normalise_architecture(platform.machine())
    docker = normalise_architecture(
        run(["docker", "info", "--format", "{{.Architecture}}"], capture=True)
    )
    return {"machine": machine, "docker": docker}


def require_target_host(architecture: str = TARGET_ARCHITECTURE) -> dict[str, str]:
    if architecture not in SUPPORTED_ARCHITECTURES:
        fail("architecture_invalid")
    architectures = host_architectures()
    if any(value != architecture for value in architectures.values()):
        fail("host_architecture_mismatch")
    return architectures


def load_artifact(arguments: argparse.Namespace) -> dict:
    verified = verify_artifact(
        arguments.directory,
        task_id=arguments.task_id,
        pr_number=arguments.pr_number,
        source_sha=arguments.source_sha,
        artifact_kind=arguments.artifact_kind,
        architecture=arguments.architecture,
    )
    require_target_host(arguments.architecture)
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
        metadata = image_metadata(
            docker_inspect(image["reference"]),
            arguments.source_sha,
            arguments.architecture,
        )
        if metadata["image_id"] != image["image_id"]:
            fail("loaded_image_id_mismatch")
    verified["status"] = "loaded"
    return verified


def parser() -> argparse.ArgumentParser:
    value = argparse.ArgumentParser()
    commands = value.add_subparsers(dest="command", required=True)
    target = commands.add_parser("target")
    target.add_argument("--field", choices=("architecture", "platform"))
    target.add_argument(
        "--architecture", choices=SUPPORTED_ARCHITECTURES, default=TARGET_ARCHITECTURE
    )
    host_check = commands.add_parser("host-check")
    host_check.add_argument(
        "--architecture", choices=SUPPORTED_ARCHITECTURES, default=TARGET_ARCHITECTURE
    )
    package = commands.add_parser("package")
    package.add_argument("--output", type=Path, required=True)
    package.add_argument("--task-id", required=True)
    package.add_argument("--pr-number", type=int, required=True)
    package.add_argument("--source-sha", required=True)
    package.add_argument("--created-at", required=True)
    package.add_argument("--image-mode", choices=tuple(IMAGE_MODES), required=True)
    package.add_argument("--artifact-kind", choices=ARTIFACT_KINDS, default="preview")
    package.add_argument(
        "--architecture", choices=SUPPORTED_ARCHITECTURES, default=TARGET_ARCHITECTURE
    )
    package.add_argument("--api-image", required=True)
    package.add_argument("--admin-image")
    for name in ("verify", "load"):
        command = commands.add_parser(name)
        command.add_argument("--directory", type=Path, required=True)
        command.add_argument("--task-id", required=True)
        command.add_argument("--pr-number", type=int, required=True)
        command.add_argument("--source-sha", required=True)
        command.add_argument("--artifact-kind", choices=ARTIFACT_KINDS, default="preview")
        command.add_argument(
            "--architecture", choices=SUPPORTED_ARCHITECTURES, default=TARGET_ARCHITECTURE
        )
    return value


def main() -> int:
    arguments = parser().parse_args()
    try:
        if arguments.command == "target":
            result = {
                "architecture": arguments.architecture,
                "platform": target_platform(arguments.architecture),
            }
            if arguments.field:
                print(result[arguments.field])
                return 0
        elif arguments.command == "host-check":
            result = {
                "architecture": arguments.architecture,
                "platform": target_platform(arguments.architecture),
                **require_target_host(arguments.architecture),
                "status": "matched",
            }
        elif arguments.command == "package":
            result = package_images(arguments)
        elif arguments.command == "verify":
            result = verify_artifact(
                arguments.directory,
                task_id=arguments.task_id,
                pr_number=arguments.pr_number,
                source_sha=arguments.source_sha,
                artifact_kind=arguments.artifact_kind,
                architecture=arguments.architecture,
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
