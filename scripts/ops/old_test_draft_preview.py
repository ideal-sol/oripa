#!/usr/bin/env python3
"""Main-controlled, OLD-only authorization for repeated exact Draft heads."""
from __future__ import annotations

import base64
import json
import os
from pathlib import Path, PurePosixPath
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


def path_role(path, mode):
    """Classify only approved ordinary feature inputs; control files never enter."""
    require(isinstance(path, str) and path and str(PurePosixPath(path)) == path
            and not PurePosixPath(path).is_absolute() and ".." not in path.split("/")
            and "\\" not in path and "*" not in path and not any(ord(c) < 32 for c in path), "path_invalid")
    name = PurePosixPath(path).name.lower()
    require(name not in {"agents.md", "package.json", "composer.json", "composer.lock", "pnpm-lock.yaml",
                        "pnpm-workspace.yaml", "package-lock.json", "yarn.lock", ".npmrc"}
            and not name.startswith((".env", "dockerfile")), "control_path_forbidden")
    parts = {part.lower() for part in PurePosixPath(path).parts}
    require(not parts & {"auth", "identity", "authorization", "session", "mfa", "webauthn",
                         "payment", "point", "wallet", "draw", "inventory"}
            and not re.search(r"(auth|session|csrf|mfa|webauthn|password|permission)", name)
            and not path.startswith(("apps/api/app/Http/Middleware/", "apps/api/app/Providers/")),
            "strict_boundary_required")
    require(path != "apps/admin/src/lib/admin-api/client.ts", "strict_boundary_required")
    if path == "worklogs/new_ver_main.md":
        return "spec"
    if path.startswith(("docs/", "direction/")) and path.endswith(".md"):
        require(path != "docs/architecture/README.md" and not path.startswith(("docs/architecture/V2_CODEX_", "docs/architecture/V2_RELEASE_",
                                     "docs/architecture/V2_AUTONOMOUS_", "docs/architecture/V2_IDENTITY_",
                                     "docs/architecture/V2_DATA_POINT_", "docs/architecture/V2_PACKAGE_",
                                     "docs/architecture/V1_TO_V2_", "docs/architecture/API_V2_AND_",
                                     "docs/operations/security/", "docs/operations/ci/",
                                     "docs/operations/github-rulesets/", "docs/operations/deployment/",
                                     "docs/operations/releases/", "docs/operations/codex-access/")),
                "control_path_forbidden")
        return "spec"
    if path.startswith(("apps/api/tests/", "apps/admin/test/")):
        require(name not in {"setup.ts", "setup.tsx", "bootstrap.php", "testcase.php", "conftest.py"},
                "test_harness_boundary_required")
        return "test"
    if path.startswith("tests/ci/openapi/") and re.fullmatch(r"test_[a-z0-9_]+_contract[.]py", name):
        return "test"
    if path in {"openapi/public/openapi.yaml", "openapi/admin/openapi.yaml", "openapi/webhook/openapi.yaml",
                "openapi/bundled/public.openapi.json", "openapi/bundled/admin.openapi.json",
                "openapi/bundled/webhook.openapi.json", "apps/admin/src/lib/admin-api/generated.ts",
                "apps/admin/scripts/generate-admin-contract.mjs", "packages/storefront-client/src/generated/public.ts"}:
        return "contract"
    roots = (("apps/admin/src/",) if mode == "admin-only" else
             ("apps/api/app/", "apps/api/routes/", "apps/api/resources/") if mode == "api-only" else
             ("apps/admin/src/", "apps/api/app/", "apps/api/routes/", "apps/api/resources/"))
    require(path.startswith(roots), "path_not_approved")
    return "runtime"


def validate_plan(plan):
    require(isinstance(plan, dict) and set(plan) == {
        "task_id", "source_pr", "branch", "baseline_sha", "image_mode", "migration", "allowed_paths", "focused_commands"},
        "plan_invalid")
    require(isinstance(plan["task_id"], str) and re.fullmatch(r"[A-Z][A-Z0-9-]{2,31}", plan["task_id"])
            and type(plan["source_pr"]) is int and 0 < plan["source_pr"] < 10**10
            and isinstance(plan["branch"], str) and re.fullmatch(r"(?:feat|feature|fix|refactor|chore|test)/[A-Za-z0-9._/-]+", plan["branch"])
            and plan["task_id"] in plan["branch"]
            and isinstance(plan["baseline_sha"], str) and SHA.fullmatch(plan["baseline_sha"])
            and isinstance(plan["image_mode"], str) and plan["image_mode"] in {"normal", "api-only", "admin-only"}
            and plan["migration"] == "none", "plan_invalid")
    paths, commands = plan["allowed_paths"], plan["focused_commands"]
    require(isinstance(paths, list) and paths and all(isinstance(p, str) for p in paths)
            and len(set(paths)) == len(paths) and isinstance(commands, list) and commands, "plan_invalid")
    for path in paths:
        path_role(path, plan["image_mode"])
    for command in commands:
        require(isinstance(command, list) and command
                and all(isinstance(arg, str) and arg and "\x00" not in arg for arg in command), "focused_command_invalid")
    return plan


def validate_document(document):
    require(isinstance(document, dict) and set(document) == {"schema_version", "target", "plans"}
            and document["schema_version"] == "1.0" and document["target"] == "old-test"
            and isinstance(document["plans"], list), "old_only_plan_required")
    identities = []
    for plan in document["plans"]:
        validate_plan(plan)
        identities.append((plan["task_id"], plan["source_pr"]))
    require(len(set(identities)) == len(identities), "duplicate_plan")
    return document


def load_document(raw):
    def unique(pairs):
        result = dict(pairs)
        require(len(result) == len(pairs), "duplicate_plan_field")
        return result
    return validate_document(json.loads(raw, object_pairs_hook=unique))


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
    approval = load_document(base64.b64decode("".join(document["content"].splitlines()), validate=True))
    plans = [p for p in approval["plans"] if p.get("task_id") == task and p.get("source_pr") == int(number)]
    require(len(plans) == 1, "approved_plan_required")
    plan = plans[0]
    mode = plan.get("image_mode")
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
    paths = []
    inventory = []
    for item in files:
        path = item.get("filename", "")
        require(item.get("status") in {"added", "modified", "removed"}
                and path in plan["allowed_paths"]
                and ".." not in path.split("/"), "path_not_approved")
        role = path_role(path, mode)
        paths.append(path)
        inventory.append({"path": path, "status": item["status"], "role": role})
    require(any(row["role"] in {"runtime", "contract"} for row in inventory), "application_change_required")
    # Baseline must be merged; otherwise a caller could hide altered control code.
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
            "file_inventory": sorted(inventory, key=lambda row: row["path"]),
            "contract_paths": sorted(row["path"] for row in inventory if row["role"] == "contract"),
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
