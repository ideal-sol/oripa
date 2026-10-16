import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock

from scripts.db import phpunit_timing as timing
from scripts.db import v2_database as database


CLASS = "Tests\\V2\\ExampleTest"
ALLOWED = {CLASS: {"test_first", "test_second"}}
EXPECTED = {"tests": 2, "assertions": 3, "skipped": 1}
JUNIT = b'''<testsuites><testsuite tests="2" assertions="3" skipped="1" errors="0" failures="0">
<testsuite name="untrusted metadata"><testcase class="Tests\\V2\\ExampleTest" name="test_first with data set &quot;private@example.test&quot;" time="2.5" assertions="3" file="private-path">
<system-out>private output</system-out><properties><property name="private" value="private value" /></properties></testcase>
<testcase class="Tests\\V2\\ExampleTest" name="test_second" time="0.0" assertions="0"><skipped /></testcase>
</testsuite></testsuite></testsuites>'''


def event(timestamp, name, identifier):
    return f"[00:00:{timestamp:012.9f} / 00:00:00.000000000] [100 bytes] Test {name} ({identifier})\n"


def events():
    first = CLASS + '::test_first#private@example.test'
    second = CLASS + "::test_second"
    return (
        event(1.0, "Preparation Started", first)
        + event(1.5, "Prepared", first)
        + event(3.5, "Finished", first)
        + event(4.0, "Preparation Started", second)
        + event(4.0, "Finished", second)
    ).encode()


class PHPUnitTimingTest(unittest.TestCase):
    def test_safe_counts_rankings_and_preparation_without_raw_metadata(self):
        report = timing.summarize_phpunit_timing(JUNIT, events(), ALLOWED, EXPECTED)
        self.assertEqual(report["counts"], {**EXPECTED, "errors": 0, "failures": 0})
        self.assertEqual(report["junit_test_seconds"], 2.5)
        self.assertEqual(report["preparation_seconds"], 0.5)
        self.assertEqual(report["prepared_tests"], 1)
        self.assertEqual(report["top_tests"][0]["method"], "test_first")
        self.assertEqual(report["top_classes"][0]["duration_seconds"], 2.5)
        self.assertEqual(report["top_classes"][0]["preparation_seconds"], 0.5)
        self.assertNotIn("private", json.dumps(report))
        self.assertNotIn("untrusted", json.dumps(report))
        for key in ("teardown_only_seconds", "database_initialization_only_seconds", "password_hashing_only_seconds"):
            self.assertIsNone(report[key])

    def test_unknown_identifiers_fail_closed_without_echoing_them(self):
        with self.assertRaisesRegex(timing.TimingFailure, "^Invalid or incomplete Full V2 timing evidence$"):
            timing.summarize_phpunit_timing(JUNIT.replace(b"test_first", b"private_identifier"), events(), ALLOWED, EXPECTED)

    def test_prepared_test_that_later_skips_remains_in_preparation_count(self):
        marker = event(4.0, "Finished", CLASS + "::test_second").encode()
        raw = events().replace(marker, event(4.0, "Prepared", CLASS + "::test_second").encode() + marker)
        report = timing.summarize_phpunit_timing(JUNIT, raw, ALLOWED, EXPECTED)
        self.assertEqual(report["prepared_tests"], 2)
        self.assertEqual(report["counts"]["skipped"], 1)

    def test_malformed_nonfinite_negative_and_entity_xml_rejected(self):
        for junit in (
            b"<not-xml", b'<!DOCTYPE testsuites [<!ENTITY x "private">]>' + JUNIT,
            JUNIT.replace(b'time="2.5"', b'time="NaN"'),
            JUNIT.replace(b'time="2.5"', b'time="-1"'),
            JUNIT.replace(b'time="2.5"', b'time="Infinity"'),
            JUNIT.replace(b'assertions="3"', b'assertions="-1"'),
        ):
            with self.subTest(junit_type=junit[:10]), self.assertRaises(timing.TimingFailure):
                timing.summarize_phpunit_timing(junit, events(), ALLOWED, EXPECTED)

    def test_outcome_or_count_mismatch_cannot_become_a_timing_pass(self):
        for junit, expected in (
            (JUNIT, {**EXPECTED, "tests": 3}),
            (JUNIT, {**EXPECTED, "assertions": 4}),
            (JUNIT.replace(b'failures="0"', b'failures="1"'), EXPECTED),
            (JUNIT.replace(b"<system-out>", b"<failure>"), EXPECTED),
            (JUNIT.replace(b'time="0.0" assertions="0"', b'time="0.0" assertions="1"'), EXPECTED),
        ):
            with self.subTest(expected=expected), self.assertRaises(timing.TimingFailure):
                timing.summarize_phpunit_timing(junit, events(), ALLOWED, expected)

    def test_missing_duplicate_reversed_or_unfinished_event_sequence_rejected(self):
        for raw in (
            b"", events().splitlines(keepends=True)[0],
            events().replace(b"00:00:01.500000000", b"00:00:00.500000000"),
            events().replace(b"Test Prepared", b"Test Preparation Started"),
            events().replace(b"Test Prepared", b"unrecognized event"),
            events().replace(b"test_second)", b"private_method)"),
            events().replace(event(4.0, "Finished", CLASS + "::test_second").encode(), b""),
        ):
            with self.subTest(length=len(raw)), self.assertRaises(timing.TimingFailure):
                timing.summarize_phpunit_timing(JUNIT, raw, ALLOWED, EXPECTED)

    def test_report_size_limits_are_enforced(self):
        with mock.patch.object(timing, "MAX_REPORT_BYTES", 10):
            with self.assertRaises(timing.TimingFailure):
                timing.summarize_phpunit_timing(JUNIT, events(), ALLOWED, EXPECTED)
            with self.assertRaises(timing.TimingFailure):
                timing.event_preparation(events(), ALLOWED)

    def test_rankings_are_bounded_and_dataset_names_never_escape(self):
        records = []
        trace = []
        names = {}
        for ordinal in range(30):
            class_name = f"Tests\\V2\\Example{ordinal}Test"
            names[class_name] = {"test_case"}
            duration = ordinal + 1
            records.append(f'<testcase class="{class_name}" name="test_case with data set &quot;private&quot;" assertions="1" time="{duration}"/>')
            trace.extend([
                event(ordinal * 100, "Preparation Started", class_name + "::test_case#private"),
                event(ordinal * 100 + 0.1, "Prepared", class_name + "::test_case#private"),
                event(ordinal * 100 + duration, "Finished", class_name + "::test_case#private"),
            ])
        raw = ('<testsuites><testsuite tests="30" assertions="30" skipped="0" errors="0" failures="0">' + ''.join(records) + '</testsuite></testsuites>').encode()
        report = timing.summarize_phpunit_timing(raw, ''.join(trace).encode(), names, {"tests": 30, "assertions": 30})
        self.assertEqual(len(report["top_tests"]), 20)
        self.assertEqual(len(report["top_classes"]), 10)
        self.assertEqual(report["top_tests"][0]["duration_seconds"], 30)
        self.assertNotIn("private", json.dumps(report))

    def test_source_allowlist_contains_current_v2_tests_only(self):
        names = timing.source_test_names(Path(__file__).resolve().parents[2])
        self.assertIn("Tests\\V2\\PreRegistrationTest", names)
        self.assertIn("test_registration_is_dedicated_disabled_by_default_and_rejects_forgery", names["Tests\\V2\\PreRegistrationTest"])
        self.assertTrue(all(name.startswith("Tests\\V2\\") for name in names))

    def test_logging_only_appends_stock_options_to_unchanged_full_suite(self):
        with mock.patch.object(database, "run") as command:
            database.run_identity_tests([], Path.cwd(), False, timing_directory="/tmp/private")
        self.assertEqual(command.call_args.args[0], [
            "exec", "-T", "api", "php", "-d", "memory_limit=512M", "vendor/bin/phpunit",
            "--configuration", "phpunit.v2.xml", "--log-junit", "/tmp/private/junit.xml",
            "--log-events-verbose-text", "/tmp/private/events.txt",
        ])

    def test_raw_read_failure_never_includes_output_in_guard_error(self):
        failure = subprocess.CalledProcessError(1, ["docker"], output=b"failure private data\n", stderr=b"exception private data")
        with mock.patch.object(database.subprocess, "run", side_effect=failure):
            with self.assertRaises(database.GuardFailure) as raised:
                database.run(["docker", "compose", "exec", "api", "php"], cwd=Path.cwd(), safe_error_only=True)
        self.assertNotIn("private", str(raised.exception))

    def diagnostic(self, failure=None, aggregation_failure=False, read_failure=False):
        with tempfile.TemporaryDirectory() as directory:
            metrics = mock.Mock(metadata={"source_sha": "a" * 40}, tests=[{"counts": EXPECTED}])
            commands = []

            def command(arguments, **keywords):
                commands.append((arguments, keywords))
                if "-r" in arguments:
                    if read_failure:
                        raise database.GuardFailure("injected read failure")
                    return JUNIT if arguments[-1].endswith("junit.xml") else events()
                return b""

            with mock.patch.object(database, "run", side_effect=command), mock.patch.object(database, "run_identity_tests", side_effect=failure) as identity, mock.patch.object(database, "source_test_names", return_value=ALLOWED):
                if failure or aggregation_failure or read_failure:
                    with self.assertRaises(type(failure) if failure else database.GuardFailure):
                        database.run_identity_diagnostics([], Path(directory), metrics, Path(directory), [])
                    self.assertFalse((Path(directory) / "full-v2-timing.json").exists())
                else:
                    database.run_identity_diagnostics([], Path(directory), metrics, Path(directory), [])
                    self.assertNotIn("private", (Path(directory) / "full-v2-timing.json").read_text())
                identity.assert_called_once()
                self.assertEqual(identity.call_args.kwargs["php_options"], [])
            self.assertIn("700", commands[0][0])
            self.assertIn("rm", commands[-2][0])
            self.assertIn("rmdir", commands[-1][0])
            for arguments, keywords in commands:
                if "-r" in arguments:
                    self.assertTrue(keywords["safe_error_only"])

    def test_success_exports_aggregate_only_then_removes_private_reports(self):
        self.diagnostic()

    def test_failed_test_is_not_retried_and_private_reports_are_removed(self):
        self.diagnostic(database.GuardFailure("injected"))

    def test_interrupt_propagates_after_private_report_cleanup(self):
        self.diagnostic(KeyboardInterrupt())

    def test_aggregation_failure_still_removes_private_reports(self):
        with mock.patch.object(database, "summarize_phpunit_timing", side_effect=timing.TimingFailure()):
            self.diagnostic(aggregation_failure=True)

    def test_read_failure_still_removes_private_reports(self):
        self.diagnostic(read_failure=True)

    def test_workflow_disables_bytecode_and_uploads_no_raw_logs(self):
        workflow = (Path(__file__).resolve().parents[2] / ".github/workflows/platform-ci.yml").read_text()
        self.assertNotIn("--identity-bytecode-cache", workflow)
        self.assertIn("--identity-timing", workflow)
        self.assertIn("/full-v2-timing.json", workflow)
        self.assertNotIn("/junit.xml", workflow)
        self.assertNotIn("/events.txt", workflow)


if __name__ == "__main__":
    unittest.main()
