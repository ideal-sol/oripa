"""Allowlisted, aggregate-only diagnostics for the unchanged Full V2 invocation."""

from collections import Counter, defaultdict
import math
from pathlib import Path
import re
import xml.etree.ElementTree as ET


MAX_REPORT_BYTES = 32 * 1024 * 1024
EVENT = re.compile(
    r"^\[(\d+:\d+:\d+\.\d+) / \d+:\d+:\d+\.\d+\] \[\d+ bytes\] "
    r"Test (Preparation Started|Prepared|Finished) \((.*)\)$"
)


class TimingFailure(ValueError):
    def __init__(self):
        super().__init__("Invalid or incomplete Full V2 timing evidence")


def source_test_names(repository: Path) -> dict[str, set[str]]:
    names = {}
    for path in sorted((repository / "apps/api/tests/V2").rglob("*Test.php")):
        source = path.read_text()
        namespace = re.search(r"^namespace (Tests\\V2(?:\\\w+)*);", source, re.MULTILINE)
        declaration = re.search(r"\bclass (\w+Test) extends\b", source)
        if namespace and declaration:
            names[namespace[1] + "\\" + declaration[1]] = set(re.findall(
                r"\bpublic function (\w+)\s*\(", source
            ))
    if not names:
        raise TimingFailure()
    return names


def seconds(value: str) -> float:
    try:
        result = float(value)
    except (ValueError, TypeError):
        raise TimingFailure() from None
    if not math.isfinite(result) or result < 0:
        raise TimingFailure()
    return result


def count(value: str) -> int:
    if not re.fullmatch(r"[0-9]{1,9}", value or ""):
        raise TimingFailure()
    return int(value)


def public_name(class_name: str, method_name: str, allowed: dict[str, set[str]]) -> tuple[str, str]:
    if class_name not in allowed or method_name not in allowed[class_name]:
        raise TimingFailure()
    return class_name, method_name


def event_preparation(raw: bytes, allowed: dict[str, set[str]]) -> dict:
    if len(raw) > MAX_REPORT_BYTES:
        raise TimingFailure()
    active = None
    previous_time = 0.0
    finished = Counter()
    prepared = Counter()
    duration = defaultdict(float)
    for line in raw.decode("utf-8", errors="strict").splitlines():
        match = EVENT.fullmatch(line)
        if not match:
            continue
        hours, minutes, remainder = match[1].split(":")
        timestamp = int(hours) * 3600 + int(minutes) * 60 + seconds(remainder)
        if timestamp < previous_time:
            raise TimingFailure()
        previous_time = timestamp
        event, identifier = match[2], match[3]
        identity = re.match(r"^(Tests\\V2(?:\\\w+)*)::(\w+)(?:$|\W)", identifier)
        if not identity:
            raise TimingFailure()
        class_name, method_name = public_name(identity[1], identity[2], allowed)
        if event == "Preparation Started":
            if active is not None:
                raise TimingFailure()
            active = {"id": identifier, "start": timestamp, "prepared": False}
        elif event == "Prepared":
            if active is None or active["id"] != identifier or active["prepared"]:
                raise TimingFailure()
            active["prepared"] = True
            duration[class_name] += timestamp - active["start"]
            prepared[class_name] += 1
        elif event == "Finished":
            if active is None or active["id"] != identifier:
                raise TimingFailure()
            finished[class_name] += 1
            active = None
    if active is not None or not finished:
        raise TimingFailure()
    return {"finished": finished, "prepared": prepared, "seconds": duration}


def summarize_phpunit_timing(junit: bytes, events: bytes, allowed: dict[str, set[str]], expected: dict) -> dict:
    if len(junit) > MAX_REPORT_BYTES or b"<!DOCTYPE" in junit or b"<!ENTITY" in junit:
        raise TimingFailure()
    try:
        root = ET.fromstring(junit)
    except ET.ParseError:
        raise TimingFailure() from None
    if root.tag != "testsuites" or len(root.findall("testsuite")) != 1:
        raise TimingFailure()
    suite = root.find("testsuite")
    totals = {key: count(suite.get(key)) for key in ("tests", "assertions", "skipped", "errors", "failures")}
    if totals["errors"] or totals["failures"] or totals["tests"] == 0:
        raise TimingFailure()
    if any(totals[key] != expected.get(key, 0) for key in ("tests", "assertions", "skipped")):
        raise TimingFailure()
    rows = []
    classes = {}
    for ordinal, case in enumerate(suite.iter("testcase"), 1):
        class_name, method_name = public_name(
            case.get("class", ""), case.get("name", "").split(" with data set ", 1)[0], allowed
        )
        duration = seconds(case.get("time"))
        assertions = count(case.get("assertions"))
        skipped = int(case.find("skipped") is not None)
        if case.find("failure") is not None or case.find("error") is not None:
            raise TimingFailure()
        rows.append({"class": class_name, "method": method_name, "invocation_ordinal": ordinal, "duration_seconds": duration, "assertions": assertions, "skipped": bool(skipped)})
        aggregate = classes.setdefault(class_name, {"class": class_name, "tests": 0, "assertions": 0, "skipped": 0, "duration_seconds": 0.0})
        aggregate["tests"] += 1
        aggregate["assertions"] += assertions
        aggregate["skipped"] += skipped
        aggregate["duration_seconds"] += duration
    if len(rows) != totals["tests"] or sum(row["assertions"] for row in rows) != totals["assertions"] or sum(row["skipped"] for row in rows) != totals["skipped"]:
        raise TimingFailure()
    preparation = event_preparation(events, allowed)
    if set(preparation["finished"]) != set(classes):
        raise TimingFailure()
    for class_name, aggregate in classes.items():
        if preparation["finished"][class_name] != aggregate["tests"]:
            raise TimingFailure()
        aggregate["prepared_tests"] = preparation["prepared"][class_name]
        if not aggregate["tests"] - aggregate["skipped"] <= aggregate["prepared_tests"] <= aggregate["tests"]:
            raise TimingFailure()
        aggregate["preparation_seconds"] = round(preparation["seconds"][class_name], 6)
        aggregate["duration_seconds"] = round(aggregate["duration_seconds"], 6)
        if aggregate["preparation_seconds"] > aggregate["duration_seconds"] + 0.01:
            raise TimingFailure()
    return {
        "schema_version": "oripa.full-v2-timing.v1",
        "outcome": "PASS",
        "counts": totals,
        "junit_test_seconds": round(sum(row["duration_seconds"] for row in rows), 6),
        "preparation_seconds": round(sum(preparation["seconds"].values()), 6),
        "prepared_tests": sum(preparation["prepared"].values()),
        "event_finished_tests": sum(preparation["finished"].values()),
        "top_tests": sorted(rows, key=lambda row: (-row["duration_seconds"], row["invocation_ordinal"]))[:20],
        "top_classes": sorted(classes.values(), key=lambda row: (-row["duration_seconds"], row["class"]))[:10],
        "preparation_definition": "PreparationStarted to Prepared; includes framework preparation, setUp and preconditions; excludes tests skipped before Prepared",
        "remaining_definition": "JUnit total minus measured preparation includes test bodies, verification, teardown and framework overhead; not isolated body time",
        "teardown_only_seconds": None,
        "database_initialization_only_seconds": None,
        "password_hashing_only_seconds": None,
        "raw_reports_retained": False,
        "dataset_labels_retained": False,
    }
