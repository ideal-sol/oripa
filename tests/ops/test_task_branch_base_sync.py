import importlib.util
from importlib.machinery import SourceFileLoader
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "infrastructure/github-app/task_branch_base_sync.py"
spec = importlib.util.spec_from_loader(
    "task_branch_base_sync", SourceFileLoader("task_branch_base_sync", str(SOURCE))
)
assert spec is not None and spec.loader is not None
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


TASK = "a" * 40
BASE = "b" * 40
CANDIDATE = "c" * 40
ALLOWED = ["worklogs/new_ver_main.md", "infrastructure/github-app/**"]


def candidate(**overrides):
    values = {
        "task_head": TASK,
        "base_sha": BASE,
        "candidate_sha": CANDIDATE,
        "parents": [TASK, BASE],
        "net_changed_paths": ["worklogs/new_ver_main.md"],
        "conflict_paths": ["worklogs/new_ver_main.md"],
        "automatic_merge_mismatches": [],
        "unresolved_conflict_paths": [],
        "resolved_blobs": {
            "worklogs/new_ver_main.md": ("candidate", "task", "base")
        },
        "allowed_paths": ALLOWED,
    }
    values.update(overrides)
    return gate.validate_candidate(**values)


class TaskBranchBaseSyncTest(unittest.TestCase):
    def test_valid_conflict_resolution_passes(self):
        result = candidate()
        self.assertTrue(result["passed"])
        self.assertEqual(result["task_head"], TASK)

    def test_stale_or_invalid_sha_rejects(self):
        with self.assertRaisesRegex(gate.TaskBranchBaseSyncError, "sync_sha_invalid"):
            candidate(base_sha="short")

    def test_parent_order_rejects(self):
        with self.assertRaisesRegex(gate.TaskBranchBaseSyncError, "sync_parent_mismatch"):
            candidate(parents=[BASE, TASK])

    def test_net_scope_rejects_base_side_loss(self):
        with self.assertRaisesRegex(gate.TaskBranchBaseSyncError, "sync_net_scope_rejected"):
            candidate(net_changed_paths=["apps/api/routes/api.php"])

    def test_automatic_merge_mismatch_rejects_base_side_loss(self):
        with self.assertRaisesRegex(gate.TaskBranchBaseSyncError, "sync_base_change_lost"):
            candidate(automatic_merge_mismatches=["infrastructure/github-app/check_run_gate.py"])

    def test_conflict_resolution_cannot_select_either_parent(self):
        with self.assertRaisesRegex(
            gate.TaskBranchBaseSyncError, "sync_conflict_resolution_rejected"
        ):
            candidate(
                resolved_blobs={
                    "worklogs/new_ver_main.md": ("base", "task", "base")
                }
            )

    def test_unresolved_conflict_rejects(self):
        with self.assertRaisesRegex(gate.TaskBranchBaseSyncError, "sync_unresolved_conflict"):
            candidate(unresolved_conflict_paths=["worklogs/new_ver_main.md"])

    def test_conflict_path_must_be_in_net_scope(self):
        with self.assertRaisesRegex(gate.TaskBranchBaseSyncError, "sync_conflict_scope_rejected"):
            candidate(conflict_paths=["infrastructure/github-app/check_run_gate.py"])

    def test_unresolved_markers_detected(self):
        self.assertTrue(gate.contains_unresolved_markers(b"x\n<<<<<<< task\ny"))
        self.assertTrue(gate.contains_unresolved_markers(b"x\n=======\ny"))
        self.assertTrue(gate.contains_unresolved_markers(b"x\n>>>>>>> base\ny"))
        self.assertFalse(gate.contains_unresolved_markers(b"resolved content"))


if __name__ == "__main__":
    unittest.main()
