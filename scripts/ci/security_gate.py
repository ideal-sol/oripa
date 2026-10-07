#!/usr/bin/env python3
"""High-confidence repository and dependency security checks."""

from __future__ import annotations

import argparse
from collections import Counter
from datetime import datetime, timezone
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts.release.readiness.change import classify, validate_change
from scripts.release.readiness.records import canonical, digest, load, seal

class SecurityFailure(RuntimeError):
    pass


SECRET_PATTERNS = {
    "private-key": re.compile(rb"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----"),
    "github-token": re.compile(rb"\b(?:gh[pousr]_|github_pat_)[A-Za-z0-9_]{20,}\b"),
    "aws-access-key": re.compile(rb"\b(?:AKIA|ASIA)[A-Z0-9]{16}\b"),
    "bearer-token": re.compile(rb"Authorization:\s*Bearer\s+[A-Za-z0-9._-]{20,}", re.I),
}


def git_output(repository: Path, *arguments: str) -> str:
    result = subprocess.run(
        ["git", "-C", str(repository), *arguments],
        check=False,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
    )
    if result.returncode:
        raise SecurityFailure(f"git command failed: {' '.join(arguments)}")
    return result.stdout


def tracked_paths(repository: Path) -> list[str]:
    return [line for line in git_output(repository, "ls-files").splitlines() if line]


def dangerous_paths(paths: list[str]) -> list[str]:
    findings = []
    for path in paths:
        lowered = path.lower()
        name = Path(lowered).name
        if name == ".env" or (
            name.startswith(".env.")
            and not name.endswith((".example", ".template", ".sample"))
        ):
            findings.append(path)
        if name in {"id_rsa", "id_ed25519", "credentials.json"}:
            findings.append(path)
        if lowered.endswith((".pem", ".key", ".p12", ".pfx")):
            findings.append(path)
        if re.search(r"(?:^|/)(?:dump|backup)[^/]*\.(?:sql|zip|tar|gz)$", lowered):
            findings.append(path)
    return sorted(set(findings))


def secret_candidates(repository: Path, paths: list[str]) -> list[dict]:
    findings = []
    for relative in paths:
        path = repository / relative
        try:
            content = path.read_bytes()
        except OSError as error:
            raise SecurityFailure(f"cannot read tracked path: {relative}") from error
        if b"\x00" in content or len(content) > 5_000_000:
            continue
        for category, pattern in SECRET_PATTERNS.items():
            if pattern.search(content):
                findings.append({"category": category, "path": relative})
    return findings


def composer_findings(audit: dict, lock: dict) -> list[dict]:
    if (not isinstance(audit, dict) or "advisories" not in audit
            or audit.get("error") or audit.get("errors") or audit.get("ignored-advisories")):
        raise SecurityFailure("composer audit advisories are missing")
    advisories_by_package = audit["advisories"]
    if advisories_by_package == []:
        advisories_by_package = {}
    if not isinstance(advisories_by_package, dict):
        raise SecurityFailure("composer audit advisories are malformed")

    if (
        not isinstance(lock, dict)
        or not isinstance(lock.get("packages"), list)
        or not isinstance(lock.get("packages-dev", []), list)
        or any(
            not isinstance(item, dict)
            or not isinstance(item.get("name"), str)
            or not isinstance(item.get("version"), str)
            for item in lock["packages"] + lock.get("packages-dev", [])
        )
    ):
        raise SecurityFailure("Composer lock is missing or malformed")
    versions = {
        item["name"]: item["version"]
        for item in lock.get("packages", []) + lock.get("packages-dev", [])
    }
    findings = []
    for package, advisories in advisories_by_package.items():
        if (
            not isinstance(package, str)
            or package not in versions
            or not isinstance(advisories, list)
            or not advisories
        ):
            raise SecurityFailure("composer audit advisories are malformed")
        for advisory in advisories:
            if not isinstance(advisory, dict):
                raise SecurityFailure("composer audit advisories are malformed")
            findings.append(
                {
                    "source": "composer",
                    "advisory_id": advisory.get("advisoryId") or advisory.get("cve"),
                    "package": package,
                    "version": versions.get(package),
                    "severity": advisory.get("severity") or "unknown",
                    "cve": advisory.get("cve"),
                }
            )
    validate_findings(findings, "composer")
    return sorted(findings, key=lambda item: json.dumps(item, sort_keys=True))


def pnpm_findings(audit: dict, lock: str) -> list[dict]:
    if (
        not isinstance(audit, dict)
        or not isinstance(audit.get("advisories"), dict)
        or not isinstance(audit.get("metadata"), dict)
        or audit.get("error")
        or audit.get("muted")
    ):
        raise SecurityFailure("pnpm audit structure is missing, malformed, or suppressed")
    counts = audit["metadata"].get("vulnerabilities")
    severities = {"info", "low", "moderate", "high", "critical"}
    if (
        not isinstance(counts, dict)
        or set(counts) != severities
        or any(type(value) is not int or value < 0 for value in counts.values())
        or not isinstance(lock, str)
        or "lockfileVersion:" not in lock
        or "importers:" not in lock
    ):
        raise SecurityFailure("pnpm audit counts or lockfile are missing or malformed")
    advisory_counts = Counter()
    findings = []
    for audit_id, advisory in audit["advisories"].items():
        if (
            not isinstance(advisory, dict)
            or not isinstance(advisory.get("findings"), list)
            or not advisory["findings"]
            or not isinstance(advisory.get("severity"), str)
            or advisory["severity"] not in severities
        ):
            raise SecurityFailure("pnpm advisory is malformed")
        advisory_counts[advisory["severity"]] += 1
        advisory_id = str(advisory.get("url", "")).rstrip("/").split("/")[-1]
        if not re.fullmatch(r"GHSA-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4}", advisory_id):
            raise SecurityFailure("pnpm advisory identity is missing or malformed")
        for finding in advisory["findings"]:
            if (
                not isinstance(finding, dict)
                or not isinstance(finding.get("paths"), list)
                or not finding["paths"]
                or not isinstance(finding.get("version"), str)
                or not isinstance(advisory.get("module_name"), str)
            ):
                raise SecurityFailure("pnpm advisory finding is malformed")
            package_version = re.escape(advisory["module_name"] + "@" + finding["version"])
            if not re.search(
                r"(?m)^  ['\"]?" + package_version + r"(?:\([^\n]*\))?['\"]?:$", lock
            ):
                raise SecurityFailure("pnpm advisory package/version is absent from lock")
            for path in finding["paths"]:
                findings.append(
                    {
                        "source": "pnpm",
                        "advisory_id": advisory_id,
                        "audit_id": str(audit_id),
                        "package": advisory.get("module_name"),
                        "version": finding.get("version"),
                        "severity": advisory.get("severity"),
                        "path": path,
                    }
                )
    if any(counts[severity] != advisory_counts[severity] for severity in severities):
        raise SecurityFailure("pnpm audit advisory counts are inconsistent")
    validate_findings(findings, "pnpm")
    return sorted(findings, key=lambda item: json.dumps(item, sort_keys=True))


def validate_findings(findings: list[dict], source: str) -> None:
    required = {"source", "advisory_id", "package", "version", "severity"}
    fields = required | ({"cve"} if source == "composer" else {"audit_id", "path"})
    if not isinstance(findings, list):
        raise SecurityFailure("dependency findings are missing or malformed")
    for finding in findings:
        if (
            not isinstance(finding, dict)
            or set(finding) != fields
            or any(
                not isinstance(finding[key], str) or not finding[key].strip()
                for key in fields - {"cve"}
            )
            or finding["source"] != source
            or finding["severity"] not in {
                "info", "low", "medium", "moderate", "high", "critical", "unknown",
            }
            or (source == "composer" and finding["cve"] is not None
                and not isinstance(finding["cve"], str))
        ):
            raise SecurityFailure("dependency finding identity is incomplete or malformed")


def validate_audit_status(status: int, findings: list[dict], source: str) -> None:
    if type(status) is not int or status != int(bool(findings)):
        raise SecurityFailure(
            f"{source} audit command failed or exit status contradicts findings"
        )


def validate_dependency_baseline(
    composer: list[dict], pnpm: list[dict], baseline: dict
) -> dict:
    if not isinstance(baseline, dict) or baseline.get("schema_version") != "1.1":
        raise SecurityFailure("unsupported dependency baseline schema")
    management = baseline.get("management", {})
    required = {"owner", "reason", "tracking_task", "removal_condition"}
    if not isinstance(management, dict) or any(
        not isinstance(management.get(key), str) or not management[key].strip()
        for key in required
    ):
        raise SecurityFailure("dependency baseline management metadata is incomplete")
    summary = {
        "composer_advisories": len(composer),
        "pnpm_findings": len(pnpm),
    }
    for source, actual in (("composer", composer), ("pnpm", pnpm)):
        expected = baseline.get(source)
        validate_findings(actual, source)
        validate_findings(expected, source)
        actual_counts = Counter(json.dumps(item, sort_keys=True) for item in actual)
        expected_counts = Counter(json.dumps(item, sort_keys=True) for item in expected)
        added = actual_counts - expected_counts
        if added:
            raise SecurityFailure(
                f"{source} advisory baseline mismatch: new="
                + json.dumps(list(added.elements()))
            )
        summary[source] = {
            "current_findings": len(actual),
            "known_findings": len(actual),
            "new_findings": 0,
            "resolved_baseline_entries": sum((expected_counts - actual_counts).values()),
        }
    return summary


def validate_workspace_pnpm_audit(pnpm: list[dict]) -> int:
    if pnpm:
        raise SecurityFailure(
            "V2 workspace pnpm audit contains findings; do not extend the V1 baseline"
        )
    return 0


DEV_TOOL_SCOPES = {
    "workspace": ("apps/admin/package.json", "apps__admin"),
    "legacy": ("legacy/v1-frontend/package.json", "."),
}
DEV_TOOL_CHAIN = "eslint-config-next>@next/eslint-plugin-next>fast-glob>micromatch>braces"


def advisory_security_fingerprint(advisory: dict) -> dict:
    fields = {
        "id", "github_advisory_id", "module_name", "severity",
        "vulnerable_versions", "patched_versions", "cves", "cwe", "cvss",
    }
    if not isinstance(advisory, dict) or not fields.issubset(advisory):
        raise SecurityFailure("advisory security fingerprint is incomplete")
    if (
        type(advisory["id"]) is not int
        or any(
            not isinstance(advisory[key], str) or not advisory[key].strip()
            for key in fields - {"id", "cves", "cwe", "cvss"}
        )
        or any(
            not isinstance(advisory[key], list) or not advisory[key]
            or any(not isinstance(value, str) or not value.strip() for value in advisory[key])
            for key in ("cves", "cwe")
        )
        or not isinstance(advisory["cvss"], dict)
        or type(advisory["cvss"].get("score")) not in (int, float)
        or not isinstance(advisory["cvss"].get("vectorString"), str)
        or not advisory["cvss"]["vectorString"].strip()
    ):
        raise SecurityFailure("advisory security fingerprint is malformed")
    return {
        **{key: advisory[key] for key in fields - {"cvss"}},
        "cvss": {key: advisory["cvss"][key] for key in ("score", "vectorString")},
    }


def partition_dev_tool_findings(
    repository: Path, scope: str, findings: list[dict], audit: dict, baseline: dict
) -> tuple[list[dict], dict]:
    exceptions = baseline.get("approved_dev_tool_advisories", [])
    if not isinstance(exceptions, list) or len(exceptions) > 1:
        raise SecurityFailure("dev-tool exception policy is malformed")
    approved = []
    remaining = list(findings)
    for exception in exceptions:
        fields = {
            "source", "advisory_id", "audit_id", "package", "version", "severity",
            "approval", "security_fingerprint", "informational_metadata", "paths",
        }
        if not isinstance(exception, dict) or set(exception) != fields:
            raise SecurityFailure("dev-tool exception identity is incomplete")
        identity = {
            "source": "pnpm", "advisory_id": "GHSA-vfj7-8cjw-p6xm",
            "audit_id": "1240992", "package": "braces", "version": "3.0.3",
            "severity": "high",
        }
        paths = {
            name: prefix + ">" + DEV_TOOL_CHAIN
            for name, (_, prefix) in DEV_TOOL_SCOPES.items()
        }
        if (
            any(exception[key] != value for key, value in identity.items())
            or exception["paths"] != paths
            or exception["approval"] != {
                "status": "HUMAN_APPROVED", "task": "CI-20261004",
                "scope": "DEV_TOOLING_ONLY",
            }
            or not isinstance(exception["security_fingerprint"], dict)
            or exception["security_fingerprint"].get("patched_versions") != "<0.0.0"
            or exception["security_fingerprint"].get("github_advisory_id") != identity["advisory_id"]
            or exception["security_fingerprint"].get("module_name") != identity["package"]
            or exception["security_fingerprint"].get("severity") != identity["severity"]
        ):
            raise SecurityFailure("dev-tool exception exceeds the Human-approved identity")
        fingerprint = advisory_security_fingerprint(exception["security_fingerprint"])
        if fingerprint != exception["security_fingerprint"]:
            raise SecurityFailure("dev-tool fingerprint contains non-security fields")
        expected = {**identity, "path": paths[scope]}
        if expected not in remaining:
            continue
        actual_fingerprint = advisory_security_fingerprint(
            audit["advisories"][identity["audit_id"]]
        )
        if actual_fingerprint != fingerprint:
            raise SecurityFailure("approved dev-tool security fingerprint changed; remediation required")
        manifest_path = DEV_TOOL_SCOPES[scope][0]
        manifest = json.loads((repository / manifest_path).read_text(encoding="utf-8"))
        if (
            not isinstance(manifest, dict)
            or not isinstance(manifest.get("devDependencies"), dict)
            or not isinstance(manifest["devDependencies"].get("eslint-config-next"), str)
            or not manifest["devDependencies"]["eslint-config-next"].strip()
            or any(
                not isinstance(manifest.get(section, {}), dict)
                or "eslint-config-next" in manifest.get(section, {})
                for section in ("dependencies", "optionalDependencies", "peerDependencies")
            )
        ):
            raise SecurityFailure("approved eslint-config-next chain is not dev-only")
        remaining.remove(expected)
        approved.append({
            **expected, "scope": scope, "manifest": manifest_path,
            "dependency_section": "devDependencies",
            "patched_versions": fingerprint["patched_versions"],
        })
    return remaining, {
        "current_findings": len(findings),
        "approved_exact_exceptions": len(approved),
        "unapproved_findings": len(remaining),
        "resolved_exception_entries": len(exceptions) - len(approved),
        "approved_dev_tool_advisories": approved,
    }


def validate_dependency_audits(
    repository: Path, audits: dict, statuses: dict, baseline: dict, change: dict | None = None
) -> dict:
    if change is not None:
        validate_change(change)
    sources = {"composer", "workspace-pnpm", "legacy-pnpm", "workspace-prod", "legacy-prod"}
    if (
        not isinstance(audits, dict) or set(audits) != sources
        or not isinstance(statuses, dict) or set(statuses) != sources
    ):
        raise SecurityFailure("audit reports or command statuses are missing or malformed")
    composer = composer_findings(
        audits["composer"],
        json.loads((repository / "apps/api/composer.lock").read_text(encoding="utf-8")),
    )
    validate_audit_status(statuses["composer"], composer, "composer")
    validate_dependency_baseline([], [], baseline)
    if change is None:
        validate_dependency_baseline(composer, [], baseline)
    visible = [{**finding, "scope": "composer", "path": "apps/api/composer.lock",
                "runtime_scope": "RUNTIME" if any(package["name"] == finding["package"] for package in
                    load(repository / "apps/api/composer.lock")["packages"]) else "DEV_ONLY",
                "approved": finding in baseline["composer"]} for finding in composer]
    remaining = {}
    scopes = {}
    for scope, lock_path in (
        ("workspace", "pnpm-lock.yaml"), ("legacy", "legacy/v1-frontend/pnpm-lock.yaml")
    ):
        lock = (repository / lock_path).read_text(encoding="utf-8")
        findings = pnpm_findings(audits[scope + "-pnpm"], lock)
        runtime = pnpm_findings(audits[scope + "-prod"], lock)
        validate_audit_status(statuses[scope + "-pnpm"], findings, scope)
        validate_audit_status(statuses[scope + "-prod"], runtime, scope + "-prod")
        if runtime and change is None:
            raise SecurityFailure(f"{scope} runtime audit contains findings; no dev-tool exception applies")
        remaining[scope], scopes[scope] = partition_dev_tool_findings(
            repository, scope, findings, audits[scope + "-pnpm"], baseline
        )
        scopes[scope]["runtime_findings"] = len(runtime)
        for finding in findings:
            visible.append({**finding, "scope": scope,
                            "runtime_scope": "RUNTIME" if finding in runtime else "DEV_ONLY",
                            "approved": finding not in remaining[scope]
                            or (scope == "legacy" and finding in baseline["pnpm"])})
        for finding in runtime:
            if finding not in findings:
                raise SecurityFailure("runtime audit contradicts full audit inventory")
            if any(item["advisory_id"] == finding["advisory_id"] for item in scopes[scope]["approved_dev_tool_advisories"]):
                raise SecurityFailure("approved dev-tool advisory has runtime exposure")
    if change is None:
        validate_workspace_pnpm_audit(remaining["workspace"])
        summary = validate_dependency_baseline(composer, remaining["legacy"], baseline)
    else:
        summary = {"composer_advisories": len(composer), "pnpm": {}}
    summary["pnpm_findings"] = scopes["legacy"]["current_findings"]
    summary["pnpm"]["current_findings"] = scopes["legacy"]["current_findings"]
    summary["pnpm"]["approved_exact_exceptions"] = scopes["legacy"]["approved_exact_exceptions"]
    summary["workspace_pnpm_findings"] = scopes["workspace"]["current_findings"]
    summary["dev_tool_scopes"] = scopes
    summary["current_findings"] = len(composer) + sum(
        item["current_findings"] for item in scopes.values()
    )
    summary["approved_exact_exceptions"] = sum(
        item["approved_exact_exceptions"] for item in scopes.values()
    )
    summary["approved_dev_tool_advisories"] = len({
        finding["advisory_id"] for item in scopes.values()
        for finding in item["approved_dev_tool_advisories"]
    })
    summary["unapproved_findings"] = sum(not item["approved"] for item in visible)
    summary["current_security_findings"] = visible
    if change is not None:
        unapproved = summary["unapproved_findings"] > 0
        unchanged = change["deltas"]["dependency_delta"] == "NONE"
        summary.update({
            "schema_version": "1.0", "producer": "scripts.ci.security_gate/v1",
            "repository": change["repository"],
            **{key: change[key] for key in ("base_sha", "head_sha", "tree_sha")},
            "change_digest": change["record_digest"],
            "dependency_fingerprint": change["head_components"]["dependency"],
            "audit_input_digest": digest({"audits": audits, "statuses": statuses}, None),
            "observed_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
            "evidence_reference": change["evidence_reference"] + ":fresh-audits",
            "dependency_delta": change["deltas"]["dependency_delta"],
            "finding_classification": "UNCHANGED_DEPENDENCY_SECURITY_POSTURE" if unapproved and unchanged
                else "PR_INTRODUCED_SECURITY_FINDING" if unapproved else "NO_UNAPPROVED_FINDINGS",
            "development_security_result": "PASS_NO_PR_INTRODUCED_DEPENDENCY_REGRESSION" if unchanged
                else "BLOCK_PR_INTRODUCED_SECURITY_FINDING" if unapproved else "PASS_FRESH_AUDITS",
            "current_security_posture": "HOLD_UNAPPROVED_FINDING" if unapproved else "PASS_APPROVED_POLICY",
            "security_maintenance_required": unapproved,
            "advisory_db_drift": "NOT_PROVEN", "reuse": "NOT_PROVEN",
        })
        summary = seal(summary, "record_digest")
    return summary


def load_policy_gate(repository: Path):
    path = repository / "scripts/ci/policy_gate.py"
    spec = importlib.util.spec_from_file_location("policy_gate_for_security", path)
    module = importlib.util.module_from_spec(spec)
    if spec.loader is None:
        raise SecurityFailure("cannot load policy gate")
    spec.loader.exec_module(module)
    return module


def validate_workflows(repository: Path, paths: list[str]) -> None:
    policy_gate = load_policy_gate(repository)
    dangerous = (
        "git push --force",
        "git push origin main",
        "docker system prune",
        "docker volume rm",
        "php artisan migrate --force",
        "php artisan migrate:fresh",
        "php artisan db:wipe",
        "/usr/local/libexec/ideal-sol-github-app-token",
        "/usr/local/libexec/ideal-sol-github-app-autonomy",
    )
    for relative in paths:
        if not relative.startswith(".github/workflows/") or not relative.endswith(
            (".yml", ".yaml")
        ):
            continue
        text = (repository / relative).read_text(encoding="utf-8")
        policy_gate.validate_workflow_text(relative, text)
        for command in dangerous:
            if command in text:
                raise SecurityFailure(f"dangerous workflow command: {relative}")


def validate_codex_rules(repository: Path) -> None:
    text = (repository / ".codex/rules/governance.rules").read_text(encoding="utf-8")
    required_tokens = (
        '"reset", "--hard"',
        '"clean", ["-fd", "-fdx"]',
        '"push", "origin", ["main"',
        '"system", "prune"',
        '"/usr/local/libexec/ideal-sol-github-app-token"',
        '"/usr/local/libexec/ideal-sol-github-app-autonomy"',
    )
    if text.count('decision = "forbidden"') < len(required_tokens):
        raise SecurityFailure("Codex forbidden rule count is insufficient")
    for token in required_tokens:
        if token not in text:
            raise SecurityFailure("required Codex safety rule is missing")
    for wrapper in (
        "/usr/local/bin/oripa-github-app-api",
        "/usr/local/bin/oripa-github-app-api-write",
        "/usr/local/bin/oripa-github-app-git",
    ):
        if wrapper not in text:
            raise SecurityFailure("approved GitHub App wrapper rule is missing")


def validate_remote(repository: Path) -> None:
    remotes = git_output(repository, "remote", "-v")
    if re.search(r"https?://[^/@\s]+:[^/@\s]+@", remotes):
        raise SecurityFailure("credential-bearing Git remote URL detected")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repository", type=Path, required=True)
    parser.add_argument("--baseline", type=Path, required=True)
    parser.add_argument("--composer-audit", type=Path, required=True)
    parser.add_argument("--pnpm-audit", type=Path, required=True)
    parser.add_argument("--workspace-pnpm-audit", type=Path, required=True)
    parser.add_argument("--workspace-prod-audit", type=Path, required=True)
    parser.add_argument("--legacy-prod-audit", type=Path, required=True)
    parser.add_argument("--audit-statuses", type=Path, required=True)
    parser.add_argument("--change-evidence", type=Path)
    parser.add_argument("--evidence-output", type=Path)
    arguments = parser.parse_args()
    repository = arguments.repository.resolve()
    try:
        paths = tracked_paths(repository)
        path_findings = dangerous_paths(paths)
        if path_findings:
            raise SecurityFailure(
                "dangerous tracked paths: " + ", ".join(path_findings)
            )
        candidates = secret_candidates(repository, paths)
        if candidates:
            redacted = ", ".join(
                f"{item['category']}:{item['path']}" for item in candidates
            )
            raise SecurityFailure("secret candidates: " + redacted)
        validate_workflows(repository, paths)
        validate_codex_rules(repository)
        validate_remote(repository)
        audits = {
            name: load(path)
            for name, path in (
                ("composer", arguments.composer_audit),
                ("workspace-pnpm", arguments.workspace_pnpm_audit),
                ("legacy-pnpm", arguments.pnpm_audit),
                ("workspace-prod", arguments.workspace_prod_audit),
                ("legacy-prod", arguments.legacy_prod_audit),
            )
        }
        baseline = load(arguments.baseline)
        statuses = load(arguments.audit_statuses)
        change = None
        if arguments.change_evidence:
            change = validate_change(load(arguments.change_evidence))
            actual = classify(repository, change["base_sha"], git_output(repository, "rev-parse", "HEAD").strip())
            if actual != change:
                raise SecurityFailure("classification evidence does not match current source")
            for path in change["dependency_inputs"]["head"]:
                if (repository / path).read_bytes() != subprocess.check_output(
                    ["git", "-C", str(repository), "show", change["head_sha"] + ":" + path]
                ):
                    raise SecurityFailure("dependency audit input differs from classified bytes")
            baseline_path = ".ci/baselines/dependency-advisories.json"
            if (arguments.baseline.resolve() != (repository / baseline_path).resolve()
                    or git_output(repository, "show", change["base_sha"] + ":" + baseline_path)
                    != arguments.baseline.read_text(encoding="utf-8")):
                raise SecurityFailure("security baseline changes require separate Human review")
        dependency_summary = validate_dependency_audits(
            repository, audits, statuses, baseline, change
        )
        if arguments.evidence_output:
            arguments.evidence_output.write_bytes(canonical(dependency_summary))
        if dependency_summary.get("development_security_result", "").startswith("BLOCK"):
            print(json.dumps(dependency_summary, sort_keys=True))
            raise SecurityFailure("PR-introduced dependency finding")
    except (
        OSError,
        ValueError,
        json.JSONDecodeError,
        subprocess.CalledProcessError,
        SecurityFailure,
    ) as error:
        print(f"security-gate: FAIL: {error}", file=sys.stderr)
        return 1
    print(
        json.dumps(
            {
                "gate": "security-gate",
                "status": "PASS",
                "tracked_files": len(paths),
                "secret_candidates": 0,
                **dependency_summary,
            },
            sort_keys=True,
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
