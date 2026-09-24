#!/usr/bin/env python3
"""Authorize an exact Human-approved Production source without building it."""

from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import runpy
import subprocess
import urllib.request


ROOT = Path(__file__).resolve().parents[2]
REPOSITORY = "ideal-sol/oripa"
AUTHORITY_PATH = "manifests/platform-production-approved-source.json"
FULL_SHA = re.compile(r"[0-9a-f]{40}")
REQUIRED_CHECKS = {
    "policy-gate", "quality-gate", "security-gate", "integration-gate", "ci-gate",
}
CHECK_GATE = runpy.run_path(str(ROOT / "infrastructure/github-app/check_run_gate.py"))


def require(condition: bool, message: str) -> None:
    if not condition:
        raise ValueError(message)


def git(repository: Path, *arguments: str) -> str:
    result = subprocess.run(
        ["git", "-C", str(repository), *arguments], text=True,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False,
    )
    require(result.returncode == 0, "source commit missing or not protected-main ancestor")
    return result.stdout.strip()


def api_get(path: str) -> object:
    require(path.startswith(f"/repos/{REPOSITORY}/"), "repository mismatch")
    request = urllib.request.Request(
        "https://api.github.com" + path,
        headers={
            "Accept": "application/vnd.github+json",
            "Authorization": "Bearer " + os.environ["GH_TOKEN"],
            "X-GitHub-Api-Version": "2022-11-28",
        },
    )
    with urllib.request.urlopen(request, timeout=30) as response:
        return json.load(response)


def checks_for(get, sha: str) -> dict:
    runs = CHECK_GATE["list_required_check_runs"](
        get, repository=REPOSITORY, head_sha=sha,
    )
    result = CHECK_GATE["evaluate_required_check_runs"](
        runs, head_sha=sha, required_checks=REQUIRED_CHECKS,
    )
    require(result["passed"], "required checks not successful")
    return result


def authorize(repository: Path, source_sha: str, workflow_sha: str,
              change_id: str, pr_number: int, get=api_get) -> dict:
    require(bool(FULL_SHA.fullmatch(source_sha)), "invalid source SHA")
    require(bool(FULL_SHA.fullmatch(workflow_sha)), "invalid workflow SHA")
    require(git(repository, "rev-parse", "HEAD") == workflow_sha, "workflow checkout mismatch")
    require(git(repository, "cat-file", "-t", source_sha) == "commit", "source is not a commit")
    main = get(f"/repos/{REPOSITORY}/branches/main")
    require(main.get("protected") is True, "protected main required")
    require(main.get("commit", {}).get("sha") == workflow_sha, "workflow SHA is not current protected main")
    git(repository, "merge-base", "--is-ancestor", source_sha, workflow_sha)
    authority = json.loads(git(repository, "show", f"{workflow_sha}:{AUTHORITY_PATH}"))
    require(
        authority.get("schema_version") == "1.0"
        and authority.get("repository") == REPOSITORY
        and authority.get("source_sha") == source_sha
        and authority.get("change_id") == change_id
        and authority.get("pr_number") == pr_number
        and isinstance(authority.get("authority"), str)
        and authority["authority"].startswith("Human-approved exact Runtime Source:")
        and authority.get("activation_authorized") is False,
        "Human-approved source authority mismatch",
    )
    pull = get(f"/repos/{REPOSITORY}/pulls/{pr_number}")
    require(
        pull.get("merged") is True and pull.get("merge_commit_sha") == source_sha
        and pull.get("base", {}).get("ref") == "main"
        and pull.get("head", {}).get("repo", {}).get("full_name") == REPOSITORY
        and change_id in str(pull.get("title", "")),
        "merged pull request authority mismatch",
    )
    reviewed_sha = pull.get("head", {}).get("sha", "")
    require(bool(FULL_SHA.fullmatch(reviewed_sha)), "invalid reviewed head")
    source_tree = git(repository, "rev-parse", f"{source_sha}^{{tree}}")
    reviewed = get(f"/repos/{REPOSITORY}/git/commits/{reviewed_sha}")
    require(reviewed.get("tree", {}).get("sha") == source_tree, "reviewed source tree mismatch")
    source_checks = checks_for(get, reviewed_sha)
    current_checks = checks_for(get, workflow_sha)
    require(
        get(f"/repos/{REPOSITORY}/branches/main") == main,
        "protected main moved during validation",
    )
    return {
        "source_sha": source_sha, "source_tree": source_tree,
        "workflow_sha": workflow_sha, "change_id": change_id, "pr_number": pr_number,
        "reviewed_sha": reviewed_sha, "authority_path": AUTHORITY_PATH,
        "source_checks": source_checks, "current_checks": current_checks,
    }


def scan_source(repository: Path) -> None:
    security = runpy.run_path(str(ROOT / "scripts/ci/security_gate.py"))
    paths = security["tracked_paths"](repository)
    require(not security["dangerous_paths"](paths), "unsafe source paths")
    require(not security["secret_candidates"](repository, paths), "source secret scan failed")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repository", type=Path, required=True)
    parser.add_argument("--scan-source", action="store_true")
    arguments = parser.parse_args()
    if arguments.scan_source:
        scan_source(arguments.repository)
        return
    require(os.environ.get("GITHUB_REPOSITORY") == REPOSITORY, "repository mismatch")
    require(os.environ.get("GITHUB_REF") == "refs/heads/main", "protected main ref required")
    require(os.environ.get("GITHUB_EVENT_NAME") == "workflow_dispatch", "dispatch required")
    evidence = authorize(
        arguments.repository, os.environ["INPUT_SOURCE_SHA"], os.environ["GITHUB_SHA"],
        os.environ["INPUT_CHANGE_ID"], int(os.environ["INPUT_PR_NUMBER"]),
    )
    (Path(os.environ["RUNNER_TEMP"]) / "production-source-authority.json").write_text(
        json.dumps(evidence, indent=2) + "\n", encoding="utf-8",
    )
    with open(os.environ["GITHUB_OUTPUT"], "a", encoding="utf-8") as output:
        for name in ("source_sha", "workflow_sha", "change_id", "pr_number"):
            output.write(f"{name}={evidence[name]}\n")


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        raise SystemExit(str(error) if isinstance(error, ValueError) else "source authority validation failed") from None
