#!/usr/bin/env python3
"""Build and verify an immutable Storefront contract artifact."""

from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tarfile


GOVERNANCE_PATH = Path("manifests/storefront-contract-releases.json")
PUBLIC_OPENAPI_PATH = Path("openapi/bundled/public.openapi.json")
PACKAGE_PATHS = {
    "@oripa/storefront-client": Path("packages/storefront-client"),
    "@oripa/site-schema": Path("packages/site-schema"),
    "@oripa/storefront-testkit": Path("packages/storefront-testkit"),
}
FULL_SHA = re.compile(r"^[0-9a-f]{40}$")
SHA256 = re.compile(r"^[0-9a-f]{64}$")
ALPHA_VERSION = re.compile(r"^(?P<family>[0-9]+)\.0\.0-alpha\.(?P<sequence>[0-9]+)$")


class ArtifactError(RuntimeError):
    """A deterministic Storefront artifact validation failure."""


def load_json(path: Path) -> dict:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError) as error:
        raise ArtifactError(f"invalid JSON: {path}") from error
    if not isinstance(value, dict):
        raise ArtifactError(f"JSON object required: {path}")
    return value


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def alpha_identity(version: object) -> tuple[int, int]:
    match = ALPHA_VERSION.fullmatch(str(version))
    if not match:
        raise ArtifactError(f"invalid alpha version: {version}")
    return int(match.group("family")), int(match.group("sequence"))


def run(command: list[str], *, cwd: Path, capture: bool = False) -> str:
    result = subprocess.run(
        command,
        cwd=cwd,
        check=False,
        stdout=subprocess.PIPE if capture else None,
        stderr=subprocess.PIPE if capture else None,
        text=True,
    )
    if result.returncode:
        raise ArtifactError(f"command failed: {command[0]}")
    return result.stdout.strip() if capture else ""


def validate_governance(value: dict) -> dict:
    required = {
        "schema_version",
        "compatibility_family",
        "channel",
        "immutable_history",
        "latest_immutable",
        "candidate",
    }
    if set(value) != required or value.get("schema_version") != "1.0":
        raise ArtifactError("release governance schema mismatch")
    family = value.get("compatibility_family")
    if family != 2 or value.get("channel") != "alpha":
        raise ArtifactError("release governance family or channel mismatch")

    history = value.get("immutable_history")
    latest = value.get("latest_immutable")
    candidate = value.get("candidate")
    if not isinstance(history, list) or not history or not isinstance(latest, dict) or not isinstance(candidate, dict):
        raise ArtifactError("release governance records missing")
    history_versions = [row.get("bundle_version") for row in history if isinstance(row, dict)]
    if len(history_versions) != len(history) or len(set(history_versions)) != len(history_versions):
        raise ArtifactError("immutable history version inventory invalid")
    for row in history:
        if (
            not SHA256.fullmatch(str(row.get("manifest_sha256", "")))
            or not FULL_SHA.fullmatch(str(row.get("source_commit", "")))
            or row.get("handoff_status") not in {"released", "retired"}
        ):
            raise ArtifactError("immutable history evidence invalid")

    latest_version = latest.get("bundle_version")
    candidate_version = candidate.get("bundle_version")
    latest_family, latest_sequence = alpha_identity(latest_version)
    candidate_family, candidate_sequence = alpha_identity(candidate_version)
    if (
        latest_version != history_versions[-1]
        or latest.get("manifest_sha256") != history[-1].get("manifest_sha256")
        or latest.get("source_commit") != history[-1].get("source_commit")
        or candidate.get("predecessor_bundle_version") != latest_version
    ):
        raise ArtifactError("candidate predecessor mismatch")
    if candidate_version in history_versions or candidate_sequence <= latest_sequence:
        raise ArtifactError("immutable existing version reissue prohibited")
    if candidate_family != latest_family or candidate_family != family or candidate_sequence != latest_sequence + 1:
        raise ArtifactError("candidate must be the next alpha bundle version")
    release_mode = candidate.get("release_mode")
    if release_mode not in {"package-only", "contract-additive"}:
        raise ArtifactError("unsupported release mode")

    latest_packages = latest.get("packages")
    candidate_packages = candidate.get("packages")
    if set(latest_packages or {}) != set(PACKAGE_PATHS) or set(candidate_packages or {}) != set(PACKAGE_PATHS):
        raise ArtifactError("package inventory mismatch")
    published = set()
    for name in PACKAGE_PATHS:
        previous = latest_packages[name]
        current = candidate_packages[name]
        alpha_identity(previous.get("version"))
        component_family, _ = alpha_identity(current.get("version"))
        if (
            component_family != family
            or not SHA256.fullmatch(str(previous.get("sha256", "")))
            or (
                name == "@oripa/site-schema"
                and not FULL_SHA.fullmatch(str(previous.get("source_tree", "")))
            )
        ):
            raise ArtifactError(f"{name}: immutable package evidence invalid")
        disposition = current.get("disposition")
        if disposition == "publish":
            if current.get("version") != candidate_version:
                raise ArtifactError(f"{name}: published package version must equal bundle version")
            published.add(name)
        elif disposition == "reference":
            if (
                current.get("version") != previous.get("version")
                or current.get("sha256") != previous.get("sha256")
                or current.get("source_bundle_version") != latest_version
                or current.get("source_tree") != previous.get("source_tree")
            ):
                raise ArtifactError(f"{name}: immutable package reference mismatch")
        else:
            raise ArtifactError(f"{name}: package disposition invalid")
    if published != {"@oripa/storefront-client", "@oripa/storefront-testkit"}:
        raise ArtifactError("package-only release set is not approved")

    contracts = candidate.get("contract_versions")
    applications = candidate.get("application_versions")
    if set(contracts or {}) != {"public", "admin", "webhook"} or set(applications or {}) != {"workspace", "admin"}:
        raise ArtifactError("platform version inventory mismatch")
    for version in [candidate.get("platform_version"), *contracts.values(), *applications.values()]:
        component_family, _ = alpha_identity(version)
        if component_family != family:
            raise ArtifactError("component compatibility family mismatch")
    public = latest.get("public_openapi")
    if (
        not isinstance(public, dict)
        or not SHA256.fullmatch(str(public.get("sha256", "")))
    ):
        raise ArtifactError("public OpenAPI immutable reference mismatch")
    public_family, _ = alpha_identity(public.get("version"))
    if public_family != family:
        raise ArtifactError("public OpenAPI compatibility family mismatch")
    if release_mode == "package-only":
        if contracts["public"] != public.get("version"):
            raise ArtifactError("public OpenAPI immutable reference mismatch")
    elif (
        contracts["public"] != candidate_version
        or contracts["admin"] != candidate_version
        or contracts["webhook"] != candidate_version
        or not SHA256.fullmatch(str(candidate.get("public_openapi_sha256", "")))
        or candidate.get("public_api_operation_count") != 62
    ):
        raise ArtifactError("additive contract candidate mismatch")
    client = candidate_packages["@oripa/storefront-client"]
    schema = candidate_packages["@oripa/site-schema"]
    testkit = candidate_packages["@oripa/storefront-testkit"]
    if client.get("minimum_public_api_contract") != contracts["public"]:
        raise ArtifactError("client Public API compatibility mismatch")
    if (
        testkit.get("storefront_client_version") != client.get("version")
        or testkit.get("site_schema_version") != schema.get("version")
    ):
        raise ArtifactError("testkit package compatibility mismatch")
    return value


def governance(repository: Path) -> dict:
    return validate_governance(load_json(repository / GOVERNANCE_PATH))


def git_tree(repository: Path, relative: Path) -> str:
    return run(["git", "rev-parse", f"HEAD:{relative.as_posix()}"], cwd=repository, capture=True)


def validate_source(repository: Path) -> dict:
    value = governance(repository)
    candidate = value["candidate"]
    packages = candidate["packages"]
    source_versions = {
        "workspace": load_json(repository / "package.json").get("version"),
        "admin": load_json(repository / "apps/admin/package.json").get("version"),
        "platform": load_json(repository / "packages/platform/package.json").get("version"),
    }
    expected_versions = {
        **candidate["application_versions"],
        "platform": candidate["platform_version"],
    }
    if source_versions != expected_versions:
        raise ArtifactError("Platform/Application version mismatch")
    for name, relative in PACKAGE_PATHS.items():
        package = load_json(repository / relative / "package.json")
        if package.get("name") != name or package.get("version") != packages[name]["version"]:
            raise ArtifactError(f"{name}: source package version mismatch")

    contracts = {}
    for surface, version in candidate["contract_versions"].items():
        path = repository / "openapi" / "bundled" / f"{surface}.openapi.json"
        actual = load_json(path).get("info", {}).get("version")
        if actual != version:
            raise ArtifactError(f"{surface} contract version mismatch")
        contracts[surface] = {"version": actual, "sha256": sha256_file(path)}
    if candidate["release_mode"] == "package-only":
        expected_public_sha256 = value["latest_immutable"]["public_openapi"]["sha256"]
    else:
        expected_public_sha256 = candidate["public_openapi_sha256"]
    if contracts["public"]["sha256"] != expected_public_sha256:
        raise ArtifactError("candidate Public OpenAPI content mismatch")

    schema = packages["@oripa/site-schema"]
    if git_tree(repository, PACKAGE_PATHS["@oripa/site-schema"]) != schema["source_tree"]:
        raise ArtifactError("referenced Site Schema source changed")
    client_package = load_json(repository / PACKAGE_PATHS["@oripa/storefront-client"] / "package.json")
    testkit_package = load_json(repository / PACKAGE_PATHS["@oripa/storefront-testkit"] / "package.json")
    if client_package.get("oripaCompatibility", {}).get("minimumPublicApiContract") != contracts["public"]["version"]:
        raise ArtifactError("client package compatibility metadata mismatch")
    if testkit_package.get("dependencies") != {
        "@oripa/site-schema": f"workspace:{schema['version']}",
        "@oripa/storefront-client": f"workspace:{packages['@oripa/storefront-client']['version']}",
    }:
        raise ArtifactError("testkit dependency versions mismatch")
    if testkit_package.get("oripaCompatibility", {}) != {
        "family": value["compatibility_family"],
        "storefrontClientVersion": packages["@oripa/storefront-client"]["version"],
        "siteSchemaVersion": schema["version"],
        "publicApiOperationCount": candidate.get("public_api_operation_count", 54),
    }:
        raise ArtifactError("testkit compatibility metadata mismatch")
    return {
        "bundle_version": candidate["bundle_version"],
        "release_mode": candidate["release_mode"],
        "platform_version": candidate["platform_version"],
        "contracts": contracts,
        "packages": {name: details["version"] for name, details in packages.items()},
    }


def read_package_manifest(path: Path) -> dict:
    with tarfile.open(path, mode="r:gz") as archive:
        stream = archive.extractfile("package/package.json")
        if stream is None:
            raise ArtifactError(f"package manifest missing: {path.name}")
        try:
            value = json.load(stream)
        except (UnicodeError, json.JSONDecodeError) as error:
            raise ArtifactError(f"package manifest invalid: {path.name}") from error
    if not isinstance(value, dict):
        raise ArtifactError(f"package manifest invalid: {path.name}")
    return value


def write_json(path: Path, value: object) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, sort_keys=True, indent=2) + "\n", encoding="utf-8")


def create_manifest(repository: Path, source_commit: str, created_at: str, assets: dict[str, dict]) -> dict:
    value = governance(repository)
    candidate = value["candidate"]
    package_rows = []
    for name, details in candidate["packages"].items():
        if details["disposition"] == "publish":
            row = {"name": name, "version": details["version"], "disposition": "published", **assets[name]}
        else:
            row = {
                "name": name,
                "version": details["version"],
                "disposition": "referenced",
                "sha256": details["sha256"],
                "source_bundle_version": details["source_bundle_version"],
            }
        package_rows.append(row)
    public = repository / PUBLIC_OPENAPI_PATH
    return {
        "schema_version": "2.0",
        "task_id": os.environ.get("INPUT_TASK_ID", "GOVERNED_TASK"),
        "generated_at": created_at,
        "source_commit": source_commit,
        "bundle": {
            "version": candidate["bundle_version"],
            "predecessor": candidate["predecessor_bundle_version"],
            "release_mode": candidate["release_mode"],
            "immutable": True,
        },
        "platform": {
            "version": candidate["platform_version"],
            "application_versions": candidate["application_versions"],
        },
        "public_openapi": {
            "version": candidate["contract_versions"]["public"],
            "file": PUBLIC_OPENAPI_PATH.name,
            "sha256": sha256_file(public),
            "compatibility_family": value["compatibility_family"],
        "breaking_change": False,
        },
        "packages": package_rows,
        "toolchain": {"node": "22.22.3", "pnpm": "10.12.1"},
        "installation": {
            "registry_publish_performed": False,
            "workspace_dependency_required": False,
            "consumer_resolution": "pin published tarballs and retain referenced package digests from the predecessor bundle",
        },
    }


def write_checksums(output: Path) -> None:
    paths = sorted(path for path in output.iterdir() if path.is_file() and path.name not in {"SHA256SUMS", "artifact-manifest.json"})
    (output / "SHA256SUMS").write_text(
        "".join(f"{sha256_file(path)}  {path.name}\n" for path in paths), encoding="ascii"
    )


def verify_checksums(output: Path) -> set[str]:
    seen = set()
    for line in (output / "SHA256SUMS").read_text(encoding="ascii").splitlines():
        digest, name = line.split("  ", 1)
        if not SHA256.fullmatch(digest) or "/" in name or name in seen:
            raise ArtifactError("checksum inventory invalid")
        path = output / name
        if not path.is_file() or sha256_file(path) != digest:
            raise ArtifactError(f"checksum mismatch: {name}")
        seen.add(name)
    return seen


def verify_manifest(repository: Path, output: Path) -> dict:
    value = governance(repository)
    candidate = value["candidate"]
    manifest = load_json(output / "artifact-manifest.json")
    if manifest.get("schema_version") != "2.0" or manifest.get("bundle") != {
        "version": candidate["bundle_version"],
        "predecessor": candidate["predecessor_bundle_version"],
        "release_mode": candidate["release_mode"],
        "immutable": True,
    }:
        raise ArtifactError("artifact manifest bundle identity mismatch")
    if not FULL_SHA.fullmatch(str(manifest.get("source_commit", ""))):
        raise ArtifactError("artifact manifest source commit invalid")
    if manifest.get("platform") != {
        "version": candidate["platform_version"],
        "application_versions": candidate["application_versions"],
    }:
        raise ArtifactError("artifact manifest Platform identity mismatch")
    public = manifest.get("public_openapi", {})
    expected_public_sha256 = (
        value["latest_immutable"]["public_openapi"]["sha256"]
        if candidate["release_mode"] == "package-only"
        else candidate["public_openapi_sha256"]
    )
    if (
        public.get("version") != candidate["contract_versions"]["public"]
        or public.get("sha256") != expected_public_sha256
        or public.get("file") != PUBLIC_OPENAPI_PATH.name
        or sha256_file(output / PUBLIC_OPENAPI_PATH.name) != public.get("sha256")
    ):
        raise ArtifactError("artifact manifest Public OpenAPI mismatch")

    rows = manifest.get("packages")
    if not isinstance(rows, list) or {row.get("name") for row in rows if isinstance(row, dict)} != set(PACKAGE_PATHS):
        raise ArtifactError("artifact manifest package inventory mismatch")
    expected_files = {"artifact-manifest.json", "SHA256SUMS", PUBLIC_OPENAPI_PATH.name}
    for row in rows:
        details = candidate["packages"][row["name"]]
        if row.get("version") != details["version"]:
            raise ArtifactError(f"{row['name']}: artifact package version mismatch")
        if details["disposition"] == "publish":
            path = output / str(row.get("file", ""))
            if row.get("disposition") != "published" or not path.is_file() or sha256_file(path) != row.get("sha256"):
                raise ArtifactError(f"{row['name']}: published package mismatch")
            package = read_package_manifest(path)
            if package.get("name") != row["name"] or package.get("version") != row["version"]:
                raise ArtifactError(f"{row['name']}: tarball identity mismatch")
            expected_files.add(path.name)
        elif row != {
            "name": row["name"],
            "version": details["version"],
            "disposition": "referenced",
            "sha256": details["sha256"],
            "source_bundle_version": details["source_bundle_version"],
        }:
            raise ArtifactError(f"{row['name']}: referenced package mismatch")
    actual_files = {path.name for path in output.iterdir() if path.is_file()}
    if actual_files != expected_files:
        raise ArtifactError("artifact file inventory mismatch")
    checksum_files = verify_checksums(output)
    if checksum_files != expected_files - {"artifact-manifest.json", "SHA256SUMS"}:
        raise ArtifactError("checksum inventory incomplete")
    return manifest


def build(repository: Path, output: Path, source_commit: str) -> None:
    if output.exists():
        raise ArtifactError("output path already exists")
    if not FULL_SHA.fullmatch(source_commit) or run(["git", "rev-parse", "HEAD"], cwd=repository, capture=True) != source_commit:
        raise ArtifactError("source commit does not match HEAD")
    validate_source(repository)
    candidate = governance(repository)["candidate"]
    output.mkdir(parents=True)
    assets = {}
    run(["pnpm", "--filter", "@oripa/site-schema", "build"], cwd=repository)
    for name, details in candidate["packages"].items():
        if details["disposition"] != "publish":
            continue
        run(["pnpm", "--filter", name, "build"], cwd=repository)
        existing = set(output.glob("*.tgz"))
        run(["pnpm", "--filter", name, "pack", "--pack-destination", str(output)], cwd=repository)
        matches = list(set(output.glob("*.tgz")) - existing)
        if len(matches) != 1:
            raise ArtifactError(f"{name}: unexpected package output")
        package = read_package_manifest(matches[0])
        if package.get("name") != name or package.get("version") != details["version"]:
            raise ArtifactError(f"{name}: package output identity mismatch")
        assets[name] = {"file": matches[0].name, "sha256": sha256_file(matches[0]), "browser_compatible": True}
    shutil.copyfile(repository / PUBLIC_OPENAPI_PATH, output / PUBLIC_OPENAPI_PATH.name)
    created = run(["git", "show", "-s", "--format=%cI", source_commit], cwd=repository, capture=True)
    generated = dt.datetime.fromisoformat(created).astimezone(dt.timezone.utc)
    write_json(output / "artifact-manifest.json", create_manifest(repository, source_commit, generated.replace(microsecond=0).isoformat().replace("+00:00", "Z"), assets))
    write_checksums(output)
    verify_manifest(repository, output)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    subparsers = parser.add_subparsers(dest="operation", required=True)
    validate = subparsers.add_parser("validate-source")
    validate.add_argument("--repository", required=True, type=Path)
    build_parser = subparsers.add_parser("build")
    build_parser.add_argument("--repository", required=True, type=Path)
    build_parser.add_argument("--output", required=True, type=Path)
    build_parser.add_argument("--source-commit", required=True)
    verify = subparsers.add_parser("verify")
    verify.add_argument("--repository", required=True, type=Path)
    verify.add_argument("--output", required=True, type=Path)
    return parser.parse_args()


def main() -> int:
    arguments = parse_args()
    try:
        repository = arguments.repository.resolve()
        if arguments.operation == "validate-source":
            print(json.dumps(validate_source(repository), sort_keys=True))
        elif arguments.operation == "build":
            build(repository, arguments.output.resolve(), arguments.source_commit)
        else:
            verify_manifest(repository, arguments.output.resolve())
    except ArtifactError as error:
        print(f"storefront_artifact_error:{error}", file=os.sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
