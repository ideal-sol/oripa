"""Allowlisted, aggregate-only diagnostics for the unchanged Full V2 invocation."""

from collections import Counter, defaultdict
import math
from pathlib import Path
import re
import xml.etree.ElementTree as ET


MAX_REPORT_BYTES = 32 * 1024 * 1024
EVENT = re.compile(
    r"^\[(\d+:\d+:\d+\.\d+) / \d+:\d+:\d+\.\d+\] \[\d+ bytes\] "
    r"Test (Preparation Started|Prepared|Skipped|Finished) \((.*)\)$"
)


class TimingFailure(ValueError):
    def __init__(self, code: int = 0):
        self.code = code if type(code) is int and 0 <= code <= 30 else 0
        super().__init__("Invalid or incomplete Full V2 timing evidence")


def source_test_names(repository: Path) -> dict[str, set[str]]:
    names = {}
    parents = {}
    for path in sorted((repository / "apps/api/tests/V2").rglob("*Test.php")):
        source = path.read_text()
        namespace = re.search(r"^namespace (Tests\\V2(?:\\\w+)*);", source, re.MULTILINE)
        declaration = re.search(r"\bclass (\w+Test) extends ([\\\w]+)\b", source)
        if namespace and declaration:
            class_name = namespace[1] + "\\" + declaration[1]
            names[class_name] = set(re.findall(
                r"\bpublic function (\w+)\s*\(", source
            ))
            parents[class_name] = (declaration[2].lstrip("\\") if declaration[2].startswith("\\")
                                   else namespace[1] + "\\" + declaration[2])
    if not names:
        raise TimingFailure()
    resolved = {}

    def inherited(class_name: str, visiting: set[str]) -> set[str]:
        if class_name in visiting:
            raise TimingFailure(17)
        if class_name not in resolved:
            methods = set(names[class_name])
            parent = parents[class_name]
            if parent in names:
                methods.update(inherited(parent, visiting | {class_name}))
            resolved[class_name] = methods
        return resolved[class_name]

    return {class_name: inherited(class_name, set()) for class_name in names}


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
        raise TimingFailure(1)
    return class_name, method_name


def event_preparation(raw: bytes, allowed: dict[str, set[str]]) -> dict:
    if len(raw) > MAX_REPORT_BYTES:
        raise TimingFailure()
    active = None
    previous_time = 0.0
    finished = Counter()
    completed = Counter()
    skipped = Counter()
    unprepared_skipped = Counter()
    seen = set()
    prepared = Counter()
    duration = defaultdict(float)
    for line in raw.decode("utf-8", errors="strict").splitlines():
        match = EVENT.fullmatch(line)
        if not match:
            continue
        hours, minutes, remainder = match[1].split(":")
        timestamp = int(hours) * 3600 + int(minutes) * 60 + seconds(remainder)
        if timestamp < previous_time:
            raise TimingFailure(2)
        previous_time = timestamp
        event, identifier = match[2], match[3]
        identity = re.match(r"^(Tests\\V2(?:\\\w+)*)::(\w+)(?:$|\W)", identifier)
        if not identity:
            raise TimingFailure(3)
        class_name, method_name = public_name(identity[1], identity[2], allowed)
        if event == "Preparation Started":
            if active is not None or identifier in seen:
                raise TimingFailure(4)
            seen.add(identifier)
            active = {"id": identifier, "start": timestamp, "prepared": False, "skipped": False}
        elif event == "Prepared":
            if active is None or active["id"] != identifier or active["prepared"]:
                raise TimingFailure(5)
            active["prepared"] = True
            duration[class_name] += timestamp - active["start"]
            prepared[class_name] += 1
        elif event == "Skipped":
            if active is None or active["id"] != identifier or active["skipped"]:
                raise TimingFailure(16)
            active["skipped"] = True
            skipped[class_name] += 1
            if not active["prepared"]:
                unprepared_skipped[class_name] += 1
                completed[class_name] += 1
                active = None
        elif event == "Finished":
            if active is None or active["id"] != identifier or not active["prepared"]:
                raise TimingFailure(6)
            finished[class_name] += 1
            completed[class_name] += 1
            active = None
    if active is not None or not completed:
        raise TimingFailure(7)
    return {"finished": finished, "completed": completed, "skipped": skipped,
            "unprepared_skipped": unprepared_skipped, "prepared": prepared, "seconds": duration}


def summarize_phpunit_timing(junit: bytes, events: bytes, allowed: dict[str, set[str]], expected: dict) -> dict:
    if len(junit) > MAX_REPORT_BYTES or b"<!DOCTYPE" in junit or b"<!ENTITY" in junit:
        raise TimingFailure()
    try:
        root = ET.fromstring(junit)
    except ET.ParseError:
        raise TimingFailure() from None
    if root.tag != "testsuites" or len(root.findall("testsuite")) != 1:
        raise TimingFailure(8)
    suite = root.find("testsuite")
    totals = {key: count(suite.get(key)) for key in ("tests", "assertions", "skipped", "errors", "failures")}
    if totals["errors"] or totals["failures"] or totals["tests"] == 0:
        raise TimingFailure(9)
    if any(totals[key] != expected.get(key, 0) for key in ("tests", "assertions", "skipped")):
        raise TimingFailure(10)
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
        raise TimingFailure(11)
    preparation = event_preparation(events, allowed)
    if set(preparation["completed"]) != set(classes):
        raise TimingFailure(12)
    for class_name, aggregate in classes.items():
        if preparation["completed"][class_name] != aggregate["tests"] or preparation["skipped"][class_name] != aggregate["skipped"]:
            raise TimingFailure(13)
        aggregate["prepared_tests"] = preparation["prepared"][class_name]
        if aggregate["prepared_tests"] != aggregate["tests"] - preparation["unprepared_skipped"][class_name]:
            raise TimingFailure(14)
        aggregate["preparation_seconds"] = round(preparation["seconds"][class_name], 6)
        aggregate["duration_seconds"] = round(aggregate["duration_seconds"], 6)
        if aggregate["preparation_seconds"] > aggregate["duration_seconds"] + 0.01:
            raise TimingFailure(15)
    return {
        "schema_version": "oripa.full-v2-timing.v1",
        "outcome": "PASS",
        "counts": totals,
        "junit_test_seconds": round(sum(row["duration_seconds"] for row in rows), 6),
        "preparation_seconds": round(sum(preparation["seconds"].values()), 6),
        "prepared_tests": sum(preparation["prepared"].values()),
        "event_finished_tests": sum(preparation["finished"].values()),
        "event_completed_tests": sum(preparation["completed"].values()),
        "unprepared_skipped_tests": sum(preparation["unprepared_skipped"].values()),
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
