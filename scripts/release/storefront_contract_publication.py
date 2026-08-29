#!/usr/bin/env python3
"""Authorize and read back canonical Storefront contract publication."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import sys
import tempfile
import urllib.error
import urllib.parse
import urllib.request
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
import storefront_contract_artifact as artifact_contract


API_ROOT = "https://api.github.com"
API_VERSION = "2022-11-28"
USER_AGENT = "oripa-storefront-contract-publication/1.0"
REPOSITORY = "ideal-sol/oripa"
MAIN_REF = "refs/heads/main"
WORKFLOW_PATH = ".github/workflows/storefront-contract-artifact-publish.yml"
REQUIRED_CHECKS = {
    "policy-gate",
    "quality-gate",
    "security-gate",
    "integration-gate",
    "ci-gate",
}
FULL_SHA = re.compile(r"^[0-9a-f]{40}$")
SHA256 = re.compile(r"^[0-9a-f]{64}$")
TASK_ID = re.compile(r"^[A-Z][A-Z0-9-]{2,31}$")
VERSION = re.compile(r"^[0-9]+\.0\.0-alpha\.[0-9]+$")
ARTIFACT_ID = re.compile(r"^[1-9][0-9]{0,19}$")
RUN_ID = re.compile(r"^[1-9][0-9]{0,19}$")
MAX_ARCHIVE_BYTES = 100 * 1024 * 1024


class PublicationError(RuntimeError):
    """A deterministic publication authority or readback failure."""


def require(condition: bool, classification: str) -> None:
    if not condition:
        raise PublicationError(classification)


def api_get(path: str, token: str) -> object:
    require(path.startswith("/") and ".." not in path and "://" not in path, "github_path_invalid")
    request = urllib.request.Request(
        API_ROOT + path,
        method="GET",
        headers={
            "Accept": "application/vnd.github+json",
            "Authorization": f"Bearer {token}",
            "X-GitHub-Api-Version": API_VERSION,
            "User-Agent": USER_AGENT,
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            body = response.read()
    except urllib.error.HTTPError as error:
        error.read()
        raise PublicationError(f"github_api_http_{error.code}") from error
    except (urllib.error.URLError, TimeoutError) as error:
        raise PublicationError("github_api_connection_failed") from error
    try:
        return json.loads(body.decode("utf-8"))
    except (UnicodeError, json.JSONDecodeError) as error:
        raise PublicationError("github_api_response_invalid") from error


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def artifact_name(version: str) -> str:
    require(bool(VERSION.fullmatch(version)), "artifact_version_invalid")
    return f"oripa-storefront-contract-{version}"


def latest_successful_checks(payload: object) -> dict[str, dict]:
    require(isinstance(payload, dict) and isinstance(payload.get("check_runs"), list), "required_checks_response_invalid")
    latest: dict[str, dict] = {}
    for check in payload["check_runs"]:
        if not isinstance(check, dict):
            continue
        name = check.get("name")
        identifier = check.get("id")
        if not isinstance(name, str) or not isinstance(identifier, int):
            continue
        current = latest.get(name)
        if current is None or identifier > current.get("id", 0):
            latest[name] = check
    return latest


def validated_source_pull(
    repository_name: str,
    source_sha: str,
    source_task_id: str,
    token: str,
) -> dict:
    associations = api_get(
        f"/repos/{repository_name}/commits/{source_sha}/pulls?per_page=100", token
    )
    require(isinstance(associations, list), "source_pull_response_invalid")
    numbers = sorted(
        {
            pull.get("number")
            for pull in associations
            if isinstance(pull, dict) and isinstance(pull.get("number"), int)
        }
    )
    matches = []
    for number in numbers:
        pull = api_get(f"/repos/{repository_name}/pulls/{number}", token)
        if not isinstance(pull, dict):
            continue
        head = pull.get("head") if isinstance(pull.get("head"), dict) else {}
        base = pull.get("base") if isinstance(pull.get("base"), dict) else {}
        head_repository = head.get("repo") if isinstance(head.get("repo"), dict) else {}
        if (
            pull.get("state") == "closed"
            and pull.get("merged") is True
            and pull.get("merge_commit_sha") == source_sha
            and base.get("ref") == "main"
            and head_repository.get("full_name") == repository_name
            and source_task_id in str(pull.get("title", ""))
            and source_task_id in str(head.get("ref", ""))
            and FULL_SHA.fullmatch(str(head.get("sha", "")))
            and head.get("sha") != source_sha
        ):
            matches.append(pull)
    require(len(matches) == 1, "exact_merged_source_pull_required")
    pull = matches[0]
    head = pull["head"]

    merged_commit = api_get(f"/repos/{repository_name}/git/commits/{source_sha}", token)
    reviewed_commit = api_get(f"/repos/{repository_name}/git/commits/{head['sha']}", token)
    merged_tree = merged_commit.get("tree") if isinstance(merged_commit, dict) else {}
    reviewed_tree = reviewed_commit.get("tree") if isinstance(reviewed_commit, dict) else {}
    require(
        isinstance(merged_tree, dict)
        and isinstance(reviewed_tree, dict)
        and FULL_SHA.fullmatch(str(merged_tree.get("sha", ""))) is not None
        and merged_tree.get("sha") == reviewed_tree.get("sha"),
        "reviewed_and_merged_tree_mismatch",
    )

    checks = latest_successful_checks(
        api_get(
            f"/repos/{repository_name}/commits/{head['sha']}/check-runs?per_page=100",
            token,
        )
    )
    failed = sorted(
        name
        for name in REQUIRED_CHECKS
        if (checks.get(name) or {}).get("status") != "completed"
        or (checks.get(name) or {}).get("conclusion") != "success"
    )
    require(not failed, "required_checks_not_successful")
    return pull


def authorize(
    repository: Path,
    repository_name: str,
    github_ref: str,
    event_sha: str,
    expected_merged_sha: str,
    source_task_id: str,
    token: str,
) -> dict[str, str]:
    require(repository_name == REPOSITORY, "repository_mismatch")
    require(github_ref == MAIN_REF, "protected_main_ref_required")
    require(bool(FULL_SHA.fullmatch(event_sha)), "event_sha_invalid")
    require(bool(FULL_SHA.fullmatch(expected_merged_sha)), "expected_merged_sha_invalid")
    require(event_sha == expected_merged_sha, "workflow_event_sha_mismatch")
    require(bool(TASK_ID.fullmatch(source_task_id)), "source_task_id_invalid")

    branch = api_get(f"/repos/{repository_name}/branches/main", token)
    commit = branch.get("commit") if isinstance(branch, dict) else {}
    require(
        isinstance(branch, dict)
        and branch.get("name") == "main"
        and branch.get("protected") is True
        and isinstance(commit, dict),
        "protected_main_authority_invalid",
    )
    require(commit.get("sha") == expected_merged_sha, "stale_main_sha_rejected")

    pull = validated_source_pull(
        repository_name, expected_merged_sha, source_task_id, token
    )
    candidate = artifact_contract.pending_candidate(repository)
    artifact_contract.validate_source(repository)
    require(candidate.get("source_commit") is None, "candidate_source_authority_conflict")
    version = str(candidate.get("bundle_version", ""))
    name = artifact_name(version)

    query = urllib.parse.urlencode({"name": name, "per_page": "100"})
    remote_artifacts = api_get(
        f"/repos/{repository_name}/actions/artifacts?{query}", token
    )
    require(
        isinstance(remote_artifacts, dict)
        and isinstance(remote_artifacts.get("total_count"), int)
        and isinstance(remote_artifacts.get("artifacts"), list),
        "artifact_inventory_response_invalid",
    )
    require(
        remote_artifacts["total_count"] == 0
        and not remote_artifacts["artifacts"],
        "immutable_artifact_version_already_exists",
    )
    return {
        "source_sha": expected_merged_sha,
        "source_pr": str(pull["number"]),
        "reviewed_head_sha": str(pull["head"]["sha"]),
        "artifact_version": version,
        "artifact_name": name,
    }


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, file_pointer, code, message, headers, url):
        return None


def download_artifact(artifact_id: str, token: str, destination: Path) -> str:
    request = urllib.request.Request(
        f"{API_ROOT}/repos/{REPOSITORY}/actions/artifacts/{artifact_id}/zip",
        method="GET",
        headers={
            "Accept": "application/vnd.github+json",
            "Authorization": f"Bearer {token}",
            "X-GitHub-Api-Version": API_VERSION,
            "User-Agent": USER_AGENT,
        },
    )
    try:
        urllib.request.build_opener(NoRedirect).open(request, timeout=60)
        raise PublicationError("artifact_redirect_missing")
    except urllib.error.HTTPError as error:
        if error.code not in {301, 302, 303, 307, 308}:
            error.read()
            raise PublicationError(f"github_api_http_{error.code}") from error
        location = error.headers.get("Location")
        require(isinstance(location, str) and location.startswith("https://"), "artifact_redirect_invalid")
    except (urllib.error.URLError, TimeoutError) as error:
        raise PublicationError("github_api_connection_failed") from error

    digest = hashlib.sha256()
    total = 0
    try:
        response_context = urllib.request.urlopen(location, timeout=60)
    except (urllib.error.HTTPError, urllib.error.URLError, TimeoutError) as error:
        raise PublicationError("artifact_download_failed") from error
    with response_context as response, destination.open("xb") as target:
        while True:
            chunk = response.read(1024 * 1024)
            if not chunk:
                break
            total += len(chunk)
            require(total <= MAX_ARCHIVE_BYTES, "artifact_archive_too_large")
            digest.update(chunk)
            target.write(chunk)
    return digest.hexdigest()


def safe_extract(source: Path, destination: Path) -> None:
    with zipfile.ZipFile(source) as archive:
        members = [member for member in archive.infolist() if not member.is_dir()]
        names = [member.filename for member in members]
        require(len(members) == 5 and len(names) == len(set(names)), "artifact_file_set_invalid")
        require(
            {"artifact-manifest.json", "SHA256SUMS", "public.openapi.json"}.issubset(names)
            and len([name for name in names if name.endswith(".tgz")]) == 2,
            "artifact_file_set_invalid",
        )
        require(sum(member.file_size for member in members) <= MAX_ARCHIVE_BYTES, "artifact_unpacked_too_large")
        for member in members:
            mode = member.external_attr >> 16
            parsed = PurePosixPath(member.filename)
            require(
                parsed.name == member.filename
                and not stat.S_ISLNK(mode),
                "artifact_member_invalid",
            )
            with archive.open(member) as source_stream, (destination / member.filename).open("xb") as target_stream:
                shutil.copyfileobj(source_stream, target_stream, length=1024 * 1024)


def readback(
    repository: Path,
    artifact_id: str,
    expected_name: str,
    expected_outer_digest: str,
    expected_source_sha: str,
    expected_version: str,
    expected_task_id: str,
    expected_run_id: str,
    output: Path,
    token: str,
) -> dict[str, str]:
    require(bool(ARTIFACT_ID.fullmatch(artifact_id)), "artifact_id_invalid")
    require(bool(RUN_ID.fullmatch(expected_run_id)), "workflow_run_id_invalid")
    require(expected_name == artifact_name(expected_version), "artifact_name_invalid")
    require(bool(SHA256.fullmatch(expected_outer_digest)), "outer_artifact_digest_invalid")
    require(bool(FULL_SHA.fullmatch(expected_source_sha)), "source_sha_invalid")
    require(bool(TASK_ID.fullmatch(expected_task_id)), "source_task_id_invalid")
    require(not output.exists(), "readback_destination_exists")

    metadata = api_get(
        f"/repos/{REPOSITORY}/actions/artifacts/{artifact_id}", token
    )
    workflow_run = metadata.get("workflow_run") if isinstance(metadata, dict) else {}
    require(
        isinstance(metadata, dict)
        and metadata.get("id") == int(artifact_id)
        and metadata.get("name") == expected_name
        and metadata.get("expired") is False
        and metadata.get("digest") == f"sha256:{expected_outer_digest}"
        and isinstance(workflow_run, dict)
        and workflow_run.get("id") == int(expected_run_id),
        "uploaded_artifact_identity_mismatch",
    )

    output.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix=".storefront-contract-readback-", dir=output.parent) as temporary:
        staging = Path(temporary)
        archive = staging / "github-artifact.zip"
        actual_outer_digest = download_artifact(artifact_id, token, archive)
        require(
            expected_outer_digest == actual_outer_digest,
            "outer_artifact_digest_mismatch",
        )
        extracted = staging / "payload"
        extracted.mkdir(mode=0o700)
        safe_extract(archive, extracted)
        artifact_contract.verify_manifest(repository, extracted)

        manifest = artifact_contract.load_json(extracted / "artifact-manifest.json")
        bundle = manifest.get("bundle") if isinstance(manifest.get("bundle"), dict) else {}
        require(manifest.get("task_id") == expected_task_id, "manifest_task_id_mismatch")
        require(manifest.get("source_commit") == expected_source_sha, "manifest_source_sha_mismatch")
        require(bundle.get("version") == expected_version, "manifest_version_mismatch")
        rows = {
            row.get("name"): row
            for row in manifest.get("packages", [])
            if isinstance(row, dict)
        }
        require(
            rows.get("@oripa/storefront-client", {}).get("disposition") == "published"
            and rows.get("@oripa/storefront-testkit", {}).get("disposition") == "published",
            "manifest_package_inventory_mismatch",
        )
        public = manifest.get("public_openapi") if isinstance(manifest.get("public_openapi"), dict) else {}
        result = {
            "artifact_id": artifact_id,
            "artifact_name": expected_name,
            "workflow_run_id": expected_run_id,
            "outer_sha256": actual_outer_digest,
            "source_commit": expected_source_sha,
            "artifact_version": expected_version,
            "manifest_sha256": sha256_file(extracted / "artifact-manifest.json"),
            "sha256sums_sha256": sha256_file(extracted / "SHA256SUMS"),
            "public_openapi_sha256": str(public.get("sha256", "")),
            "client_sha256": str(rows["@oripa/storefront-client"].get("sha256", "")),
            "testkit_sha256": str(rows["@oripa/storefront-testkit"].get("sha256", "")),
        }
        require(
            all(re.fullmatch(r"[0-9a-f]{64}", result[key]) for key in (
                "manifest_sha256",
                "sha256sums_sha256",
                "public_openapi_sha256",
                "client_sha256",
                "testkit_sha256",
            )),
            "readback_digest_inventory_invalid",
        )
        os.rename(extracted, output)
    return result


def write_outputs(path: Path, values: dict[str, str]) -> None:
    with path.open("a", encoding="utf-8") as stream:
        for key, value in values.items():
            require("\n" not in key + value and "\r" not in key + value, "github_output_invalid")
            stream.write(f"{key}={value}\n")


def write_summary(path: Path, values: dict[str, str]) -> None:
    with path.open("a", encoding="utf-8") as stream:
        stream.write("## Storefront Contract Artifact Readback\n\n")
        for key in (
            "artifact_id",
            "artifact_name",
            "workflow_run_id",
            "source_commit",
            "artifact_version",
            "outer_sha256",
            "manifest_sha256",
            "sha256sums_sha256",
            "public_openapi_sha256",
            "client_sha256",
            "testkit_sha256",
        ):
            stream.write(f"- `{key}`: `{values[key]}`\n")


def parser() -> argparse.ArgumentParser:
    result = argparse.ArgumentParser()
    subparsers = result.add_subparsers(dest="command", required=True)
    authorize_parser = subparsers.add_parser("authorize")
    authorize_parser.add_argument("--repository", type=Path, required=True)
    authorize_parser.add_argument("--repository-name", required=True)
    authorize_parser.add_argument("--github-ref", required=True)
    authorize_parser.add_argument("--event-sha", required=True)
    authorize_parser.add_argument("--expected-merged-sha", required=True)
    authorize_parser.add_argument("--source-task-id", required=True)
    authorize_parser.add_argument("--github-output", type=Path, required=True)

    readback_parser = subparsers.add_parser("readback")
    readback_parser.add_argument("--repository", type=Path, required=True)
    readback_parser.add_argument("--artifact-id", required=True)
    readback_parser.add_argument("--artifact-name", required=True)
    readback_parser.add_argument("--expected-outer-digest", required=True)
    readback_parser.add_argument("--expected-source-sha", required=True)
    readback_parser.add_argument("--expected-version", required=True)
    readback_parser.add_argument("--expected-task-id", required=True)
    readback_parser.add_argument("--expected-run-id", required=True)
    readback_parser.add_argument("--output", type=Path, required=True)
    readback_parser.add_argument("--github-step-summary", type=Path, required=True)
    return result


def main() -> None:
    arguments = parser().parse_args()
    token = os.environ.get("GITHUB_TOKEN", "")
    require(len(token) >= 20, "github_token_unavailable")
    if arguments.command == "authorize":
        values = authorize(
            arguments.repository.resolve(),
            arguments.repository_name,
            arguments.github_ref,
            arguments.event_sha,
            arguments.expected_merged_sha,
            arguments.source_task_id,
            token,
        )
        write_outputs(arguments.github_output, values)
    else:
        values = readback(
            arguments.repository.resolve(),
            arguments.artifact_id,
            arguments.artifact_name,
            arguments.expected_outer_digest,
            arguments.expected_source_sha,
            arguments.expected_version,
            arguments.expected_task_id,
            arguments.expected_run_id,
            arguments.output,
            token,
        )
        write_summary(arguments.github_step_summary, values)
    print(json.dumps(values, sort_keys=True))


if __name__ == "__main__":
    try:
        main()
    except (PublicationError, artifact_contract.ArtifactError, OSError, ValueError, zipfile.BadZipFile) as error:
        classification = str(error) if isinstance(error, (PublicationError, artifact_contract.ArtifactError)) else "publication_verification_failed"
        print(f"publication_error:{classification}", file=sys.stderr)
        raise SystemExit(1)
