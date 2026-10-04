#!/usr/bin/env python3
"""Reject new ESLint fingerprints while allowing resolved approved findings."""

from __future__ import annotations

import argparse
from collections import Counter
import hashlib
import json
from pathlib import Path
import re
import sys


class BaselineFailure(RuntimeError):
    pass


def normalize_path(value: str) -> str:
    marker = "/legacy/v1-frontend/"
    if marker in value:
        return "legacy/v1-frontend/" + value.split(marker, 1)[1]
    value = value.replace("\\", "/")
    return (
        value
        if value.startswith("legacy/v1-frontend/")
        else "legacy/v1-frontend/" + value.lstrip("/")
    )


def normalize_message(value: str) -> str:
    normalized = value.replace("\\", "/")
    return re.sub(
        r"(?m)^.*?/legacy/v1-frontend/",
        "frontend/",
        normalized,
    )


def normalize_findings(report: list[dict]) -> list[dict]:
    if not isinstance(report, list) or not report:
        raise BaselineFailure("ESLint report is missing or malformed")
    findings = []
    for file_result in report:
        if (
            not isinstance(file_result, dict)
            or not isinstance(file_result.get("filePath"), str)
            or not file_result["filePath"].strip()
            or not isinstance(file_result.get("messages"), list)
        ):
            raise BaselineFailure("ESLint file result is malformed")
        path = normalize_path(str(file_result.get("filePath", "")))
        for message in file_result["messages"]:
            if (
                not isinstance(message, dict)
                or message.get("fatal")
                or type(message.get("severity")) is not int
                or message["severity"] not in {1, 2}
                or not isinstance(message.get("ruleId"), str)
                or not message["ruleId"]
                or not isinstance(message.get("message"), str)
                or not message["message"]
                or any(
                    type(message.get(key)) is not int or message[key] < 1
                    for key in ("line", "column")
                )
                or any(
                    message.get(key) is not None
                    and (type(message[key]) is not int or message[key] < 1)
                    for key in ("endLine", "endColumn")
                )
            ):
                raise BaselineFailure("ESLint message is malformed or fatal")
            item = {
                "path": path,
                "line": message.get("line"),
                "column": message.get("column"),
                "end_line": message.get("endLine"),
                "end_column": message.get("endColumn"),
                "rule_id": message.get("ruleId"),
                "severity": message.get("severity"),
                "message_sha256": hashlib.sha256(
                    normalize_message(str(message.get("message", ""))).encode()
                ).hexdigest(),
            }
            item["fingerprint"] = hashlib.sha256(
                json.dumps(item, sort_keys=True, separators=(",", ":")).encode()
            ).hexdigest()
            findings.append(item)
        for key, severity in (("errorCount", 2), ("warningCount", 1)):
            if (
                type(file_result.get(key)) is not int
                or file_result[key] != sum(
                    message["severity"] == severity for message in file_result["messages"]
                )
            ):
                raise BaselineFailure("ESLint report counts are missing or inconsistent")
        if (
            type(file_result.get("fatalErrorCount")) is not int
            or file_result["fatalErrorCount"] != 0
        ):
            raise BaselineFailure("ESLint report has fatal errors or missing counts")
    return sorted(findings, key=lambda item: item["fingerprint"])


def validate_baseline(report: list[dict], baseline: dict) -> dict:
    if not isinstance(baseline, dict) or baseline.get("schema_version") != "1.1":
        raise BaselineFailure("unsupported ESLint baseline schema")
    management = baseline.get("management", {})
    required = {"owner", "reason", "removal_condition", "tracking_task"}
    if not isinstance(management, dict) or any(
        not isinstance(management.get(key), str) or not management[key].strip()
        for key in required
    ):
        raise BaselineFailure("ESLint baseline management metadata is incomplete")

    actual = normalize_findings(report)
    expected = baseline.get("findings")
    if not isinstance(expected, list):
        raise BaselineFailure("ESLint baseline findings are missing or malformed")
    fields = {
        "path", "line", "column", "end_line", "end_column", "rule_id",
        "severity", "message_sha256", "fingerprint",
    }
    for item in expected:
        if not isinstance(item, dict) or set(item) != fields:
            raise BaselineFailure("ESLint baseline finding is malformed")
        if (
            any(
                not isinstance(item[key], str) or not item[key].strip()
                for key in ("path", "rule_id", "message_sha256", "fingerprint")
            )
            or not item["path"].startswith("legacy/v1-frontend/")
            or not re.fullmatch(r"[0-9a-f]{64}", item["message_sha256"])
            or type(item["severity"]) is not int
            or item["severity"] not in {1, 2}
            or any(
                type(item[key]) is not int or item[key] < 1
                for key in ("line", "column")
            )
            or any(
                item[key] is not None and (type(item[key]) is not int or item[key] < 1)
                for key in ("end_line", "end_column")
            )
        ):
            raise BaselineFailure("ESLint baseline finding is malformed")
        payload = {key: value for key, value in item.items() if key != "fingerprint"}
        digest = hashlib.sha256(
            json.dumps(payload, sort_keys=True, separators=(",", ":")).encode()
        ).hexdigest()
        if item["fingerprint"] != digest:
            raise BaselineFailure("ESLint baseline fingerprint is invalid")
    actual_counts = Counter(json.dumps(item, sort_keys=True) for item in actual)
    expected_counts = Counter(json.dumps(item, sort_keys=True) for item in expected)
    added = actual_counts - expected_counts
    resolved = expected_counts - actual_counts
    if added:
        raise BaselineFailure(
            "ESLint baseline mismatch: new=" + json.dumps(list(added.elements()))
        )
    return {
        "findings": len(actual),
        "current_findings": len(actual),
        "known_findings": len(actual),
        "new_findings": 0,
        "resolved_baseline_entries": sum(resolved.values()),
        "errors": sum(item["severity"] == 2 for item in actual),
        "warnings": sum(item["severity"] == 1 for item in actual),
    }


def validate_exit_status(status: int, report: list[dict]) -> None:
    findings = normalize_findings(report)
    expected = int(any(item["severity"] == 2 for item in findings))
    if type(status) is not int or status != expected:
        raise BaselineFailure("ESLint command failed or exit status contradicts report")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--report", type=Path, required=True)
    parser.add_argument("--baseline", type=Path, required=True)
    parser.add_argument("--exit-status", type=int, required=True)
    arguments = parser.parse_args()
    try:
        report = json.loads(arguments.report.read_text(encoding="utf-8"))
        baseline = json.loads(arguments.baseline.read_text(encoding="utf-8"))
        validate_exit_status(arguments.exit_status, report)
        summary = validate_baseline(report, baseline)
    except (OSError, ValueError, json.JSONDecodeError, BaselineFailure) as error:
        print(f"lint-baseline: FAIL: {error}", file=sys.stderr)
        return 1
    print(json.dumps({"gate": "lint-baseline", "status": "PASS", **summary}, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
