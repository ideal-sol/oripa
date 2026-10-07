"""Canonical source deltas shared by Development CI and Readiness consumers."""

import hashlib
import json
from pathlib import Path, PurePosixPath
import subprocess

from .records import DIGEST, SHA, check_digest, digest, fields, matches, require, seal, strings, timestamp


COMPONENTS = (
    "application", "dependency", "migration", "contract", "authority",
    "security_policy", "runtime_config", "api_write_path", "test", "docs",
    "ci_governance",
)
AUTHORITY_PATHS = {
    "manifests/platform-old-test-approved-source.json",
    "manifests/platform-production-approved-source.json",
}
REQUIRED_DEPENDENCIES = {
    "apps/api/composer.json", "apps/api/composer.lock", "package.json",
    "pnpm-lock.yaml", "pnpm-workspace.yaml", "legacy/v1-frontend/package.json",
    "legacy/v1-frontend/pnpm-lock.yaml",
}
PRODUCER = "scripts.release.readiness.change/v1"


def git(repository, *arguments):
    completed = subprocess.run(["git", "-C", str(repository), *arguments], capture_output=True)
    require(completed.returncode == 0, "SOURCE_LOOKUP_FAILED")
    return completed.stdout


def dependency_path(path):
    name = PurePosixPath(path).name
    return (name in {"package.json", "composer.json", "composer.lock", "pnpm-lock.yaml",
                     "pnpm-workspace.yaml", "package-lock.json", "yarn.lock", ".npmrc",
                     ".pnpmfile.cjs", "pnpmfile.cjs", "bun.lock", "bun.lockb"}
            or "patches" in PurePosixPath(path).parts)


def path_components(path):
    parsed = PurePosixPath(path)
    require(not parsed.is_absolute() and str(parsed) == path and ".." not in parsed.parts
            and "\\" not in path and not any(ord(char) < 32 for char in path), "PATH_INVALID")
    if parsed.name in {"requirements.txt", "pyproject.toml", "poetry.lock", "uv.lock", "Cargo.toml", "Cargo.lock",
                       "Gemfile", "Gemfile.lock", "go.mod", "go.sum", "bun.lock", "bun.lockb", "yarn.lock", "package-lock.json"}:
        return set()
    if dependency_path(path):
        return {"dependency"}
    if path in AUTHORITY_PATHS:
        return {"authority"}
    if path.startswith((".github/", ".ci/", ".codex/", "scripts/ci/", "scripts/release/readiness/",
                        "tests/ci/", "infrastructure/github-app/", "docs/operations/ci/")):
        return {"ci_governance", "security_policy"}
    if path.startswith(("docs/architecture/", "docs/operations/security/", "docs/operations/github-rulesets/",
                        "docs/operations/releases/", "docs/operations/deployment/")):
        return {"security_policy", "docs"}
    if path.startswith(("tests/",)) or any(part in {"tests", "test", "__tests__"} for part in parsed.parts):
        return {"test"}
    if path.startswith(("docs/", "worklogs/", "direction/")) or path in {"README.md", "TASK_BOARD.md"}:
        return {"docs"}
    if path.startswith(("openapi/", "packages/storefront-client/", "packages/site-schema/", "packages/storefront-testkit/")):
        return {"contract"}
    if "database" in parsed.parts:
        return {"migration", "application"}
    if (path.startswith(("infra/", "infrastructure/", "deployments/", "scripts/ops/", "scripts/db/", "scripts/release/"))
            or "Dockerfile" in parsed.name or path.startswith("docker-compose")
            or parsed.name.startswith(".env") or path in {".dockerignore", "Makefile"}
            or path.startswith(("apps/api/config/", "apps/api/bootstrap/"))):
        return {"runtime_config", "security_policy"}
    if path in {"AGENTS.md", "SECURITY.md", ".gitignore"} or path.startswith("manifests/"):
        return {"security_policy"}
    if path.startswith(("apps/", "legacy/", "packages/", "backend/")):
        components = {"application"}
        if path.startswith(("apps/api/app/", "apps/api/routes/", "backend/", "legacy/v1/")):
            components.add("api_write_path")
        return components
    return set()


def inventory(repository, revision):
    require(matches(SHA, revision), "SOURCE_SHA_INVALID")
    rows = {}
    for entry in git(repository, "ls-tree", "-r", "-z", revision).split(b"\0"):
        if not entry:
            continue
        metadata, raw_path = entry.split(b"\t", 1)
        mode, kind, object_id = metadata.decode().split()
        path = raw_path.decode("utf-8")
        rows[path] = {"mode": mode, "kind": kind, "object_id": object_id}
    require(rows, "SOURCE_TREE_EMPTY")
    return rows


def source_components(repository, revision, rows):
    components = {name: {} for name in COMPONENTS}
    unknown = []
    for path, entry in rows.items():
        names = path_components(path)
        if not names:
            unknown.append(path)
        for name in names:
            components[name][path] = entry
    require(REQUIRED_DEPENDENCIES <= set(components["dependency"]), "DEPENDENCY_INPUT_MISSING")
    for path, entry in components["dependency"].items():
        require(entry["mode"] == "100644" and entry["kind"] == "blob", "DEPENDENCY_INPUT_MODE_INVALID")
        content = git(repository, "show", revision + ":" + path)
        components["dependency"][path] = {
            **entry, "sha256": "sha256:" + hashlib.sha256(content).hexdigest(), "bytes": len(content),
        }
    return {name: digest(entries, None) for name, entries in components.items()}, components["dependency"], unknown


def change_classes(deltas):
    require(set(deltas) == {name + "_delta" for name in COMPONENTS}, "CLASSIFICATION_INCOMPLETE")
    require(all(value in {"NONE", "PRESENT", "UNKNOWN"} for value in deltas.values()), "DELTA_INVALID")
    classes = [name.upper() for name in COMPONENTS
               if name not in {"authority", "docs", "test"} and deltas[name + "_delta"] == "PRESENT"]
    if authority_only(deltas):
        classes.append("AUTHORITY_ONLY")
    elif deltas["authority_delta"] == "PRESENT":
        classes.append("AUTHORITY")
    present = {name for name in COMPONENTS if deltas[name + "_delta"] != "NONE"}
    if present and present <= {"test", "docs"}:
        classes.append("TEST_ONLY" if "test" in present else "DOCS_ONLY")
    return sorted(classes)


def authority_only(deltas):
    return (deltas.get("authority_delta") == "PRESENT"
            and all(deltas.get(name + "_delta") == "NONE" for name in COMPONENTS
                    if name not in {"authority", "test", "docs"})
            and all(value != "UNKNOWN" for value in deltas.values()))


def validate_authority(repository, revision, path, base_sha):
    def unique_pairs(pairs):
        record = dict(pairs)
        require(len(record) == len(pairs), "AUTHORITY_DUPLICATE_FIELD")
        return record
    record = json.loads(git(repository, "show", revision + ":" + path).decode("utf-8"), object_pairs_hook=unique_pairs)
    old = path.endswith("old-test-approved-source.json")
    expected = {"schema_version", "repository", "source_sha", "authority", "activation_authorized"}
    expected |= {"task_id", "source_pr", "target", "platform", "image_mode"} if old else {"change_id", "pr_number"}
    require(isinstance(record, dict) and set(record) == expected, "AUTHORITY_STRUCTURE_INVALID")
    require(record["schema_version"] == "1.0" and record["repository"] == "ideal-sol/oripa"
            and record["activation_authorized"] is False, "AUTHORITY_BOUNDARY_INVALID")
    require(matches(SHA, record["source_sha"]), "AUTHORITY_SOURCE_INVALID")
    require(type(record["source_pr" if old else "pr_number"]) is int
            and record["source_pr" if old else "pr_number"] > 0, "AUTHORITY_PR_INVALID")
    for key in ("authority", "task_id" if old else "change_id"):
        require(isinstance(record[key], str) and record[key].strip(), "AUTHORITY_IDENTITY_MISSING")
    require(record["authority"].startswith("Human-approved exact OLD Test payload:" if old
                                          else "Human-approved exact Runtime Source:"), "AUTHORITY_APPROVAL_STRUCTURE_INVALID")
    if old:
        require(record["target"] == "old-test" and record["platform"] == "linux/amd64"
                and record["image_mode"] in {"normal", "api-only"}, "AUTHORITY_SCOPE_INVALID")
    git(repository, "merge-base", "--is-ancestor", record["source_sha"], base_sha)
    return {"path": path, "source_sha": record["source_sha"],
            "source_pr": record["source_pr" if old else "pr_number"],
            "source_tree": git(repository, "rev-parse", record["source_sha"] + "^{tree}").decode().strip(),
            "record_digest": digest(record, None), "provenance": "PROTECTED_BASE_ANCESTOR",
            "human_approval": "PR_REVIEW_REQUIRED", "activation_authorized": False}


def classify(repository, base_sha, head_sha):
    repository = Path(repository)
    before, after = inventory(repository, base_sha), inventory(repository, head_sha)
    base_digests, base_dependencies, _ = source_components(repository, base_sha, before)
    head_digests, head_dependencies, _ = source_components(repository, head_sha, after)
    changed = sorted(path for path in before.keys() | after.keys() if before.get(path) != after.get(path))
    unknown = [path for path in changed if not path_components(path)
               or any(entry and (entry["kind"] != "blob" or entry["mode"] not in {"100644", "100755"})
                      for entry in (before.get(path), after.get(path)))]
    deltas = {name + "_delta": "UNKNOWN" if unknown else
              "NONE" if base_digests[name] == head_digests[name] else "PRESENT" for name in COMPONENTS}
    authority = [validate_authority(repository, head_sha, path, base_sha)
                 for path in changed if path in AUTHORITY_PATHS]
    return seal({
        "schema_version": "1.0", "producer": PRODUCER, "repository": "ideal-sol/oripa",
        "base_sha": base_sha, "head_sha": head_sha,
        "tree_sha": git(repository, "rev-parse", head_sha + "^{tree}").decode().strip(),
        "base_tree_sha": git(repository, "rev-parse", base_sha + "^{tree}").decode().strip(),
        "evidence_reference": "git:" + base_sha + ".." + head_sha,
        "changed_paths": changed, "unknown_paths": unknown, "deltas": deltas,
        "change_classes": change_classes(deltas), "base_components": base_digests,
        "head_components": head_digests,
        "delta_components": {name: digest({path: {"before": before.get(path), "after": after.get(path)}
                                           for path in changed if name in path_components(path)}, None)
                             for name in COMPONENTS},
        "dependency_inputs": {"base": base_dependencies, "head": head_dependencies},
        "authority_records": authority, "reuse": "NOT_PROVEN",
        "validation_path": "AUTHORITY_FOCUSED" if authority_only(deltas) else "NORMAL_STRICT_CI",
    }, "record_digest")


def validate_change(record, binding=None):
    require(isinstance(record, dict), "CLASSIFICATION_MISSING")
    fields(record, "schema_version producer repository base_sha head_sha tree_sha base_tree_sha "
                   "evidence_reference changed_paths unknown_paths deltas change_classes base_components "
                   "head_components delta_components dependency_inputs authority_records reuse validation_path record_digest")
    check_digest(record, "record_digest")
    require(record.get("schema_version") == "1.0" and record.get("producer") == PRODUCER
            and record.get("repository") == "ideal-sol/oripa", "CLASSIFICATION_PRODUCER_INVALID")
    for key in ("base_sha", "head_sha", "tree_sha", "base_tree_sha"):
        require(matches(SHA, record.get(key)), "CLASSIFICATION_IDENTITY_INVALID")
    require(record["evidence_reference"] == "git:" + record["base_sha"] + ".." + record["head_sha"]
            and record["reuse"] == "NOT_PROVEN", "CLASSIFICATION_REFERENCE_INVALID")
    require(strings(record["changed_paths"]) and record["changed_paths"] == sorted(record["changed_paths"]),
            "CLASSIFICATION_PATHS_INVALID")
    for path in record["changed_paths"]:
        require(path_components(path), "CLASSIFICATION_UNKNOWN")
    for key in ("base_components", "head_components", "delta_components"):
        require(isinstance(record[key], dict) and set(record[key]) == set(COMPONENTS), "COMPONENT_INVENTORY_INVALID")
        require(all(matches(DIGEST, value) for value in record[key].values()), "COMPONENT_DIGEST_INVALID")
    require(isinstance(record["authority_records"], list)
            and all(isinstance(entry, dict) for entry in record["authority_records"]), "AUTHORITY_RECORDS_INVALID")
    require([entry.get("path") for entry in record["authority_records"]] ==
            [path for path in record["changed_paths"] if path in AUTHORITY_PATHS], "AUTHORITY_RECORDS_MISSING")
    if binding is not None:
        require(all(record.get(key) == value for key, value in binding.items()), "CLASSIFICATION_BINDING_MISMATCH")
    require(record.get("unknown_paths") == [], "CLASSIFICATION_UNKNOWN")
    require(record.get("change_classes") == change_classes(record.get("deltas", {})), "CLASSIFICATION_CLASSES_MISMATCH")
    for name in COMPONENTS:
        before = record.get("base_components", {}).get(name)
        after = record.get("head_components", {}).get(name)
        require(matches(DIGEST, before) and matches(DIGEST, after), "COMPONENT_DIGEST_INVALID")
        require(record["deltas"][name + "_delta"] == ("NONE" if before == after else "PRESENT"), "DELTA_DIGEST_MISMATCH")
    for side in ("base", "head"):
        inputs = record.get("dependency_inputs", {}).get(side)
        require(isinstance(inputs, dict) and REQUIRED_DEPENDENCIES <= set(inputs), "DEPENDENCY_INPUT_MISSING")
        for path, entry in inputs.items():
            require(dependency_path(path) and isinstance(entry, dict)
                    and matches(DIGEST, entry.get("sha256")) and matches(SHA, entry.get("object_id"))
                    and entry.get("mode") == "100644" and entry.get("kind") == "blob"
                    and type(entry.get("bytes")) is int and entry["bytes"] > 0, "DEPENDENCY_FINGERPRINT_INVALID")
        require(digest(inputs, None) == record[side + "_components"]["dependency"], "DEPENDENCY_FINGERPRINT_MISMATCH")
    expected = "AUTHORITY_FOCUSED" if authority_only(record["deltas"]) else "NORMAL_STRICT_CI"
    require(record.get("validation_path") == expected, "CLASSIFICATION_PATH_MISMATCH")
    return record


def compare_evidence(previous, current):
    validate_change(previous)
    validate_change(current)
    same = {name: previous["head_components"][name] == current["head_components"][name] for name in COMPONENTS}
    return {"same_components": same, "invalidated": [name for name, equal in same.items() if not equal],
            "same_delta_components": {name: previous["delta_components"][name] == current["delta_components"][name]
                                      for name in COMPONENTS},
            "authority_continuity": "REFRESH_REQUIRED", "advisory_posture": "REFRESH_REQUIRED",
            "required_check_inventory": "REFRESH_REQUIRED", "reuse": "NOT_PROVEN"}


def security_posture(record, binding):
    require(isinstance(record, dict), "SECURITY_POSTURE_MISSING")
    check_digest(record, "record_digest")
    require(record.get("schema_version") == "1.0"
            and record.get("producer") == "scripts.ci.security_gate/v1", "SECURITY_PRODUCER_INVALID")
    timestamp(record.get("observed_at"))
    require(all(record.get(key) == value for key, value in binding.items()), "SECURITY_BINDING_MISMATCH")
    for key in ("dependency_fingerprint", "audit_input_digest", "change_digest"):
        require(matches(DIGEST, record.get(key)), "SECURITY_DIGEST_INVALID")
    findings = record.get("current_security_findings")
    require(isinstance(findings, list), "SECURITY_FINDINGS_MISSING")
    for finding in findings:
        require(isinstance(finding, dict) and type(finding.get("approved")) is bool
                and all(isinstance(finding.get(key), str) and finding[key] for key in
                        ("advisory_id", "package", "version", "path", "severity", "runtime_scope")), "SECURITY_FINDING_INVALID")
    count = sum(not finding["approved"] for finding in findings)
    require(type(record.get("unapproved_findings")) is int and record["unapproved_findings"] == count
            and type(record.get("current_findings")) is int
            and record["current_findings"] == len(findings), "SECURITY_FINDING_COUNT_MISMATCH")
    expected = "HOLD_UNAPPROVED_FINDING" if count else "PASS_APPROVED_POLICY"
    require(record.get("dependency_delta") in {"NONE", "PRESENT"}, "SECURITY_DEPENDENCY_UNKNOWN")
    development = "PASS_NO_PR_INTRODUCED_DEPENDENCY_REGRESSION" if record["dependency_delta"] == "NONE" else (
        "BLOCK_PR_INTRODUCED_SECURITY_FINDING" if count else "PASS_FRESH_AUDITS")
    require(record.get("development_security_result") == development, "SECURITY_DEVELOPMENT_RESULT_MISMATCH")
    require(record.get("current_security_posture") == expected
            and record.get("security_maintenance_required") is bool(count), "SECURITY_POSTURE_MISMATCH")
    return "HOLD" if count else "PASS"
