import importlib.util
from importlib.machinery import SourceFileLoader
from pathlib import Path
from typing import Optional
import unittest


ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "infrastructure/github-app/check_run_gate.py"
spec = importlib.util.spec_from_loader(
    "check_run_gate", SourceFileLoader("check_run_gate", str(SOURCE))
)
assert spec is not None and spec.loader is not None
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


HEAD = "a" * 40
OTHER_HEAD = "b" * 40
REQUIRED = {"policy-gate", "ci-gate"}


def check(
    name: str,
    identifier: int,
    *,
    head_sha: str = HEAD,
    status: str = "completed",
    conclusion: Optional[str] = "success",
    source: Optional[dict] = None,
) -> dict:
    return {
        "id": identifier,
        "name": name,
        "head_sha": head_sha,
        "status": status,
        "conclusion": conclusion,
        "started_at": f"2026-08-17T08:{identifier:02d}:00Z",
        "completed_at": f"2026-08-17T08:{identifier:02d}:01Z",
        "app": source
        or {"id": 15368, "slug": "github-actions", "owner": {"login": "github"}},
    }


class CheckRunGateTest(unittest.TestCase):
    def evaluate(self, checks):
        return gate.evaluate_required_check_runs(
            checks, head_sha=HEAD, required_checks=REQUIRED
        )

    def test_newer_success_supersedes_old_failure_on_same_head(self):
        result = self.evaluate(
            [
                check("policy-gate", 1, conclusion="failure"),
                check("policy-gate", 2),
                check("ci-gate", 3),
            ]
        )
        self.assertTrue(result["passed"])

    def test_newer_failure_rejects_same_head(self):
        result = self.evaluate(
            [
                check("policy-gate", 1),
                check("policy-gate", 2, conclusion="failure"),
                check("ci-gate", 3),
            ]
        )
        self.assertFalse(result["passed"])
        self.assertIn("policy-gate:not_success", result["failures"])

    def test_missing_required_check_rejects(self):
        result = self.evaluate([check("policy-gate", 1)])
        self.assertFalse(result["passed"])
        self.assertIn("ci-gate:missing", result["failures"])

    def test_latest_pending_check_rejects(self):
        result = self.evaluate(
            [
                check("policy-gate", 1),
                check("ci-gate", 2),
                check("ci-gate", 3, status="in_progress", conclusion=None),
            ]
        )
        self.assertFalse(result["passed"])
        self.assertIn("ci-gate:pending", result["failures"])

    def test_wrong_head_success_rejects(self):
        result = self.evaluate(
            [check("policy-gate", 1), check("ci-gate", 2, head_sha=OTHER_HEAD)]
        )
        self.assertFalse(result["passed"])
        self.assertIn("ci-gate:stale_head", result["failures"])

    def test_expected_source_mismatch_rejects(self):
        result = self.evaluate(
            [
                check("policy-gate", 1),
                check("ci-gate", 2, source={"id": 1, "slug": "other", "owner": {"login": "other"}}),
            ]
        )
        self.assertFalse(result["passed"])
        self.assertIn("ci-gate:source_mismatch", result["failures"])

    def test_unrelated_duplicate_does_not_affect_required_result(self):
        result = self.evaluate(
            [
                check("policy-gate", 1),
                check("ci-gate", 2),
                check("unrelated-check", 3, conclusion="failure"),
            ]
        )
        self.assertTrue(result["passed"])

    def test_all_pages_are_read_and_truncation_rejects(self):
        calls = []

        def api_get(path):
            calls.append(path)
            return {"total_count": 101, "check_runs": [{}] * (100 if len(calls) == 1 else 1)}

        self.assertEqual(len(gate.list_required_check_runs(api_get, repository="ideal-sol/oripa", head_sha=HEAD)), 101)
        self.assertEqual(len(calls), 2)

        with self.assertRaisesRegex(gate.CheckRunGateError, "required_checks_response_truncated"):
            gate.list_required_check_runs(
                lambda path: {"total_count": 1001, "check_runs": [{}] * 100},
                repository="ideal-sol/oripa",
                head_sha=HEAD,
            )


if __name__ == "__main__":
    unittest.main()
