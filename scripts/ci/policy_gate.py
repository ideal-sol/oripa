#!/usr/bin/env python3
"""Repository governance checks for Platform CI."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
from typing import Iterable


FULL_SHA = re.compile(r"^[0-9a-f]{40}$")
TASK_ID = re.compile(r"^[A-Z]+-[0-9]+[A-Z]?$")
ACTION_REF = re.compile(
    r"^\s*(?:-\s*)?uses:\s+([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)"
    r"(?:/[A-Za-z0-9_.-]+)*"
    r"@([0-9a-f]{40})(?:\s+#.*)?$"
)
REQUIRED_REPOSITORY_FILES = {
    "AGENTS.md",
    ".github/CODEOWNERS",
    ".github/ISSUE_TEMPLATE/task.yml",
    ".github/ISSUE_TEMPLATE/config.yml",
    ".github/pull_request_template.md",
    "apps/api/AGENTS.md",
    "apps/admin/AGENTS.md",
    "packages/AGENTS.md",
    "openapi/AGENTS.md",
    "infrastructure/AGENTS.md",
    "docs/AGENTS.md",
    "legacy/v1/AGENTS.md",
    "legacy/v1-frontend/AGENTS.md",
    "legacy/v1-frontend/README.md",
    "docs/architecture/README.md",
}
WORKSPACE_REQUIRED_FILES = {
    "apps/README.md",
    "apps/api/README.md",
    "apps/admin/README.md",
    "apps/api/AGENTS.md",
    "apps/admin/AGENTS.md",
    "packages/README.md",
    "packages/platform/README.md",
    "packages/storefront-client/README.md",
    "packages/site-schema/README.md",
    "packages/storefront-testkit/README.md",
    "packages/AGENTS.md",
    "openapi/README.md",
    "openapi/AGENTS.md",
    "infrastructure/README.md",
    "infrastructure/AGENTS.md",
    "deployments/README.md",
    "manifests/README.md",
    "manifests/schemas/release-manifest.schema.json",
    "manifests/schemas/deployment-manifest.schema.json",
    "manifests/examples/release-manifest.example.json",
    "manifests/examples/deployment-manifest.example.json",
    "legacy/README.md",
    "legacy/v1/README.md",
    "legacy/v1/AGENTS.md",
    "docs/operations/repository-layout/README.md",
}
API_APPLICATION_REQUIRED_FILES = {
    "apps/api/.env.example",
    "apps/api/artisan",
    "apps/api/composer.json",
    "apps/api/composer.lock",
    "apps/api/phpunit.xml",
    "apps/api/routes/api.php",
    "apps/api/tests/TestCase.php",
}
LEGACY_FRONTEND_REQUIRED_FILES = {
    "legacy/v1-frontend/.env.example",
    "legacy/v1-frontend/AGENTS.md",
    "legacy/v1-frontend/README.md",
    "legacy/v1-frontend/package.json",
    "legacy/v1-frontend/pnpm-lock.yaml",
    "legacy/v1-frontend/next.config.ts",
    "legacy/v1-frontend/tsconfig.json",
    "legacy/v1-frontend/src/app/page.tsx",
}
BOUNDARY_READMES = {
    "apps/README.md",
    "apps/api/README.md",
    "apps/admin/README.md",
    "packages/README.md",
    "packages/platform/README.md",
    "packages/storefront-client/README.md",
    "packages/site-schema/README.md",
    "packages/storefront-testkit/README.md",
    "openapi/README.md",
    "infrastructure/README.md",
    "deployments/README.md",
    "manifests/README.md",
    "legacy/README.md",
    "legacy/v1/README.md",
}
BOUNDARY_HEADINGS = {
    "Responsibility",
    "Ownership",
    "Planned Components",
    "Allowed Scope",
    "Forbidden Scope",
    "Status",
}
RELEASE_MANIFEST_REQUIRED = {
    "schema_version",
    "platform_version",
    "package_versions",
    "api_contract_version",
    "migration_revision",
    "source_commit",
    "image_digest",
    "sbom_reference",
    "created_at",
}
DEPLOYMENT_MANIFEST_REQUIRED = {
    "schema_version",
    "site_id",
    "environment",
    "platform_version",
    "package_versions",
    "image_digest",
    "migration_revision",
    "deployed_at",
    "approved_by",
    "source_release_manifest",
}
SEMANTIC_VERSION = re.compile(
    r"^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?$"
)
REQUIRED_PR_HEADINGS = {
    "Task",
    "Summary",
    "Specification sources",
    "Scope",
    "Verification performed",
    "Verification not performed",
}
CURRENT_SECURITY = (
    "V2_IDENTITY_AUTHORIZATION_SECURITY_BASELINE_FINAL_REV1_2026-07-22.md"
)
OBSOLETE_SECURITY = (
    "V2_IDENTITY_AUTHORIZATION_SECURITY_BASELINE_FINAL_2026-07-22.md"
)
CURRENT_GOVERNANCE = "V2_CODEX_GIT_CI_GOVERNANCE_FINAL_REV2_2026-07-23.md"
CURRENT_RELEASE_GATES = "V2_RELEASE_GATES_FINAL_REV1_2026-07-23.md"


class PolicyFailure(RuntimeError):
    """A deterministic policy violation."""


def run_git(repository: Path, *arguments: str) -> str:
    result = subprocess.run(
        ["git", "-C", str(repository), *arguments],
        check=False,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
    )
    if result.returncode:
        raise PolicyFailure(f"git command failed: {' '.join(arguments)}")
    return result.stdout


def tracked_paths(repository: Path) -> list[str]:
    return [
        line
        for line in run_git(repository, "ls-files").splitlines()
        if line.strip()
    ]


def changed_paths(repository: Path, base_sha: str, head_sha: str) -> list[str]:
    if not FULL_SHA.fullmatch(base_sha) or not FULL_SHA.fullmatch(head_sha):
        raise PolicyFailure("pull request base or head SHA is not full length")
    output = run_git(repository, "diff", "--name-only", f"{base_sha}...{head_sha}")
    return sorted(line for line in output.splitlines() if line)


def markdown_headings(body: str) -> set[str]:
    return {
        match.group(1).strip()
        for match in re.finditer(r"^#{2,3}\s+(.+?)\s*$", body, re.MULTILINE)
    }


def metadata_value(body: str, label: str) -> str:
    match = re.search(
        rf"^-\s+{re.escape(label)}:\s*`?([^`\n]+?)`?\s*$",
        body,
        re.MULTILINE,
    )
    if not match:
        raise PolicyFailure(f"pull request metadata missing: {label}")
    return match.group(1).strip()


def section_bullets(body: str, heading: str) -> list[str]:
    match = re.search(
        rf"^###\s+{re.escape(heading)}\s*$([\s\S]*?)(?=^##{{2,3}}\s+|\Z)",
        body,
        re.MULTILINE,
    )
    if not match:
        raise PolicyFailure(f"pull request section missing: {heading}")
    values = []
    for line in match.group(1).splitlines():
        item = re.match(r"^\s*-\s+(.+?)\s*$", line)
        if not item:
            continue
        value = item.group(1).strip().strip("`")
        if value.startswith("/"):
            value = value[1:]
        if value and value != "-":
            values.append(value)
    if not values:
        raise PolicyFailure(f"pull request section is empty: {heading}")
    return values


def declared_path_allowed(path: str, declared_paths: Iterable[str]) -> bool:
    for declared in declared_paths:
        if path == declared:
            return True
        if declared.endswith("/**") and path.startswith(f"{declared[:-3]}/"):
            return True
    return False


def validate_pr_body(
    body: str,
    title: str,
    actual_changed_paths: Iterable[str],
    expected_base_sha: str,
) -> None:
    headings = markdown_headings(body)
    missing_headings = sorted(REQUIRED_PR_HEADINGS - headings)
    if missing_headings:
        raise PolicyFailure(
            "pull request headings missing: " + ", ".join(missing_headings)
        )

    task_id = metadata_value(body, "Task ID")
    risk = metadata_value(body, "Risk")
    base_sha = metadata_value(body, "Base SHA")
    if not TASK_ID.fullmatch(task_id) or task_id not in title:
        raise PolicyFailure("pull request Task ID is invalid or absent from title")
    if risk not in {"R1", "R2", "R3", "R4"}:
        raise PolicyFailure("pull request Risk must be R1 through R4")
    if base_sha != expected_base_sha or not FULL_SHA.fullmatch(base_sha):
        raise PolicyFailure("pull request Base SHA does not match the event base")

    declared_changed = set(section_bullets(body, "Changed files"))
    allowed = set(section_bullets(body, "Allowed paths"))
    actual = set(actual_changed_paths)
    if declared_changed != actual:
        raise PolicyFailure("declared Changed files do not match the Git diff")
    if not all(declared_path_allowed(path, allowed) for path in actual):
        raise PolicyFailure("Git diff includes a path outside declared Allowed paths")


def validate_dangerous_paths(paths: Iterable[str]) -> None:
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
    if findings:
        raise PolicyFailure(
            "dangerous tracked paths: " + ", ".join(sorted(set(findings)))
        )


def validate_workflow_text(path: str, text: str) -> None:
    if "pull_request_target" in text:
        raise PolicyFailure(f"{path}: pull_request_target is prohibited")
    if re.search(r"^\s*permissions:\s*(?:write-all|read-all)\s*$", text, re.MULTILINE):
        raise PolicyFailure(f"{path}: workflow permissions must be explicit")
    for match in re.finditer(
        r"^(\s+)(actions|checks|contents|deployments|id-token|issues|packages|"
        r"pull-requests|security-events|statuses):\s*write\s*$",
        text,
        re.MULTILINE,
    ):
        indent, permission = match.groups()
        codeql_job_upload = (
            path == ".github/workflows/codeql.yml"
            and permission == "security-events"
            and len(indent) >= 6
            and "github/codeql-action/analyze@" in text
        )
        if not codeql_job_upload:
            raise PolicyFailure(f"{path}: write workflow permission is prohibited")
    if "permissions:" not in text or not re.search(
        r"^\s+contents:\s*read\s*$", text, re.MULTILINE
    ):
        raise PolicyFailure(f"{path}: read-only contents permission is required")
    if "timeout-minutes:" not in text:
        raise PolicyFailure(f"{path}: every workflow requires job timeouts")
    if "concurrency:" not in text:
        raise PolicyFailure(f"{path}: workflow concurrency is required")
    if "secrets." in text:
        raise PolicyFailure(f"{path}: policy workflow must not consume secrets")

    for line in text.splitlines():
        if "uses:" not in line:
            continue
        match = ACTION_REF.fullmatch(line)
        if not match:
            raise PolicyFailure(f"{path}: action is not pinned to a full SHA")

    in_run_block = False
    run_indent = 0
    for line in text.splitlines():
        indent = len(line) - len(line.lstrip())
        if re.match(r"^\s*run:\s*", line):
            in_run_block = True
            run_indent = indent
        elif in_run_block and line.strip() and indent <= run_indent:
            in_run_block = False
        if in_run_block and "${{ github.event.pull_request." in line:
            raise PolicyFailure(
                f"{path}: untrusted pull request input appears in a shell block"
            )


def validate_basic_structures(repository: Path, paths: Iterable[str]) -> None:
    for relative in paths:
        path = repository / relative
        if path.suffix == ".json":
            try:
                json.loads(path.read_text(encoding="utf-8"))
            except (UnicodeError, json.JSONDecodeError) as error:
                raise PolicyFailure(f"{relative}: invalid JSON") from error
        elif path.suffix in {".yml", ".yaml"}:
            text = path.read_text(encoding="utf-8")
            if not text.strip() or "\t" in text:
                raise PolicyFailure(f"{relative}: invalid basic YAML structure")
        elif path.suffix == ".toml":
            text = path.read_text(encoding="utf-8")
            for number, line in enumerate(text.splitlines(), 1):
                stripped = line.strip()
                if not stripped or stripped.startswith("#"):
                    continue
                if (
                    re.fullmatch(r"\[[A-Za-z0-9_.\"/-]+\]", stripped)
                    or "=" in stripped
                ):
                    continue
                raise PolicyFailure(f"{relative}:{number}: invalid basic TOML line")
        elif path.suffix == ".md":
            try:
                text = path.read_text(encoding="utf-8")
            except UnicodeError as error:
                raise PolicyFailure(f"{relative}: invalid UTF-8 Markdown") from error
            if not text.strip():
                raise PolicyFailure(f"{relative}: empty Markdown")


def load_json(repository: Path, relative: str) -> dict:
    try:
        value = json.loads((repository / relative).read_text(encoding="utf-8"))
    except (UnicodeError, json.JSONDecodeError) as error:
        raise PolicyFailure(f"{relative}: invalid JSON") from error
    if not isinstance(value, dict):
        raise PolicyFailure(f"{relative}: top-level value must be an object")
    return value


def validate_workspace_configuration(repository: Path) -> None:
    package = load_json(repository, "package.json")
    if package.get("name") != "@oripa/platform-workspace":
        raise PolicyFailure("package.json: workspace name is invalid")
    if package.get("version") != "2.0.0-alpha.1":
        raise PolicyFailure("package.json: V2 workspace version is invalid")
    if package.get("private") is not True:
        raise PolicyFailure("package.json: root workspace must be private")
    if package.get("packageManager") != "pnpm@10.12.1":
        raise PolicyFailure("package.json: packageManager must match the V1 lockfile")
    if package.get("dependencies") or package.get("devDependencies"):
        raise PolicyFailure("package.json: skeleton must not add dependencies")

    workspace_text = (repository / "pnpm-workspace.yaml").read_text(encoding="utf-8")
    members = {
        match.group(1).strip().strip("'\"")
        for match in re.finditer(r"^\s*-\s+(.+?)\s*$", workspace_text, re.MULTILINE)
    }
    expected = {"apps/admin", "packages/*"}
    if members != expected:
        raise PolicyFailure(
            "pnpm-workspace.yaml: workspace members must be apps/admin and packages/*"
        )
    if re.search(
        r"(?:^|/)(?:backend|frontend|legacy/v1-frontend)(?:/|$)",
        "\n".join(members),
    ):
        raise PolicyFailure("pnpm-workspace.yaml: V1 paths must not enter V2 workspace")


def validate_boundary_readmes(repository: Path) -> None:
    for relative in sorted(BOUNDARY_READMES):
        text = (repository / relative).read_text(encoding="utf-8")
        headings = markdown_headings(text)
        missing = sorted(BOUNDARY_HEADINGS - headings)
        if missing:
            raise PolicyFailure(
                f"{relative}: responsibility headings missing: {', '.join(missing)}"
            )
        for statement in ("AGENTS.md", "Skeleton", "Production", "V1"):
            if statement not in text:
                raise PolicyFailure(
                    f"{relative}: required boundary statement missing: {statement}"
                )
        if len(text.strip()) < 300:
            raise PolicyFailure(f"{relative}: skeleton boundary is not substantive")


def validate_manifest_schema(
    repository: Path,
    relative: str,
    expected_required: set[str],
) -> dict:
    schema = load_json(repository, relative)
    if schema.get("$schema") != "https://json-schema.org/draft/2020-12/schema":
        raise PolicyFailure(f"{relative}: JSON Schema Draft 2020-12 is required")
    if schema.get("type") != "object" or schema.get("additionalProperties") is not False:
        raise PolicyFailure(f"{relative}: strict object schema is required")
    required = schema.get("required")
    properties = schema.get("properties")
    if not isinstance(required, list) or not expected_required.issubset(required):
        raise PolicyFailure(f"{relative}: required manifest fields are missing")
    if not isinstance(properties, dict) or not expected_required.issubset(properties):
        raise PolicyFailure(f"{relative}: manifest properties are missing")
    semantic_version = schema.get("$defs", {}).get("semantic_version", {})
    if semantic_version.get("pattern") != SEMANTIC_VERSION.pattern:
        raise PolicyFailure(f"{relative}: semantic version policy is invalid")
    return schema


def validate_schema_value(
    value: object,
    schema: dict,
    root_schema: dict,
    location: str,
) -> None:
    reference = schema.get("$ref")
    if reference:
        if not isinstance(reference, str) or not reference.startswith("#/$defs/"):
            raise PolicyFailure(f"{location}: unsupported JSON Schema reference")
        definition = reference.removeprefix("#/$defs/")
        target = root_schema.get("$defs", {}).get(definition)
        if not isinstance(target, dict):
            raise PolicyFailure(f"{location}: unresolved JSON Schema reference")
        validate_schema_value(value, target, root_schema, location)
        return

    if "const" in schema and value != schema["const"]:
        raise PolicyFailure(f"{location}: value does not match const")
    if "enum" in schema and value not in schema["enum"]:
        raise PolicyFailure(f"{location}: value is outside enum")

    expected_type = schema.get("type")
    type_matches = {
        "object": isinstance(value, dict),
        "array": isinstance(value, list),
        "string": isinstance(value, str),
        "boolean": isinstance(value, bool),
        "integer": isinstance(value, int) and not isinstance(value, bool),
    }
    if expected_type and not type_matches.get(expected_type, False):
        raise PolicyFailure(f"{location}: value is not {expected_type}")

    if isinstance(value, str):
        if len(value) < int(schema.get("minLength", 0)):
            raise PolicyFailure(f"{location}: string is too short")
        pattern = schema.get("pattern")
        if pattern and not re.fullmatch(pattern, value):
            raise PolicyFailure(f"{location}: string does not match pattern")
        if schema.get("format") == "date-time" and not re.fullmatch(
            r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z", value
        ):
            raise PolicyFailure(f"{location}: string is not UTC date-time")

    if isinstance(value, dict):
        required = schema.get("required", [])
        missing = sorted(set(required) - set(value))
        if missing:
            raise PolicyFailure(
                f"{location}: required fields missing: {', '.join(missing)}"
            )
        if len(value) < int(schema.get("minProperties", 0)):
            raise PolicyFailure(f"{location}: object has too few properties")
        properties = schema.get("properties", {})
        additional = schema.get("additionalProperties", True)
        for key, item in value.items():
            child_schema = properties.get(key)
            if child_schema is None:
                if additional is False:
                    raise PolicyFailure(f"{location}: unexpected field: {key}")
                if isinstance(additional, dict):
                    child_schema = additional
            if isinstance(child_schema, dict):
                validate_schema_value(
                    item, child_schema, root_schema, f"{location}.{key}"
                )

    if isinstance(value, list):
        if schema.get("uniqueItems"):
            serialized = [json.dumps(item, sort_keys=True) for item in value]
            if len(serialized) != len(set(serialized)):
                raise PolicyFailure(f"{location}: array items must be unique")
        item_schema = schema.get("items")
        if isinstance(item_schema, dict):
            for index, item in enumerate(value):
                validate_schema_value(
                    item, item_schema, root_schema, f"{location}[{index}]"
                )


def validate_manifest_example(
    repository: Path,
    relative: str,
    schema: dict,
) -> None:
    value = load_json(repository, relative)
    validate_schema_value(value, schema, schema, relative)


def validate_no_v1_copy(repository: Path, paths: Iterable[str]) -> None:
    path_set = set(paths)
    source_paths = [
        path
        for path in path_set
        if path.startswith(("backend/", "frontend/", "legacy/v1-frontend/"))
        and not path.endswith((".md", ".lock"))
        and (repository / path).is_file()
    ]
    target_paths = [
        path
        for path in path_set
        if path.startswith(("apps/api/", "apps/admin/", "packages/"))
        and not path.endswith((".md", ".lock"))
        and (repository / path).is_file()
    ]
    source_hashes = {}
    for relative in source_paths:
        content = (repository / relative).read_bytes()
        if len(content) >= 64:
            source_hashes.setdefault(hashlib.sha256(content).digest(), relative)
    copied = []
    for relative in target_paths:
        content = (repository / relative).read_bytes()
        source = source_hashes.get(hashlib.sha256(content).digest())
        if len(content) >= 64 and source:
            copied.append(f"{source} -> {relative}")
    if copied:
        raise PolicyFailure("V1 content copied into V2 workspace: " + ", ".join(copied))


def validate_api_application_layout(paths: Iterable[str]) -> None:
    path_set = set(paths)
    missing = sorted(API_APPLICATION_REQUIRED_FILES - path_set)
    if missing:
        raise PolicyFailure(
            "required API application files missing: " + ", ".join(missing)
        )
    legacy_paths = sorted(path for path in path_set if path.startswith("backend/"))
    if legacy_paths:
        raise PolicyFailure("legacy backend path remains tracked")


def validate_legacy_frontend_layout(repository: Path, paths: Iterable[str]) -> None:
    path_set = set(paths)
    missing = sorted(LEGACY_FRONTEND_REQUIRED_FILES - path_set)
    if missing:
        raise PolicyFailure(
            "required legacy frontend files missing: " + ", ".join(missing)
        )
    old_paths = sorted(path for path in path_set if path.startswith("frontend/"))
    if old_paths:
        raise PolicyFailure("legacy frontend source path remains tracked")
    nested = sorted(
        path
        for path in path_set
        if path.startswith("legacy/v1-frontend/frontend/")
    )
    if nested:
        raise PolicyFailure("legacy frontend was moved into a nested frontend directory")

    for relative in paths:
        if not relative.endswith(("Dockerfile", ".dockerfile")):
            continue
        if relative == "infra/docker/frontend/Dockerfile":
            continue
        text = (repository / relative).read_text(encoding="utf-8", errors="replace")
        if "legacy/v1-frontend" in text:
            raise PolicyFailure(
                f"{relative}: V2 Production image must not copy legacy frontend"
            )


def validate_workspace_skeleton(repository: Path, paths: Iterable[str]) -> None:
    path_set = set(paths)
    missing = sorted(WORKSPACE_REQUIRED_FILES - path_set)
    if missing:
        raise PolicyFailure("required workspace files missing: " + ", ".join(missing))
    configuration_files = {"package.json", "pnpm-workspace.yaml"} & path_set
    if configuration_files and configuration_files != {
        "package.json",
        "pnpm-workspace.yaml",
    }:
        raise PolicyFailure("root workspace configuration must be introduced together")
    if configuration_files:
        validate_workspace_configuration(repository)
    validate_boundary_readmes(repository)
    release_schema = validate_manifest_schema(
        repository,
        "manifests/schemas/release-manifest.schema.json",
        RELEASE_MANIFEST_REQUIRED,
    )
    deployment_schema = validate_manifest_schema(
        repository,
        "manifests/schemas/deployment-manifest.schema.json",
        DEPLOYMENT_MANIFEST_REQUIRED,
    )
    validate_manifest_example(
        repository,
        "manifests/examples/release-manifest.example.json",
        release_schema,
    )
    validate_manifest_example(
        repository,
        "manifests/examples/deployment-manifest.example.json",
        deployment_schema,
    )
    validate_no_v1_copy(repository, paths)


def validate_architecture_index(repository: Path) -> None:
    index_path = repository / "docs/architecture/README.md"
    text = index_path.read_text(encoding="utf-8")
    for link in re.findall(r"\[[^\]]+\]\(([^)]+)\)", text):
        if "://" in link or link.startswith("#"):
            continue
        target = (index_path.parent / link.split("#", 1)[0]).resolve()
        if not target.is_file():
            raise PolicyFailure(f"architecture index link does not exist: {link}")
    for current in (CURRENT_SECURITY, CURRENT_GOVERNANCE, CURRENT_RELEASE_GATES):
        if current not in text or not (index_path.parent / current).is_file():
            raise PolicyFailure(f"architecture authority missing: {current}")
    if OBSOLETE_SECURITY in text:
        raise PolicyFailure("obsolete non-revision Security baseline is referenced")
    if "sole current security baseline" not in text:
        raise PolicyFailure("Security REV1 is not identified as the sole baseline")
    if "behavioral references only" not in text:
        raise PolicyFailure("V1 is not identified as behavioral reference only")


def validate_governance_statements(repository: Path, paths: Iterable[str]) -> None:
    prohibited = re.compile(
        r"(?:direct\s+main\s+push|force\s+push)\s*[:=]\s*"
        r"(?:allowed|enabled|on|yes)",
        re.IGNORECASE,
    )
    for relative in paths:
        if not relative.endswith((".md", ".yml", ".yaml", ".py", ".toml")):
            continue
        text = (repository / relative).read_text(encoding="utf-8", errors="replace")
        if prohibited.search(text):
            raise PolicyFailure(
                f"{relative}: governance statement permits a protected operation"
            )


def validate_dependency_review_allowlist(repository: Path) -> None:
    baseline = load_json(repository, ".ci/baselines/dependency-advisories.json")
    expected = {
        item.get("advisory_id")
        for item in baseline.get("pnpm", [])
        if item.get("severity") in {"high", "critical"}
    }
    if None in expected:
        raise PolicyFailure("dependency advisory baseline has an invalid advisory ID")
    workflow = (
        repository / ".github/workflows/dependency-review.yml"
    ).read_text(encoding="utf-8")
    actual = set(re.findall(r"GHSA-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4}", workflow))
    if actual != expected:
        raise PolicyFailure(
            "dependency-review allow-ghsas must exactly match the expiring "
            "high-severity pnpm baseline"
        )


def validate_repository(repository: Path) -> list[str]:
    paths = tracked_paths(repository)
    missing = sorted(REQUIRED_REPOSITORY_FILES - set(paths))
    if missing:
        raise PolicyFailure("required governance files missing: " + ", ".join(missing))
    validate_dangerous_paths(paths)
    validate_basic_structures(repository, paths)
    validate_workspace_skeleton(repository, paths)
    validate_api_application_layout(paths)
    validate_legacy_frontend_layout(repository, paths)
    validate_architecture_index(repository)
    validate_governance_statements(repository, paths)
    validate_dependency_review_allowlist(repository)
    for relative in paths:
        if relative.startswith(".github/workflows/") and relative.endswith(
            (".yml", ".yaml")
        ):
            validate_workflow_text(
                relative, (repository / relative).read_text(encoding="utf-8")
            )
    return paths


def validate_event(repository: Path, event_name: str, event_path: Path) -> None:
    if event_name != "pull_request":
        return
    event = json.loads(event_path.read_text(encoding="utf-8"))
    pull_request = event.get("pull_request")
    if not isinstance(pull_request, dict):
        raise PolicyFailure("pull_request event payload is missing")
    base_sha = pull_request.get("base", {}).get("sha")
    head_sha = pull_request.get("head", {}).get("sha")
    body = pull_request.get("body") or ""
    title = pull_request.get("title") or ""
    paths = changed_paths(repository, str(base_sha), str(head_sha))
    validate_pr_body(body, title, paths, str(base_sha))


def parse_arguments() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repository", type=Path, required=True)
    return parser.parse_args()


def main() -> int:
    arguments = parse_arguments()
    repository = arguments.repository.resolve()
    try:
        paths = validate_repository(repository)
        event_name = os.environ.get("POLICY_EVENT_NAME", "")
        event_value = os.environ.get("POLICY_EVENT_PATH", "")
        if event_name:
            if not event_value:
                raise PolicyFailure("POLICY_EVENT_PATH is required")
            validate_event(repository, event_name, Path(event_value))
    except (OSError, ValueError, PolicyFailure) as error:
        print(f"policy-gate: FAIL: {error}", file=sys.stderr)
        return 1
    print(
        json.dumps(
            {
                "gate": "policy-gate",
                "status": "PASS",
                "tracked_files": len(paths),
                "event": os.environ.get("POLICY_EVENT_NAME") or "local",
            },
            sort_keys=True,
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
