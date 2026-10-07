"""Reuse existing validators and explicitly supplied evidence transports."""

import json
import re

from .evaluator import SOURCE
from .records import SHA, matches, require, seal, text, timestamp
from .change import classify, security_posture, validate_change


def verify_change_authority(change, get=None):
    get = get or SOURCE["api_get"]
    root = "/repos/ideal-sol/oripa"
    main = get(root + "/branches/main")
    require(main.get("protected") is True and main.get("commit", {}).get("sha") == change["base_sha"],
            "AUTHORITY_BASE_MOVED")
    evidence = []
    for record in change["authority_records"]:
        pull = get(root + "/pulls/" + str(record["source_pr"]))
        require(pull.get("merged") is True and pull.get("merge_commit_sha") == record["source_sha"]
                and pull.get("base", {}).get("ref") == "main"
                and pull.get("head", {}).get("repo", {}).get("full_name") == "ideal-sol/oripa",
                "AUTHORITY_PR_MISMATCH")
        reviewed = pull.get("head", {}).get("sha")
        require(matches(SHA, reviewed), "AUTHORITY_REVIEWED_SHA_INVALID")
        commit = get(root + "/git/commits/" + reviewed)
        require(commit.get("sha") == reviewed and commit.get("tree", {}).get("sha") == record["source_tree"],
                "AUTHORITY_PROVENANCE_MISMATCH")
        evidence.append(SOURCE["checks_for"](get, reviewed))
    require(get(root + "/branches/main") == main, "AUTHORITY_CONTINUITY_CHANGED")
    return {"base_sha": change["base_sha"], "head_sha": change["head_sha"],
            "change_digest": change["record_digest"], "source_checks": evidence,
            "continuity": "CONFIRMED_AT_VALIDATION", "reuse": "NOT_PROVEN"}


def confirm_advisory_drift(repository, change, current, previous_sha, *, get, get_bytes):
    validate_change(change)
    binding = {key: change[key] for key in ("repository", "base_sha", "head_sha", "tree_sha")}
    require(security_posture(current, binding) == "HOLD"
            and change["deltas"]["dependency_delta"] == "NONE", "DRIFT_UNCHANGED_FINDING_REQUIRED")
    require(matches(SHA, previous_sha), "DRIFT_PREVIOUS_SHA_INVALID")
    historical = validate_change(classify(repository, previous_sha, change["base_sha"]))
    require(historical["deltas"]["dependency_delta"] == "NONE"
            and historical["deltas"]["security_policy_delta"] == "NONE", "DRIFT_INPUTS_CHANGED")
    root = "/repos/ideal-sol/oripa"
    checks = SOURCE["CHECK_GATE"]["list_required_check_runs"](get, repository="ideal-sol/oripa", head_sha=previous_sha)
    evaluated = SOURCE["CHECK_GATE"]["evaluate_required_check_runs"](
        checks, head_sha=previous_sha, required_checks={"security-gate"},
    )
    require(evaluated["passed"], "DRIFT_PRIOR_SECURITY_PASS_NOT_PROVEN")
    selected = evaluated["runs"][0]
    require(timestamp(selected.get("completed_at")) < timestamp(current.get("observed_at")),
            "DRIFT_CHRONOLOGY_INVALID")
    check = next(row for row in checks if row.get("id") == selected["id"])
    match = re.fullmatch(r"https://github.com/ideal-sol/oripa/actions/runs/([0-9]+)/job/([0-9]+)", check.get("details_url", ""))
    require(match is not None, "DRIFT_LOG_PROVENANCE_MISSING")
    run_id, job_id = match.groups()
    job = get(root + "/actions/jobs/" + job_id)
    require(job.get("run_id") == int(run_id) and job.get("check_run_url") ==
            "https://api.github.com" + root + "/check-runs/" + str(selected["id"])
            and job.get("head_sha") == previous_sha and job.get("conclusion") == "success", "DRIFT_JOB_BINDING_MISMATCH")
    reports = []
    for line in get_bytes(root + "/actions/jobs/" + job_id + "/logs").decode("utf-8").splitlines():
        start = line.find("{")
        if start < 0:
            continue
        try:
            report = json.loads(line[start:])
        except ValueError:
            continue
        if isinstance(report, dict) and report.get("gate") == "security-gate":
            reports.append(report)
    require(len(reports) == 1 and reports[0].get("status") == "PASS"
            and reports[0].get("unapproved_findings") == 0, "DRIFT_PRIOR_AUDIT_PASS_MISSING")
    return seal({**current, "finding_classification": "ADVISORY_DB_DRIFT_CONFIRMED",
                 "advisory_db_drift": "ADVISORY_DB_DRIFT_CONFIRMED",
                 "historical_security_pass": {"source_sha": previous_sha,
                    "dependency_fingerprint": historical["head_components"]["dependency"],
                    "check_run_id": selected["id"], "evidence_reference": check["details_url"]}}, "record_digest")


def replay_source_authority(repository, source_sha, workflow_sha, change_id, pr_number, responses):
    def offline_get(path):
        require(path in responses, "SOURCE_REPLAY_RESPONSE_MISSING")
        return responses[path]

    return SOURCE["authorize"](
        repository, source_sha, workflow_sha, change_id, pr_number, get=offline_get,
    )


def platform_artifact_receipt(verified, artifact, service):
    require(verified.get("status") == "verified", "VERIFICATION_RECEIPT_NOT_SUCCESSFUL")
    require(verified.get("artifact_kind") == "production-candidate"
            and verified.get("architecture") == "arm64" and verified.get("platform") == "linux/arm64",
            "PRODUCTION_ARCHITECTURE_RECEIPT_INVALID")
    require(text(verified.get("manifest_sha256")), "MANIFEST_RECEIPT_MISSING")
    images = [image for image in verified.get("images", []) if image.get("name") == service]
    require(len(images) == 1 and images[0].get("image_id") == artifact["digest"], "IMAGE_RECEIPT_MISMATCH")
    return {
        "status": "VERIFIED", "artifact": artifact, "source_sha": verified["source_commit"],
        "architecture": "arm64", "validator": "scripts/ops/preview_image_artifact.py:verify_artifact",
        "manifest_sha256": verified["manifest_sha256"],
    }
