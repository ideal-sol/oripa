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
TREE = "d" * 40


def candidate(**overrides):
    values = {
        "task_head": TASK,
        "base_sha": BASE,
        "candidate_sha": CANDIDATE,
        "parents": [TASK, BASE],
        "task_head_is_ancestor": True,
        "base_is_ancestor": True,
        "candidate_tree_sha": TREE,
        "canonical_tree_sha": TREE,
        "net_changed_paths": ["worklogs/new_ver_main.md"],
        "conflict_paths": [],
        "allowed_paths": ALLOWED,
    }
    values.update(overrides)
    return gate.validate_candidate(**values)


class TaskBranchBaseSyncTest(unittest.TestCase):
    def test_current_ref_locks_pass(self):
        self.assertIsNone(
            gate.validate_current_refs(
                task_head=TASK,
                base_sha=BASE,
                remote_task_head=TASK,
                remote_base_sha=BASE,
            )
        )

    def test_stale_task_head_rejects(self):
        with self.assertRaisesRegex(gate.TaskBranchBaseSyncError, "sync_task_head_changed"):
            gate.validate_current_refs(
                task_head=TASK,
                base_sha=BASE,
                remote_task_head="d" * 40,
                remote_base_sha=BASE,
            )

    def test_stale_base_rejects(self):
        with self.assertRaisesRegex(gate.TaskBranchBaseSyncError, "sync_base_changed"):
            gate.validate_current_refs(
                task_head=TASK,
                base_sha=BASE,
                remote_task_head=TASK,
                remote_base_sha="d" * 40,
            )

    def test_valid_clean_exact_scope_candidate_passes(self):
        result = candidate()
        self.assertTrue(result["passed"])
        self.assertEqual(result["task_head"], TASK)
        self.assertEqual(result["candidate_tree_sha"], TREE)
        self.assertEqual(result["canonical_tree_sha"], TREE)

    def test_stale_or_invalid_sha_rejects(self):
        with self.assertRaisesRegex(gate.TaskBranchBaseSyncError, "sync_sha_invalid"):
            candidate(base_sha="short")

    def test_parent_order_rejects(self):
        with self.assertRaisesRegex(gate.TaskBranchBaseSyncError, "sync_parent_mismatch"):
            candidate(parents=[BASE, TASK])

    def test_net_scope_rejects_task_change_outside_policy(self):
        with self.assertRaisesRegex(gate.TaskBranchBaseSyncError, "sync_net_scope_rejected"):
            candidate(net_changed_paths=["apps/api/routes/api.php"])

    def test_empty_task_scope_rejects_task_omission(self):
        with self.assertRaisesRegex(gate.TaskBranchBaseSyncError, "sync_net_scope_rejected"):
            candidate(net_changed_paths=[])

    def test_task_head_must_be_ancestor(self):
        with self.assertRaisesRegex(gate.TaskBranchBaseSyncError, "sync_task_change_lost"):
            candidate(task_head_is_ancestor=False)

    def test_base_must_be_ancestor(self):
        with self.assertRaisesRegex(gate.TaskBranchBaseSyncError, "sync_base_change_lost"):
            candidate(base_is_ancestor=False)

    def test_conflict_rejects_without_manual_resolution(self):
        with self.assertRaisesRegex(gate.TaskBranchBaseSyncError, "sync_conflict_required"):
            candidate(conflict_paths=["worklogs/new_ver_main.md"])

    def test_candidate_tree_must_equal_canonical_clean_merge_tree(self):
        with self.assertRaisesRegex(gate.TaskBranchBaseSyncError, "sync_tree_mismatch"):
            candidate(candidate_tree_sha="e" * 40)

    def test_duplicate_scope_path_rejects(self):
        with self.assertRaisesRegex(gate.TaskBranchBaseSyncError, "sync_net_scope_rejected"):
            candidate(
                net_changed_paths=[
                    "worklogs/new_ver_main.md",
                    "worklogs/new_ver_main.md",
                ]
            )

    def test_candidate_cannot_reuse_a_parent(self):
        with self.assertRaisesRegex(gate.TaskBranchBaseSyncError, "sync_candidate_invalid"):
            candidate(candidate_sha=TASK)


if __name__ == "__main__":
    unittest.main()
