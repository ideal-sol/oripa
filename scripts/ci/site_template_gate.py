#!/usr/bin/env python3
"""Validate a Site Template without importing Platform implementation code."""

from __future__ import annotations

import argparse
import json
from pathlib import Path
import re
import sys


class SiteTemplateFailure(RuntimeError):
    pass


EXACT_VERSION = re.compile(r"[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?")
SOURCE_SUFFIXES = {".js", ".jsx", ".ts", ".tsx", ".mjs", ".cjs"}
REQUIRED_PACKAGES = {"@oripa/storefront-client", "@oripa/site-schema"}
REQUIRED_SCRIPTS = {"build", "typecheck", "lint", "test:contract"}
FORBIDDEN_DIRECTORIES = {
    "backend",
    "apps/api",
    "apps/admin",
    "infrastructure",
}
FORBIDDEN_SOURCE = {
    "Math.random": re.compile(r"\bMath\.random\s*\("),
    "Laravel namespace": re.compile(r"\b(?:Illuminate|App\\Domain|App\\Models)\\"),
    "payment logic": re.compile(r"\b(?:paymentWebhook|authorizePayment|capturePayment)\b"),
    "point logic": re.compile(r"\b(?:pointLedger|consumePoints|grantPoints)\b"),
    "draw logic": re.compile(r"\b(?:drawService|selectPrize|calculateProbability)\b"),
    "admin logic": re.compile(r"\b(?:adminSession|adminApi|AdminGuard)\b"),
}
DIRECT_API_FETCH = re.compile(
    r"\bfetch\s*\([^)]*(?:https?://[^'\"\s)]*)?/api/v2(?:[/\"'\s)])",
    re.IGNORECASE | re.DOTALL,
)
SENSITIVE_ENV_NAME = re.compile(
    r"(?:SECRET|TOKEN|PASSWORD|PRIVATE_KEY|CREDENTIAL|PRODUCTION)", re.IGNORECASE
)
HIGH_CONFIDENCE_VALUE = re.compile(
    r"(?:gh[pousr]_|github_pat_)[A-Za-z0-9_]{20,}|AKIA[A-Z0-9]{16}"
)


def load_json(path: Path) -> dict:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError) as error:
        raise SiteTemplateFailure(f"invalid JSON: {path.name}") from error
    if not isinstance(value, dict):
        raise SiteTemplateFailure(f"JSON root must be an object: {path.name}")
    return value


def require_template_file(root: Path, relative: str) -> Path:
    path = root / relative
    if not path.is_file() or path.is_symlink():
        raise SiteTemplateFailure(f"required regular file missing: {relative}")
    return path


def validate_package(package: dict) -> dict:
    dependencies = {}
    for section in ("dependencies", "devDependencies", "peerDependencies"):
        values = package.get(section, {})
        if not isinstance(values, dict):
            raise SiteTemplateFailure(f"{section} must be an object")
        dependencies.update(values)

    missing = sorted(REQUIRED_PACKAGES - dependencies.keys())
    if missing:
        raise SiteTemplateFailure("required first-party packages missing")
    for name, version in dependencies.items():
        if name.startswith("@oripa/") and (
            not isinstance(version, str) or not EXACT_VERSION.fullmatch(version)
        ):
            raise SiteTemplateFailure(f"first-party package is not exact: {name}")

    scripts = package.get("scripts", {})
    if not isinstance(scripts, dict):
        raise SiteTemplateFailure("scripts must be an object")
    missing_scripts = sorted(
        name for name in REQUIRED_SCRIPTS if not str(scripts.get(name, "")).strip()
    )
    if missing_scripts:
        raise SiteTemplateFailure("required validation scripts missing")
    return dependencies


def validate_site_config(config: dict, dependencies: dict) -> None:
    required = {
        "schema_version": "1.0",
        "repository_role": "site",
        "platform_source": "package-only",
    }
    for key, expected in required.items():
        if config.get(key) != expected:
            raise SiteTemplateFailure(f"site config value invalid: {key}")
    site_id = config.get("site_id")
    if not isinstance(site_id, str) or not re.fullmatch(r"[a-z0-9][a-z0-9-]{1,62}", site_id):
        raise SiteTemplateFailure("site_id is invalid")
    packages = config.get("packages")
    if not isinstance(packages, dict):
        raise SiteTemplateFailure("site config packages must be an object")
    for name in REQUIRED_PACKAGES:
        if packages.get(name) != dependencies.get(name):
            raise SiteTemplateFailure(f"site config package mismatch: {name}")


def validate_environment(path: Path) -> None:
    for raw_line in path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#"):
            continue
        if "=" not in line:
            raise SiteTemplateFailure("environment template line is invalid")
        name, value = line.split("=", 1)
        if SENSITIVE_ENV_NAME.search(name):
            raise SiteTemplateFailure("sensitive environment name is forbidden")
        if HIGH_CONFIDENCE_VALUE.search(value):
            raise SiteTemplateFailure("credential-like environment value is forbidden")
        if "customer-" in value.lower() or "other-site" in value.lower():
            raise SiteTemplateFailure("cross-site environment value is forbidden")


def source_files(root: Path) -> list[Path]:
    source_root = root / "src"
    if not source_root.is_dir():
        raise SiteTemplateFailure("src directory is missing")
    files = sorted(
        path
        for path in source_root.rglob("*")
        if path.is_file() and not path.is_symlink() and path.suffix in SOURCE_SUFFIXES
    )
    if not files:
        raise SiteTemplateFailure("site source is empty")
    return files


def validate_sources(root: Path) -> int:
    combined = []
    files = source_files(root)
    for path in files:
        try:
            text = path.read_text(encoding="utf-8")
        except (OSError, UnicodeError) as error:
            raise SiteTemplateFailure("site source is not UTF-8 text") from error
        if DIRECT_API_FETCH.search(text):
            raise SiteTemplateFailure("direct /api/v2 fetch is forbidden")
        for classification, pattern in FORBIDDEN_SOURCE.items():
            if pattern.search(text):
                raise SiteTemplateFailure(f"Platform logic is forbidden: {classification}")
        combined.append(text)
    if "@oripa/storefront-client" not in "\n".join(combined):
        raise SiteTemplateFailure("storefront client usage is required")
    return len(files)


def validate_template(root: Path) -> dict:
    root = root.resolve()
    if not root.is_dir():
        raise SiteTemplateFailure("template directory is missing")
    for relative in FORBIDDEN_DIRECTORIES:
        if (root / relative).exists():
            raise SiteTemplateFailure(f"Platform directory copied into Site: {relative}")

    package = load_json(require_template_file(root, "package.json"))
    config = load_json(require_template_file(root, "site.config.json"))
    environment = require_template_file(root, ".env.example")
    dependencies = validate_package(package)
    validate_site_config(config, dependencies)
    validate_environment(environment)
    source_count = validate_sources(root)
    return {
        "canonical_template": False,
        "first_party_packages": sorted(REQUIRED_PACKAGES),
        "source_files": source_count,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--template", type=Path, required=True)
    arguments = parser.parse_args()
    try:
        summary = validate_template(arguments.template)
    except SiteTemplateFailure as error:
        print(f"site-template-gate: FAIL: {error}", file=sys.stderr)
        return 1
    print(
        json.dumps(
            {"gate": "site-template-gate", "status": "PASS", **summary},
            sort_keys=True,
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
