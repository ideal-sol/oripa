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
STOREFRONT_CLIENT_REQUIRED_FILES = {
    "packages/storefront-client/.gitignore",
    "packages/storefront-client/README.md",
    "packages/storefront-client/package.json",
    "packages/storefront-client/eslint.config.mjs",
    "packages/storefront-client/tsconfig.json",
    "packages/storefront-client/tsconfig.build.json",
    "packages/storefront-client/scripts/check-generated.mjs",
    "packages/storefront-client/src/browser.ts",
    "packages/storefront-client/src/constants.ts",
    "packages/storefront-client/src/errors.ts",
    "packages/storefront-client/src/generated/public.ts",
    "packages/storefront-client/src/index.ts",
    "packages/storefront-client/src/server.ts",
    "packages/storefront-client/src/transport.ts",
    "packages/storefront-client/src/types.ts",
    "packages/storefront-client/test/client.test.mjs",
}
WORKSPACE_REQUIRED_FILES = {
    ".dockerignore",
    ".github/dependabot.yml",
    "package.json",
    "pnpm-workspace.yaml",
    "pnpm-lock.yaml",
    "apps/README.md",
    "apps/api/README.md",
    "apps/admin/README.md",
    "apps/admin/Dockerfile",
    "apps/admin/package.json",
    "apps/admin/next.config.ts",
    "apps/admin/tsconfig.json",
    "apps/admin/eslint.config.mjs",
    "apps/admin/next-env.d.ts",
    "apps/admin/src/app/layout.tsx",
    "apps/admin/src/app/page.tsx",
    "apps/admin/src/app/api/health/route.ts",
    "apps/api/AGENTS.md",
    "apps/admin/AGENTS.md",
    "packages/README.md",
    "packages/platform/README.md",
    "packages/platform/package.json",
    *STOREFRONT_CLIENT_REQUIRED_FILES,
    "packages/site-schema/README.md",
    "packages/site-schema/package.json",
    "packages/storefront-testkit/README.md",
    "packages/storefront-testkit/package.json",
    "packages/AGENTS.md",
    "openapi/README.md",
    "openapi/AGENTS.md",
    "openapi/redocly.yaml",
    "openapi/components/common.yaml",
    "openapi/public/openapi.yaml",
    "openapi/admin/openapi.yaml",
    "openapi/webhook/openapi.yaml",
    "openapi/bundled/public.openapi.json",
    "openapi/bundled/admin.openapi.json",
    "openapi/bundled/webhook.openapi.json",
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
    "docker-compose.yml",
    "docker-compose.v2.yml",
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
ADMIN_SKELETON_FILES = {
    "apps/admin/AGENTS.md",
    "apps/admin/README.md",
    "apps/admin/Dockerfile",
    "apps/admin/package.json",
    "apps/admin/next.config.ts",
    "apps/admin/tsconfig.json",
    "apps/admin/eslint.config.mjs",
    "apps/admin/next-env.d.ts",
    "apps/admin/src/app/layout.tsx",
    "apps/admin/src/app/page.tsx",
    "apps/admin/src/app/api/health/route.ts",
}
PACKAGE_SKELETONS = {
    "packages/platform/package.json": "@oripa/platform",
    "packages/site-schema/package.json": "@oripa/site-schema",
    "packages/storefront-testkit/package.json": "@oripa/storefront-testkit",
}
ADMIN_DEPENDENCY_VERSIONS = {
    "next": "16.2.11",
    "react": "19.2.7",
    "react-dom": "19.2.7",
}
ADMIN_DEV_DEPENDENCY_VERSIONS = {
    "@types/node": "25.9.2",
    "@types/react": "19.2.17",
    "@types/react-dom": "19.2.3",
    "eslint": "9.39.4",
    "eslint-config-next": "16.2.11",
    "typescript": "6.0.3",
}
ROOT_DEV_DEPENDENCY_VERSIONS = {
    "@redocly/cli": "2.40.0",
}
STOREFRONT_CLIENT_DEV_DEPENDENCY_VERSIONS = {
    "eslint": "9.39.4",
    "openapi-typescript": "7.13.0",
    "typescript": "5.9.3",
    "typescript-eslint": "8.65.0",
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
    if package.get("engines") != {"node": "22.22.3", "pnpm": "10.12.1"}:
        raise PolicyFailure("package.json: Node and pnpm engines must be exact")
    if package.get("dependencies"):
        raise PolicyFailure("package.json: root runtime dependencies are prohibited")
    if package.get("devDependencies") != ROOT_DEV_DEPENDENCY_VERSIONS:
        raise PolicyFailure(
            "package.json: only the pinned OpenAPI validation tool is allowed"
        )
    if package.get("pnpm") != {
        "overrides": {
            "js-yaml": "4.3.0",
            "postcss": "8.5.10",
            "sharp": "0.35.0",
        }
    }:
        raise PolicyFailure("package.json: audited exact pnpm overrides are invalid")

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
        r"(?:^|/)(?:apps/api|backend|frontend|legacy/v1-frontend)(?:/|$)",
        "\n".join(members),
    ):
        raise PolicyFailure(
            "pnpm-workspace.yaml: API and V1 paths must not enter V2 workspace"
        )

    lock_text = (repository / "pnpm-lock.yaml").read_text(encoding="utf-8")
    if not lock_text.startswith("lockfileVersion: '9.0'\n"):
        raise PolicyFailure("pnpm-lock.yaml: lockfileVersion 9.0 is required")
    importer_text = lock_text.split("\npackages:\n", 1)[0]
    importers = set(
        re.findall(r"^  ([A-Za-z0-9@._/-]+):(?: \{\})?$", importer_text, re.MULTILINE)
    )
    expected_importers = {
        ".",
        "apps/admin",
        "packages/platform",
        "packages/site-schema",
        "packages/storefront-client",
        "packages/storefront-testkit",
    }
    if importers != expected_importers:
        raise PolicyFailure("pnpm-lock.yaml: workspace importers are invalid")
    if "legacy/v1-frontend" in importer_text or "apps/api" in importer_text:
        raise PolicyFailure("pnpm-lock.yaml: excluded paths entered the V2 lockfile")

    dependabot = (repository / ".github/dependabot.yml").read_text(encoding="utf-8")
    npm_directories = set(
        re.findall(
            r"package-ecosystem:\s*npm\s+directory:\s*([^ \n]+)",
            dependabot,
            re.MULTILINE,
        )
    )
    if npm_directories != {"/", "/legacy/v1-frontend"}:
        raise PolicyFailure(
            ".github/dependabot.yml: Root and Legacy npm scopes must remain separate"
        )


def validate_exact_dependency_versions(
    package: dict,
    expected_dependencies: dict[str, str],
    expected_dev_dependencies: dict[str, str],
    relative: str,
) -> None:
    if package.get("dependencies", {}) != expected_dependencies:
        raise PolicyFailure(f"{relative}: exact runtime dependencies are invalid")
    if package.get("devDependencies", {}) != expected_dev_dependencies:
        raise PolicyFailure(f"{relative}: exact development dependencies are invalid")
    for section in ("dependencies", "devDependencies"):
        for name, version in package.get(section, {}).items():
            if not SEMANTIC_VERSION.fullmatch(version):
                raise PolicyFailure(
                    f"{relative}: dependency {name} must use an exact version"
                )


def validate_admin_skeleton(repository: Path, paths: Iterable[str]) -> None:
    path_set = set(paths)
    actual = {path for path in path_set if path.startswith("apps/admin/")}
    unexpected = sorted(actual - ADMIN_SKELETON_FILES)
    missing = sorted(ADMIN_SKELETON_FILES - actual)
    if missing:
        raise PolicyFailure("Admin Skeleton files missing: " + ", ".join(missing))
    if unexpected:
        raise PolicyFailure(
            "Admin Skeleton contains unapproved application files: "
            + ", ".join(unexpected)
        )

    package = load_json(repository, "apps/admin/package.json")
    if (
        package.get("name") != "@oripa/admin"
        or package.get("version") != "2.0.0-alpha.1"
        or package.get("private") is not True
        or package.get("packageManager") != "pnpm@10.12.1"
        or package.get("engines") != {"node": "22.22.3", "pnpm": "10.12.1"}
    ):
        raise PolicyFailure("apps/admin/package.json: Skeleton identity is invalid")
    validate_exact_dependency_versions(
        package,
        ADMIN_DEPENDENCY_VERSIONS,
        ADMIN_DEV_DEPENDENCY_VERSIONS,
        "apps/admin/package.json",
    )
    required_scripts = {"build", "dev", "lint", "start", "typecheck"}
    if set(package.get("scripts", {})) != required_scripts:
        raise PolicyFailure("apps/admin/package.json: required scripts are invalid")

    source = "\n".join(
        (repository / relative).read_text(encoding="utf-8", errors="replace")
        for relative in sorted(actual)
        if relative.endswith((".ts", ".tsx", ".mjs"))
    )
    for prohibited in (
        "admin-dashboard",
        "legacy/v1-frontend",
        "Math.random",
        "/api/v2",
        "fetch(",
        "cookies(",
    ):
        if prohibited in source:
            raise PolicyFailure(
                f"apps/admin: Skeleton contains prohibited implementation: {prohibited}"
            )
    layout = (repository / "apps/admin/src/app/layout.tsx").read_text(
        encoding="utf-8"
    )
    if "index: false" not in layout or "follow: false" not in layout:
        raise PolicyFailure("apps/admin: noindex and nofollow metadata are required")
    health = (repository / "apps/admin/src/app/api/health/route.ts").read_text(
        encoding="utf-8"
    )
    if (
        "export function GET" not in health
        or 'status: "ok"' not in health
        or "production_ready: false" not in health
    ):
        raise PolicyFailure("apps/admin: deterministic Skeleton health is required")


def validate_package_skeletons(repository: Path) -> None:
    for relative, expected_name in PACKAGE_SKELETONS.items():
        package = load_json(repository, relative)
        if (
            package.get("name") != expected_name
            or package.get("version") != "2.0.0-alpha.1"
            or package.get("private") is not True
        ):
            raise PolicyFailure(f"{relative}: Package Skeleton identity is invalid")
        forbidden = {
            "bin",
            "dependencies",
            "devDependencies",
            "exports",
            "main",
            "module",
            "optionalDependencies",
            "peerDependencies",
            "scripts",
        }
        present = sorted(forbidden & set(package))
        if present:
            raise PolicyFailure(
                f"{relative}: Skeleton must not define implementation: "
                + ", ".join(present)
            )


def validate_storefront_client(repository: Path, paths: Iterable[str]) -> None:
    path_set = set(paths)
    missing = sorted(STOREFRONT_CLIENT_REQUIRED_FILES - path_set)
    if missing:
        raise PolicyFailure(
            "Storefront Client files missing: " + ", ".join(missing)
        )

    package = load_json(repository, "packages/storefront-client/package.json")
    identity = {
        "name": package.get("name"),
        "version": package.get("version"),
        "private": package.get("private"),
        "type": package.get("type"),
        "sideEffects": package.get("sideEffects"),
        "packageManager": package.get("packageManager"),
        "engines": package.get("engines"),
        "files": package.get("files"),
    }
    if identity != {
        "name": "@oripa/storefront-client",
        "version": "2.0.0-alpha.1",
        "private": True,
        "type": "module",
        "sideEffects": False,
        "packageManager": "pnpm@10.12.1",
        "engines": {"node": "22.22.3", "pnpm": "10.12.1"},
        "files": ["dist"],
    }:
        raise PolicyFailure(
            "packages/storefront-client/package.json: Alpha identity is invalid"
        )
    validate_exact_dependency_versions(
        package,
        {},
        STOREFRONT_CLIENT_DEV_DEPENDENCY_VERSIONS,
        "packages/storefront-client/package.json",
    )
    expected_scripts = {
        "build",
        "generate",
        "generate:check",
        "lint",
        "test",
        "typecheck",
    }
    if set(package.get("scripts", {})) != expected_scripts:
        raise PolicyFailure(
            "packages/storefront-client/package.json: scripts are invalid"
        )
    if set(package.get("exports", {})) != {
        ".",
        "./browser",
        "./server",
        "./types",
    }:
        raise PolicyFailure(
            "packages/storefront-client/package.json: public exports are invalid"
        )
    if package.get("oripaCompatibility") != {
        "family": 2,
        "apiMajor": 2,
        "minimumPublicApiContract": "2.0.0-alpha.1",
        "requiredCapabilities": [],
    }:
        raise PolicyFailure(
            "packages/storefront-client/package.json: compatibility metadata is invalid"
        )
    if (
        repository / "packages/storefront-client/.gitignore"
    ).read_text(encoding="utf-8").strip() != "/dist/":
        raise PolicyFailure(
            "packages/storefront-client/.gitignore: only dist output must be ignored"
        )

    generated = (
        repository / "packages/storefront-client/src/generated/public.ts"
    ).read_text(encoding="utf-8")
    for required in (
        "This file was auto-generated by openapi-typescript.",
        "export type paths = Record<string, never>;",
        "export type operations = Record<string, never>;",
    ):
        if required not in generated:
            raise PolicyFailure(
                "packages/storefront-client: generated Public API types are invalid"
            )

    public_surface = "\n".join(
        (repository / relative).read_text(encoding="utf-8")
        for relative in (
            "packages/storefront-client/src/index.ts",
            "packages/storefront-client/src/types.ts",
            "packages/storefront-client/src/browser.ts",
            "packages/storefront-client/src/server.ts",
        )
    )
    if re.search(r"\b(?:Admin|Webhook)", public_surface):
        raise PolicyFailure(
            "packages/storefront-client: Admin or Webhook type is publicly exported"
        )

    browser = (
        repository / "packages/storefront-client/src/browser.ts"
    ).read_text(encoding="utf-8")
    if 'credentials: "include"' not in browser:
        raise PolicyFailure(
            "packages/storefront-client: Browser credentials must be include"
        )
    transport = (
        repository / "packages/storefront-client/src/transport.ts"
    ).read_text(encoding="utf-8")
    for required in (
        "X-Oripa-Client-Version",
        "X-Oripa-Site-Version",
        "Idempotency-Key",
        "AbortSignal",
        "RETRYABLE_STATUS",
        "csrf_initializer",
        "application/problem+json",
    ):
        if required not in transport and required not in (
            repository / "packages/storefront-client/src/constants.ts"
        ).read_text(encoding="utf-8"):
            raise PolicyFailure(
                f"packages/storefront-client: transport boundary missing {required}"
            )


def validate_compose_skeletons(repository: Path) -> None:
    v1 = (repository / "docker-compose.yml").read_text(encoding="utf-8")
    for required in ("./apps/api", "./legacy/v1-frontend", "postgres:", "redis:"):
        if required not in v1:
            raise PolicyFailure(f"docker-compose.yml: V1 reference missing {required}")
    if "non-production characterization only" not in v1:
        raise PolicyFailure("docker-compose.yml: V1 non-Production purpose is missing")

    v2 = (repository / "docker-compose.v2.yml").read_text(encoding="utf-8")
    for required in ("api:", "admin:", "postgres:", "redis:", "healthcheck:"):
        if required not in v2:
            raise PolicyFailure(f"docker-compose.v2.yml: required value missing {required}")
    for prohibited in ("legacy/v1-frontend", "container_name:"):
        if prohibited in v2:
            raise PolicyFailure(
                f"docker-compose.v2.yml: prohibited value present {prohibited}"
            )
    if "non-production-skeleton" not in v2 and "never a Production" not in v2:
        raise PolicyFailure("docker-compose.v2.yml: non-Production purpose is missing")
    dockerignore = (repository / ".dockerignore").read_text(encoding="utf-8")
    if not re.search(r"^legacy/v1-frontend$", dockerignore, re.MULTILINE):
        raise PolicyFailure(".dockerignore: Legacy Frontend root-context exclusion missing")


def validate_boundary_readmes(repository: Path) -> None:
    for relative in sorted(BOUNDARY_READMES):
        text = (repository / relative).read_text(encoding="utf-8")
        headings = markdown_headings(text)
        missing = sorted(BOUNDARY_HEADINGS - headings)
        if missing:
            raise PolicyFailure(
                f"{relative}: responsibility headings missing: {', '.join(missing)}"
            )
        status_statement = (
            "Alpha"
            if relative == "packages/storefront-client/README.md"
            else "Skeleton"
        )
        for statement in ("AGENTS.md", status_statement, "Production", "V1"):
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
    validate_workspace_configuration(repository)
    validate_admin_skeleton(repository, paths)
    validate_package_skeletons(repository)
    validate_storefront_client(repository, paths)
    validate_compose_skeletons(repository)
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
