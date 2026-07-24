#!/usr/bin/env python3
"""Validate, bundle, and compare the three OpenAPI contract surfaces."""

from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
from typing import Any, Iterable


SURFACES = {
    "public": {
        "source": "openapi/public/openapi.yaml",
        "bundle": "openapi/bundled/public.openapi.json",
        "title": "Oripa Public API",
        "server": "/api/v2",
    },
    "admin": {
        "source": "openapi/admin/openapi.yaml",
        "bundle": "openapi/bundled/admin.openapi.json",
        "title": "Oripa Admin API",
        "server": "/admin/api/v2",
    },
    "webhook": {
        "source": "openapi/webhook/openapi.yaml",
        "bundle": "openapi/bundled/webhook.openapi.json",
        "title": "Oripa Webhook API",
        "server": "/webhooks/v2",
    },
}
HTTP_METHODS = {"get", "put", "post", "delete", "options", "head", "patch", "trace"}
REQUIRED_COMMON_SCHEMAS = {
    "OpaqueId",
    "SemanticVersion",
    "UtcDateTime",
    "BusinessDate",
    "ProblemDetails",
}
PUBLIC_LEAK_FIELDS = {
    "password_hash",
    "remember_token",
    "provider_secret",
    "cost_price",
    "profit_rate",
    "qa_plan_id",
    "qa_item_id",
    "individual_ppm",
    "internal_weight",
}
SEMVER = re.compile(
    r"^2\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?$"
)
FULL_SHA = re.compile(r"^[0-9a-f]{40}$")


class ContractFailure(RuntimeError):
    """A deterministic OpenAPI contract violation."""


def run(
    arguments: list[str],
    repository: Path,
    classification: str,
) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(
        arguments,
        cwd=repository,
        check=False,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
    )
    if result.returncode:
        detail = result.stderr.strip().splitlines()
        suffix = f": {detail[-1]}" if detail else ""
        raise ContractFailure(f"{classification}{suffix}")
    return result


def iter_operations(document: dict[str, Any]) -> Iterable[tuple[str, str, dict[str, Any]]]:
    for path, path_item in document.get("paths", {}).items():
        if not isinstance(path_item, dict):
            continue
        for method, operation in path_item.items():
            if method.lower() in HTTP_METHODS and isinstance(operation, dict):
                yield path, method.lower(), operation


def nested_keys(value: Any) -> Iterable[str]:
    if isinstance(value, dict):
        for key, child in value.items():
            yield str(key)
            yield from nested_keys(child)
    elif isinstance(value, list):
        for child in value:
            yield from nested_keys(child)


def validate_document(surface: str, document: dict[str, Any]) -> set[str]:
    expected = SURFACES[surface]
    if document.get("openapi") != "3.1.1":
        raise ContractFailure(f"{surface}: OpenAPI 3.1.1 is required")
    if document.get("jsonSchemaDialect") != "https://json-schema.org/draft/2020-12/schema":
        raise ContractFailure(f"{surface}: JSON Schema Draft 2020-12 dialect is required")
    info = document.get("info")
    if not isinstance(info, dict):
        raise ContractFailure(f"{surface}: info is required")
    if info.get("title") != expected["title"] or not SEMVER.fullmatch(
        str(info.get("version", ""))
    ):
        raise ContractFailure(f"{surface}: title or contract version is invalid")
    if info.get("x-status") not in {"skeleton", "alpha"}:
        raise ContractFailure(f"{surface}: contract status must be skeleton or alpha")
    if document.get("x-oripa-surface") != surface:
        raise ContractFailure(f"{surface}: surface marker is invalid")
    if document.get("servers") != [{"url": expected["server"]}]:
        raise ContractFailure(f"{surface}: server namespace is invalid")
    if not isinstance(document.get("paths"), dict):
        raise ContractFailure(f"{surface}: paths must be an object")
    if info.get("x-status") == "skeleton" and document["paths"]:
        raise ContractFailure(f"{surface}: Skeleton must not define business endpoints")

    components = document.get("components")
    schemas = components.get("schemas") if isinstance(components, dict) else None
    if not isinstance(schemas, dict) or not REQUIRED_COMMON_SCHEMAS.issubset(schemas):
        raise ContractFailure(f"{surface}: required common schemas are missing")
    problem = schemas.get("ProblemDetails")
    if not isinstance(problem, dict):
        raise ContractFailure(f"{surface}: ProblemDetails is missing")
    required_problem = {"type", "title", "status", "code", "request_id", "retryable"}
    if not required_problem.issubset(set(problem.get("required", []))):
        raise ContractFailure(f"{surface}: ProblemDetails required fields are incomplete")

    operation_ids: set[str] = set()
    for path, method, operation in iter_operations(document):
        operation_id = operation.get("operationId")
        if not isinstance(operation_id, str) or not operation_id:
            raise ContractFailure(f"{surface}: {method.upper()} {path} lacks operationId")
        if operation_id in operation_ids:
            raise ContractFailure(f"{surface}: duplicate operationId: {operation_id}")
        operation_ids.add(operation_id)
        for extension in (
            "x-idempotency",
            "x-rate-limit",
            "x-cache-policy",
            "x-stability",
        ):
            if extension not in operation:
                raise ContractFailure(
                    f"{surface}: {method.upper()} {path} lacks {extension}"
                )

    if surface == "public":
        for name, schema in schemas.items():
            if not isinstance(schema, dict):
                continue
            properties = schema.get("properties", {})
            if not isinstance(properties, dict) or "password" not in properties:
                continue
            password = properties["password"]
            if (
                name not in {"UserRegistrationRequest", "PasswordLoginRequest"}
                or not isinstance(password, dict)
                or password.get("writeOnly") is not True
            ):
                raise ContractFailure("public: password is exposed outside an auth request")
        leaked = sorted(
            {key.lower() for key in nested_keys(document)} & PUBLIC_LEAK_FIELDS
        )
        if leaked:
            raise ContractFailure("public: internal schema fields leaked: " + ", ".join(leaked))
    return operation_ids


def compare_schema(previous: Any, current: Any, location: str) -> list[str]:
    findings: list[str] = []
    if not isinstance(previous, dict) or not isinstance(current, dict):
        return findings
    if previous.get("type") != current.get("type"):
        findings.append(f"{location}: type changed")
    previous_required = set(previous.get("required", []))
    current_required = set(current.get("required", []))
    for field in sorted(current_required - previous_required):
        findings.append(f"{location}: required field added: {field}")
    previous_properties = previous.get("properties", {})
    current_properties = current.get("properties", {})
    if isinstance(previous_properties, dict) and isinstance(current_properties, dict):
        for field in sorted(set(previous_properties) - set(current_properties)):
            findings.append(f"{location}: property removed: {field}")
        for field in sorted(set(previous_properties) & set(current_properties)):
            findings.extend(
                compare_schema(
                    previous_properties[field],
                    current_properties[field],
                    f"{location}.{field}",
                )
            )
    previous_enum = set(previous.get("enum", []))
    current_enum = set(current.get("enum", []))
    for value in sorted(previous_enum - current_enum, key=str):
        findings.append(f"{location}: enum value removed: {value}")
    return findings


def breaking_changes(previous: dict[str, Any], current: dict[str, Any]) -> list[str]:
    findings: list[str] = []
    previous_paths = previous.get("paths", {})
    current_paths = current.get("paths", {})
    if not isinstance(previous_paths, dict) or not isinstance(current_paths, dict):
        return ["paths: invalid object"]
    for path in sorted(set(previous_paths) - set(current_paths)):
        findings.append(f"{path}: path removed")
    for path in sorted(set(previous_paths) & set(current_paths)):
        old_item = previous_paths[path]
        new_item = current_paths[path]
        if not isinstance(old_item, dict) or not isinstance(new_item, dict):
            continue
        for method in sorted(HTTP_METHODS & set(old_item)):
            if method not in new_item:
                findings.append(f"{method.upper()} {path}: operation removed")
                continue
            old_operation = old_item[method]
            new_operation = new_item[method]
            if not isinstance(old_operation, dict) or not isinstance(new_operation, dict):
                continue
            if old_operation.get("operationId") != new_operation.get("operationId"):
                findings.append(f"{method.upper()} {path}: operationId changed")
            if old_operation.get("security") != new_operation.get("security"):
                findings.append(f"{method.upper()} {path}: security changed")
            if old_operation.get("x-idempotency") != new_operation.get("x-idempotency"):
                findings.append(f"{method.upper()} {path}: idempotency changed")
            old_responses = old_operation.get("responses", {})
            new_responses = new_operation.get("responses", {})
            if isinstance(old_responses, dict) and isinstance(new_responses, dict):
                for status in sorted(set(old_responses) - set(new_responses)):
                    findings.append(f"{method.upper()} {path}: response removed: {status}")

    old_schemas = previous.get("components", {}).get("schemas", {})
    new_schemas = current.get("components", {}).get("schemas", {})
    if isinstance(old_schemas, dict) and isinstance(new_schemas, dict):
        for name in sorted(set(old_schemas) - set(new_schemas)):
            findings.append(f"components.schemas.{name}: schema removed")
        for name in sorted(set(old_schemas) & set(new_schemas)):
            findings.extend(
                compare_schema(
                    old_schemas[name],
                    new_schemas[name],
                    f"components.schemas.{name}",
                )
            )
    return findings


def event_base_sha() -> str | None:
    if os.environ.get("GITHUB_EVENT_NAME") != "pull_request":
        return None
    event_path = os.environ.get("GITHUB_EVENT_PATH")
    if not event_path:
        raise ContractFailure("pull request event path is unavailable")
    event = json.loads(Path(event_path).read_text(encoding="utf-8"))
    base_sha = str(event.get("pull_request", {}).get("base", {}).get("sha", ""))
    if not FULL_SHA.fullmatch(base_sha):
        raise ContractFailure("pull request base SHA is invalid")
    return base_sha


def previous_bundle(repository: Path, base_sha: str, relative: str) -> dict[str, Any] | None:
    result = subprocess.run(
        ["git", "-C", str(repository), "show", f"{base_sha}:{relative}"],
        check=False,
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        text=True,
    )
    if result.returncode:
        return None
    value = json.loads(result.stdout)
    if not isinstance(value, dict):
        raise ContractFailure(f"{relative}: previous bundle is invalid")
    return value


def generate_bundles(repository: Path, output_root: Path) -> dict[str, Path]:
    sources = [str(value["source"]) for value in SURFACES.values()]
    run(
        [
            "pnpm",
            "exec",
            "redocly",
            "lint",
            "--config",
            "openapi/redocly.yaml",
            *sources,
        ],
        repository,
        "OpenAPI lint failed",
    )
    generated: dict[str, Path] = {}
    for surface, values in SURFACES.items():
        output = output_root / f"{surface}.openapi.json"
        run(
            [
                "pnpm",
                "exec",
                "redocly",
                "bundle",
                "--config",
                "openapi/redocly.yaml",
                str(values["source"]),
                "--output",
                str(output),
            ],
            repository,
            f"{surface}: bundle failed",
        )
        generated[surface] = output
    return generated


def validate_generated(
    repository: Path,
    generated: dict[str, Path],
    check_bundles: bool,
    write_bundles: bool,
) -> dict[str, int]:
    all_operation_ids: set[str] = set()
    base_sha = event_base_sha()
    summary: dict[str, int] = {}
    for surface, generated_path in generated.items():
        document = json.loads(generated_path.read_text(encoding="utf-8"))
        if not isinstance(document, dict):
            raise ContractFailure(f"{surface}: bundle is not an object")
        operation_ids = validate_document(surface, document)
        duplicates = all_operation_ids & operation_ids
        if duplicates:
            raise ContractFailure(
                "operationId is duplicated across surfaces: " + ", ".join(sorted(duplicates))
            )
        all_operation_ids.update(operation_ids)
        relative = str(SURFACES[surface]["bundle"])
        target = repository / relative
        if write_bundles:
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(generated_path.read_bytes())
        if check_bundles:
            if not target.is_file() or target.read_bytes() != generated_path.read_bytes():
                raise ContractFailure(f"{surface}: committed bundle differs from source")
        if base_sha:
            previous = previous_bundle(repository, base_sha, relative)
            if previous is not None:
                findings = breaking_changes(previous, document)
                if findings:
                    raise ContractFailure(
                        f"{surface}: breaking change detected: " + "; ".join(findings)
                    )
        summary[surface] = len(operation_ids)
    return summary


def parse_arguments() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repository", required=True, type=Path)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--check-bundles", action="store_true")
    mode.add_argument("--write-bundles", action="store_true")
    return parser.parse_args()


def main() -> int:
    arguments = parse_arguments()
    repository = arguments.repository.resolve()
    try:
        with tempfile.TemporaryDirectory(prefix="oripa-openapi-") as temporary:
            generated = generate_bundles(repository, Path(temporary))
            summary = validate_generated(
                repository,
                generated,
                arguments.check_bundles,
                arguments.write_bundles,
            )
    except (ContractFailure, OSError, ValueError, json.JSONDecodeError) as error:
        print(f"openapi-contract-gate: FAIL: {error}", file=sys.stderr)
        return 1
    print(
        json.dumps(
            {
                "gate": "openapi-contract-gate",
                "status": "PASS",
                "surfaces": summary,
                "bundle_mode": "check" if arguments.check_bundles else "write",
            },
            sort_keys=True,
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
