#!/usr/bin/env python3
"""Compare ESLint JSON output with an exact, expiring baseline."""

from __future__ import annotations

import argparse
import datetime
import hashlib
import json
from pathlib import Path
import sys


class BaselineFailure(RuntimeError):
    pass


def normalize_path(value: str) -> str:
    marker = "/frontend/"
    if marker in value:
        return "frontend/" + value.split(marker, 1)[1]
    value = value.replace("\\", "/")
    return value if value.startswith("frontend/") else "frontend/" + value.lstrip("/")


def normalize_findings(report: list[dict]) -> list[dict]:
    findings = []
    for file_result in report:
        path = normalize_path(str(file_result.get("filePath", "")))
        for message in file_result.get("messages", []):
            item = {
                "path": path,
                "line": message.get("line"),
                "column": message.get("column"),
                "end_line": message.get("endLine"),
                "end_column": message.get("endColumn"),
                "rule_id": message.get("ruleId"),
                "severity": message.get("severity"),
                "message_sha256": hashlib.sha256(
                    str(message.get("message", "")).encode()
                ).hexdigest(),
            }
            item["fingerprint"] = hashlib.sha256(
                json.dumps(item, sort_keys=True, separators=(",", ":")).encode()
            ).hexdigest()
            findings.append(item)
    return sorted(findings, key=lambda item: item["fingerprint"])


def validate_baseline(report: list[dict], baseline: dict, today: datetime.date) -> dict:
    if baseline.get("schema_version") != "1.0":
        raise BaselineFailure("unsupported ESLint baseline schema")
    management = baseline.get("management", {})
    required = {"owner", "reason", "removal_condition", "expires_at", "tracking_task"}
    if not required.issubset(management) or any(
        not str(management.get(key, "")).strip() for key in required
    ):
        raise BaselineFailure("ESLint baseline management metadata is incomplete")
    expires = datetime.date.fromisoformat(management["expires_at"])
    if today > expires:
        raise BaselineFailure("ESLint baseline has expired")

    actual = normalize_findings(report)
    expected = sorted(
        baseline.get("findings", []), key=lambda item: item.get("fingerprint", "")
    )
    if actual != expected:
        actual_ids = {item["fingerprint"] for item in actual}
        expected_ids = {item.get("fingerprint") for item in expected}
        added = sorted(actual_ids - expected_ids)
        missing = sorted(expected_ids - actual_ids)
        raise BaselineFailure(
            f"ESLint baseline mismatch: new={len(added)} missing={len(missing)}"
        )
    return {
        "findings": len(actual),
        "errors": sum(item["severity"] == 2 for item in actual),
        "warnings": sum(item["severity"] == 1 for item in actual),
        "expires_at": management["expires_at"],
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--report", type=Path, required=True)
    parser.add_argument("--baseline", type=Path, required=True)
    arguments = parser.parse_args()
    try:
        report = json.loads(arguments.report.read_text(encoding="utf-8"))
        baseline = json.loads(arguments.baseline.read_text(encoding="utf-8"))
        summary = validate_baseline(report, baseline, datetime.date.today())
    except (OSError, ValueError, json.JSONDecodeError, BaselineFailure) as error:
        print(f"lint-baseline: FAIL: {error}", file=sys.stderr)
        return 1
    print(json.dumps({"gate": "lint-baseline", "status": "PASS", **summary}, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
