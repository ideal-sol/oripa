#!/usr/bin/env python3
"""Compare PHPUnit JUnit failures with an exact, expiring legacy baseline."""

from __future__ import annotations

import argparse
import datetime
import json
from pathlib import Path
import sys
import xml.etree.ElementTree as ET


class BaselineFailure(RuntimeError):
    pass


def normalize_failures(root: ET.Element) -> list[dict]:
    failures = []
    for case in root.iter("testcase"):
        for failure in [*case.findall("failure"), *case.findall("error")]:
            failures.append(
                {
                    "class": case.get("class") or case.get("classname"),
                    "name": case.get("name"),
                    "type": failure.get("type"),
                }
            )
    return sorted(
        failures,
        key=lambda item: (str(item["class"]), str(item["name"]), str(item["type"])),
    )


def validate_baseline(
    report: ET.Element, baseline: dict, today: datetime.date
) -> dict:
    if baseline.get("schema_version") != "1.0":
        raise BaselineFailure("unsupported backend test baseline schema")
    management = baseline.get("management", {})
    required = {"owner", "reason", "removal_condition", "expires_at", "tracking_task"}
    if not required.issubset(management) or any(
        not str(management.get(key, "")).strip() for key in required
    ):
        raise BaselineFailure("backend test baseline management metadata is incomplete")
    expires = datetime.date.fromisoformat(management["expires_at"])
    if today > expires:
        raise BaselineFailure("backend test baseline has expired")

    actual = normalize_failures(report)
    expected = sorted(
        baseline.get("failures", []),
        key=lambda item: (str(item["class"]), str(item["name"]), str(item["type"])),
    )
    if actual != expected:
        actual_keys = {
            (item["class"], item["name"], item["type"]) for item in actual
        }
        expected_keys = {
            (item["class"], item["name"], item["type"]) for item in expected
        }
        raise BaselineFailure(
            "backend test baseline mismatch: "
            f"new={len(actual_keys - expected_keys)} "
            f"missing={len(expected_keys - actual_keys)}"
        )
    return {"failures": len(actual), "expires_at": management["expires_at"]}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--report", type=Path, required=True)
    parser.add_argument("--baseline", type=Path, required=True)
    arguments = parser.parse_args()
    try:
        report = ET.parse(arguments.report).getroot()
        baseline = json.loads(arguments.baseline.read_text(encoding="utf-8"))
        summary = validate_baseline(report, baseline, datetime.date.today())
    except (OSError, ValueError, ET.ParseError, json.JSONDecodeError, BaselineFailure) as error:
        print(f"backend-test-baseline: FAIL: {error}", file=sys.stderr)
        return 1
    print(
        json.dumps(
            {"gate": "backend-test-baseline", "status": "PASS", **summary},
            sort_keys=True,
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
