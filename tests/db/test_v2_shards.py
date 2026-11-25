import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from xml.etree import ElementTree as ET

from scripts.db.v2_shards import aggregate, failure_summary, fingerprint, partition, results


def listing(names):
    suite = ET.Element("testSuite", xmlns="https://xml.phpunit.de/testSuite")
    tests = ET.SubElement(suite, "tests")
    for cls in names:
        group = ET.SubElement(tests, "testClass", name=cls, file=cls + ".php")
        ET.SubElement(group, "testMethod", name="test", id=cls + "::test")
    return ET.tostring(suite)


class PartitionTest(unittest.TestCase):
    def test_weighted_allocation_and_transitive_dependencies(self):
        names = ["A", "B", "C", "D", "E", "F", "New"]
        weights = {"classes": {"A": 20, "B": 10}, "default": 1}
        deps = {"C": ["D"], "D": ["E"]}
        buckets, _ = partition(listing(names), deps, weights)
        self.assertEqual(buckets, partition(listing(names), deps, weights)[0])
        self.assertEqual([cls for group in buckets for cls in group], names)
        self.assertEqual(buckets[0], ["A"])
        self.assertTrue(any(set("CDE") <= set(group) for group in buckets))

    def test_reporting_keeps_the_db_cleanup_that_precedes_it_in_the_serial_suite(self):
        cleanup = r"Tests\V2\PrizeShippingConcurrencyTest"
        reporting = r"Tests\V2\ReportingExportVerticalSliceTest"
        shipping = r"Tests\V2\ShippingMinimumPointSettingsTest"
        names = ["A", "B", cleanup, "Q", reporting, "S", shipping, "Z"]
        buckets, _ = partition(listing(names), {}, {"default": 1, "classes": {}})
        self.assertIn([cleanup, "Q", reporting, "S", shipping], buckets)

    def test_empty_shard_and_duplicate_discovery_rejected(self):
        for names in ("ABC", "ABCDA"):
            with self.assertRaises(ValueError):
                partition(listing(names), {}, {"default": 1, "classes": {}})

    def test_junit_provider_inheritance_and_setup_skip(self):
        xml = b'''<testsuites><testsuite tests="3" assertions="3" skipped="1" failures="0" errors="0">
          <testcase class="Child" name='test with data set "label"' assertions="2" time="1"/>
          <testcase class="Child" name="test with data set #0" assertions="0" time="0"><skipped/></testcase>
          <testcase class="Parent" name="test with data set #0" assertions="1" time="0"/>
          </testsuite></testsuites>'''
        actual, _ = results(xml)
        self.assertEqual(actual, {fingerprint("Child::test#label"): [2, False],
                                  fingerprint("Child::test#0"): [0, True],
                                  fingerprint("Parent::test#0"): [1, False]})
        with self.assertRaises(ValueError):
            results(xml.replace(b'assertions="3"', b'assertions="2"'))

    def test_failure_summary_contains_only_discovered_symbols_and_source_locations(self):
        xml = b'''<testsuites><testcase class="T" name='test with data set "private label"'>
          <failure>Failed asserting fixture payload /code/tests/V2/BannerImportTest.php:45</failure>
          </testcase></testsuites>'''
        summary = failure_summary(xml, {"T": ["T::test#private label"]}, Path(__file__).resolve().parents[2])
        self.assertEqual(summary, ["T::test failure Failed asserting tests/V2/BannerImportTest.php:45"])

    def test_workflow_propagates_matrix_outcomes_and_planner_skip(self):
        workflow = (Path(__file__).resolve().parents[2] / ".github/workflows/platform-ci.yml").read_text()
        shard = workflow.split("\n  database-shard:\n", 1)[1].split("\n  integration-database:", 1)[0]
        aggregate_job = workflow.split("\n  integration-database:\n", 1)[1].split("\n  #", 1)[0]
        planned = "fromJSON(needs.policy-gate.outputs.plan_jobs).integration-database"
        self.assertIn("if: " + planned, shard)
        self.assertIn("fail-fast: false", shard)
        self.assertIn("shard: [1, 2, 3, 4]", shard)
        self.assertIn("if: always() && needs.policy-gate.result == 'success' && " + planned, aggregate_job)
        command = next(line.split("run: ", 1)[1] for line in aggregate_job.splitlines() if 'run: test ' in line)
        for result in ("success", "cancelled", "failure", "skipped", ""):
            status = subprocess.run(["sh", "-c", command], env={"SHARD_RESULT": result}).returncode
            self.assertEqual(status == 0, result == "success")

    def test_required_evidence_and_serial_comparison(self):
        plan = [[fingerprint(cls)] for cls in "ABCD"]
        identity = ["sha", "run", "attempt"]
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)
            for shard in range(1, 5):
                report = dict(plan=plan, shard=shard, smoke_complete=True, identity=identity,
                              cases={plan[shard - 1][0]: [1, False]})
                last = path / f"shard-{shard}.json"
                last.write_text(json.dumps(report))
            serial = path / "serial.json"
            baseline = {**report, "shard": 0, "cases": {ids[0]: [1, False] for ids in plan}}
            serial.write_text(json.dumps(baseline))
            self.assertEqual(aggregate(path, identity, serial), (4, 4, 0))
            baseline["cases"][plan[0][0]] = [0, True]
            serial.write_text(json.dumps(baseline))
            with self.assertRaises(ValueError):
                aggregate(path, identity, serial)
            for changes in ({"cases": {}}, {"cases": {plan[0][0]: [1, False]}}, {"shard": 2},
                            {"identity": ["other", "run", "attempt"]}, {"identity": ["sha", "run", "other"]},
                            {"identity": ["sha", "other", "attempt"]}, {"smoke_complete": False}):
                last.write_text(json.dumps({**report, **changes}))
                with self.assertRaises(ValueError):
                    aggregate(path, identity)
            last.unlink()
            with self.assertRaises(FileNotFoundError):
                aggregate(path, identity)
