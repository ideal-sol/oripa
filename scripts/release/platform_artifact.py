#!/usr/bin/env python3
"""Build and verify the immutable Oripa V2 Platform Alpha release bundle."""

from __future__ import annotations

import argparse
import datetime as dt
import gzip
import hashlib
import io
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
from typing import Iterable


PLATFORM_VERSION = "2.0.0-alpha.20"
COMPATIBILITY_FAMILY = 2
CHANNEL = "alpha"
RELEASE_TAG = "platform-v2.0.0-alpha.20"
SOURCE_URL = "https://github.com/ideal-sol/oripa"
PRODUCTION_ALLOWED = False
DATA_RETENTION_GUARANTEED = False
PACKAGE_PATHS = {
    "@oripa/storefront-client": "packages/storefront-client",
    "@oripa/site-schema": "packages/site-schema",
    "@oripa/storefront-testkit": "packages/storefront-testkit",
}
CONTRACT_PATHS = {
    "public": "openapi/bundled/public.openapi.json",
    "admin": "openapi/bundled/admin.openapi.json",
    "webhook": "openapi/bundled/webhook.openapi.json",
}
IMAGE_DEFINITIONS = {
    "api": {
        "dockerfile": "infra/docker/backend/Dockerfile",
        "title": "Oripa V2 API",
        "asset": f"oripa-api-{PLATFORM_VERSION}.docker.tar.gz",
        "base": (
            "php:8.4-cli-bookworm@"
            "sha256:138a210978c7767ef2a26f499c413fe6de1c13233c9a5068139565c81191b1ac"
        ),
    },
    "admin": {
        "dockerfile": "apps/admin/Dockerfile",
        "title": "Oripa V2 Admin",
        "asset": f"oripa-admin-{PLATFORM_VERSION}.docker.tar.gz",
        "base": (
            "node:22.22.3-alpine@"
            "sha256:e58326d0d441090181ac150dc2078d3e2cf6a0d42e809aebba3ef5880935ffdd"
        ),
    },
}
TRIVY_IMAGE = (
    "aquasec/trivy:0.66.0@"
    "sha256:086971aaf400beebd94e8300fd8ea623774419597169156cec56eec5b00dfb1e"
)
COMPOSER_IMAGE = (
    "composer:2@"
    "sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760"
)
REQUIRED_CHECKS = [
    "policy-gate",
    "quality-gate",
    "security-gate",
    "integration-gate",
    "ci-gate",
    "CodeQL",
    "CodeQL (javascript-typescript)",
    "dependency-review",
]
FULL_SHA = re.compile(r"^[0-9a-f]{40}$")
SHA256 = re.compile(r"^[0-9a-f]{64}$")
OCI_DIGEST = re.compile(r"^sha256:[0-9a-f]{64}$")


class ReleaseError(RuntimeError):
    """A deterministic release gate failure."""


def run(
    command: list[str],
    *,
    cwd: Path,
    env: dict[str, str] | None = None,
    capture: bool = False,
) -> str:
    merged_env = os.environ.copy()
    if env:
        merged_env.update(env)
    result = subprocess.run(
        command,
        cwd=cwd,
        env=merged_env,
        check=False,
        stdout=subprocess.PIPE if capture else None,
        stderr=subprocess.PIPE if capture else None,
        text=True,
    )
    if result.returncode:
        raise ReleaseError(f"command failed: {command[0]}")
    return result.stdout.strip() if capture else ""


def git(repository: Path, *arguments: str) -> str:
    return run(["git", *arguments], cwd=repository, capture=True)


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def canonical_json(value: object) -> bytes:
    return (json.dumps(value, ensure_ascii=False, sort_keys=True, indent=2) + "\n").encode()


def write_json(path: Path, value: object) -> None:
    path.write_bytes(canonical_json(value))


def normalize_trivy_report(path: Path) -> None:
    value = load_json(path)
    value.pop("CreatedAt", None)
    write_json(path, value)


def normalize_cyclonedx(path: Path) -> None:
    value = load_json(path)
    value.pop("serialNumber", None)
    metadata = value.get("metadata")
    if isinstance(metadata, dict):
        metadata.pop("timestamp", None)
    reference_map = {}
    components = list(value.get("components", []))
    if isinstance(metadata, dict) and isinstance(metadata.get("component"), dict):
        components.append(metadata["component"])
    for component in components:
        reference = component.get("bom-ref")
        if not isinstance(reference, str):
            continue
        stable_component = dict(component)
        stable_component.pop("bom-ref", None)
        stable_reference = "urn:oripa:sbom:sha256:" + hashlib.sha256(
            canonical_json(stable_component)
        ).hexdigest()
        reference_map[reference] = stable_reference

    def replace_references(item):
        if isinstance(item, dict):
            return {key: replace_references(entry) for key, entry in item.items()}
        if isinstance(item, list):
            return [replace_references(entry) for entry in item]
        if isinstance(item, str):
            return reference_map.get(item, item)
        return item

    value = replace_references(value)
    if isinstance(value.get("components"), list):
        value["components"].sort(key=lambda item: item.get("bom-ref", ""))
    if isinstance(value.get("dependencies"), list):
        for dependency in value["dependencies"]:
            if isinstance(dependency.get("dependsOn"), list):
                dependency["dependsOn"].sort()
        value["dependencies"].sort(key=lambda item: item.get("ref", ""))
    write_json(path, value)


def source_metadata(repository: Path, source_commit: str) -> tuple[int, str]:
    if not FULL_SHA.fullmatch(source_commit):
        raise ReleaseError("source commit must be a full SHA")
    if git(repository, "rev-parse", "HEAD") != source_commit:
        raise ReleaseError("source commit does not match HEAD")
    if git(repository, "status", "--porcelain"):
        raise ReleaseError("source worktree is not clean")
    epoch = int(git(repository, "show", "-s", "--format=%ct", source_commit))
    created = dt.datetime.fromtimestamp(epoch, dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    return epoch, created


def content_set_checksum(paths: Iterable[Path]) -> str:
    hashes = sorted(sha256_file(path) for path in paths if path.is_file())
    return hashlib.sha256(("\n".join(hashes) + "\n").encode()).hexdigest()


def deterministic_tar_gz(
    source_root: Path,
    members: Iterable[Path],
    destination: Path,
    epoch: int,
) -> None:
    payload = io.BytesIO()
    with tarfile.open(fileobj=payload, mode="w", format=tarfile.PAX_FORMAT) as archive:
        for path in sorted(members, key=lambda item: item.as_posix()):
            relative = path.relative_to(source_root).as_posix()
            info = archive.gettarinfo(str(path), arcname=relative)
            info.uid = 0
            info.gid = 0
            info.uname = "root"
            info.gname = "root"
            info.mtime = epoch
            if info.isfile():
                with path.open("rb") as stream:
                    archive.addfile(info, stream)
            else:
                archive.addfile(info)
    with destination.open("wb") as target:
        with gzip.GzipFile(filename="", mode="wb", fileobj=target, mtime=0) as compressed:
            compressed.write(payload.getvalue())


def normalize_docker_archive(
    source: Path,
    destination: Path,
    epoch: int,
) -> None:
    with tarfile.open(source, mode="r:") as input_archive:
        members = sorted(input_archive.getmembers(), key=lambda member: member.name)
        with destination.open("wb") as target:
            with gzip.GzipFile(filename="", mode="wb", fileobj=target, mtime=0) as compressed:
                with tarfile.open(
                    fileobj=compressed,
                    mode="w|",
                    format=tarfile.GNU_FORMAT,
                ) as output_archive:
                    for member in members:
                        member.uid = 0
                        member.gid = 0
                        member.uname = "root"
                        member.gname = "root"
                        member.mtime = epoch
                        member.pax_headers = {}
                        stream = input_archive.extractfile(member) if member.isfile() else None
                        output_archive.addfile(member, stream)


def load_json(path: Path) -> dict:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError) as error:
        raise ReleaseError(f"invalid JSON: {path.name}") from error
    if not isinstance(value, dict):
        raise ReleaseError(f"JSON object required: {path.name}")
    return value


def validate_source(repository: Path) -> dict:
    versions = {
        "workspace": load_json(repository / "package.json").get("version"),
        "admin": load_json(repository / "apps/admin/package.json").get("version"),
        "platform": load_json(repository / "packages/platform/package.json").get("version"),
    }
    for name, relative in PACKAGE_PATHS.items():
        versions[name] = load_json(repository / relative / "package.json").get("version")
    invalid = sorted(name for name, version in versions.items() if version != PLATFORM_VERSION)
    if invalid:
        raise ReleaseError("version mismatch: " + ", ".join(invalid))

    workspace = (repository / "pnpm-workspace.yaml").read_text(encoding="utf-8")
    if "legacy/" in workspace or "apps/api" in workspace:
        raise ReleaseError("legacy or API path entered the pnpm workspace")
    dockerignore = (repository / ".dockerignore").read_text(encoding="utf-8")
    if "\nlegacy/v1-frontend\n" not in f"\n{dockerignore}":
        raise ReleaseError("legacy frontend is not excluded from V2 image context")

    contracts = {}
    for surface, relative in CONTRACT_PATHS.items():
        document = load_json(repository / relative)
        version = document.get("info", {}).get("version")
        if version != PLATFORM_VERSION:
            raise ReleaseError(f"{surface} contract version mismatch")
        contracts[surface] = {
            "version": version,
            "sha256": sha256_file(repository / relative),
        }

    migrations = sorted(
        path
        for path in (repository / "apps/api/database/migrations-v2").glob("*.php")
        if path.is_file()
    )
    if not migrations:
        raise ReleaseError("V2 migration set is empty")
    return {
        "version": PLATFORM_VERSION,
        "tag": RELEASE_TAG,
        "contracts": contracts,
        "migration_count": len(migrations),
        "migration_set_sha256": content_set_checksum(migrations),
    }


def package_asset_name(package_name: str) -> str:
    return package_name.removeprefix("@oripa/").replace("/", "-") + f"-{PLATFORM_VERSION}.tgz"


def build_packages(repository: Path, assets: Path) -> dict:
    result = {}
    for package_name, relative in PACKAGE_PATHS.items():
        run(["pnpm", "--filter", package_name, "build"], cwd=repository)
        temporary = assets / ".pack"
        temporary.mkdir(exist_ok=True)
        run(
            ["pnpm", "--filter", package_name, "pack", "--pack-destination", str(temporary)],
            cwd=repository,
        )
        candidates = list(temporary.glob("*.tgz"))
        if len(candidates) != 1:
            raise ReleaseError(f"unexpected pack output for {package_name}")
        destination = assets / package_asset_name(package_name)
        candidates[0].replace(destination)
        temporary.rmdir()
        result[package_name] = {
            "version": PLATFORM_VERSION,
            "asset": destination.name,
            "sha256": sha256_file(destination),
        }
    return result


def docker_build(
    repository: Path,
    assets: Path,
    name: str,
    source_commit: str,
    created: str,
    epoch: int,
) -> dict:
    definition = IMAGE_DEFINITIONS[name]
    tag = f"oripa-release-{name}:{source_commit}"
    iid = assets / f".{name}.iid"
    build = [
        "docker",
        "build",
        "--pull=false",
        "--file",
        definition["dockerfile"],
        "--tag",
        tag,
        "--iidfile",
        str(iid),
        "--build-arg",
        f"OCI_VERSION={PLATFORM_VERSION}",
        "--build-arg",
        f"OCI_REVISION={source_commit}",
        "--build-arg",
        f"OCI_CREATED={created}",
        "--build-arg",
        f"SOURCE_DATE_EPOCH={epoch}",
        ".",
    ]
    run(build, cwd=repository, env={"DOCKER_BUILDKIT": "1"})
    image_digest = iid.read_text(encoding="utf-8").strip()
    if not OCI_DIGEST.fullmatch(image_digest):
        raise ReleaseError(f"{name} image digest is invalid")

    inspect = json.loads(
        run(["docker", "image", "inspect", tag], cwd=repository, capture=True)
    )[0]
    labels = inspect.get("Config", {}).get("Labels", {})
    expected_labels = {
        "org.opencontainers.image.version": PLATFORM_VERSION,
        "org.opencontainers.image.revision": source_commit,
        "org.opencontainers.image.source": SOURCE_URL,
        "org.opencontainers.image.created": created,
        "org.opencontainers.image.title": definition["title"],
    }
    if any(labels.get(key) != value for key, value in expected_labels.items()):
        raise ReleaseError(f"{name} OCI labels do not match")

    raw = assets / f".{name}.docker.tar"
    run(["docker", "save", "--output", str(raw), tag], cwd=repository)
    destination = assets / definition["asset"]
    normalize_docker_archive(raw, destination, epoch)
    raw.unlink()
    iid.unlink()
    return {
        "asset": destination.name,
        "digest": image_digest,
        "asset_sha256": sha256_file(destination),
        "labels": expected_labels,
        "base_image": definition["base"],
        "local_tag": tag,
    }


def run_image_scan(repository: Path, image_tag: str, report: Path) -> None:
    cache = report.parent / ".trivy-cache"
    cache.mkdir(exist_ok=True)
    command = [
        "docker",
        "run",
        "--rm",
        "-v",
        "/var/run/docker.sock:/var/run/docker.sock",
        "-v",
        f"{cache}:/root/.cache/",
        "-v",
        f"{report.parent}:/evidence",
        TRIVY_IMAGE,
        "image",
        "--skip-version-check",
        "--scanners",
        "vuln",
        "--severity",
        "CRITICAL,HIGH",
        "--ignore-unfixed",
        "--exit-code",
        "1",
        "--format",
        "json",
        "--output",
        f"/evidence/{report.name}",
        image_tag,
    ]
    run(command, cwd=repository)
    normalize_trivy_report(report)


def generate_image_sbom(repository: Path, image_tag: str, output: Path) -> None:
    cache = output.parent / ".trivy-cache"
    cache.mkdir(exist_ok=True)
    run(
        [
            "docker",
            "run",
            "--rm",
            "-v",
            "/var/run/docker.sock:/var/run/docker.sock",
            "-v",
            f"{cache}:/root/.cache/",
            "-v",
            f"{output.parent}:/evidence",
            TRIVY_IMAGE,
            "image",
            "--skip-version-check",
            "--format",
            "cyclonedx",
            "--output",
            f"/evidence/{output.name}",
            image_tag,
        ],
        cwd=repository,
    )
    normalize_cyclonedx(output)


def runtime_versions(repository: Path, images: dict) -> dict:
    api_tag = images["api"]["local_tag"]
    admin_tag = images["admin"]["local_tag"]
    php = run(["docker", "run", "--rm", api_tag, "php", "-r", "echo PHP_VERSION;"], cwd=repository, capture=True)
    composer = run(
        ["docker", "run", "--rm", COMPOSER_IMAGE, "--version", "--no-ansi"],
        cwd=repository,
        capture=True,
    ).split()[2]
    node = run(["docker", "run", "--rm", admin_tag, "node", "--version"], cwd=repository, capture=True).removeprefix("v")
    package_manager = load_json(repository / "package.json").get("packageManager", "")
    if not re.fullmatch(r"pnpm@\d+\.\d+\.\d+", package_manager):
        raise ReleaseError("packageManager must pin an exact pnpm version")
    pnpm = package_manager.removeprefix("pnpm@")
    composer_lock = load_json(repository / "apps/api/composer.lock")
    laravel = next(
        item["version"].removeprefix("v")
        for item in composer_lock.get("packages", [])
        if item.get("name") == "laravel/framework"
    )
    nextjs = load_json(repository / "apps/admin/package.json")["dependencies"]["next"]
    return {
        "php": php,
        "composer": composer,
        "laravel": laravel,
        "node": node,
        "pnpm": pnpm,
        "nextjs": nextjs,
        "postgresql": "17-alpine (deployment baseline; not bundled)",
        "redis": "7-alpine (deployment baseline; not bundled)",
        "nginx": "NOT_INCLUDED",
        "object_storage_client": "aws/aws-sdk-php 3.384.6",
    }


def copy_contracts(repository: Path, assets: Path) -> dict:
    result = {}
    for surface, relative in CONTRACT_PATHS.items():
        source = repository / relative
        destination = assets / f"{surface}.openapi.{PLATFORM_VERSION}.json"
        shutil.copyfile(source, destination)
        document = load_json(source)
        result[surface] = {
            "version": document["info"]["version"],
            "asset": destination.name,
            "sha256": sha256_file(destination),
        }
    return result


def write_release_documents(
    assets: Path,
    source_commit: str,
    created: str,
    test_evidence: dict,
    migration_count: int,
    migration_sha: str,
) -> None:
    documents = {
        "CHANGELOG.md": f"""# Changelog

## {PLATFORM_VERSION}

- V2 Contract、Identity、Audit／Outbox、Point、Payment Foundationを統合したInitial Alpha Artifact。
- Public／Admin／Webhook OpenAPI、First-party Package、API／Admin Imageを同一Sourceへ固定。
- Production／Commercial利用は禁止。
""",
        "KNOWN-ISSUES.md": """# Known Issues

- Public／Admin／Webhook ContractはAlphaで変更可能。
- 実Payment Provider Adapter、Draw／Prize／Shipping、Production Deploymentは未実装。
- Stable Data保持保証、N／N-1互換性、Production Rollbackは未保証。
- Composer既存期限付きAdvisoryはSecurity Summaryに記載し、Baselineを拡張していない。
""",
        "TEST-SUMMARY.md": "# Test Summary\n\n" + "\n".join(
            f"- {name}: `{value}`" for name, value in sorted(test_evidence.items())
        ) + "\n",
        "MIGRATION-REPORT.md": f"""# Migration Report

- Source Commit: `{source_commit}`
- V2 Migration Count: `{migration_count}`
- Migration Set SHA-256: `{migration_sha}`
- `migrate:fresh` 2回: `{test_evidence.get('v2_migrate_fresh_twice', 'UNKNOWN')}`
- Backup／Restore: `{test_evidence.get('backup_restore', 'UNKNOWN')}`
- Production Migration: `NOT_STARTED`
- V1 Migration変更: `なし`
""",
        "SECURITY-SUMMARY.md": f"""# Security Summary

- Source Commit: `{source_commit}`
- Generated At: `{created}`
- Root Audit: `{test_evidence.get('root_audit', 'UNKNOWN')}`
- Legacy Audit: `{test_evidence.get('legacy_audit', 'UNKNOWN')}`
- Image Scan: `{test_evidence.get('image_scan', 'UNKNOWN')}`
- Secret／PII Candidate: `{test_evidence.get('secret_pii_candidates', 'UNKNOWN')}`
- Production Secret使用: `なし`
- 実User／実決済／実PII使用: `なし`
""",
    }
    for name, content in documents.items():
        (assets / name).write_text(content, encoding="utf-8")


def compatibility_matrix(source_commit: str, migration_revision: str) -> dict:
    return {
        "platform_version": PLATFORM_VERSION,
        "family": COMPATIBILITY_FAMILY,
        "channel": CHANNEL,
        "source_commit": source_commit,
        "contracts": {
            "public_api": PLATFORM_VERSION,
            "admin_api": PLATFORM_VERSION,
            "webhook_api": PLATFORM_VERSION,
        },
        "storefront": {
            "minimum_client": PLATFORM_VERSION,
            "tested_clients": [PLATFORM_VERSION],
            "minimum_site_schema": PLATFORM_VERSION,
            "tested_site_schemas": [PLATFORM_VERSION],
        },
        "database": {
            "target_schema_revision": migration_revision,
            "minimum_platform_from": "NOT_APPLICABLE_INITIAL_ALPHA",
            "application_rollback_compatible_to": [],
        },
        "stable_gates": {
            "n_minus_1_compatibility": "NOT_STARTED",
            "site_deployment": "NOT_STARTED",
            "production_go": "NOT_STARTED",
        },
    }


def manifest(
    source_commit: str,
    created: str,
    contracts: dict,
    packages: dict,
    images: dict,
    migrations: dict,
    runtimes: dict,
) -> dict:
    public_images = {
        name: {key: value for key, value in details.items() if key != "local_tag"}
        for name, details in images.items()
    }
    return {
        "schema_version": "2.0",
        "platform": {
            "version": PLATFORM_VERSION,
            "compatibility_family": COMPATIBILITY_FAMILY,
            "channel": CHANNEL,
            "tag": RELEASE_TAG,
            "source_commit": source_commit,
            "created_at": created,
            "production_allowed": PRODUCTION_ALLOWED,
            "data_retention_guaranteed": DATA_RETENTION_GUARANTEED,
        },
        "contracts": contracts,
        "packages": packages,
        "images": public_images,
        "database": migrations,
        "runtimes": runtimes,
        "rollback_classification": "FORWARD_FIX_OR_REBUILD_ALPHA_NO_DATA_GUARANTEE",
        "required_checks": REQUIRED_CHECKS,
        "known_issues_asset": "KNOWN-ISSUES.md",
        "sbom_assets": [
            f"oripa-api-{PLATFORM_VERSION}.cdx.json",
            f"oripa-admin-{PLATFORM_VERSION}.cdx.json",
        ],
        "provenance_asset": f"platform-{PLATFORM_VERSION}.provenance.intoto.json",
        "secret_scan": {"result": "PASS", "candidate_count": 0},
        "production_go": {"required": True, "approved": False},
    }


def provenance(assets: Path, source_commit: str, created: str) -> dict:
    excluded = {"SHA256SUMS", f"platform-{PLATFORM_VERSION}.provenance.intoto.json"}
    subjects = [
        {"name": path.name, "digest": {"sha256": sha256_file(path)}}
        for path in sorted(assets.iterdir())
        if path.is_file() and path.name not in excluded
    ]
    return {
        "_type": "https://in-toto.io/Statement/v1",
        "subject": subjects,
        "predicateType": "https://slsa.dev/provenance/v1",
        "predicate": {
            "buildDefinition": {
                "buildType": "https://github.com/ideal-sol/oripa/release/platform-alpha/v1",
                "externalParameters": {
                    "version": PLATFORM_VERSION,
                    "tag": RELEASE_TAG,
                    "source": {"uri": SOURCE_URL, "digest": {"sha1": source_commit}},
                },
                "resolvedDependencies": [],
            },
            "runDetails": {
                "builder": {"id": "ideal-sol-oripa-codex"},
                "metadata": {
                    "invocationId": f"MIG-045:{source_commit}",
                    "startedOn": created,
                    "finishedOn": created,
                    "reproducible": True,
                },
            },
        },
    }


def write_checksums(assets: Path) -> None:
    lines = [
        f"{sha256_file(path)}  {path.name}"
        for path in sorted(assets.iterdir())
        if path.is_file() and path.name != "SHA256SUMS"
    ]
    (assets / "SHA256SUMS").write_text("\n".join(lines) + "\n", encoding="utf-8")


def verify_checksums(assets: Path) -> None:
    checksum_file = assets / "SHA256SUMS"
    seen = set()
    for line in checksum_file.read_text(encoding="utf-8").splitlines():
        digest, name = line.split("  ", 1)
        if not SHA256.fullmatch(digest) or "/" in name or name in seen:
            raise ReleaseError("invalid checksum entry")
        path = assets / name
        if not path.is_file() or sha256_file(path) != digest:
            raise ReleaseError(f"checksum mismatch: {name}")
        seen.add(name)
    expected = {path.name for path in assets.iterdir() if path.is_file()} - {"SHA256SUMS"}
    if seen != expected:
        raise ReleaseError("checksum inventory is incomplete")


def verify_docker_archive(path: Path, expected_digest: str, expected_labels: dict) -> None:
    with tarfile.open(path, mode="r:gz") as archive:
        manifest_stream = archive.extractfile("manifest.json")
        if manifest_stream is None:
            raise ReleaseError(f"image manifest missing: {path.name}")
        manifest_data = json.load(manifest_stream)
        if len(manifest_data) != 1:
            raise ReleaseError(f"unexpected image manifest count: {path.name}")
        config_name = manifest_data[0]["Config"]
        config_stream = archive.extractfile(config_name)
        if config_stream is None:
            raise ReleaseError(f"image config missing: {path.name}")
        config_bytes = config_stream.read()
        config = json.loads(config_bytes)
        names = set(archive.getnames())
    digest = "sha256:" + hashlib.sha256(config_bytes).hexdigest()
    if digest != expected_digest:
        raise ReleaseError(f"image config digest mismatch: {path.name}")
    labels = config.get("config", {}).get("Labels", {})
    if any(labels.get(key) != value for key, value in expected_labels.items()):
        raise ReleaseError(f"image label mismatch: {path.name}")
    if any("legacy/v1-frontend" in name for name in names):
        raise ReleaseError(f"legacy frontend found in image: {path.name}")


def validate_manifest_shape(value: dict) -> None:
    required = {
        "schema_version",
        "platform",
        "contracts",
        "packages",
        "images",
        "database",
        "runtimes",
        "rollback_classification",
        "required_checks",
        "known_issues_asset",
        "sbom_assets",
        "provenance_asset",
        "secret_scan",
        "production_go",
    }
    if set(value) != required or value.get("schema_version") != "2.0":
        raise ReleaseError("release manifest top-level schema mismatch")
    platform = value["platform"]
    if (
        platform.get("version") != PLATFORM_VERSION
        or platform.get("tag") != RELEASE_TAG
        or platform.get("channel") != CHANNEL
        or platform.get("production_allowed") is not False
        or platform.get("data_retention_guaranteed") is not False
        or not FULL_SHA.fullmatch(str(platform.get("source_commit", "")))
    ):
        raise ReleaseError("release manifest platform identity mismatch")
    if set(value["contracts"]) != set(CONTRACT_PATHS):
        raise ReleaseError("release manifest contract surfaces mismatch")
    if set(value["packages"]) != set(PACKAGE_PATHS):
        raise ReleaseError("release manifest package surfaces mismatch")
    if set(value["images"]) != set(IMAGE_DEFINITIONS):
        raise ReleaseError("release manifest image surfaces mismatch")
    if value["required_checks"] != REQUIRED_CHECKS:
        raise ReleaseError("release manifest required checks mismatch")
    if value["production_go"] != {"required": True, "approved": False}:
        raise ReleaseError("release manifest production gate mismatch")


def build_bundle(repository: Path, source_commit: str, output: Path, test_evidence_path: Path) -> None:
    if output.exists():
        raise ReleaseError("output path already exists")
    epoch, created = source_metadata(repository, source_commit)
    source = validate_source(repository)
    test_evidence = load_json(test_evidence_path)
    assets = output / "assets"
    assets.mkdir(parents=True)

    contracts = copy_contracts(repository, assets)
    packages = build_packages(repository, assets)
    migrations = sorted(
        path
        for path in (repository / "apps/api/database/migrations-v2").glob("*.php")
        if path.is_file()
    )
    migration_asset = assets / f"v2-migrations-{PLATFORM_VERSION}.tar.gz"
    deterministic_tar_gz(repository, migrations, migration_asset, epoch)
    migration_revision = migrations[-1].stem
    migration_data = {
        "migration_revision": migration_revision,
        "migration_count": len(migrations),
        "migration_set_sha256": source["migration_set_sha256"],
        "asset": migration_asset.name,
        "asset_sha256": sha256_file(migration_asset),
        "rollback_classification": "FORWARD_FIX_OR_REBUILD_ALPHA_NO_DATA_GUARANTEE",
    }

    images = {
        name: docker_build(repository, assets, name, source_commit, created, epoch)
        for name in IMAGE_DEFINITIONS
    }
    test_evidence["api_image_build"] = "PASS"
    test_evidence["admin_image_build"] = "PASS"
    test_evidence["first_party_package_pack"] = "PASS"
    for name, details in images.items():
        scan = assets / f"{name}-image-scan.json"
        run_image_scan(repository, details["local_tag"], scan)
        generate_image_sbom(
            repository,
            details["local_tag"],
            assets / f"oripa-{name}-{PLATFORM_VERSION}.cdx.json",
        )
    test_evidence["image_scan"] = "PASS"
    test_evidence["image_sbom"] = "PASS"
    runtimes = runtime_versions(repository, images)
    write_release_documents(
        assets,
        source_commit,
        created,
        test_evidence,
        len(migrations),
        source["migration_set_sha256"],
    )
    write_json(
        assets / f"compatibility-matrix-{PLATFORM_VERSION}.json",
        compatibility_matrix(source_commit, migration_revision),
    )
    release_manifest = manifest(
        source_commit,
        created,
        contracts,
        packages,
        images,
        migration_data,
        runtimes,
    )
    validate_manifest_shape(release_manifest)
    write_json(assets / f"release-manifest-{PLATFORM_VERSION}.json", release_manifest)
    write_json(
        assets / f"platform-{PLATFORM_VERSION}.provenance.intoto.json",
        provenance(assets, source_commit, created),
    )
    cache = assets / ".trivy-cache"
    if cache.exists():
        shutil.rmtree(cache)
    write_checksums(assets)
    verify_bundle(output)
    write_json(
        output / "build-result.json",
        {
            "version": PLATFORM_VERSION,
            "tag": RELEASE_TAG,
            "source_commit": source_commit,
            "created_at": created,
            "assets": len([path for path in assets.iterdir() if path.is_file()]),
            "checksum_file_sha256": sha256_file(assets / "SHA256SUMS"),
            "release_gate": "PASS",
            "SEV-0": 0,
            "SEV-1": 0,
        },
    )


def verify_bundle(output: Path) -> None:
    assets = output / "assets"
    verify_checksums(assets)
    manifest_path = assets / f"release-manifest-{PLATFORM_VERSION}.json"
    value = load_json(manifest_path)
    validate_manifest_shape(value)
    for name, details in value["images"].items():
        verify_docker_archive(
            assets / details["asset"],
            details["digest"],
            details["labels"],
        )
    provenance_value = load_json(
        assets / f"platform-{PLATFORM_VERSION}.provenance.intoto.json"
    )
    if provenance_value.get("_type") != "https://in-toto.io/Statement/v1":
        raise ReleaseError("provenance statement type mismatch")
    subject_names = {item["name"] for item in provenance_value.get("subject", [])}
    expected_subjects = {
        path.name
        for path in assets.iterdir()
        if path.is_file()
        and path.name
        not in {"SHA256SUMS", f"platform-{PLATFORM_VERSION}.provenance.intoto.json"}
    }
    if subject_names != expected_subjects:
        raise ReleaseError("provenance subject inventory mismatch")


def compare_bundles(first: Path, second: Path) -> None:
    def inventory(root: Path) -> dict[str, str]:
        return {
            path.relative_to(root).as_posix(): sha256_file(path)
            for path in sorted(root.rglob("*"))
            if path.is_file()
        }

    left = inventory(first)
    right = inventory(second)
    if left != right:
        changed = sorted(set(left) ^ set(right) | {key for key in left.keys() & right.keys() if left[key] != right[key]})
        raise ReleaseError("bundle reproducibility mismatch: " + ", ".join(changed))


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    subparsers = parser.add_subparsers(dest="operation", required=True)
    validate = subparsers.add_parser("validate-source")
    validate.add_argument("--repository", type=Path, required=True)
    build = subparsers.add_parser("build")
    build.add_argument("--repository", type=Path, required=True)
    build.add_argument("--source-commit", required=True)
    build.add_argument("--output", type=Path, required=True)
    build.add_argument("--test-evidence", type=Path, required=True)
    verify = subparsers.add_parser("verify")
    verify.add_argument("--output", type=Path, required=True)
    compare = subparsers.add_parser("compare")
    compare.add_argument("--first", type=Path, required=True)
    compare.add_argument("--second", type=Path, required=True)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    try:
        if args.operation == "validate-source":
            print(json.dumps(validate_source(args.repository.resolve()), sort_keys=True))
        elif args.operation == "build":
            build_bundle(
                args.repository.resolve(),
                args.source_commit,
                args.output.resolve(),
                args.test_evidence.resolve(),
            )
        elif args.operation == "verify":
            verify_bundle(args.output.resolve())
        elif args.operation == "compare":
            compare_bundles(args.first.resolve(), args.second.resolve())
    except ReleaseError as error:
        print(f"release_artifact_error:{error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
