from contextlib import contextmanager
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import runpy
import stat
import subprocess
import sys
import tempfile
import unittest
from unittest import mock


ROOT = Path(__file__).resolve().parents[2]
WRAPPER_SOURCE = ROOT / "infrastructure/github-app/oripa-github-app-git"
SYNC_HELPER = ROOT / "infrastructure/github-app/task_branch_base_sync.py"
TASK_POLICY = ROOT / "infrastructure/github-app/task_policy.py"
MANIFEST = ROOT / "infrastructure/github-app/oripa-github-app-git.manifest.json"
PROVISION = ROOT / "infrastructure/github-app/provision_git_wrapper.py"
IMPORTED_SHA256 = "81a5cba7ff3f8dc3ba7eb4decce2aec74fc83b11996e3c43fa7b6cfb0851114f"

OPS_027_PATHS = [
    ".github/workflows/platform-ci.yml",
    ".github/workflows/platform-production-arm64-artifact.yml",
    "apps/admin/Dockerfile",
    "apps/admin/README.md",
    "apps/admin/src/app/api/health/route.ts",
    "apps/admin/test/admin-announcement-management.test.tsx",
    "apps/admin/test/admin-health-route.test.ts",
    "deployments/OPS-027-platform-production-arm64-runtime.json",
    "docs/operations/deployment/README.md",
    "docs/operations/deployment/platform-production-arm64-runtime.md",
    "docs/operations/deployment/preview-image-build.md",
    "infra/docker/backend/Dockerfile",
    "infra/docker/backend/apache-production.conf",
    "scripts/ci/policy_gate.py",
    "scripts/ops/preview_image_artifact.py",
    "tests/ops/test_preview_image_pipeline.py",
    "worklogs/new_ver_main.md",
]
MIG_100_PATHS = [
    "apps/admin/e2e/admin-announcement-management.spec.ts",
    "apps/admin/test/admin-announcement-management.test.tsx",
    "worklogs/new_ver_main.md",
]


def load_wrapper() -> dict:
    original_run_path = runpy.run_path

    def repository_run_path(path, *arguments, **keywords):
        name = Path(path).name
        if name == SYNC_HELPER.name:
            return original_run_path(str(SYNC_HELPER))
        if name == TASK_POLICY.name:
            return original_run_path(str(TASK_POLICY))
        return original_run_path(path, *arguments, **keywords)

    with mock.patch.object(runpy, "run_path", side_effect=repository_run_path):
        namespace = original_run_path(
            str(WRAPPER_SOURCE), run_name="oripa_git_wrapper_test"
        )
    return namespace["main"].__globals__


def load_provision():
    spec = importlib.util.spec_from_file_location("provision_git_wrapper", PROVISION)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


class TemporaryGitRepository:
    def __init__(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.path = Path(self.temporary.name)
        self.git("init", "-q", "-b", "main")
        self.git("config", "user.name", "Oripa Test")
        self.git("config", "user.email", "oripa-test@example.invalid")

    def close(self):
        self.temporary.cleanup()

    def git(self, *arguments, input_text=None, check=True):
        result = subprocess.run(
            ["git", "-C", str(self.path), *arguments],
            input=input_text,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
            text=True,
        )
        if check and result.returncode != 0:
            raise AssertionError(result.stderr)
        return result

    def write(self, path, content):
        destination = self.path / path
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_text(content, encoding="utf-8")

    def commit(self, message):
        self.git("add", "-A")
        self.git("commit", "-q", "-m", message)
        return self.git("rev-parse", "HEAD").stdout.strip()

    def tree(self, commit):
        return self.git("show", "-s", "--format=%T", commit).stdout.strip()

    def commit_tree(self, tree, parents):
        arguments = ["commit-tree", tree]
        for parent in parents:
            arguments.extend(["-p", parent])
        return self.git(*arguments, input_text="sync candidate\n").stdout.strip()

    def merge_tree(self, task_head, base_sha, *, check=True):
        result = self.git(
            "merge-tree",
            "--write-tree",
            task_head,
            base_sha,
            check=check,
        )
        return result.stdout.splitlines()[0], result

    def clean_graph(self, task_paths, main_paths):
        overlap = set(task_paths) & set(main_paths)
        for path in sorted(set(task_paths) | set(main_paths)):
            if path in overlap:
                self.write(path, "".join(f"base {index}\n" for index in range(20)))
            else:
                self.write(path, "base\n")
        self.write("shared.txt", "base\n")
        base = self.commit("base")

        self.git("switch", "-q", "-c", "task")
        for path in task_paths:
            if path in overlap:
                lines = (self.path / path).read_text(encoding="utf-8").splitlines()
                lines[2] = "task change"
                self.write(path, "\n".join(lines) + "\n")
            else:
                self.write(path, "task\n")
        task_head = self.commit("task")

        self.git("switch", "-q", "main")
        for path in main_paths:
            if path in overlap:
                lines = (self.path / path).read_text(encoding="utf-8").splitlines()
                lines[16] = "main change"
                self.write(path, "\n".join(lines) + "\n")
            else:
                self.write(path, "main\n")
        main_head = self.commit("main")

        canonical_tree, _ = self.merge_tree(task_head, main_head)
        candidate = self.commit_tree(canonical_tree, [task_head, main_head])
        return {
            "base": base,
            "task": task_head,
            "main": main_head,
            "canonical_tree": canonical_tree,
            "candidate": candidate,
        }


class GitWrapperCleanSyncTest(unittest.TestCase):
    def setUp(self):
        self.wrapper = load_wrapper()
        self.repository = TemporaryGitRepository()
        self.wrapper["REPOSITORY_ROOT"] = str(self.repository.path)

    def tearDown(self):
        self.repository.close()

    def validate(self, graph, allowed_paths):
        return self.wrapper["validate_sync_candidate"](
            {"allowed_paths": allowed_paths},
            graph["task"],
            graph["main"],
            graph["candidate"],
        )

    def test_clean_sync_uses_diff_from_latest_main_for_scope(self):
        task_path = "infrastructure/github-app/task_branch_base_sync.py"
        main_path = "apps/admin/e2e/unrelated-main.spec.ts"
        graph = self.repository.clean_graph([task_path], [main_path])

        result = self.validate(graph, [task_path])

        self.assertTrue(result["passed"])
        self.assertEqual(result["net_changed_paths"], [task_path])
        self.assertNotIn(main_path, result["net_changed_paths"])
        self.assertEqual(result["candidate_tree_sha"], result["canonical_tree_sha"])

    def test_multiple_main_only_paths_do_not_expand_task_policy(self):
        task_paths = [
            "infrastructure/github-app/oripa-github-app-git",
            "tests/ops/test_github_app_git_wrapper.py",
        ]
        main_paths = [
            "apps/admin/e2e/one.spec.ts",
            "apps/admin/e2e/two.spec.ts",
            "docs/operations/releases/main-only.md",
        ]
        graph = self.repository.clean_graph(task_paths, main_paths)

        result = self.validate(graph, task_paths)

        self.assertEqual(result["net_changed_paths"], sorted(task_paths))

    def test_ops_027_stage_3b_and_mig_100_structure_is_accepted(self):
        graph = self.repository.clean_graph(OPS_027_PATHS, MIG_100_PATHS)

        result = self.validate(graph, OPS_027_PATHS)

        self.assertEqual(len(result["net_changed_paths"]), 17)
        self.assertEqual(result["net_changed_paths"], sorted(OPS_027_PATHS))
        self.assertNotIn(MIG_100_PATHS[0], result["net_changed_paths"])
        self.assertEqual(
            self.repository.git(
                "merge-base", "--is-ancestor", graph["task"], graph["candidate"]
            ).returncode,
            0,
        )
        self.assertEqual(
            self.repository.git(
                "merge-base", "--is-ancestor", graph["main"], graph["candidate"]
            ).returncode,
            0,
        )

    def test_task_change_outside_allowed_paths_rejects(self):
        allowed = "infrastructure/github-app/task_branch_base_sync.py"
        unexpected = "apps/api/routes/api.php"
        graph = self.repository.clean_graph(
            [allowed, unexpected], ["apps/admin/e2e/main-only.spec.ts"]
        )

        with self.assertRaisesRegex(
            self.wrapper["GitWrapperError"], "sync_net_scope_rejected"
        ):
            self.validate(graph, [allowed])

    def test_task_rename_requires_both_old_and_new_paths(self):
        old_path = "outside/task-source.txt"
        new_path = "infrastructure/github-app/task-source.txt"
        self.repository.write(old_path, "task source\n")
        self.repository.write("main.txt", "base\n")
        self.repository.commit("base")
        self.repository.git("switch", "-q", "-c", "task")
        (self.repository.path / new_path).parent.mkdir(parents=True, exist_ok=True)
        (self.repository.path / old_path).rename(self.repository.path / new_path)
        task_head = self.repository.commit("task rename")
        self.repository.git("switch", "-q", "main")
        self.repository.write("main.txt", "main\n")
        main_head = self.repository.commit("main")
        canonical_tree, _ = self.repository.merge_tree(task_head, main_head)
        candidate = self.repository.commit_tree(
            canonical_tree, [task_head, main_head]
        )

        with self.assertRaisesRegex(
            self.wrapper["GitWrapperError"], "sync_net_scope_rejected"
        ):
            self.wrapper["validate_sync_candidate"](
                {"allowed_paths": [new_path]}, task_head, main_head, candidate
            )

    def test_conflicting_merge_rejects(self):
        path = "worklogs/new_ver_main.md"
        self.repository.write(path, "base\n")
        self.repository.commit("base")
        self.repository.git("switch", "-q", "-c", "task")
        self.repository.write(path, "task\n")
        task_head = self.repository.commit("task")
        self.repository.git("switch", "-q", "main")
        self.repository.write(path, "main\n")
        main_head = self.repository.commit("main")
        _, merge_result = self.repository.merge_tree(task_head, main_head, check=False)
        self.assertEqual(merge_result.returncode, 1)
        candidate = self.repository.commit_tree(
            self.repository.tree(task_head), [task_head, main_head]
        )

        with self.assertRaisesRegex(
            self.wrapper["GitWrapperError"], "sync_conflict_required"
        ):
            self.wrapper["validate_sync_candidate"](
                {"allowed_paths": [path]}, task_head, main_head, candidate
            )

    def test_candidate_tree_missing_main_change_rejects(self):
        graph = self.repository.clean_graph(["task.txt"], ["main.txt"])
        graph["candidate"] = self.repository.commit_tree(
            self.repository.tree(graph["task"]), [graph["task"], graph["main"]]
        )

        with self.assertRaisesRegex(self.wrapper["GitWrapperError"], "sync_tree_mismatch"):
            self.validate(graph, ["task.txt"])

    def test_candidate_tree_missing_task_change_rejects(self):
        graph = self.repository.clean_graph(["task.txt"], ["main.txt"])
        graph["candidate"] = self.repository.commit_tree(
            self.repository.tree(graph["main"]), [graph["task"], graph["main"]]
        )

        with self.assertRaisesRegex(self.wrapper["GitWrapperError"], "sync_tree_mismatch"):
            self.validate(graph, ["task.txt"])

    def test_forged_parent_graph_rejects(self):
        graph = self.repository.clean_graph(["task.txt"], ["main.txt"])
        graph["candidate"] = self.repository.commit_tree(
            graph["canonical_tree"], [graph["main"], graph["task"]]
        )

        with self.assertRaisesRegex(
            self.wrapper["GitWrapperError"], "sync_parent_mismatch"
        ):
            self.validate(graph, ["task.txt"])

    def test_non_fast_forward_rejects_before_push(self):
        policy = {
            "branch": "fix/GOV-029-github-app-clean-base-sync",
            "base_sha": "d" * 40,
        }
        task_head = "a" * 40
        base_sha = "b" * 40
        candidate = "c" * 40
        self.wrapper["validate_policy_branch"] = mock.Mock()
        self.wrapper["validate_commit_sha"] = mock.Mock()
        self.wrapper["public_remote_branch_sha"] = mock.Mock(return_value=task_head)
        self.wrapper["public_main_sha"] = mock.Mock(return_value=base_sha)
        self.wrapper["is_ancestor"] = mock.Mock(side_effect=[True, True, False])
        self.wrapper["run_authenticated_git"] = mock.Mock()

        with self.assertRaisesRegex(
            self.wrapper["GitWrapperError"], "sync_non_fast_forward_rejected"
        ):
            self.wrapper["sync_task_branch_base"](
                policy, [task_head, base_sha, candidate]
            )

        self.wrapper["run_authenticated_git"].assert_not_called()

    def test_main_move_before_push_rejects_without_mutation(self):
        policy = {
            "branch": "fix/GOV-029-github-app-clean-base-sync",
            "base_sha": "d" * 40,
        }
        task_head = "a" * 40
        base_sha = "b" * 40
        candidate = "c" * 40
        self.wrapper["validate_policy_branch"] = mock.Mock()
        self.wrapper["validate_commit_sha"] = mock.Mock()
        self.wrapper["public_remote_branch_sha"] = mock.Mock(
            side_effect=[task_head, task_head]
        )
        self.wrapper["public_main_sha"] = mock.Mock(
            side_effect=[base_sha, "e" * 40]
        )
        self.wrapper["is_ancestor"] = mock.Mock(return_value=True)
        self.wrapper["validate_sync_candidate"] = mock.Mock(return_value={})
        self.wrapper["run_authenticated_git"] = mock.Mock()

        with self.assertRaisesRegex(self.wrapper["GitWrapperError"], "sync_base_changed"):
            self.wrapper["sync_task_branch_base"](
                policy, [task_head, base_sha, candidate]
            )

        self.wrapper["run_authenticated_git"].assert_not_called()

    def test_task_head_move_before_push_rejects_without_mutation(self):
        policy = {
            "branch": "fix/GOV-029-github-app-clean-base-sync",
            "base_sha": "d" * 40,
        }
        task_head = "a" * 40
        base_sha = "b" * 40
        candidate = "c" * 40
        self.wrapper["validate_policy_branch"] = mock.Mock()
        self.wrapper["validate_commit_sha"] = mock.Mock()
        self.wrapper["public_remote_branch_sha"] = mock.Mock(
            side_effect=[task_head, "e" * 40]
        )
        self.wrapper["public_main_sha"] = mock.Mock(return_value=base_sha)
        self.wrapper["is_ancestor"] = mock.Mock(return_value=True)
        self.wrapper["validate_sync_candidate"] = mock.Mock(return_value={})
        self.wrapper["run_authenticated_git"] = mock.Mock()

        with self.assertRaisesRegex(
            self.wrapper["GitWrapperError"], "sync_task_head_changed"
        ):
            self.wrapper["sync_task_branch_base"](
                policy, [task_head, base_sha, candidate]
            )

        self.wrapper["run_authenticated_git"].assert_not_called()

    def test_main_move_during_push_never_reports_sync_success(self):
        policy = {
            "branch": "fix/GOV-029-github-app-clean-base-sync",
            "base_sha": "d" * 40,
        }
        task_head = "a" * 40
        base_sha = "b" * 40
        candidate = "c" * 40
        self.wrapper["validate_policy_branch"] = mock.Mock()
        self.wrapper["validate_commit_sha"] = mock.Mock()
        self.wrapper["public_remote_branch_sha"] = mock.Mock(
            side_effect=[task_head, task_head, candidate]
        )
        self.wrapper["public_main_sha"] = mock.Mock(
            side_effect=[base_sha, base_sha, "e" * 40]
        )
        self.wrapper["is_ancestor"] = mock.Mock(return_value=True)
        self.wrapper["validate_sync_candidate"] = mock.Mock(
            return_value={"passed": True}
        )
        self.wrapper["run_authenticated_git"] = mock.Mock()

        with self.assertRaisesRegex(self.wrapper["GitWrapperError"], "sync_base_changed"):
            self.wrapper["sync_task_branch_base"](
                policy, [task_head, base_sha, candidate]
            )

        self.wrapper["run_authenticated_git"].assert_called_once()


class GitWrapperAuthorityTest(unittest.TestCase):
    def setUp(self):
        self.wrapper = load_wrapper()

    def policy(self):
        return {
            "task_id": "GOV-029",
            "issue_title": "GOV-029 Git wrapper",
            "pr_title": "GOV-029 Git wrapper",
            "branch": "fix/GOV-029-github-app-clean-base-sync",
            "base_branch": "main",
            "base_sha": "a" * 40,
            "allowed_paths": ["infrastructure/github-app/oripa-github-app-git"],
            "allowed_operations": ["push-new-branch", "push-task-branch"],
            "risk": "R4",
            "lane": "Strict Change",
            "activation": "none",
        }

    def test_missing_policy_rejects(self):
        with self.assertRaisesRegex(self.wrapper["GitWrapperError"], "policy_unavailable"):
            self.wrapper["read_policy_file"](Path("/definitely/missing/GOV-029.json"))

    def test_task_id_mismatch_rejects(self):
        with self.assertRaisesRegex(
            self.wrapper["GitWrapperError"], "policy_task_id_invalid"
        ):
            self.wrapper["validate_policy_data"](self.policy(), "GOV-030")

    def test_branch_mismatch_rejects(self):
        with self.assertRaisesRegex(
            self.wrapper["GitWrapperError"], "task_branch_not_allowed"
        ):
            self.wrapper["validate_policy_branch"](self.policy(), "fix/GOV-029-other")

    def test_invalid_policy_base_sha_rejects(self):
        policy = self.policy()
        policy["base_sha"] = "short"
        with self.assertRaisesRegex(
            self.wrapper["GitWrapperError"], "policy_base_sha_invalid"
        ):
            self.wrapper["validate_policy_data"](policy, "GOV-029")

    def test_existing_write_authority_and_branch_prefixes_are_unchanged(self):
        self.assertEqual(
            self.wrapper["GIT_OPERATIONS"],
            {
                "create-base-branch",
                "push-new-branch",
                "push-task-branch",
                "sync-task-branch-base",
            },
        )
        self.assertNotIn("infra/", self.wrapper["ALLOWED_PREFIXES"])
        source = WRAPPER_SOURCE.read_text(encoding="utf-8")
        self.assertNotIn('"--force"', source)
        self.assertIn('f"{candidate_sha}:{remote_ref}"', source)

    def test_any_git_merge_tree_conflict_exit_rejects(self):
        completed = subprocess.CompletedProcess(
            args=[],
            returncode=1,
            stdout="f" * 40 + "\nunrecognized conflict details\n",
            stderr="",
        )
        with mock.patch.object(self.wrapper["subprocess"], "run", return_value=completed):
            with self.assertRaisesRegex(
                self.wrapper["GitWrapperError"], "sync_conflict_required"
            ):
                self.wrapper["merge_tree"]("a" * 40, "b" * 40)


class GitWrapperProvisionTest(unittest.TestCase):
    def setUp(self):
        self.provision = load_provision()

    def canonical_repository(
        self,
        origin="git@github.com:ideal-sol/oripa.git",
    ):
        repository = TemporaryGitRepository()
        self.addCleanup(repository.close)
        for path in (PROVISION, MANIFEST, WRAPPER_SOURCE):
            relative = path.relative_to(ROOT)
            repository.write(relative, path.read_text(encoding="utf-8"))
        source_head = repository.commit("merged canonical source")
        repository.git("remote", "add", "origin", origin)
        repository.git("update-ref", "refs/remotes/origin/main", source_head)
        return repository, source_head

    def detached_checkout(self, repository, head):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        checkout = Path(temporary.name) / "detached"
        repository.git("worktree", "add", "--detach", str(checkout), head)
        return checkout

    @contextmanager
    def authority_context(self, checkout, live_main):
        with (
            mock.patch.object(
                self.provision,
                "__file__",
                str(checkout / PROVISION.relative_to(ROOT)),
            ),
            mock.patch.object(
                self.provision,
                "live_main_sha",
                return_value=live_main,
            ),
        ):
            yield

    def test_manifest_binds_source_runtime_role_mode_and_checksums(self):
        manifest, source_path, source_payload = self.provision.load_manifest(ROOT)

        self.assertEqual(source_path, WRAPPER_SOURCE)
        self.assertEqual(
            manifest["repository_source"]["imported_sha256"], IMPORTED_SHA256
        )
        self.assertEqual(
            hashlib.sha256(source_payload).hexdigest(),
            manifest["repository_source"]["sha256"],
        )
        self.assertEqual(
            manifest["runtime"],
            {
                "destination": "/usr/local/bin/oripa-github-app-git",
                "gid": 0,
                "group": "root",
                "mode": "0700",
                "owner": "root",
                "uid": 0,
            },
        )
        self.assertEqual(manifest["role"], "GitHub App Git delivery wrapper")

    def test_manifest_schema_rejects_destination_override(self):
        value = json.loads(MANIFEST.read_text(encoding="utf-8"))
        value["runtime"]["destination"] = "/tmp/wrapper"
        with self.assertRaisesRegex(
            self.provision.ProvisionFailure, "manifest_runtime_invalid"
        ):
            self.provision.validate_manifest(value)

    def test_host_mutation_rejects_noncanonical_repository_root(self):
        with tempfile.TemporaryDirectory() as temporary:
            with self.assertRaisesRegex(
                self.provision.ProvisionFailure, "repository_root_invalid"
            ):
                self.provision.require_merged_main(Path(temporary))

    def test_clean_exact_detached_checkout_is_valid_provision_authority(self):
        repository, expected_head = self.canonical_repository()
        checkout = self.detached_checkout(repository, expected_head)

        self.assertEqual(
            subprocess.run(
                ["git", "-C", str(checkout), "status", "--porcelain"],
                check=True,
                capture_output=True,
                text=True,
            ).stdout,
            "",
        )
        self.assertEqual(
            subprocess.run(
                ["git", "-C", str(checkout), "branch", "--show-current"],
                check=True,
                capture_output=True,
                text=True,
            ).stdout,
            "",
        )
        with self.authority_context(checkout, expected_head):
            authority = self.provision.require_repository_authority(
                checkout, expected_head
            )

        self.assertEqual(authority["head_sha"], expected_head)

    def test_sanitized_git_uses_exact_root_safe_directory(self):
        repository, expected_head = self.canonical_repository()
        checkout = self.detached_checkout(repository, expected_head)
        actual_run = subprocess.run
        repository_commands = []

        def reject_untrusted_repository(command, *arguments, **keywords):
            if command[0] == self.provision.GIT and "-C" in command:
                command_root = command[command.index("-C") + 1]
                exact_setting = f"safe.directory={command_root}"
                self.assertEqual(keywords["env"]["GIT_CONFIG_GLOBAL"], os.devnull)
                self.assertEqual(keywords["env"]["GIT_CONFIG_NOSYSTEM"], "1")
                if exact_setting not in command:
                    return subprocess.CompletedProcess(command, 128, "", "")
                self.assertNotIn("safe.directory=*", command)
                repository_commands.append(command)
            return actual_run(command, *arguments, **keywords)

        with (
            self.authority_context(checkout, expected_head),
            mock.patch.object(
                self.provision.subprocess,
                "run",
                side_effect=reject_untrusted_repository,
            ),
        ):
            authority = self.provision.require_repository_authority(
                checkout, expected_head
            )

        self.assertEqual(authority["head_sha"], expected_head)
        self.assertGreaterEqual(len(repository_commands), 8)

    def test_explicit_detached_verify_source_cli_passes(self):
        repository, expected_head = self.canonical_repository()
        checkout = self.detached_checkout(repository, expected_head)

        with (
            self.authority_context(checkout, expected_head),
            mock.patch.object(
                sys,
                "argv",
                [
                    "provision_git_wrapper.py",
                    "--repo-root",
                    str(checkout),
                    "--expected-head",
                    expected_head,
                    "verify-source",
                ],
            ),
            mock.patch("builtins.print") as output,
        ):
            self.provision.main()

        result = json.loads(output.call_args.args[0])
        self.assertEqual(result["status"], "verified")
        self.assertEqual(
            result["source"],
            str(checkout / WRAPPER_SOURCE.relative_to(ROOT)),
        )

    def test_default_clean_main_remains_valid_provision_authority(self):
        repository, expected_head = self.canonical_repository()

        with (
            self.authority_context(repository.path, expected_head),
            mock.patch.object(
                self.provision,
                "EXPECTED_REPOSITORY_ROOT",
                repository.path,
            ),
        ):
            actual_head = self.provision.require_merged_main(repository.path)

        self.assertEqual(actual_head, expected_head)

    def test_default_verify_source_cli_remains_backward_compatible(self):
        repository, expected_head = self.canonical_repository()

        with (
            self.authority_context(repository.path, expected_head),
            mock.patch.object(
                self.provision,
                "EXPECTED_REPOSITORY_ROOT",
                repository.path,
            ),
            mock.patch.object(
                sys,
                "argv",
                ["provision_git_wrapper.py", "verify-source"],
            ),
            mock.patch("builtins.print") as output,
        ):
            self.provision.main()

        result = json.loads(output.call_args.args[0])
        self.assertEqual(result["status"], "verified")
        self.assertEqual(
            result["source_sha256"],
            hashlib.sha256(WRAPPER_SOURCE.read_bytes()).hexdigest(),
        )

    def test_active_primary_task_branch_is_unchanged_by_detached_install(self):
        repository, source_head = self.canonical_repository()
        repository.git("switch", "-q", "-c", "chore/OPS-027-active-task")
        repository.write("ops-027.txt", "active task\n")
        task_head = repository.commit("active task")
        checkout = self.detached_checkout(repository, source_head)
        before = {
            "branch": repository.git("branch", "--show-current").stdout.strip(),
            "head": repository.git("rev-parse", "HEAD").stdout.strip(),
            "status": repository.git("status", "--porcelain").stdout,
        }

        with tempfile.TemporaryDirectory() as temporary:
            runtime_root = Path(temporary)
            destination_directory = runtime_root / "bin"
            destination_directory.mkdir(mode=0o700)
            destination = destination_directory / "oripa-github-app-git"
            old_payload = b"#!/usr/bin/env python3\nprint('old')\n"
            destination.write_bytes(old_payload)
            destination.chmod(0o700)
            backup_directory = runtime_root / "backups"
            with self.authority_context(checkout, source_head):
                manifest, _, source_payload = self.provision.load_manifest(
                    checkout, source_head
                )
                with (
                    mock.patch.object(
                        self.provision,
                        "current_contract",
                        return_value=(
                            destination,
                            backup_directory,
                            os.getuid(),
                            os.getgid(),
                            0o700,
                        ),
                    ),
                    mock.patch.object(self.provision, "require_root"),
                ):
                    result = self.provision.install_current(
                        manifest,
                        source_payload,
                        hashlib.sha256(old_payload).hexdigest(),
                        checkout,
                        source_head,
                    )

        after = {
            "branch": repository.git("branch", "--show-current").stdout.strip(),
            "head": repository.git("rev-parse", "HEAD").stdout.strip(),
            "status": repository.git("status", "--porcelain").stdout,
        }
        self.assertEqual(before, after)
        self.assertEqual(after["branch"], "chore/OPS-027-active-task")
        self.assertEqual(after["head"], task_head)
        self.assertEqual(result["repository_head"], source_head)

    def test_older_merged_source_is_valid_when_live_main_is_newer(self):
        repository, source_head = self.canonical_repository()
        repository.write("newer-main.txt", "later merge\n")
        live_main = repository.commit("later main merge")
        repository.git("update-ref", "refs/remotes/origin/main", live_main)
        checkout = self.detached_checkout(repository, source_head)

        with self.authority_context(checkout, live_main):
            authority = self.provision.require_repository_authority(
                checkout, source_head
            )

        self.assertEqual(authority["head_sha"], source_head)
        self.assertEqual(authority["live_main_sha"], live_main)

    def test_relative_repository_root_rejects(self):
        with self.assertRaisesRegex(
            self.provision.ProvisionFailure,
            "repository_root_not_absolute",
        ):
            self.provision.validate_repository_root(Path("relative/repository"))

    def test_non_git_repository_root_rejects(self):
        with tempfile.TemporaryDirectory() as temporary:
            with self.assertRaisesRegex(
                self.provision.ProvisionFailure,
                "repository_git_worktree_invalid",
            ):
                self.provision.require_repository_authority(
                    Path(temporary), "a" * 40
                )

    def test_wrong_repository_origin_rejects(self):
        repository, expected_head = self.canonical_repository(
            "git@github.com:ideal-sol/not-oripa.git"
        )
        checkout = self.detached_checkout(repository, expected_head)

        with self.authority_context(checkout, expected_head):
            with self.assertRaisesRegex(
                self.provision.ProvisionFailure,
                "repository_origin_invalid",
            ):
                self.provision.require_repository_authority(
                    checkout, expected_head
                )

    def test_dirty_detached_checkout_rejects(self):
        repository, expected_head = self.canonical_repository()
        checkout = self.detached_checkout(repository, expected_head)
        (checkout / "untracked.txt").write_text("dirty\n", encoding="utf-8")

        with self.authority_context(checkout, expected_head):
            with self.assertRaisesRegex(
                self.provision.ProvisionFailure,
                "repository_not_clean",
            ):
                self.provision.require_repository_authority(
                    checkout, expected_head
                )

    def test_tracked_mutation_rejects(self):
        repository, expected_head = self.canonical_repository()
        checkout = self.detached_checkout(repository, expected_head)
        helper_path = checkout / PROVISION.relative_to(ROOT)
        helper_path.write_text(
            helper_path.read_text(encoding="utf-8") + "\n",
            encoding="utf-8",
        )

        with self.authority_context(checkout, expected_head):
            with self.assertRaisesRegex(
                self.provision.ProvisionFailure,
                "repository_not_clean",
            ):
                self.provision.require_repository_authority(
                    checkout, expected_head
                )

    def test_expected_head_mismatch_rejects(self):
        repository, expected_head = self.canonical_repository()
        checkout = self.detached_checkout(repository, expected_head)

        with self.authority_context(checkout, expected_head):
            with self.assertRaisesRegex(
                self.provision.ProvisionFailure,
                "repository_head_mismatch",
            ):
                self.provision.require_repository_authority(checkout, "f" * 40)

    def test_unmerged_detached_head_rejects(self):
        repository, live_main = self.canonical_repository()
        repository.git("switch", "-q", "-c", "unmerged-task")
        repository.write("unmerged.txt", "not merged\n")
        unmerged_head = repository.commit("unmerged task")
        checkout = self.detached_checkout(repository, unmerged_head)

        with self.authority_context(checkout, live_main):
            with self.assertRaisesRegex(
                self.provision.ProvisionFailure,
                "repository_head_not_merged",
            ):
                self.provision.require_repository_authority(
                    checkout, unmerged_head
                )

    def test_stale_origin_main_rejects_before_ancestry_trust(self):
        repository, source_head = self.canonical_repository()
        repository.write("newer-main.txt", "later merge\n")
        live_main = repository.commit("later main merge")
        checkout = self.detached_checkout(repository, source_head)

        with self.authority_context(checkout, live_main):
            with self.assertRaisesRegex(
                self.provision.ProvisionFailure,
                "repository_origin_main_mismatch",
            ):
                self.provision.require_repository_authority(
                    checkout, source_head
                )

    def test_manifest_bytes_must_match_expected_head(self):
        repository, expected_head = self.canonical_repository()
        checkout = self.detached_checkout(repository, expected_head)
        manifest_path = checkout / MANIFEST.relative_to(ROOT)
        manifest_path.write_text(
            manifest_path.read_text(encoding="utf-8") + "\n",
            encoding="utf-8",
        )

        with self.authority_context(checkout, expected_head):
            with self.assertRaisesRegex(
                self.provision.ProvisionFailure,
                "manifest_head_mismatch",
            ):
                self.provision.load_manifest(checkout, expected_head)

    def test_source_checksum_mismatch_rejects(self):
        repository, expected_head = self.canonical_repository()
        checkout = self.detached_checkout(repository, expected_head)
        source_path = checkout / WRAPPER_SOURCE.relative_to(ROOT)
        source_path.write_text(
            source_path.read_text(encoding="utf-8") + "\n",
            encoding="utf-8",
        )

        with self.authority_context(checkout, expected_head):
            with self.assertRaisesRegex(
                self.provision.ProvisionFailure,
                "source_sha_mismatch",
            ):
                self.provision.load_manifest(checkout)

    def test_source_syntax_mismatch_rejects(self):
        repository, expected_head = self.canonical_repository()
        checkout = self.detached_checkout(repository, expected_head)
        source_path = checkout / WRAPPER_SOURCE.relative_to(ROOT)
        source_path.write_text("def invalid(:\n", encoding="utf-8")
        manifest_path = checkout / MANIFEST.relative_to(ROOT)
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        manifest["repository_source"]["sha256"] = hashlib.sha256(
            source_path.read_bytes()
        ).hexdigest()
        manifest_path.write_text(
            json.dumps(manifest, indent=2) + "\n",
            encoding="utf-8",
        )

        with self.authority_context(checkout, expected_head):
            with self.assertRaisesRegex(
                self.provision.ProvisionFailure,
                "source_syntax_invalid",
            ):
                self.provision.load_manifest(checkout)

    def test_wrapper_source_symlink_escape_rejects(self):
        repository, expected_head = self.canonical_repository()
        checkout = self.detached_checkout(repository, expected_head)
        source_path = checkout / WRAPPER_SOURCE.relative_to(ROOT)
        with tempfile.TemporaryDirectory() as temporary:
            external = Path(temporary) / "external-wrapper"
            external.write_text(
                WRAPPER_SOURCE.read_text(encoding="utf-8"),
                encoding="utf-8",
            )
            source_path.unlink()
            source_path.symlink_to(external)
            with self.authority_context(checkout, expected_head):
                with self.assertRaisesRegex(
                    self.provision.ProvisionFailure,
                    "source_path_invalid",
                ):
                    self.provision.load_manifest(checkout)

    def test_manifest_symlink_escape_rejects(self):
        repository, expected_head = self.canonical_repository()
        checkout = self.detached_checkout(repository, expected_head)
        manifest_path = checkout / MANIFEST.relative_to(ROOT)
        with tempfile.TemporaryDirectory() as temporary:
            external = Path(temporary) / "external-manifest.json"
            external.write_text(
                MANIFEST.read_text(encoding="utf-8"),
                encoding="utf-8",
            )
            manifest_path.unlink()
            manifest_path.symlink_to(external)
            with self.authority_context(checkout, expected_head):
                with self.assertRaisesRegex(
                    self.provision.ProvisionFailure,
                    "manifest_path_invalid",
                ):
                    self.provision.load_manifest(checkout)

    def test_helper_from_different_checkout_rejects(self):
        repository, expected_head = self.canonical_repository()
        checkout = self.detached_checkout(repository, expected_head)

        with self.assertRaisesRegex(
            self.provision.ProvisionFailure,
            "helper_checkout_mismatch",
        ):
            self.provision.load_manifest(checkout)

    def test_loaded_source_substitution_rejects_before_install(self):
        repository, expected_head = self.canonical_repository()
        checkout = self.detached_checkout(repository, expected_head)
        with self.authority_context(checkout, expected_head):
            manifest, _, _ = self.provision.load_manifest(
                checkout, expected_head
            )
            substituted_payload = b"#!/usr/bin/env python3\nprint('substitute')\n"
            substituted_manifest = json.loads(json.dumps(manifest))
            substituted_manifest["repository_source"]["sha256"] = hashlib.sha256(
                substituted_payload
            ).hexdigest()

            with (
                mock.patch.object(self.provision, "require_root"),
                self.assertRaisesRegex(
                    self.provision.ProvisionFailure,
                    "provision_source_changed",
                ),
            ):
                self.provision.install_current(
                    substituted_manifest,
                    substituted_payload,
                    "0" * 64,
                    checkout,
                    expected_head,
                )

    def test_repository_root_symlink_rejects(self):
        repository, expected_head = self.canonical_repository()
        checkout = self.detached_checkout(repository, expected_head)
        with tempfile.TemporaryDirectory() as temporary:
            linked_root = Path(temporary) / "linked-root"
            linked_root.symlink_to(checkout, target_is_directory=True)
            with self.assertRaisesRegex(
                self.provision.ProvisionFailure,
                "repository_root_invalid|repository_root_not_canonical",
            ):
                self.provision.validate_repository_root(linked_root)

    def test_explicit_repository_root_requires_expected_head(self):
        with mock.patch.object(
            sys,
            "argv",
            [
                "provision_git_wrapper.py",
                "--repo-root",
                "/var/tmp/detached",
                "status",
            ],
        ):
            with self.assertRaisesRegex(
                self.provision.ProvisionFailure,
                "explicit_authority_arguments_required",
            ):
                self.provision.main()

    def test_installed_runtime_drift_is_detected(self):
        with tempfile.TemporaryDirectory() as temporary:
            destination = Path(temporary) / "oripa-github-app-git"
            payload = b"#!/usr/bin/env python3\nprint('expected')\n"
            destination.write_bytes(payload)
            destination.chmod(0o700)
            expected = hashlib.sha256(payload).hexdigest()

            self.provision.verify_managed_file(
                destination,
                expected,
                uid=os.getuid(),
                gid=os.getgid(),
                mode=0o700,
            )
            destination.write_bytes(b"#!/usr/bin/env python3\nprint('drift')\n")

            with self.assertRaisesRegex(
                self.provision.ProvisionFailure, "installed_sha_mismatch"
            ):
                self.provision.verify_managed_file(
                    destination,
                    expected,
                    uid=os.getuid(),
                    gid=os.getgid(),
                    mode=0o700,
                )

    def test_atomic_install_backs_up_previous_exact_wrapper(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            destination_directory = root / "bin"
            backup_directory = root / "backups"
            destination_directory.mkdir(mode=0o700)
            destination = destination_directory / "oripa-github-app-git"
            destination.write_bytes(b"#!/usr/bin/env python3\nprint('old')\n")
            destination.chmod(0o700)
            payload = b"#!/usr/bin/env python3\nprint('new')\n"
            expected = hashlib.sha256(payload).hexdigest()
            uid = os.getuid()
            gid = os.getgid()

            result = self.provision.install_payload(
                destination,
                payload,
                expected,
                backup_directory,
                uid=uid,
                gid=gid,
                mode=0o700,
            )

            self.assertEqual(destination.read_bytes(), payload)
            self.assertEqual(stat.S_IMODE(destination.stat().st_mode), 0o700)
            self.assertEqual(result["post_sha256"], expected)
            backup = backup_directory / f"oripa-github-app-git.{result['pre_sha256']}"
            self.assertTrue(backup.is_file())
            self.assertEqual(hashlib.sha256(backup.read_bytes()).hexdigest(), result["pre_sha256"])

    def test_pre_replace_failure_preserves_previous_wrapper(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            destination_directory = root / "bin"
            destination_directory.mkdir(mode=0o700)
            destination = destination_directory / "oripa-github-app-git"
            old_payload = b"#!/usr/bin/env python3\nprint('old')\n"
            destination.write_bytes(old_payload)
            destination.chmod(0o700)
            new_payload = b"#!/usr/bin/env python3\nprint('new')\n"
            uid = os.getuid()
            gid = os.getgid()

            with mock.patch.object(
                self.provision,
                "assert_destination_unchanged",
                side_effect=self.provision.ProvisionFailure("destination_changed"),
            ):
                with self.assertRaisesRegex(
                    self.provision.ProvisionFailure, "destination_changed"
                ):
                    self.provision.install_payload(
                        destination,
                        new_payload,
                        hashlib.sha256(new_payload).hexdigest(),
                        root / "backups",
                        uid=uid,
                        gid=gid,
                        mode=0o700,
                    )

            self.assertEqual(destination.read_bytes(), old_payload)

    def test_post_replace_failure_restores_previous_wrapper(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            destination_directory = root / "bin"
            destination_directory.mkdir(mode=0o700)
            destination = destination_directory / "oripa-github-app-git"
            old_payload = b"#!/usr/bin/env python3\nprint('old')\n"
            destination.write_bytes(old_payload)
            destination.chmod(0o700)
            new_payload = b"#!/usr/bin/env python3\nprint('new')\n"
            new_sha256 = hashlib.sha256(new_payload).hexdigest()
            uid = os.getuid()
            gid = os.getgid()
            original_verify = self.provision.verify_managed_file

            def fail_new_install(path, expected_sha256, **keywords):
                if path == destination and expected_sha256 == new_sha256:
                    raise self.provision.ProvisionFailure("installed_sha_mismatch")
                return original_verify(path, expected_sha256, **keywords)

            with mock.patch.object(
                self.provision, "verify_managed_file", side_effect=fail_new_install
            ):
                with self.assertRaisesRegex(
                    self.provision.ProvisionFailure, "installed_sha_mismatch"
                ):
                    self.provision.install_payload(
                        destination,
                        new_payload,
                        new_sha256,
                        root / "backups",
                        uid=uid,
                        gid=gid,
                        mode=0o700,
                    )

            self.assertEqual(destination.read_bytes(), old_payload)
            self.assertEqual(stat.S_IMODE(destination.stat().st_mode), 0o700)

    def test_post_install_authority_change_restores_previous_wrapper(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            destination_directory = root / "bin"
            destination_directory.mkdir(mode=0o700)
            destination = destination_directory / "oripa-github-app-git"
            old_payload = b"#!/usr/bin/env python3\nprint('old')\n"
            destination.write_bytes(old_payload)
            destination.chmod(0o700)
            new_payload = b"#!/usr/bin/env python3\nprint('new')\n"

            with self.assertRaisesRegex(
                self.provision.ProvisionFailure, "repository_main_changed"
            ):
                self.provision.install_payload(
                    destination,
                    new_payload,
                    hashlib.sha256(new_payload).hexdigest(),
                    root / "backups",
                    uid=os.getuid(),
                    gid=os.getgid(),
                    mode=0o700,
                    post_install_validation=lambda: self.provision.fail(
                        "repository_main_changed"
                    ),
                )

            self.assertEqual(destination.read_bytes(), old_payload)

    def test_expected_preinstall_sha_rejects_drift_before_replace(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            destination_directory = root / "bin"
            destination_directory.mkdir(mode=0o700)
            destination = destination_directory / "oripa-github-app-git"
            old_payload = b"#!/usr/bin/env python3\nprint('old')\n"
            destination.write_bytes(old_payload)
            destination.chmod(0o700)
            new_payload = b"#!/usr/bin/env python3\nprint('new')\n"

            with self.assertRaisesRegex(
                self.provision.ProvisionFailure, "preinstall_sha_mismatch"
            ):
                self.provision.install_payload(
                    destination,
                    new_payload,
                    hashlib.sha256(new_payload).hexdigest(),
                    root / "backups",
                    uid=os.getuid(),
                    gid=os.getgid(),
                    mode=0o700,
                    expected_pre_sha256="0" * 64,
                )

            self.assertEqual(destination.read_bytes(), old_payload)


if __name__ == "__main__":
    unittest.main()
