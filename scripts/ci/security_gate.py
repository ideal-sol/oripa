#!/usr/bin/env python3
"""High-confidence repository and dependency security checks."""

from __future__ import annotations

import argparse
import datetime
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import sys


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
    versions = {
        item["name"]: item["version"]
        for item in lock.get("packages", []) + lock.get("packages-dev", [])
    }
    findings = []
    for package, advisories in audit.get("advisories", {}).items():
        for advisory in advisories:
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
    return sorted(findings, key=lambda item: json.dumps(item, sort_keys=True))


def pnpm_findings(audit: dict) -> list[dict]:
    findings = []
    for audit_id, advisory in audit.get("advisories", {}).items():
        advisory_id = str(advisory.get("url", "")).rstrip("/").split("/")[-1]
        for finding in advisory.get("findings", []):
            for path in finding.get("paths", []):
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
    return sorted(findings, key=lambda item: json.dumps(item, sort_keys=True))


def validate_dependency_baseline(
    composer: list[dict], pnpm: list[dict], baseline: dict, today: datetime.date
) -> dict:
    if baseline.get("schema_version") != "1.0":
        raise SecurityFailure("unsupported dependency baseline schema")
    management = baseline.get("management", {})
    required = {"owner", "reason", "expires_at", "tracking_task", "removal_condition"}
    if not required.issubset(management) or any(
        not str(management.get(key, "")).strip() for key in required
    ):
        raise SecurityFailure("dependency baseline management metadata is incomplete")
    if today > datetime.date.fromisoformat(management["expires_at"]):
        raise SecurityFailure("dependency advisory baseline has expired")
    expected_composer = sorted(
        baseline.get("composer", []), key=lambda item: json.dumps(item, sort_keys=True)
    )
    expected_pnpm = sorted(
        baseline.get("pnpm", []), key=lambda item: json.dumps(item, sort_keys=True)
    )
    if composer != expected_composer:
        raise SecurityFailure("Composer advisory baseline mismatch")
    if pnpm != expected_pnpm:
        raise SecurityFailure("pnpm advisory baseline mismatch")
    return {
        "composer_advisories": len(composer),
        "pnpm_findings": len(pnpm),
        "expires_at": management["expires_at"],
    }


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
        composer_audit = json.loads(arguments.composer_audit.read_text(encoding="utf-8"))
        pnpm_audit = json.loads(arguments.pnpm_audit.read_text(encoding="utf-8"))
        composer_lock = json.loads(
            (repository / "apps/api/composer.lock").read_text(encoding="utf-8")
        )
        baseline = json.loads(arguments.baseline.read_text(encoding="utf-8"))
        dependency_summary = validate_dependency_baseline(
            composer_findings(composer_audit, composer_lock),
            pnpm_findings(pnpm_audit),
            baseline,
            datetime.date.today(),
        )
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
