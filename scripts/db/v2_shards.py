"""Partition the PHPUnit listing, reconcile JUnit and publish only case fingerprints."""
import argparse
from collections import defaultdict
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import time
import xml.etree.ElementTree as ET


def fingerprint(value):
    return hashlib.sha256(value.encode()).hexdigest()


def partition(xml, dependencies, weights):
    tests = ET.fromstring(xml).find("{*}tests")
    classes = defaultdict(list)
    files = {}
    parent = {}

    def root(name):
        while parent[name] != name:
            name = parent[name]
        return name

    all_ids = set()
    for node in tests:
        if node.tag.split("}")[-1] != "testClass":
            raise ValueError("Unsupported PHPUnit listing entry")
        name = node.attrib["name"]
        parent.setdefault(name, name)
        file_class = files.setdefault(node.attrib["file"], name)
        parent[root(name)] = root(file_class)
        for method in node:
            case = method.attrib["id"]
            if not case.startswith(name + "::") or case in all_ids:
                raise ValueError("Invalid or duplicate PHPUnit ID")
            all_ids.add(case)
            classes[name].append(case)

    for name, targets in dependencies.items():
        for target in targets:
            parent[root(name)] = root(target)

    # Reporting checks the whole payments table and ledger. In the serial suite,
    # the preceding prize concurrency fixture restores a clean schema in finally.
    reporting = r"Tests\V2\ReportingExportVerticalSliceTest"
    if reporting in classes:
        cleanup = r"Tests\V2\PrizeShippingConcurrencyTest"
        parent[root(reporting)] = root(cleanup)

    groups = defaultdict(list)
    for name in classes:
        groups[root(name)].append(name)
    # Historical JUnit seconds per case. Unknown classes use the suite residual mean.
    def cost(group):
        return sum(len(classes[name]) * weights["classes"].get(name, weights["default"])
                   for name in group)

    buckets = [[] for _ in range(4)]
    loads = [0.0] * 4
    for group in sorted(groups.values(), key=lambda g: (-cost(g), min(g))):
        index = min(range(4), key=lambda i: (loads[i], i))
        buckets[index].extend(sorted(group))
        loads[index] += cost(group)
    if not all(buckets):
        raise ValueError("Empty DB shard")
    return buckets, classes


def results(xml):
    suite = ET.fromstring(xml).find("testsuite")
    cases = {}
    seconds = defaultdict(float)
    for case in suite.iter("testcase"):
        name = case.attrib["name"]
        method, separator, dataset = name.partition(" with data set ")
        if separator:
            if re.fullmatch(r"#-?\d+", dataset):
                method += dataset
            elif dataset.startswith('"') and dataset.endswith('"'):
                method += "#" + dataset[1:-1]
            else:
                raise ValueError("Unknown PHPUnit dataset format")
        cls = case.attrib["class"]
        key = fingerprint(cls + "::" + method)
        assertions = int(case.attrib["assertions"])
        if key in cases or assertions < 0 or any(case.find(tag) is not None for tag in ("error", "failure")):
            raise ValueError("Failed or duplicate JUnit case")
        cases[key] = [assertions, case.find("skipped") is not None]
        if "time" not in case.attrib:
            seconds[cls] = None
        elif seconds[cls] is not None:
            seconds[cls] += float(case.attrib["time"])
    expected = [len(cases), sum(v[0] for v in cases.values()), sum(v[1] for v in cases.values()), 0, 0]
    if expected != [int(suite.attrib[k]) for k in ("tests", "assertions", "skipped", "errors", "failures")]:
        raise ValueError("JUnit totals disagree")
    return cases, seconds


def identity(repository):
    sha = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=repository, text=True).strip()
    if sha != os.environ["DB_SOURCE_SHA"]:
        raise ValueError("Unexpected checkout")
    return [sha, os.environ["GITHUB_RUN_ID"], os.environ["GITHUB_RUN_ATTEMPT"]]


def failure_summary(xml, classes, repository):
    summaries = []
    for case in ET.fromstring(xml).iter("testcase"):
        issue = case.find("error")
        if issue is None:
            issue = case.find("failure")
        if issue is None:
            continue
        cls = case.attrib.get("class", "")
        method = case.attrib.get("name", "").partition(" with data set ")[0]
        prefix = cls + "::" + method
        if not any(test.partition("#")[0] == prefix for test in classes.get(cls, [])):
            continue
        # Report only source identifiers, test locations and known failure categories.
        detail = issue.text or ""
        frames = re.findall(r"/(tests/V2/\w+\.php):(\d+)", detail)
        locations = [f"{path}:{line}" for path, line in frames if (repository / "apps/api" / path).is_file()]
        categories = [key for key in ("SQLSTATE", "timeout", "Failed asserting", "memory") if key in detail]
        summaries.append(" ".join([prefix, issue.tag, *categories, *locations[:2]]))
    return summaries[:12]


def execute(run, base, repository, shard):
    api = base + ["exec", "-T", "api"]
    phpunit = api + ["php", "-d", "memory_limit=512M", "vendor/bin/phpunit", "--configuration", "phpunit.v2.xml"]
    run(phpunit + ["--list-tests-xml", "/tmp/v2-list.xml"], cwd=repository)
    listing = run(api + ["cat", "/tmp/v2-list.xml"], cwd=repository)
    here = Path(__file__).parent
    dependencies = json.loads(run(api + ["php"], cwd=repository, input_bytes=(here / "phpunit_dependencies.php").read_bytes()))
    buckets, classes = partition(listing, dict(dependencies), json.loads((here / "v2_shard_weights.json").read_text()))
    plan = [sorted(fingerprint(case) for cls in bucket for case in classes[cls]) for bucket in buckets]
    options = []
    expected = {case for bucket in plan for case in bucket}
    if shard:
        pattern = "|".join(re.escape(cls) for cls in buckets[shard - 1])
        options = ["--filter", "/^(?:" + pattern + ")::/"]
        expected = set(plan[shard - 1])
    # Keep raw JUnit, including any failure text and dataset labels, inside the isolated container.
    try:
        run(phpunit + options + ["--log-junit", "/tmp/v2-junit.xml"], cwd=repository)
    except RuntimeError as error:
        cause = error.__cause__
        status = cause.returncode if isinstance(cause, subprocess.CalledProcessError) else None
        print(f"Full V2 process failed on shard {shard} (exit={status})", flush=True)
        xml = run(api + ["cat", "/tmp/v2-junit.xml"], cwd=repository)
        print(json.dumps(failure_summary(xml, classes, repository)), flush=True)
        raise
    cases, seconds = results(run(api + ["cat", "/tmp/v2-junit.xml"], cwd=repository))
    if expected != cases.keys():
        raise ValueError("Executed cases disagree with the PHPUnit listing")
    return dict(identity=identity(repository), shard=shard, plan=plan, cases=cases, class_seconds=seconds)


def aggregate(directory, expected_identity, serial=None):
    combined = {}
    reference = None
    for shard in range(1, 5):
        report = json.loads((directory / f"shard-{shard}.json").read_text())
        plan = report["plan"]
        if (report["identity"] != expected_identity or report["shard"] != shard
                or report["smoke_complete"] is not True or len(plan) != 4
                or (reference is not None and plan != reference)):
            raise ValueError("Inconsistent shard evidence")
        cases = report["cases"]
        if (not cases or sorted(cases) != plan[shard - 1] or combined.keys() & cases.keys()
                or any(not isinstance(v, list) or len(v) != 2 or type(v[0]) is not int or v[0] < 0
                       or type(v[1]) is not bool for v in cases.values())):
            raise ValueError("Invalid, missing or duplicate case evidence")
        reference = plan
        combined.update(cases)
    if serial is not None:
        baseline = json.loads(serial.read_text())
        if (baseline["identity"] != expected_identity or baseline["shard"] != 0
                or baseline["smoke_complete"] is not True or baseline["plan"] != reference
                or baseline["cases"] != combined):
            raise ValueError("Serial and sharded per-case outcomes disagree")
    return len(combined), sum(v[0] for v in combined.values()), sum(v[1] for v in combined.values())


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("directory", type=Path)
    parser.add_argument("--serial", type=Path)
    args = parser.parse_args()
    started = time.time()
    counts = aggregate(args.directory, identity(Path.cwd()), args.serial)
    for path in sorted(args.directory.glob("shard-*.json")):
        report = json.loads(path.read_text())
        values = report["cases"].values()
        print(json.dumps({"shard": report["shard"], "classes": len(report["class_seconds"]),
                          "cases": len(values), "assertions": sum(v[0] for v in values),
                          "skips": sum(v[1] for v in values), "runner": report.get("runner"),
                          "stages": report.get("stages"), "total": report.get("total")}))
    print(f"Cases={counts[0]} assertions={counts[1]} skips={counts[2]} "
          f"aggregation_start={started:.3f} end={time.time():.3f} seconds={time.time()-started:.3f}")
