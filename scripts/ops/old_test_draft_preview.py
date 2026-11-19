#!/usr/bin/env python3
"""Main-controlled, OLD-only authorization for repeated exact Draft heads."""
from __future__ import annotations

import base64
import json
import os
from pathlib import Path
import re
import runpy
import subprocess

ROOT = Path(__file__).resolve().parents[2]
REPOSITORY = "ideal-sol/oripa"
PLAN_PATH = "manifests/old-test-draft-preview.json"
WORKFLOW = ".github/workflows/old-test-draft-preview.yml"
SHA = re.compile(r"[0-9a-f]{40}")


class DraftHold(ValueError):
    pass


def require(value, reason):
    if not value:
        raise DraftHold(reason)


def authorize(task, number, head_sha, control, *, get, compare=None):
    require(re.fullmatch(r"[A-Z][A-Z0-9-]{2,31}", task)
            and re.fullmatch(r"[1-9][0-9]{0,9}", number)
            and SHA.fullmatch(head_sha) and SHA.fullmatch(control), "identity_invalid")
    prefix = f"/repos/{REPOSITORY}"
    main = get(prefix + "/branches/main")
    require(main.get("protected") is True and main.get("commit", {}).get("sha") == control,
            "protected_control_required")
    document = get(f"{prefix}/contents/{PLAN_PATH}?ref={control}")
    require(document.get("encoding") == "base64", "plan_encoding_invalid")
    # GitHub Contents API wraps Base64 at 60 columns, including a trailing LF.
    approval = json.loads(base64.b64decode("".join(document["content"].splitlines()), validate=True))
    require(approval.get("schema_version") == "1.0" and approval.get("target") == "old-test",
            "old_only_plan_required")
    plans = [p for p in approval["plans"] if p.get("task_id") == task and p.get("source_pr") == int(number)]
    require(len(plans) == 1, "approved_plan_required")
    plan = plans[0]
    mode = plan.get("image_mode")
    require(mode in {"normal", "api-only", "admin-only"}
            and SHA.fullmatch(str(plan.get("baseline_sha", "")))
            and plan.get("migration") == "none"
            and plan.get("focused_commands") and plan.get("allowed_paths"), "plan_invalid")
    pull = get(f"{prefix}/pulls/{number}")
    head, base = pull.get("head", {}), pull.get("base", {})
    require(pull.get("state") == "open" and pull.get("draft") is True
            and head.get("sha") == head_sha and head.get("ref") == plan.get("branch")
            and head.get("repo", {}).get("full_name") == REPOSITORY
            and base.get("repo", {}).get("full_name") == REPOSITORY and base.get("ref") == "main"
            and task in pull.get("title", "") and task in head.get("ref", ""), "draft_pr_mismatch")
    # Compare the entire approved baseline, including deletions; GitHub truncates at 300.
    compare = compare or (lambda before, after: get(f"{prefix}/compare/{before}...{after}?per_page=100"))
    comparison = compare(plan["baseline_sha"], head_sha)
    files = comparison.get("files", [])
    require(comparison.get("status") == "ahead"
            and comparison.get("merge_base_commit", {}).get("sha") == plan["baseline_sha"]
            and 0 < len(files) < 300, "scope_invalid_or_truncated")
    roots = (("apps/admin/src/",) if mode == "admin-only" else
             ("apps/api/app/", "apps/api/routes/", "apps/api/resources/") if mode == "api-only" else
             ("apps/admin/src/", "apps/api/app/", "apps/api/routes/", "apps/api/resources/"))
    paths = []
    for item in files:
        path = item.get("filename", "")
        require(item.get("status") in {"added", "modified", "removed"}
                and path in plan["allowed_paths"] and path.startswith(roots)
                and ".." not in path.split("/"), "path_not_approved")
        paths.append(path)
    for command in plan["focused_commands"]:
        require(isinstance(command, list) and command
                and all(isinstance(arg, str) and arg and "\x00" not in arg for arg in command),
                "focused_command_invalid")
    # Baseline must be merged; otherwise a caller could hide altered workflow/tests.
    ancestry = compare(plan["baseline_sha"], control)
    require(ancestry.get("status") in {"ahead", "identical"}
            and ancestry.get("merge_base_commit", {}).get("sha") == plan["baseline_sha"],
            "baseline_not_merged")
    gate = runpy.run_path(str(ROOT / "infrastructure/github-app/check_run_gate.py"))
    checks = gate["list_required_check_runs"](get, repository=REPOSITORY, head_sha=head_sha)
    summary = gate["evaluate_required_check_runs"](
        checks, head_sha=head_sha, required_checks={"policy-gate", "security-gate"})
    require(summary["passed"], "early_checks_required")
    for selected in summary["runs"]:
        check = next(c for c in checks if c.get("id") == selected["id"])
        match = re.fullmatch(r"https://github.com/ideal-sol/oripa/actions/runs/([0-9]+)/job/[0-9]+",
                             check.get("details_url", ""))
        require(match, "check_run_invalid")
        run = get(f"{prefix}/actions/runs/{match[1]}")
        require(run.get("path") == ".github/workflows/platform-ci.yml"
                and run.get("head_sha") == head_sha and run.get("event") == "pull_request",
                "check_workflow_invalid")
    commit = get(f"{prefix}/git/commits/{head_sha}")
    tree = commit.get("tree", {}).get("sha", "")
    require(commit.get("sha") == head_sha and SHA.fullmatch(tree), "tree_invalid")
    current_main = get(prefix + "/branches/main")
    current_pull = get(f"{prefix}/pulls/{number}")
    # CI completion updates mergeability metadata without changing authority.
    # Recheck every bound identity field, not volatile unrelated API fields.
    require(current_main.get("protected") is True and current_main.get("commit", {}).get("sha") == control
            and all(current_pull.get(key) == pull.get(key) for key in ("state", "draft", "title"))
            and all(current_pull.get("head", {}).get(key) == head.get(key) for key in ("sha", "ref"))
            and current_pull.get("head", {}).get("repo", {}).get("full_name") == REPOSITORY
            and current_pull.get("base", {}).get("ref") == "main"
            and current_pull.get("base", {}).get("repo", {}).get("full_name") == REPOSITORY,
            "authority_moved")
    return {"task_id": task, "source_pr": int(number), "source_sha": head_sha,
            "source_tree": tree, "control_sha": control, "target": "old-test",
            "platform": "linux/amd64", "image_mode": mode, "authority_mode": "draft-preview",
            "authority_path": PLAN_PATH, "plan": plan, "changed_paths": sorted(paths),
            "build_recipe": None if mode == "admin-only" else {
                "path": "infra/docker/backend/old-test-line-698.Dockerfile",
                "recipe_sha256": "8643a57c4a271708a924c9fb3435e6ba7a77239e59cf46ea2f26d7faf739aace",
                "source_sha256": "2911e9119773b7bad658d90a1e368189f200a4b942b4ed9f93f0bf3ac4c6edb0"},
            "early_checks": summary["runs"], "activation_authorized": False}


def main():
    require(os.environ.get("GITHUB_REPOSITORY") == REPOSITORY
            and os.environ.get("GITHUB_REF") == "refs/heads/main"
            and os.environ.get("GITHUB_EVENT_NAME") == "workflow_dispatch", "main_dispatch_required")
    from_helper = runpy.run_path(str(ROOT / "scripts/ops/old_test_source_authority.py"))
    control = os.environ["GITHUB_SHA"]
    require(subprocess.check_output(["git", "-C", str(ROOT), "rev-parse", "HEAD"], text=True).strip()
            == control, "control_checkout_mismatch")
    authority = authorize(os.environ["INPUT_TASK_ID"], os.environ["INPUT_PR_NUMBER"],
                          os.environ["INPUT_SOURCE_SHA"], control, get=from_helper["api_get"])
    authority.update(run_id=int(os.environ["GITHUB_RUN_ID"]), run_attempt=int(os.environ["GITHUB_RUN_ATTEMPT"]))
    (Path(os.environ["RUNNER_TEMP"]) / "source-authority.json").write_text(json.dumps(authority, sort_keys=True))


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        # Bounded diagnostic: no raw exception text, URL, env or response body.
        raise SystemExit("draft_preview_hold:" + (str(error) if isinstance(error, DraftHold)
                                                else type(error).__name__)) from None
