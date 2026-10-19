import contextlib
import importlib.util
import io
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("old_test_deploy", ROOT / "scripts/ops/old_test_deploy.py")
deploy = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(deploy)
REAL_RUN = deploy.run
OLD_IMAGE = "sha256:" + "1" * 64
NEW_IMAGE = "sha256:" + "2" * 64
SOURCE_SHA = "a" * 40
BASE_SHA = "b" * 40


class DeploymentTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name)
        self.lock = self.directory / "f2b.lock"
        self.lock.touch()
        self.runner = self.directory / "runner.py"
        self.runner.write_text('IMAGE = "' + OLD_IMAGE + '"\n')
        self.events = []
        self.candidate = {
            "version": 1, "target": "old-test", "task_id": "OLD-DEPLOY-P2-01",
            "services": ["api"], "migrations": [], "rollback_schema_compatible": False,
            "platform_source": str(self.directory / "new"), "current_platform_source": str(self.directory / "old"),
            "current_platform_sha": BASE_SHA,
            "artifact": {"directory": str(self.directory), "authority": {"source_sha": SOURCE_SHA}},
            "images": {"api": {"image_id": NEW_IMAGE}},
            "previous": {"api": {"id": "old-api", "image": OLD_IMAGE},
                         "admin": {"id": "old-admin", "image": OLD_IMAGE},
                         deploy.SMS: "active", **{timer: "active" for timer in deploy.TIMERS},
                         "storefront": {"unit": "old.service", "directory": "/old", "sha": BASE_SHA,
                                        "environment": [], "overlays": {}}},
            "storefront": {"directory": "/new", "sha": SOURCE_SHA, "overlays": {}},
            "storefront_new_unit": "new.service", "storefront_unit": "old.service",
            "state": {"phase": "prepared", "migration_attempted": False, "applied": []},
        }
        self.operation = deploy.Deployment(self.candidate, self.directory / "candidate.json")
        self.operation.runtime = {role: self.runtime(role) for role in ("api", "admin")}
        self.patches = contextlib.ExitStack()
        self.addCleanup(self.patches.close)
        self.patches.enter_context(mock.patch.object(deploy, "F2B_LOCK", self.lock))
        self.patches.enter_context(mock.patch.object(deploy, "F2B_RUNNER", self.runner))
        self.command = self.patches.enter_context(mock.patch.object(deploy, "run", side_effect=self.command_run))
        self.patches.enter_context(mock.patch.object(deploy, "save"))
        self.preflight = self.patches.enter_context(mock.patch.object(self.operation, "preflight"))
        self.patches.enter_context(mock.patch.object(self.operation, "load", side_effect=lambda: self.events.append("load")))
        self.patches.enter_context(mock.patch.object(self.operation, "guard", side_effect=lambda *args: self.events.append("guard")))
        self.patches.enter_context(mock.patch.object(self.operation, "backup", side_effect=lambda: self.events.append("backup")))
        self.patches.enter_context(mock.patch.object(self.operation, "switch", side_effect=lambda role, image: self.events.append(("switch", role, image))))
        self.patches.enter_context(mock.patch.object(self.operation, "start_storefront", side_effect=lambda selected, name: self.events.append(("storefront", selected["directory"], name))))
        self.patches.enter_context(mock.patch.object(self.operation, "health", side_effect=lambda: self.events.append("health")))
        self.patches.enter_context(mock.patch.object(deploy, "unit", return_value={"ActiveState": "inactive"}))

    def runtime(self, role):
        return {"Id": "old-" + role, "Name": "/" + role, "Image": OLD_IMAGE,
                "Config": {"Labels": {"com.docker.compose.project": "test", "com.docker.compose.service": role,
                           "com.docker.compose.project.config_files": "/external/compose.json"}, "Env": []}}

    def command_run(self, arguments, **kwargs):
        self.events.append(arguments)
        return b""

    def apply(self, **kwargs):
        self.operation.apply(deploy.identity(self.candidate), **kwargs)

    def migration(self):
        self.candidate["services"] = ["api", "admin"]
        self.candidate["images"]["admin"] = {"image_id": NEW_IMAGE}
        self.candidate["migrations"] = ["2026_10_19_000090_first.php", "2026_10_19_000091_second.php"]

    def test_api_without_migration_has_no_backup_guard_or_sms_stop(self):
        self.apply()
        self.assertNotIn("backup", self.events)
        self.assertNotIn("guard", self.events)
        self.assertFalse(any(deploy.SMS in event for event in self.events))
        self.assertLess(self.events.index(["systemctl", "stop", deploy.TIMERS[1]]), self.events.index(["docker", "stop", "/api"]))
        self.assertIn(("switch", "api", NEW_IMAGE), self.events)
        self.assertIn(NEW_IMAGE, self.runner.read_text())
        self.assertEqual(self.candidate["state"]["phase"], "complete")

    def test_storefront_only_never_stops_api_database_or_timers(self):
        self.candidate["services"] = ["storefront"]
        self.apply()
        self.assertEqual(self.events, [["systemctl", "stop", "old.service"],
            ("storefront", "/new", "new.service"), "health"])

    def test_changed_candidate_and_missing_go_stop_before_mutation(self):
        with self.assertRaises(deploy.Hold):
            self.operation.apply("unapproved")
        self.command.assert_not_called()
        for reason in ("source_sha_mismatch", "artifact_files_changed", "runtime_or_candidate_changed"):
            self.preflight.side_effect = deploy.Hold(reason)
            with self.assertRaises(deploy.Hold):
                self.apply()
            self.assertEqual(self.events, [])

    def test_guard_or_backup_failure_prevents_migration_and_switch(self):
        self.migration()
        for method in ("guard", "backup"):
            with self.subTest(method=method):
                self.candidate["state"]["phase"] = "prepared"
                self.events.clear()
                with mock.patch.object(self.operation, method, side_effect=deploy.Hold("failed")):
                    with self.assertRaises(deploy.Hold):
                        self.apply()
                self.assertFalse(any(isinstance(event, tuple) and event[0] == "switch" for event in self.events))
                self.assertFalse(any(isinstance(event, list) and "migrate" in event for event in self.events))

    def test_f2b_drain_failure_never_stops_api_or_resumes_workers(self):
        with mock.patch.object(self.operation, "wait", side_effect=deploy.Hold("busy")):
            with self.assertRaises(deploy.Hold):
                self.apply()
        self.assertNotIn(["docker", "stop", "/api"], self.events)
        self.assertFalse(any(isinstance(event, list) and "start" in event for event in self.events))

    def test_migrations_use_only_approved_paths_in_order(self):
        self.migration()
        self.apply()
        commands = [event for event in self.events if isinstance(event, list) and "migrate" in event]
        self.assertEqual([argument for command in commands for argument in command if argument.startswith("--path=")],
            ["--path=database/migrations-v2/" + name for name in self.candidate["migrations"]])
        self.assertLess(self.events.index("backup"), self.events.index(commands[0]))
        self.assertEqual(self.candidate["state"]["applied"], self.candidate["migrations"])
        self.assertTrue(self.candidate["state"]["schema_verified"])

    def test_partial_migration_never_restores_or_resumes_and_blocks_rollback(self):
        self.migration()
        def fail_second(arguments, **kwargs):
            if any(self.candidate["migrations"][1] in argument for argument in arguments):
                raise deploy.Hold("migration_failed")
            return self.command_run(arguments, **kwargs)
        self.command.side_effect = fail_second
        with self.assertRaises(deploy.Hold):
            self.apply()
        self.assertEqual(self.candidate["state"]["applied"], self.candidate["migrations"][:1])
        self.assertFalse(any(isinstance(event, list) and ("pg_restore" in event or "start" in event) for event in self.events))
        self.candidate["rollback_schema_compatible"] = True
        with self.assertRaisesRegex(deploy.Hold, "compatibility_unknown"):
            self.operation.rollback(deploy.identity(self.candidate))

    def test_stopped_workers_and_timers_are_not_started(self):
        self.migration()
        for name in (deploy.SMS,) + deploy.TIMERS:
            self.candidate["previous"][name] = "inactive"
        self.apply()
        self.assertFalse(any(isinstance(event, list) and "systemctl" in event and "start" in event for event in self.events))

    def test_apply_and_rollback_dry_run_execute_no_mutation(self):
        self.apply(dry_run=True)
        self.assertEqual(self.events, [])
        self.assertEqual(self.candidate["state"]["phase"], "prepared")
        self.candidate["state"]["phase"] = "switch"
        with mock.patch.object(deploy, "inspect", return_value=self.runtime("api")):
            self.operation.rollback(deploy.identity(self.candidate), dry_run=True)
        self.assertEqual(self.events, [])
        self.assertIn(OLD_IMAGE, self.runner.read_text())

    def test_rollback_restores_images_storefront_and_f2b_pin(self):
        self.candidate["services"] = ["api", "storefront"]
        self.candidate["state"]["phase"] = "complete"
        self.runner.write_text('IMAGE = "' + NEW_IMAGE + '"\n')
        with mock.patch.object(deploy, "inspect", return_value=self.runtime("api")), mock.patch.object(
                deploy, "storefront", return_value={"directory": "/old", "sha": BASE_SHA, "overlays": {}}):
            self.operation.rollback(deploy.identity(self.candidate))
        self.assertIn(("switch", "api", OLD_IMAGE), self.events)
        self.assertIn(("storefront", "/old", "new.service"), self.events)
        self.assertIn(OLD_IMAGE, self.runner.read_text())
        self.assertEqual(self.candidate["state"]["phase"], "rolled_back")

    def test_candidate_identity_includes_scope_but_not_progress(self):
        approved = deploy.identity(self.candidate)
        self.candidate["state"]["phase"] = "load"
        self.assertEqual(deploy.identity(self.candidate), approved)
        self.candidate["migrations"].append("extra.php")
        self.assertNotEqual(deploy.identity(self.candidate), approved)

    def test_storefront_overlay_accepts_only_existing_exact_changes(self):
        repository = self.directory / "storefront"
        repository.mkdir()
        (repository / "node_modules/next/dist/bin").mkdir(parents=True)
        (repository / "node_modules/next/dist/bin/next").touch()
        original = b"const nextConfig: NextConfig = {\n};\n"
        allowed = original.replace(b"{\n", b'{\n  allowedDevOrigins: ["test.luxe-pack.biz"],\n')
        (repository / "next.config.ts").write_bytes(allowed)
        def reads(arguments, **kwargs):
            if "get-url" in arguments:
                return b"git@github.com:ideal-sol/luxe-pack-storefront.git\n"
            if "diff" in arguments:
                return b"next.config.ts\n"
            return b""
        with mock.patch.object(deploy, "source", return_value=(repository, SOURCE_SHA)), mock.patch.object(
                deploy, "source_bytes", return_value=original), mock.patch.object(deploy, "run", side_effect=reads):
            accepted = deploy.storefront(str(repository), SOURCE_SHA)
            self.assertIn("next.config.ts", accepted["overlays"])
            (repository / "next.config.ts").write_bytes(allowed + b"arbitrary_change\n")
            with self.assertRaisesRegex(deploy.Hold, "overlay_invalid"):
                deploy.storefront(str(repository), SOURCE_SHA)
        self.assertTrue((repository / "next.config.ts").read_bytes().endswith(b"arbitrary_change\n"))

    def test_preflight_rejects_unapproved_migrations_without_guard_bypass(self):
        original_preflight = deploy.Deployment.preflight
        with mock.patch.object(self.operation, "artifact", return_value={}), mock.patch.object(deploy, "source",
                side_effect=lambda path, expected=None: (Path(path), expected or BASE_SHA)), mock.patch.object(
                deploy, "migration_files", side_effect=[[deploy.MIGRATIONS + "/old.php"],
                    [deploy.MIGRATIONS + "/old.php", deploy.MIGRATIONS + "/000088_unapproved.php"]]):
            with self.assertRaisesRegex(deploy.Hold, "migration_scope_incompatible"):
                original_preflight(self.operation)
        self.assertEqual(self.events, [])

    def test_file_backed_backup_uses_archive_parse_and_no_stdout_dump(self):
        def backup_run(arguments, **kwargs):
            if arguments[:2] == ["docker", "cp"]:
                Path(arguments[-1]).write_bytes(b"synthetic-custom-archive")
            return self.command_run(arguments, **kwargs)
        self.command.side_effect = backup_run
        deploy.Deployment.backup(self.operation)
        self.assertIn('--file "$1"', self.events[0][-3])
        self.assertEqual(self.events[1][3], "pg_restore")
        self.assertIn("--file=/dev/null", self.events[1])
        self.assertIn("backup_sha256", self.candidate["state"])

    def test_external_errors_are_redacted(self):
        with mock.patch.object(deploy.subprocess, "run", return_value=subprocess.CompletedProcess(
                ["synthetic"], 1, stdout=b"private-value", stderr=b"private-value")):
            with self.assertRaises(deploy.Hold) as failure:
                REAL_RUN(["synthetic"])
        self.assertNotIn("private-value", str(failure.exception))
        with mock.patch.object(deploy, "destination", side_effect=RuntimeError("private-value")), mock.patch.object(
                deploy.sys, "argv", ["deploy", "apply", "--candidate", "/private/candidate.json", "--go", "example"]):
            output = io.StringIO()
            with contextlib.redirect_stdout(output):
                self.assertEqual(deploy.main(), 1)
            self.assertNotIn("private-value", output.getvalue())

    def test_prepare_dry_run_does_not_write_candidate(self):
        arguments = ["deploy", "prepare", "--candidate", str(self.directory / "candidate.json"),
                     "--task-id", "OLD-DEPLOY-P2-01", "--artifact-directory", str(self.directory),
                     "--artifact-id", "123", "--artifact-digest", "sha256:" + "3" * 64,
                     "--platform-source", "/new", "--current-platform-source", "/old", "--services", "api", "--dry-run"]
        with mock.patch.object(deploy.sys, "argv", arguments), mock.patch.object(deploy, "destination"), mock.patch.object(
                deploy, "source", return_value=(Path("/old"), BASE_SHA)), mock.patch.object(
                deploy.Deployment, "preflight"), contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(deploy.main(), 0)
        self.assertFalse((self.directory / "candidate.json").exists())
        self.command.assert_not_called()


if __name__ == "__main__":
    unittest.main()
