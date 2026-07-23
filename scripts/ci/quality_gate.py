#!/usr/bin/env python3
"""Dependency-free repository quality checks."""

from __future__ import annotations

import argparse
import json
from pathlib import Path
import re
import subprocess
import sys
import xml.etree.ElementTree as ElementTree


class QualityFailure(RuntimeError):
    pass


def git_paths(repository: Path) -> list[str]:
    result = subprocess.run(
        ["git", "-C", str(repository), "ls-files"],
        check=True,
        stdout=subprocess.PIPE,
        text=True,
    )
    return [line for line in result.stdout.splitlines() if line]


def validate_json(repository: Path, paths: list[str]) -> int:
    count = 0
    for relative in paths:
        if not relative.endswith(".json"):
            continue
        try:
            json.loads((repository / relative).read_text(encoding="utf-8"))
        except (UnicodeError, json.JSONDecodeError) as error:
            raise QualityFailure(f"invalid JSON: {relative}") from error
        count += 1
    return count


def validate_xml(repository: Path, paths: list[str]) -> int:
    count = 0
    for relative in paths:
        if not relative.endswith(".xml"):
            continue
        try:
            ElementTree.parse(repository / relative)
        except ElementTree.ParseError as error:
            raise QualityFailure(f"invalid XML: {relative}") from error
        count += 1
    return count


def validate_yaml(repository: Path, paths: list[str]) -> int:
    count = 0
    for relative in paths:
        if not relative.endswith((".yml", ".yaml")):
            continue
        text = (repository / relative).read_text(encoding="utf-8")
        if not text.strip() or "\t" in text:
            raise QualityFailure(f"invalid basic YAML: {relative}")
        count += 1
    return count


def validate_toml(repository: Path, paths: list[str]) -> int:
    count = 0
    for relative in paths:
        if not relative.endswith(".toml"):
            continue
        for number, line in enumerate(
            (repository / relative).read_text(encoding="utf-8").splitlines(), 1
        ):
            value = line.strip()
            if not value or value.startswith("#"):
                continue
            if re.fullmatch(r"\[[A-Za-z0-9_.\"/-]+\]", value) or "=" in value:
                continue
            raise QualityFailure(f"invalid basic TOML: {relative}:{number}")
        count += 1
    return count


def validate_php(repository: Path, paths: list[str]) -> int:
    php_paths = [
        relative
        for relative in paths
        if relative.startswith("apps/api/") and relative.endswith(".php")
    ]
    for relative in php_paths:
        result = subprocess.run(
            ["php", "-l", str(repository / relative)],
            check=False,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.PIPE,
            text=True,
        )
        if result.returncode:
            raise QualityFailure(f"PHP syntax failed: {relative}")
    return len(php_paths)


def validate_manifests(repository: Path, paths: list[str]) -> None:
    required = {
        "apps/api/composer.json",
        "apps/api/composer.lock",
        "legacy/v1-frontend/package.json",
        "legacy/v1-frontend/pnpm-lock.yaml",
    }
    missing = sorted(required - set(paths))
    if missing:
        raise QualityFailure("manifest or lockfile missing: " + ", ".join(missing))


def validate_contracts(repository: Path, paths: list[str]) -> int:
    contracts = [
        relative
        for relative in paths
        if relative.startswith(("openapi/", "packages/"))
        and (
            relative.endswith((".openapi.json", ".schema.json"))
            or Path(relative).name in {"openapi.json", "schema.json"}
        )
    ]
    for relative in contracts:
        try:
            json.loads((repository / relative).read_text(encoding="utf-8"))
        except (UnicodeError, json.JSONDecodeError) as error:
            raise QualityFailure(f"invalid contract JSON: {relative}") from error
    return len(contracts)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repository", type=Path, required=True)
    arguments = parser.parse_args()
    repository = arguments.repository.resolve()
    try:
        paths = git_paths(repository)
        validate_manifests(repository, paths)
        summary = {
            "json": validate_json(repository, paths),
            "xml": validate_xml(repository, paths),
            "yaml": validate_yaml(repository, paths),
            "toml": validate_toml(repository, paths),
            "php": validate_php(repository, paths),
            "contracts": validate_contracts(repository, paths),
        }
        subprocess.run(
            ["git", "-C", str(repository), "diff", "--check"],
            check=True,
            stdout=subprocess.PIPE,
            text=True,
        )
    except (OSError, subprocess.CalledProcessError, QualityFailure) as error:
        print(f"quality-gate: FAIL: {error}", file=sys.stderr)
        return 1
    print(json.dumps({"gate": "quality-gate", "status": "PASS", **summary}, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
