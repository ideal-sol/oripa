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


def parse_git_time(value: str) -> dt.datetime:
    try:
        return dt.datetime.fromisoformat(value.replace("Z", "+00:00")).astimezone(
            dt.timezone.utc
        )
    except ValueError as error:
        raise ArtifactError("source commit timestamp invalid") from error


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


def validate_immutable_release(value: dict) -> None:
    required = {
        "bundle_version",
        "manifest_sha256",
        "source_commit",
        "handoff_status",
        "release_mode",
        "platform_version",
        "application_versions",
        "contract_versions",
        "public_openapi",
        "packages",
    }
    if set(value) != required or value["handoff_status"] != "released":
        raise ArtifactError("immutable release record invalid")
    alpha_identity(value["bundle_version"])
    if (
        not SHA256.fullmatch(str(value["manifest_sha256"]))
        or not FULL_SHA.fullmatch(str(value["source_commit"]))
        or value["release_mode"] not in {"package-only", "contract-additive"}
    ):
        raise ArtifactError("immutable release evidence invalid")
    if set(value["application_versions"]) != {"workspace", "admin"} or set(
        value["contract_versions"]
    ) != {"public", "admin", "webhook"}:
        raise ArtifactError("immutable release version inventory invalid")
    public = value["public_openapi"]
    if (
        set(public) != {"version", "sha256", "operation_count"}
        or not SHA256.fullmatch(str(public["sha256"]))
        or not isinstance(public["operation_count"], int)
        or public["operation_count"] <= 0
    ):
        raise ArtifactError("immutable Public OpenAPI evidence invalid")
    for version in [
        value["platform_version"],
        *value["application_versions"].values(),
        *value["contract_versions"].values(),
        public["version"],
    ]:
        alpha_identity(version)
    packages = value["packages"]
    if set(packages) != set(PACKAGE_PATHS):
        raise ArtifactError("immutable package inventory mismatch")
    for name, package in packages.items():
        alpha_identity(package.get("version"))
        if not SHA256.fullmatch(str(package.get("sha256", ""))):
            raise ArtifactError(f"{name}: immutable package digest invalid")
    client = packages["@oripa/storefront-client"]
    schema = packages["@oripa/site-schema"]
    testkit = packages["@oripa/storefront-testkit"]
    capabilities = client.get("required_capabilities")
    if (
        not isinstance(capabilities, list)
        or capabilities != sorted(set(capabilities))
        or client.get("minimum_public_api_contract") != public["version"]
        or not FULL_SHA.fullmatch(str(schema.get("source_tree", "")))
        or not alpha_identity(schema.get("source_bundle_version"))
        or testkit.get("storefront_client_version") != client["version"]
        or testkit.get("site_schema_version") != schema["version"]
        or testkit.get("public_api_operation_count") != public["operation_count"]
    ):
        raise ArtifactError("immutable package compatibility metadata invalid")


def release_source(value: dict) -> dict:
    candidate = value.get("candidate")
    if isinstance(candidate, dict):
        return candidate
    latest = value["latest_immutable"]
    return {
        "release_state": "released",
        "bundle_version": latest["bundle_version"],
        "release_mode": latest["release_mode"],
        "platform_version": latest["platform_version"],
        "application_versions": latest["application_versions"],
        "contract_versions": latest["contract_versions"],
        "public_openapi_sha256": latest["public_openapi"]["sha256"],
        "public_api_operation_count": latest["public_openapi"]["operation_count"],
        "packages": latest["packages"],
    }


def validate_governance(value: dict) -> dict:
    required = {
        "schema_version",
        "compatibility_family",
        "channel",
        "immutable_history",
        "latest_immutable",
        "candidate",
    }
    if set(value) != required or value.get("schema_version") != "2.0":
        raise ArtifactError("release governance schema mismatch")
    family = value.get("compatibility_family")
    if family != 2 or value.get("channel") != "alpha":
        raise ArtifactError("release governance family or channel mismatch")

    history = value.get("immutable_history")
    latest = value.get("latest_immutable")
    candidate = value.get("candidate")
    if not isinstance(history, list) or not history or not isinstance(latest, dict):
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

    validate_immutable_release(latest)
    latest_version = latest.get("bundle_version")
    latest_family, latest_sequence = alpha_identity(latest_version)
    if latest != history[-1]:
        raise ArtifactError("latest immutable release mismatch")
    if candidate is None:
        return value
    if not isinstance(candidate, dict) or candidate.get("release_state") != "pending":
        raise ArtifactError("release candidate state invalid")
    candidate_version = candidate.get("bundle_version")
    candidate_family, candidate_sequence = alpha_identity(candidate_version)
    if candidate.get("predecessor_bundle_version") != latest_version:
        raise ArtifactError("candidate predecessor mismatch")
    if candidate_version in history_versions or candidate_sequence <= latest_sequence:
        raise ArtifactError("immutable existing version reissue prohibited")
    if candidate_family != latest_family or candidate_family != family or candidate_sequence != latest_sequence + 1:
        raise ArtifactError("candidate must be the next alpha bundle version")
    release_mode = candidate.get("release_mode")
    if release_mode not in {"package-only", "contract-additive"}:
        raise ArtifactError("unsupported release mode")

    latest_packages = latest["packages"]
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
                or current.get("source_bundle_version") != previous.get("source_bundle_version")
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
    public = latest["public_openapi"]
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
    else:
        public_family, public_sequence = alpha_identity(public["version"])
        contract_versions = {alpha_identity(version) for version in contracts.values()}
        if (
            len(contract_versions) != 1
            or contract_versions != {(public_family, public_sequence + 1)}
            or not SHA256.fullmatch(str(candidate.get("public_openapi_sha256", "")))
            or candidate.get("public_openapi_sha256") == public["sha256"]
            or not isinstance(candidate.get("public_api_operation_count"), int)
            or candidate["public_api_operation_count"] <= public["operation_count"]
        ):
            raise ArtifactError("additive contract candidate mismatch")
    client = candidate_packages["@oripa/storefront-client"]
    schema = candidate_packages["@oripa/site-schema"]
    testkit = candidate_packages["@oripa/storefront-testkit"]
    if client.get("minimum_public_api_contract") != contracts["public"]:
        raise ArtifactError("client Public API compatibility mismatch")
    if (
        client.get("required_capabilities") != sorted(set(client.get("required_capabilities", [])))
        or "payment.fincode.v2" not in client.get("required_capabilities", [])
        or testkit.get("storefront_client_version") != client.get("version")
        or testkit.get("site_schema_version") != schema.get("version")
        or testkit.get("public_api_operation_count") != candidate.get("public_api_operation_count")
    ):
        raise ArtifactError("testkit package compatibility mismatch")
    return value


def governance(repository: Path) -> dict:
    return validate_governance(load_json(repository / GOVERNANCE_PATH))


def pending_candidate(repository: Path) -> dict:
    candidate = governance(repository).get("candidate")
    if not isinstance(candidate, dict):
        raise ArtifactError("no pending Storefront artifact candidate")
    return candidate


def verification_target(value: dict) -> dict:
    candidate = value.get("candidate")
    if isinstance(candidate, dict):
        return candidate
    latest = value["latest_immutable"]
    history = value["immutable_history"]
    packages = {
        name: {
            **details,
            "disposition": "reference" if name == "@oripa/site-schema" else "publish",
        }
        for name, details in latest["packages"].items()
    }
    return {
        "release_state": "released",
        "bundle_version": latest["bundle_version"],
        "predecessor_bundle_version": history[-2]["bundle_version"],
        "release_mode": latest["release_mode"],
        "platform_version": latest["platform_version"],
        "application_versions": latest["application_versions"],
        "contract_versions": latest["contract_versions"],
        "public_openapi_sha256": latest["public_openapi"]["sha256"],
        "public_api_operation_count": latest["public_openapi"]["operation_count"],
        "packages": packages,
        "source_commit": latest["source_commit"],
        "manifest_sha256": latest["manifest_sha256"],
    }


def git_tree(repository: Path, relative: Path) -> str:
    return run(["git", "rev-parse", f"HEAD:{relative.as_posix()}"], cwd=repository, capture=True)


def validate_source(repository: Path) -> dict:
    value = governance(repository)
    source = release_source(value)
    packages = source["packages"]
    source_versions = {
        "workspace": load_json(repository / "package.json").get("version"),
        "admin": load_json(repository / "apps/admin/package.json").get("version"),
        "platform": load_json(repository / "packages/platform/package.json").get("version"),
    }
    expected_versions = {
        **source["application_versions"],
        "platform": source["platform_version"],
    }
    if source_versions != expected_versions:
        raise ArtifactError("Platform/Application version mismatch")
    for name, relative in PACKAGE_PATHS.items():
        package = load_json(repository / relative / "package.json")
        if package.get("name") != name or package.get("version") != packages[name]["version"]:
            raise ArtifactError(f"{name}: source package version mismatch")

    contracts = {}
    for surface, version in source["contract_versions"].items():
        path = repository / "openapi" / "bundled" / f"{surface}.openapi.json"
        actual = load_json(path).get("info", {}).get("version")
        if actual != version:
            raise ArtifactError(f"{surface} contract version mismatch")
        contracts[surface] = {"version": actual, "sha256": sha256_file(path)}
    expected_public_sha256 = source["public_openapi_sha256"]
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
        "publicApiOperationCount": source["public_api_operation_count"],
    }:
        raise ArtifactError("testkit compatibility metadata mismatch")
    return {
        "bundle_version": source["bundle_version"],
        "release_state": source["release_state"],
        "release_mode": source["release_mode"],
        "platform_version": source["platform_version"],
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
    candidate = pending_candidate(repository)
    package_rows = []
    for name, details in candidate["packages"].items():
        if details["disposition"] == "publish":
            compatibility = {
                key: details[key]
                for key in (
                    "minimum_public_api_contract",
                    "required_capabilities",
                    "storefront_client_version",
                    "site_schema_version",
                    "public_api_operation_count",
                )
                if key in details
            }
            row = {
                "name": name,
                "version": details["version"],
                "disposition": "published",
                **assets[name],
                **compatibility,
            }
        else:
            row = {
                "name": name,
                "version": details["version"],
                "disposition": "referenced",
                "sha256": details["sha256"],
                "source_bundle_version": details["source_bundle_version"],
                "source_tree": details["source_tree"],
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
            "operation_count": candidate["public_api_operation_count"],
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
    candidate = verification_target(value)
    manifest = load_json(output / "artifact-manifest.json")
    if manifest.get("schema_version") != "2.0" or manifest.get("bundle") != {
        "version": candidate["bundle_version"],
        "predecessor": candidate["predecessor_bundle_version"],
        "release_mode": candidate["release_mode"],
        "immutable": True,
    }:
        raise ArtifactError("artifact manifest bundle identity mismatch")
    if not FULL_SHA.fullmatch(str(manifest.get("source_commit", ""))) or (
        candidate.get("source_commit") is not None
        and manifest.get("source_commit") != candidate["source_commit"]
    ):
        raise ArtifactError("artifact manifest source commit invalid")
    if manifest.get("platform") != {
        "version": candidate["platform_version"],
        "application_versions": candidate["application_versions"],
    }:
        raise ArtifactError("artifact manifest Platform identity mismatch")
    public = manifest.get("public_openapi", {})
    expected_public_sha256 = candidate["public_openapi_sha256"]
    if (
        public.get("version") != candidate["contract_versions"]["public"]
        or public.get("sha256") != expected_public_sha256
        or public.get("file") != PUBLIC_OPENAPI_PATH.name
        or public.get("operation_count") != candidate["public_api_operation_count"]
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
            if (
                row.get("disposition") != "published"
                or not path.is_file()
                or sha256_file(path) != row.get("sha256")
                or (details.get("sha256") is not None and row.get("sha256") != details["sha256"])
            ):
                raise ArtifactError(f"{row['name']}: published package mismatch")
            package = read_package_manifest(path)
            if package.get("name") != row["name"] or package.get("version") != row["version"]:
                raise ArtifactError(f"{row['name']}: tarball identity mismatch")
            for key in (
                "minimum_public_api_contract",
                "required_capabilities",
                "storefront_client_version",
                "site_schema_version",
                "public_api_operation_count",
            ):
                if key in details and row.get(key) != details[key]:
                    raise ArtifactError(f"{row['name']}: compatibility metadata mismatch")
            expected_files.add(path.name)
        elif row != {
            "name": row["name"],
            "version": details["version"],
            "disposition": "referenced",
            "sha256": details["sha256"],
            "source_bundle_version": details["source_bundle_version"],
            "source_tree": details["source_tree"],
        }:
            raise ArtifactError(f"{row['name']}: referenced package mismatch")
    actual_files = {path.name for path in output.iterdir() if path.is_file()}
    if actual_files != expected_files:
        raise ArtifactError("artifact file inventory mismatch")
    checksum_files = verify_checksums(output)
    if checksum_files != expected_files - {"artifact-manifest.json", "SHA256SUMS"}:
        raise ArtifactError("checksum inventory incomplete")
    if candidate.get("manifest_sha256") is not None and sha256_file(output / "artifact-manifest.json") != candidate["manifest_sha256"]:
        raise ArtifactError("artifact manifest immutable digest mismatch")
    return manifest


def build(repository: Path, output: Path, source_commit: str) -> None:
    if output.exists():
        raise ArtifactError("output path already exists")
    if not FULL_SHA.fullmatch(source_commit) or run(["git", "rev-parse", "HEAD"], cwd=repository, capture=True) != source_commit:
        raise ArtifactError("source commit does not match HEAD")
    validate_source(repository)
    candidate = pending_candidate(repository)
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
    generated = parse_git_time(created)
    write_json(output / "artifact-manifest.json", create_manifest(repository, source_commit, generated.replace(microsecond=0).isoformat().replace("+00:00", "Z"), assets))
    write_checksums(output)
    verify_manifest(repository, output)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    subparsers = parser.add_subparsers(dest="operation", required=True)
    validate = subparsers.add_parser("validate-source")
    validate.add_argument("--repository", required=True, type=Path)
    candidate = subparsers.add_parser("candidate-version")
    candidate.add_argument("--repository", required=True, type=Path)
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
        elif arguments.operation == "candidate-version":
            candidate = governance(repository).get("candidate")
            print(candidate["bundle_version"] if isinstance(candidate, dict) else "")
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
