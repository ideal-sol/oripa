import importlib.util
import json
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[3]
SCRIPT = ROOT / "scripts/ci/policy_gate.py"
FIXTURES = Path(__file__).parent / "fixtures"
SPEC = importlib.util.spec_from_file_location("policy_gate", SCRIPT)
policy_gate = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(policy_gate)


def fixture(name):
    return json.loads((FIXTURES / name).read_text(encoding="utf-8"))


class PolicyGateTest(unittest.TestCase):
    def test_positive_pull_request_fixture_passes(self):
        data = fixture("positive.json")
        policy_gate.validate_pr_body(
            data["pr_body"],
            data["title"],
            data["changed_paths"],
            data["base_sha"],
        )
        policy_gate.validate_workflow_text("fixture.yml", data["workflow"])
        policy_gate.validate_dangerous_paths(data["tracked_paths"])

    def test_missing_metadata_fixture_fails(self):
        data = fixture("negative_missing_metadata.json")
        with self.assertRaisesRegex(policy_gate.PolicyFailure, "Risk"):
            policy_gate.validate_pr_body(
                data["pr_body"],
                data["title"],
                data["changed_paths"],
                data["base_sha"],
            )

    def test_floating_action_fixture_fails(self):
        data = fixture("negative_floating_action.json")
        with self.assertRaisesRegex(policy_gate.PolicyFailure, "full SHA"):
            policy_gate.validate_workflow_text("fixture.yml", data["workflow"])

    def test_secret_path_fixture_fails(self):
        data = fixture("negative_secret_path.json")
        with self.assertRaisesRegex(policy_gate.PolicyFailure, "dangerous tracked"):
            policy_gate.validate_dangerous_paths(data["tracked_paths"])

    def test_codeql_job_can_upload_security_events(self):
        workflow = """\
permissions:
  contents: read
concurrency:
  group: codeql
jobs:
  analyze:
    timeout-minutes: 30
    permissions:
      contents: read
      security-events: write
    steps:
      - uses: github/codeql-action/analyze@e4fba868fa4b1b91e1fdab776edc8cfbe6e9fb81 # v4
"""
        policy_gate.validate_workflow_text(".github/workflows/codeql.yml", workflow)

    def test_non_codeql_security_events_write_fails(self):
        workflow = """\
permissions:
  contents: read
concurrency:
  group: unsafe
jobs:
  analyze:
    timeout-minutes: 30
    permissions:
      contents: read
      security-events: write
    steps:
      - uses: actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683 # v4.2.2
"""
        with self.assertRaisesRegex(policy_gate.PolicyFailure, "write workflow permission"):
            policy_gate.validate_workflow_text(".github/workflows/unsafe.yml", workflow)


if __name__ == "__main__":
    unittest.main()
