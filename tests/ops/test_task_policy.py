import importlib.util
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "infrastructure/github-app/task_policy.py"
SPEC = importlib.util.spec_from_file_location("task_policy", SCRIPT)
task_policy = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(task_policy)


class TaskPolicyTest(unittest.TestCase):
    def test_metadata_accepts_exact_lane_and_activation_values(self):
        for lane in task_policy.LANES:
            for activation in task_policy.ACTIVATION_MODES:
                policy = {"lane": lane, "activation": activation}
                self.assertIs(task_policy.validate_governance_metadata(policy), policy)

    def test_missing_or_invalid_metadata_fails_closed(self):
        for policy in ({}, {"lane": "Lite", "activation": "none"}, {"lane": "Strict Change", "activation": "later"}):
            with self.subTest(policy=policy):
                with self.assertRaises(task_policy.TaskPolicyFailure):
                    task_policy.validate_governance_metadata(policy)

    def test_codex_lane_downgrade_is_rejected(self):
        with self.assertRaises(task_policy.TaskPolicyFailure):
            task_policy.validate_lane_transition("Strict Change", "Standard Change")
        self.assertEqual(
            task_policy.validate_lane_transition("Lite Maintenance", "Strict Change"),
            "Strict Change",
        )
