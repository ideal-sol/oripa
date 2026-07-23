import datetime
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[3]


def load(name, relative):
    spec = importlib.util.spec_from_file_location(name, ROOT / relative)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


quality_gate = load("quality_gate", "scripts/ci/quality_gate.py")
lint_baseline = load("lint_baseline", "scripts/ci/lint_baseline.py")
backend_test_baseline = load(
    "backend_test_baseline", "scripts/ci/backend_test_baseline.py"
)


class QualityGateTest(unittest.TestCase):
    def test_invalid_json_fails(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "bad.json").write_text("{", encoding="utf-8")
            with self.assertRaises(quality_gate.QualityFailure):
                quality_gate.validate_json(root, ["bad.json"])

    def test_new_lint_finding_fails_exact_baseline(self):
        report = [
            {
                "filePath": "/workspace/frontend/example.tsx",
                "messages": [
                    {
                        "line": 1,
                        "column": 1,
                        "endLine": 1,
                        "endColumn": 2,
                        "ruleId": "example/rule",
                        "severity": 2,
                        "message": "new finding",
                    }
                ],
            }
        ]
        baseline = {
            "schema_version": "1.0",
            "management": {
                "owner": "platform-maintainers",
                "reason": "fixture",
                "removal_condition": "fixture",
                "expires_at": "2099-01-01",
                "tracking_task": "FIXTURE-001",
            },
            "findings": [],
        }
        with self.assertRaises(lint_baseline.BaselineFailure):
            lint_baseline.validate_baseline(
                report, baseline, datetime.date(2026, 7, 23)
            )

    def test_lint_message_path_is_workspace_independent(self):
        message = (
            "Error: fixture\n"
            "/home/runner/work/oripa/oripa/frontend/src/example.tsx:1:1\n"
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

    def test_new_backend_failure_fails_exact_baseline(self):
        report = backend_test_baseline.ET.fromstring(
            """
            <testsuites>
              <testsuite>
                <testcase class="Tests\\Feature\\ExampleTest" name="test_example">
                  <failure type="PHPUnit\\Framework\\ExpectationFailedException" />
                </testcase>
              </testsuite>
            </testsuites>
            """
        )
        baseline = {
            "schema_version": "1.0",
            "management": {
                "owner": "platform-maintainers",
                "reason": "fixture",
                "removal_condition": "fixture",
                "expires_at": "2099-01-01",
                "tracking_task": "FIXTURE-002",
            },
            "failures": [],
        }
        with self.assertRaises(backend_test_baseline.BaselineFailure):
            backend_test_baseline.validate_baseline(
                report, baseline, datetime.date(2026, 7, 23)
            )

    def test_known_backend_failures_match_exact_baseline(self):
        report = backend_test_baseline.ET.fromstring(
            r"""
            <testsuites>
              <testsuite>
                <testcase class="Tests\Feature\AdminPaymentApiTest"
                  name="test_admin_can_mark_chargeback_and_user_is_suspended">
                  <failure type="PHPUnit\Framework\ExpectationFailedException" />
                </testcase>
                <testcase class="Tests\Feature\AdminPaymentApiTest"
                  name="test_admin_can_mark_succeeded_payment_as_refunded_and_audit_log_is_recorded">
                  <failure type="PHPUnit\Framework\ExpectationFailedException" />
                </testcase>
              </testsuite>
            </testsuites>
            """
        )
        baseline = json.loads(
            (ROOT / ".ci/baselines/backend-tests.json").read_text(encoding="utf-8")
        )
        summary = backend_test_baseline.validate_baseline(
            report, baseline, datetime.date(2026, 7, 23)
        )
        self.assertEqual(summary["failures"], 2)


if __name__ == "__main__":
    unittest.main()
