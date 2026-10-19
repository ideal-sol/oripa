"""Exact-source integration applicability; never accepts caller skip overrides."""

from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts.release.readiness.change import AUTHORITY_PATHS, classify, path_components, validate_change
from scripts.release.readiness.records import canonical, digest, require, seal

SCOPES = (
    "backend", "database", "migration", "admin", "agency", "legacy_frontend",
    "contract", "storefront_client", "testkit", "site_schema", "site_template",
    "release_artifact", "arm64_artifact",
)
JOBS = {
    "integration-suite": ("backend",),
    "integration-database": ("database", "migration"),
    "integration-support": ("legacy_frontend", "site_template", "release_artifact"),
}
GLOBAL_PREFIXES = (
    ".github/", ".ci/", ".codex/", "scripts/release/readiness/",
    "infra/", "infrastructure/", "deployments/", "scripts/db/", "tests/db/",
)
GOVERNANCE_ONLY = {
    "scripts/ci/policy_gate.py", "tests/ci/policy/test_policy_gate.py",
}
OPERATION_WRAPPER_PATHS = {
    "infrastructure/github-app/task_branch_base_sync.py",
    "tests/ops/test_task_branch_base_sync.py",
}
AUTHORITY_TEST_PATHS = {
    "tests/ops/test_old_test_source_authority.py",
    "tests/ops/test_production_source_authority.py",
}


def source_only_authority(change):
    return (change["validation_path"] == "AUTHORITY_FOCUSED"
            and all(path in AUTHORITY_PATHS | AUTHORITY_TEST_PATHS
                    or path_components(path) == {"docs"} for path in change["changed_paths"]))


def affected_scopes(path):
    components = path_components(path)
    if path in OPERATION_WRAPPER_PATHS:
        return set()
    if "dependency" in components or path.startswith(GLOBAL_PREFIXES):
        return set(SCOPES)
    if path in GOVERNANCE_ONLY:
        return set()
    if path.startswith(("scripts/ci/", "tests/ci/", "scripts/ops/", "tests/ops/")):
        return set(SCOPES)
    if path.startswith(("docs/", "worklogs/", "direction/")) or path in {"README.md", "TASK_BOARD.md"}:
        return set()
    if path.startswith(("apps/api/", "backend/", "legacy/v1/")):
        return {"backend", "database", "contract", "release_artifact", "arm64_artifact"} | (
            {"migration"} if "migration" in components else set())
    if path.startswith("apps/admin/"):
        return {"admin", "database", "release_artifact", "arm64_artifact"}
    if path.startswith("apps/agency/"):
        return {"agency", "release_artifact", "arm64_artifact"}
    if path.startswith("legacy/v1-frontend/"):
        return {"legacy_frontend", "release_artifact", "arm64_artifact"}
    if path.startswith(("openapi/", "packages/storefront-client/", "packages/site-schema/", "packages/storefront-testkit/")):
        return {"backend", "database", "admin", "agency", "contract", "storefront_client",
                "testkit", "site_schema", "site_template", "release_artifact", "arm64_artifact"}
    if path.startswith(("scripts/release/", "tests/release/", "manifests/")):
        return set(SCOPES)
    return None


def build_plan(change, *, lane_key, activation, event_name, validation_path):
    require(lane_key in {"lite", "standard", "strict"}, "PLAN_LANE_INVALID")
    require(activation in {"none", "deferred", "immediate"}, "PLAN_ACTIVATION_INVALID")
    require(validation_path in {"AUTHORITY_FOCUSED", "NORMAL_STRICT_CI"}, "PLAN_PATH_INVALID")
    valid = True
    try:
        validate_change(change)
    except ValueError:
        valid = False
    authority = (valid and source_only_authority(change)
                 and validation_path == "AUTHORITY_FOCUSED" and activation in {"none", "deferred"}
                 and event_name == "pull_request")
    paths = change["changed_paths"]
    hits = {scope: [] for scope in SCOPES}
    unknown = not valid
    if not authority:
        for path in paths:
            affected = affected_scopes(path)
            if affected is None:
                unknown = True
            else:
                for scope in affected:
                    hits[scope].append(path)
    full = event_name != "pull_request" or (activation != "none" and not authority)
    scopes = {}
    for scope in SCOPES:
        if unknown:
            status, reason = "UNKNOWN", "Unproven source coverage: execute full validation"
        elif authority:
            status, reason = "NOT_APPLICABLE", "Existing verified AUTHORITY_FOCUSED boundary"
        elif full or hits[scope]:
            status, reason = "APPLICABLE", "Full event/activation validation or affected source inputs"
        else:
            status, reason = "NOT_APPLICABLE", "Canonical component deltas and complete path coverage exclude this scope"
        if scope == "arm64_artifact" and event_name != "pull_request":
            status, reason = "NOT_APPLICABLE", "PR_ONLY_ARM64_BOUNDARY"
        scopes[scope] = {
            "status": status, "reason": reason, "evidence": hits[scope],
            "component_deltas": change["deltas"], "changed_paths_digest": digest(paths, None),
        }
    jobs = {}
    for job, names in JOBS.items():
        applicable = any(scopes[name]["status"] != "NOT_APPLICABLE" for name in names)
        jobs[job] = {
            "required": applicable and (lane_key != "lite" or unknown), "scopes": list(names),
            "reason": "Existing Lite focused quality boundary" if lane_key == "lite" and not unknown
            else "Execute all applicable or UNKNOWN scopes; N/A requires canonical evidence",
        }
    return seal({
        "schema_version": "1.0", "producer": "scripts.ci.platform_ci_plan/v1",
        "base_sha": change["base_sha"], "head_sha": change["head_sha"], "tree_sha": change["tree_sha"],
        "canonical_change_digest": change["record_digest"],
        "changed_paths_digest": digest(paths, None), "component_deltas": change["deltas"],
        "lane_key": lane_key, "activation": activation, "event_name": event_name,
        "validation_path": validation_path, "scopes": scopes, "jobs": jobs,
        "quality_authority": "quality-gate in the same workflow run and exact checkout",
        "fallback": "NORMAL_STRICT_CI" if unknown or full else "NONE",
    }, "record_digest")


def verify_results(plan, needs, expected_digest):
    require(plan["record_digest"] == expected_digest, "PLAN_BINDING_MISMATCH")
    for gate in ("policy-gate", "quality-gate"):
        require(needs.get(gate, {}).get("result") == "success", "PLAN_PREREQUISITE_FAILED:" + gate)
    for job, expectation in plan["jobs"].items():
        expected = "success" if expectation["required"] else "skipped"
        require(needs.get(job, {}).get("result") == expected, "PLAN_JOB_RESULT_INVALID:" + job)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--repository", type=Path, required=True)
    parser.add_argument("--plan", type=Path, required=True)
    parser.add_argument("--expected-digest", required=True)
    parser.add_argument("--results-env", required=True)
    args = parser.parse_args()
    try:
        plan = json.loads(args.plan.read_text())
        change = classify(args.repository, plan["base_sha"], plan["head_sha"])
        expected = build_plan(change, **{key: plan[key] for key in (
            "lane_key", "activation", "event_name", "validation_path")})
        require(canonical(expected) == canonical(plan), "PLAN_RECOMPUTATION_MISMATCH")
        verify_results(expected, json.loads(os.environ[args.results_env]), args.expected_digest)
    except (ValueError, KeyError, OSError) as error:
        print(f"platform-ci-plan: FAIL: {error}", file=sys.stderr)
        return 1
    print("platform-ci-plan: PASS: exact-source applicable jobs and quality authority verified")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
