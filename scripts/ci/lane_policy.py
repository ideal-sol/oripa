#!/usr/bin/env python3
"""Lane and activation policy for pull-request governance."""

from __future__ import annotations

import argparse
import json
import os
from pathlib import Path, PurePosixPath
import re
import subprocess
from typing import Iterable


LANES = ("Lite Maintenance", "Standard Change", "Strict Change")
LANE_KEYS = {
    "Lite Maintenance": "lite",
    "Standard Change": "standard",
    "Strict Change": "strict",
}
LANE_RANK = {lane: rank for rank, lane in enumerate(LANES)}
ACTIVATION_MODES = ("none", "deferred", "immediate")
FULL_SHA = re.compile(r"^[0-9a-f]{40}$")
HIGH_CONFIDENCE_SECRET_PATTERNS = (
    re.compile(r"gh[pousr]_[A-Za-z0-9_]{20,}"),
    re.compile(r"github_pat_[A-Za-z0-9_]{20,}"),
    re.compile(r"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----"),
    re.compile(r"Bearer [A-Za-z0-9._-]{20,}"),
    re.compile(r"AKIA[0-9A-Z]{16}"),
)

STRICT_EXACT_PATHS = {
    ".dockerignore",
    ".github/CODEOWNERS",
    ".github/dependabot.yml",
    ".github/pull_request_template.md",
    "AGENTS.md",
    "Makefile",
    "SECURITY.md",
    "docker-compose.yml",
    "docker-compose.v2.yml",
    "package.json",
    "pnpm-lock.yaml",
    "pnpm-workspace.yaml",
}
STRICT_PREFIXES = (
    ".ci/",
    ".codex/",
    ".github/issue_template/",
    ".github/workflows/",
    "deployments/",
    "docs/architecture/",
    "docs/operations/deployment/",
    "docs/operations/ci/",
    "docs/operations/github-rulesets/",
    "docs/operations/releases/",
    "docs/operations/security/",
    "infra/",
    "infrastructure/",
    "manifests/",
    "scripts/ci/",
    "scripts/db/",
    "scripts/ops/",
    "scripts/release/",
    "tests/ci/",
    "tests/db/",
    "tests/ops/",
)
STRICT_SEGMENTS = {
    "archive",
    "auth",
    "authentication",
    "authorization",
    "chargeback",
    "coin",
    "credential",
    "csrf",
    "dependency",
    "deploy",
    "deployment",
    "draw",
    "inventory",
    "lockfile",
    "mfa",
    "migration",
    "migrations",
    "network",
    "payment",
    "point",
    "production",
    "refund",
    "release",
    "secret",
    "security",
    "session",
    "workflow",
}
LITE_SUFFIXES = (
    ".css",
    ".gif",
    ".ico",
    ".jpeg",
    ".jpg",
    ".md",
    ".png",
    ".svg",
    ".webp",
)
LITE_UI_PREFIXES = (
    "apps/admin/public/",
    "apps/admin/src/app/",
    "apps/admin/src/components/",
    "apps/admin/test/",
)
STANDARD_PREFIXES = (
    "apps/",
    "backend/",
    "direction/",
    "docs/",
    "legacy/",
    "openapi/",
    "packages/",
    "scripts/",
    "tests/",
    "worklogs/",
)


class LanePolicyFailure(ValueError):
    pass


def _segments(path: str) -> set[str]:
    return {
        token
        for part in PurePosixPath(path.lower()).parts
        for token in re.split(r"[^a-z0-9]+", part)
        if token
    }


def validate_path(path: str) -> None:
    parsed = PurePosixPath(path)
    if (
        not path
        or parsed.is_absolute()
        or ".." in parsed.parts
        or str(parsed) != path
        or "\\" in path
        or "\x00" in path
    ):
        raise LanePolicyFailure(f"invalid changed path: {path!r}")


def classify_path(path: str) -> str:
    validate_path(path)
    lowered = path.lower()
    name = PurePosixPath(lowered).name
    segments = _segments(lowered)
    if (
        path in STRICT_EXACT_PATHS
        or lowered.startswith(STRICT_PREFIXES)
        or "database" in segments
        or bool(segments & STRICT_SEGMENTS)
        or name in {
            "composer.json",
            "composer.lock",
            "package.json",
            "pyproject.toml",
            "requirements.txt",
        }
        or name.endswith((".lock", ".pem", ".key", ".p12", ".pfx"))
    ):
        return "Strict Change"
    if lowered.startswith(LITE_UI_PREFIXES) and (
        lowered.endswith(LITE_SUFFIXES)
        or lowered.endswith((".tsx", ".test.tsx", ".spec.tsx"))
    ):
        return "Lite Maintenance"
    if lowered.endswith(LITE_SUFFIXES) and lowered.startswith(
        ("docs/", "worklogs/", "apps/")
    ):
        return "Lite Maintenance"
    if lowered in {"readme.md", "task_board.md"}:
        return "Lite Maintenance"
    if lowered.startswith(STANDARD_PREFIXES):
        return "Standard Change"
    raise LanePolicyFailure(f"unclassified changed path: {path}")


def required_lane(paths: Iterable[str]) -> str:
    classified = [(path, classify_path(path)) for path in paths]
    if not classified:
        raise LanePolicyFailure("changed path set is empty")
    return max((lane for _, lane in classified), key=LANE_RANK.__getitem__)


def validate_lane(requested_lane: str, paths: Iterable[str]) -> dict[str, object]:
    if requested_lane not in LANE_RANK:
        raise LanePolicyFailure("Lane must be Lite Maintenance, Standard Change, or Strict Change")
    path_list = sorted(set(paths))
    minimum = required_lane(path_list)
    if LANE_RANK[requested_lane] < LANE_RANK[minimum]:
        raise LanePolicyFailure(
            f"requested Lane {requested_lane} is below required Lane {minimum}"
        )
    return {
        "lane": requested_lane,
        "lane_key": LANE_KEYS[requested_lane],
        "minimum_lane": minimum,
        "changed_paths": path_list,
    }


def validate_activation(value: str) -> str:
    if value not in ACTIVATION_MODES:
        raise LanePolicyFailure("Application Runtime Activation must be none, deferred, or immediate")
    return value


def metadata_value(body: str, label: str) -> str:
    match = re.search(
        rf"^-\s+{re.escape(label)}:\s*`?([^`\n]+?)`?\s*$",
        body,
        re.MULTILINE,
    )
    if not match:
        raise LanePolicyFailure(f"pull request metadata missing: {label}")
    return match.group(1).strip()


def validate_pr_lane(body: str, paths: Iterable[str]) -> dict[str, object]:
    lane = metadata_value(body, "Lane")
    activation = validate_activation(metadata_value(body, "Application Runtime Activation"))
    path_list = list(paths)
    result = validate_lane(lane, path_list)
    ui_verification = metadata_value(body, "UI Verification")
    if ui_verification not in {"PASS", "NOT_APPLICABLE"}:
        raise LanePolicyFailure("UI Verification must be PASS or NOT_APPLICABLE")
    if lane == "Lite Maintenance" and any(
        path.lower().startswith(LITE_UI_PREFIXES) for path in path_list
    ) and ui_verification != "PASS":
        raise LanePolicyFailure("Lite UI changes require targeted UI Verification PASS")
    result["activation"] = activation
    result["ui_verification"] = ui_verification
    return result


def run_git(repository: Path, *arguments: str) -> str:
    result = subprocess.run(
        ["git", "-C", str(repository), *arguments],
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        check=False,
    )
    if result.returncode != 0:
        raise LanePolicyFailure("git changed-path lookup failed")
    return result.stdout


def event_policy(repository: Path, event_name: str, event_path: Path | None) -> dict[str, object]:
    if event_name != "pull_request":
        return {
            "lane": "Strict Change",
            "lane_key": "strict",
            "minimum_lane": "Strict Change",
            "activation": "none",
            "changed_paths": [],
        }
    if event_path is None or not event_path.is_file():
        raise LanePolicyFailure("pull request event payload is missing")
    event = json.loads(event_path.read_text(encoding="utf-8"))
    pull_request = event.get("pull_request")
    if not isinstance(pull_request, dict):
        raise LanePolicyFailure("pull request event payload is invalid")
    base_sha = str(pull_request.get("base", {}).get("sha", ""))
    head_sha = str(pull_request.get("head", {}).get("sha", ""))
    if not FULL_SHA.fullmatch(base_sha) or not FULL_SHA.fullmatch(head_sha):
        raise LanePolicyFailure("pull request base or head SHA is invalid")
    paths = sorted(
        line
        for line in run_git(repository, "diff", "--name-only", f"{base_sha}...{head_sha}").splitlines()
        if line
    )
    return validate_pr_lane(pull_request.get("body") or "", paths)


def scan_added_diff(repository: Path, paths: Iterable[str], base_sha: str, head_sha: str) -> None:
    if not FULL_SHA.fullmatch(base_sha) or not FULL_SHA.fullmatch(head_sha):
        raise LanePolicyFailure("diff secret scan SHA is invalid")
    findings = 0
    for path in paths:
        diff = run_git(
            repository,
            "diff",
            "--unified=0",
            "--no-ext-diff",
            f"{base_sha}...{head_sha}",
            "--",
            path,
        )
        for line in diff.splitlines():
            if not line.startswith("+") or line.startswith("+++"):
                continue
            if any(pattern.search(line) for pattern in HIGH_CONFIDENCE_SECRET_PATTERNS):
                findings += 1
    if findings:
        raise LanePolicyFailure(
            f"high-confidence secret candidates found in added diff: {findings}"
        )


def write_github_output(path: Path, result: dict[str, object]) -> None:
    with path.open("a", encoding="utf-8") as output:
        for key in ("lane", "lane_key", "minimum_lane", "activation"):
            print(f"{key}={result[key]}", file=output)


def parse_arguments() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repository", type=Path, required=True)
    parser.add_argument("--event-name", default=os.environ.get("GITHUB_EVENT_NAME", ""))
    parser.add_argument("--event-path", type=Path)
    parser.add_argument("--github-output", type=Path)
    parser.add_argument("--secret-scan", action="store_true")
    return parser.parse_args()


def main() -> int:
    arguments = parse_arguments()
    try:
        result = event_policy(
            arguments.repository.resolve(),
            arguments.event_name,
            arguments.event_path,
        )
        if arguments.secret_scan and arguments.event_name == "pull_request":
            event = json.loads(arguments.event_path.read_text(encoding="utf-8"))
            pull_request = event["pull_request"]
            scan_added_diff(
                arguments.repository.resolve(),
                result["changed_paths"],
                str(pull_request["base"]["sha"]),
                str(pull_request["head"]["sha"]),
            )
        if arguments.github_output:
            write_github_output(arguments.github_output, result)
    except (OSError, json.JSONDecodeError, LanePolicyFailure) as error:
        print(f"lane-policy: FAIL: {error}")
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
