import contextlib
import importlib.util
import inspect
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest import mock


ROOT = Path(__file__).resolve().parents[3]


def load(name, relative):
    spec = importlib.util.spec_from_file_location(name, ROOT / relative)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


quality_gate = load("quality_gate", "scripts/ci/quality_gate.py")
lint_baseline = load("lint_baseline", "scripts/ci/lint_baseline.py")


class QualityGateTest(unittest.TestCase):
    def report(self):
        return [{
            "filePath": "/workspace/legacy/v1-frontend/example.tsx",
            "messages": [{
                "line": 1, "column": 1, "endLine": 1, "endColumn": 2,
                "ruleId": "example/rule", "severity": 2, "message": "known finding",
            }],
            "errorCount": 1, "warningCount": 0, "fatalErrorCount": 0,
        }]

    def baseline(self):
        return {
            "schema_version": "1.1",
            "management": {
                "owner": "platform-maintainers", "reason": "fixture",
                "removal_condition": "fixture", "tracking_task": "FIXTURE-001",
            },
            "findings": lint_baseline.normalize_findings(self.report()),
        }

    def test_invalid_json_fails(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "bad.json").write_text("{", encoding="utf-8")
            with self.assertRaises(quality_gate.QualityFailure):
                quality_gate.validate_json(root, ["bad.json"])

    def test_new_lint_finding_fails_exact_baseline(self):
        report = self.report()
        report[0]["messages"].append({**report[0]["messages"][0], "message": "new finding"})
        report[0]["errorCount"] = 2
        with self.assertRaises(lint_baseline.BaselineFailure):
            lint_baseline.validate_baseline(report, self.baseline())

    def test_known_findings_without_expiry_or_clock_input_pass(self):
        self.assertEqual(list(inspect.signature(lint_baseline.validate_baseline).parameters), ["report", "baseline"])
        summary = lint_baseline.validate_baseline(self.report(), self.baseline())
        self.assertEqual(summary["current_findings"], 1)
        self.assertEqual(summary["known_findings"], 1)
        self.assertEqual(summary["new_findings"], 0)
        self.assertEqual(summary["resolved_baseline_entries"], 0)
        self.assertNotIn("expires_at", summary)

    def test_improvement_and_all_resolved_are_nonblocking(self):
        report = self.report()
        report[0]["messages"].append({**report[0]["messages"][0], "message": "second finding"})
        report[0]["errorCount"] = 2
        baseline = self.baseline()
        baseline["findings"] = lint_baseline.normalize_findings(report)
        for current in (1, 0):
            with self.subTest(current=current):
                report[0]["messages"] = report[0]["messages"][:current]
                report[0]["errorCount"] = current
                summary = lint_baseline.validate_baseline(report, baseline)
                self.assertEqual(summary["current_findings"], current)
                self.assertEqual(summary["resolved_baseline_entries"], 2 - current)
                self.assertEqual(summary["new_findings"], 0)

    def test_changed_rule_message_location_or_severity_fails(self):
        for key, value in (("ruleId", "new/rule"), ("message", "changed"), ("line", 2), ("severity", 2)):
            with self.subTest(key=key), self.assertRaisesRegex(lint_baseline.BaselineFailure, "baseline mismatch"):
                report = self.report()
                report[0]["messages"][0]["severity"] = 1
                report[0]["errorCount"] = 0
                report[0]["warningCount"] = 1
                baseline = self.baseline()
                baseline["findings"] = lint_baseline.normalize_findings(report)
                report[0]["messages"][0][key] = value
                if key == "severity":
                    report[0]["errorCount"] = 1
                    report[0]["warningCount"] = 0
                lint_baseline.validate_baseline(report, baseline)

    def test_duplicate_fingerprint_cannot_hide_new_occurrence(self):
        report = self.report()
        report[0]["messages"] *= 2
        report[0]["errorCount"] = 2
        with self.assertRaisesRegex(lint_baseline.BaselineFailure, "baseline mismatch"):
            lint_baseline.validate_baseline(report, self.baseline())

    def test_malformed_or_incomplete_report_fails_closed(self):
        cases = [None, {}, [], [{}], [{"filePath": "fixture", "messages": []}]]
        for key, value in (("messages", None), ("messages", [{}]), ("errorCount", 0), ("fatalErrorCount", 1)):
            report = self.report()
            report[0][key] = value
            cases.append(report)
        for report in cases:
            with self.subTest(report=report), self.assertRaises(lint_baseline.BaselineFailure):
                lint_baseline.validate_baseline(report, self.baseline())

    def test_invalid_baseline_schema_metadata_or_digest_fails_closed(self):
        for key, value in (("schema_version", "1.0"), ("management", None), ("findings", None), ("findings", [{}])):
            with self.subTest(key=key), self.assertRaises(lint_baseline.BaselineFailure):
                lint_baseline.validate_baseline(self.report(), {**self.baseline(), key: value})
        for key in self.baseline()["management"]:
            for value in (None, "", [], 123):
                with self.subTest(key=key, value=value), self.assertRaises(lint_baseline.BaselineFailure):
                    baseline = self.baseline()
                    baseline["management"][key] = value
                    lint_baseline.validate_baseline(self.report(), baseline)
        baseline = self.baseline()
        baseline["findings"][0]["fingerprint"] = "0" * 64
        with self.assertRaisesRegex(lint_baseline.BaselineFailure, "fingerprint is invalid"):
            lint_baseline.validate_baseline(self.report(), baseline)

    def test_exit_status_must_agree_with_valid_report(self):
        lint_baseline.validate_exit_status(1, self.report())
        clean = self.report()
        clean[0]["messages"] = []
        clean[0]["errorCount"] = 0
        lint_baseline.validate_exit_status(0, clean)
        for status, report in ((1, clean), (2, clean), (2, self.report()), (0, self.report())):
            with self.subTest(status=status), self.assertRaises(lint_baseline.BaselineFailure):
                lint_baseline.validate_exit_status(status, report)

    def test_workflow_supplies_lint_exit_status(self):
        workflow = (ROOT / ".github/workflows/platform-ci.yml").read_text()
        self.assertIn('pnpm --ignore-workspace exec eslint . --format json', workflow)
        self.assertIn('--exit-status "$eslint_status"', workflow)

    def test_malformed_json_and_unavailable_lint_fail_cli(self):
        with tempfile.TemporaryDirectory() as directory:
            report = Path(directory) / "report.json"
            arguments = [
                "lint_baseline.py", "--report", str(report), "--exit-status", "0",
                "--baseline", str(ROOT / ".ci/baselines/frontend-eslint.json"),
            ]
            for content in (None, "{", "{}"):
                with self.subTest(content=content):
                    if content is not None:
                        report.write_text(content, encoding="utf-8")
                    output = io.StringIO()
                    with mock.patch("sys.argv", arguments), contextlib.redirect_stderr(output):
                        self.assertEqual(lint_baseline.main(), 1)
                    self.assertIn("lint-baseline: FAIL", output.getvalue())

    def test_repository_baseline_retains_nine_approved_findings_without_expiry(self):
        baseline = json.loads((ROOT / ".ci/baselines/frontend-eslint.json").read_text())
        self.assertEqual(baseline["schema_version"], "1.1")
        self.assertNotIn("expires_at", baseline["management"])
        self.assertEqual(len(baseline["findings"]), 9)
        self.assertEqual(sum(item["severity"] == 2 for item in baseline["findings"]), 8)

    def test_lint_message_path_is_workspace_independent(self):
        message = (
            "Error: fixture\n"
            "/home/runner/work/oripa/oripa/legacy/v1-frontend/src/example.tsx:1:1\n"
            "detail"
        )
        local = message.replace(
            "/home/runner/work/oripa/oripa",
            "/var/www/oripa-worktrees/GOV-009",
        )
        self.assertEqual(
            lint_baseline.normalize_message(message),
            lint_baseline.normalize_message(local),
        )

    def test_backend_suite_runs_without_failure_baseline(self):
        workflow = (ROOT / ".github/workflows/platform-ci.yml").read_text(
            encoding="utf-8"
        )

        self.assertIn("working-directory: apps/api\n        run: php artisan test", workflow)
        self.assertNotIn("backend_test_baseline.py", workflow)
        self.assertFalse((ROOT / ".ci/baselines/backend-tests.json").exists())

if __name__ == "__main__":
    unittest.main()
