"""Merged-source evidence for the OLD Test simple path (both repositories)."""
import json
import hashlib
import re
import runpy
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
POLICY = ROOT / "infrastructure/github-app"
CHECK = runpy.run_path(str(POLICY / "check_run_gate.py"))
REVIEW = runpy.run_path(str(POLICY / "self_review_policy.py"))
REQUIRED = {"policy-gate", "quality-gate", "security-gate", "integration-gate", "ci-gate"}
PLATFORM = "ideal-sol/oripa"
SITE = "ideal-sol/luxe-pack-storefront"
SHA = re.compile(r"[0-9a-f]{40}")
MODES = {"api-only", "admin-only", "normal"}


class Hold(ValueError):
    """Messages are static diagnostic codes, safe for the operation journal."""


def require(ok, reason):
    if not ok:
        raise Hold(reason)


def merged_source(repo, pr, sha, get):
    require(repo in (PLATFORM, SITE) and type(pr) is int and pr > 0
            and SHA.fullmatch(sha), "invalid_source")
    prefix = f"/repos/{repo}"
    branch = get(prefix + "/branches/main")
    main = branch["commit"]["sha"]
    require(branch["protected"] is True and SHA.fullmatch(main), "unprotected_main")
    ancestry = get(f"{prefix}/compare/{sha}...{main}")
    require(ancestry["status"] in ("ahead", "identical")
            and ancestry["merge_base_commit"]["sha"] == sha, "source_outside_main")
    pull = get(f"{prefix}/pulls/{pr}")
    head = pull["head"]["sha"]
    require(pull["merged"] is True and pull["state"] == "closed" and pull["merge_commit_sha"] == sha
            and pull["base"]["ref"] == "main" and pull["base"]["repo"]["full_name"] == repo
            and pull["head"]["repo"]["full_name"] == repo and SHA.fullmatch(head), "merged_pr_mismatch")
    merged = get(f"{prefix}/git/commits/{sha}")
    reviewed = get(f"{prefix}/git/commits/{head}")
    tree = merged["tree"]["sha"]
    require(merged["sha"] == sha and reviewed["sha"] == head and SHA.fullmatch(tree)
            and tree == reviewed["tree"]["sha"], "reviewed_tree_mismatch")
    runs = CHECK["list_required_check_runs"](get, repository=repo, head_sha=head)
    checks = CHECK["evaluate_required_check_runs"](runs, head_sha=head, required_checks=REQUIRED)
    require(checks["passed"], "required_checks_failed")

    # The merge broker publishes its authenticated review before squash. Ignore
    # editable PR text, other authors and comments edited after merge.
    actor = pull["merged_by"]
    require(actor["type"] == "Bot" and type(actor["id"]) is int, "merge_broker_required")
    marker = ("oripa-self-review:v2" if repo == PLATFORM else "luxe-pack-storefront-self-review:v1")
    merged_at = REVIEW["parse_time"](pull["merged_at"])
    matches = []
    for page in range(1, 11):
        comments = get(f"{prefix}/issues/{pr}/comments?per_page=100&page={page}")
        for comment in comments:
            if comment["user"]["id"] != actor["id"] or REVIEW["parse_time"](comment["updated_at"]) > merged_at:
                continue
            match = re.fullmatch(r"<!-- " + marker + r" -->\s*```json\s*({.*})\s*```\s*",
                                 comment["body"], re.DOTALL)
            if match:
                proof = json.loads(match[1])
                if proof.get("head_sha") == head:
                    matches.append((comment["id"], proof))
        if len(comments) < 100:
            break
    else:
        raise Hold("review_history_truncated")
    require(matches, "review_missing")
    review_id, proof = max(matches, key=lambda entry: entry[0])
    require(proof.get("schema_version") == ("2.0" if repo == PLATFORM else "1.0")
            and proof["merge_recommendation"] == "MERGE" and proof["scope"] == proof["secret_pii"] == "PASS"
            and proof["github_checks"]["passed"] is True and proof["local_validation"]
            and all(item["status"] == "PASS" for item in proof["local_validation"])
            and proof["findings"]["SEV-0"] == proof["findings"]["SEV-1"] == 0, "review_failed")
    REVIEW["validate_freshness"](proof["lane"] if repo == PLATFORM else "Strict Change",
                                 proof["created_at"], now=merged_at)
    return {"repository": repo, "source_pr": pr, "source_sha": sha, "source_tree": tree,
            "reviewed_sha": head, "review_comment": review_id,
            "checks": [run["id"] for run in checks["runs"]]}


def dispatch_plan(env):
    issuer = env.get("SIMPLE_ISSUER_ID", "")
    plan = env.get("SIMPLE_PLAN", "")
    require(env.get("SIMPLE_ENABLED") == "true" and re.fullmatch(r"[1-9][0-9]*", issuer)
            and env.get("GITHUB_ACTOR_ID") == issuer and env.get("GITHUB_RUN_ATTEMPT") == "1"
            and re.fullmatch(r"sha256:[0-9a-f]{64}", plan), "approved_dispatch_required")
    return plan


def build_authority(task, pr, sha, control, mode, get):
    require(re.fullmatch(r"[A-Z][A-Z0-9-]{2,31}", task) and re.fullmatch(r"[1-9][0-9]{0,9}", pr)
            and SHA.fullmatch(control) and mode in MODES, "invalid_build_request")
    evidence = merged_source(PLATFORM, int(pr), sha, get)
    return {**evidence, "task_id": task, "target": "old-test", "authority_mode": "simple",
            "activation_authorized": False, "platform": "linux/amd64", "image_mode": mode,
            "control_sha": control}


def recipe_identity(role, recipe, build_files):
    """Bind relevant control blobs so an unrelated merge preserves the recipe."""
    require(role in ("api", "admin") and len(build_files) in (3, 4)
            and all(SHA.fullmatch(blob) for blob in build_files), "invalid_recipe")
    return "sha256:" + hashlib.sha256(json.dumps([role, *build_files]).encode() + b"\0" + recipe).hexdigest()


def validate_download(record, run, authority, verified, outer_digest, role, recipe, build_files, contract):
    """Admission after canonical zip/archive verification. Cache only its result."""
    sha, task = authority["source_sha"], authority["task_id"]
    control = authority["control_sha"]
    workflow = ".github/workflows/old-test-main-artifact.yml"
    require(record["expired"] is False and record["digest"] == outer_digest
            and re.fullmatch(r"sha256:[0-9a-f]{64}", outer_digest)
            and type(record["id"]) is int and record["id"] > 0, "artifact_unavailable")
    require(authority.get("authority_mode") in (None, "simple") and authority["target"] == "old-test"
            and authority["platform"] == "linux/amd64" and authority["image_mode"] in MODES,
            "artifact_scope_invalid")
    prefix = "oripa-old-test-simple-images" if authority.get("authority_mode") == "simple" else "oripa-old-test-images"
    require(record["name"] == f"{prefix}-{task}-{sha}" and run["head_sha"] == control
            and run["repository"]["full_name"] == PLATFORM and run["head_branch"] == "main"
            and run["event"] == "workflow_dispatch" and run["path"] == workflow
            and run["status"] == "completed" and run["conclusion"] == "success"
            and run["id"] == record["workflow_run"]["id"] == authority["run_id"]
            and run["run_attempt"] == authority["run_attempt"], "untrusted_workflow_run")
    require(verified["status"] == "verified" and verified["task_id"] == task
            and verified["source_commit"] == sha and verified["pull_request"] == authority["source_pr"]
            and verified["platform"] == "linux/amd64" and verified["artifact_kind"] == "old-test",
            "archive_identity_mismatch")
    images = {image["name"]: image["image_id"] for image in verified["images"]}
    expected = {"normal": ["api", "admin"], "api-only": ["api"], "admin-only": ["admin"]}
    require(len(images) == len(verified["images"]) and list(images) == expected[authority["image_mode"]]
            and role in images, "archive_target_mismatch")
    return {"repository": PLATFORM, "source": sha, "revision": sha, "role": role, "target": "old-test",
            "identity": images[role], "contract": contract, "workflow": workflow, "arch": "linux/amd64",
            "recipe": recipe_identity(role, recipe, build_files), "artifact_id": record["id"],
            "artifact_digest": outer_digest, "run_id": run["id"]}
