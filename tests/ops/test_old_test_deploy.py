import contextlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import tempfile
from types import SimpleNamespace
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


def startup(directory):
    return {"User": "luxe-pack-storefront-preview", "WorkingDirectory": directory,
            "Restart": "on-failure", "KillMode": "control-group", "TimeoutStopUSec": "1min 30s",
            "environment": ["NODE_ENV=development"],
            "command": ["/usr/bin/node", directory + "/node_modules/next/dist/bin/next",
                        "dev", "--hostname", "127.0.0.1", "--port", "3241"]}


def old_unit():
    definition = startup("/old")
    return {**{key: value for key, value in definition.items() if key not in {"environment", "command"}},
            "Id": "old.service", "ActiveState": "inactive", "EnvironmentFiles": "",
            "Environment": "NODE_ENV=development", "ExecStart": "{ argv[]=" + " ".join(definition["command"]) + " ; }"}


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
                         "admin": {"id": "old-admin", "image": OLD_IMAGE}, "f2b_runner": deploy.file_digest(self.runner),
                         deploy.SMS: "active", **{timer: "active" for timer in deploy.TIMERS},
                         "storefront": {"unit": "old.service", "directory": "/old", "sha": BASE_SHA,
                                        "startup": startup("/old"), "overlays": {}}},
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
        self.patches.enter_context(mock.patch.object(deploy, "unit", side_effect=lambda name: {
            "ActiveState": self.candidate["previous"].get(name, "inactive")}))

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
        for key in ("artifact", "platform_source", "current_platform_source", "current_platform_sha"):
            self.candidate.pop(key)
        self.candidate["images"] = {}
        self.apply()
        self.assertEqual(self.events, [["node", "/new/scripts/verify-vendored-artifacts.mjs"], ["systemctl", "stop", "old.service"],
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

    def test_api_only_artifact_migration_fences_and_restarts_unchanged_admin(self):
        self.migration()
        self.candidate["services"] = ["api"]
        self.candidate["images"].pop("admin")
        before = [deploy.MIGRATIONS + "/old.php"]
        after = before + [deploy.MIGRATIONS + "/" + name for name in self.candidate["migrations"]]
        with mock.patch.object(self.operation, "artifact", return_value=self.candidate["images"]), mock.patch.object(
                deploy, "source", side_effect=lambda path, expected=None: (Path(path), expected or BASE_SHA)), mock.patch.object(
                deploy, "migration_files", side_effect=[before, after]), mock.patch.object(
                deploy, "source_bytes", return_value=b"unchanged"), mock.patch.object(
                self.operation, "snapshot", return_value=self.candidate["previous"]), mock.patch.object(
                deploy, "decoded", side_effect=[{"services": {"api": {"environment": {}}}}, [{"Config": {"Env": []}}]]):
            deploy.Deployment.preflight(self.operation)
        self.events.clear()
        self.apply()
        self.assertIn(["docker", "stop", "/admin"], self.events)
        self.assertIn(["docker", "start", "/admin"], self.events)
        self.assertNotIn(("switch", "admin", NEW_IMAGE), self.events)
        self.assertEqual(self.candidate["state"]["applied"], self.candidate["migrations"])

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
        self.candidate["state"]["storefront_attempted"] = True
        self.runner.write_text('IMAGE = "' + NEW_IMAGE + '"\n')
        with mock.patch.object(deploy, "inspect", return_value=self.runtime("api")), mock.patch.object(
                deploy, "storefront", return_value={"directory": "/old", "sha": BASE_SHA, "overlays": {}}):
            self.operation.rollback(deploy.identity(self.candidate))
        self.assertIn(("switch", "api", OLD_IMAGE), self.events)
        self.assertIn(("storefront", "/old", "old.service"), self.events)
        self.assertIn(OLD_IMAGE, self.runner.read_text())
        self.assertEqual(self.candidate["state"]["phase"], "rolled_back")

    def test_storefront_only_prepare_needs_no_platform_inputs_or_artifact(self):
        arguments = ["deploy", "prepare", "--candidate", str(self.directory / "candidate.json"),
                     "--task-id", "OLD-DEPLOY-P2-01", "--services", "storefront",
                     "--storefront-source", "/new", "--storefront-sha", SOURCE_SHA,
                     "--storefront-unit", "old.service"]
        observed = {"storefront": self.candidate["previous"]["storefront"]}
        self.command.side_effect = lambda command, **kwargs: SOURCE_SHA.encode() if "ls-remote" in command else b""
        with mock.patch.object(deploy.sys, "argv", arguments), mock.patch.object(deploy, "destination"), mock.patch.object(
                deploy, "storefront", return_value=self.candidate["storefront"]), mock.patch.object(
                deploy.Deployment, "snapshot", return_value=observed), mock.patch.object(
                deploy.Deployment, "artifact", side_effect=AssertionError("artifact must not be used")), mock.patch.object(
                deploy, "source", side_effect=AssertionError("platform source must not be used")), contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(deploy.main(), 0)
        prepared = deploy.save.call_args.args[1]
        self.assertNotIn("artifact", prepared)
        self.assertNotIn("platform_source", prepared)
        self.assertEqual(prepared["images"], {})
        self.assertEqual(prepared["previous"], observed)
        self.command.assert_any_call(["git", "-c", "safe.directory=/new", "-C", "/new", "merge-base", "--is-ancestor",
                                      SOURCE_SHA, SOURCE_SHA])

    def test_platform_prepare_still_requires_artifact_inputs(self):
        with mock.patch.object(deploy.sys, "argv", ["deploy", "prepare", "--candidate", "/private/candidate.json",
                "--task-id", "OLD-DEPLOY-P2-01", "--services", "api"]), mock.patch.object(
                deploy, "destination"), contextlib.redirect_stdout(io.StringIO()) as output:
            self.assertEqual(deploy.main(), 1)
        self.assertIn("platform_inputs_required", output.getvalue())
        self.command.assert_not_called()

    def test_storefront_rollback_reuses_old_unit_or_recreates_exact_old_definition(self):
        self.candidate["services"] = ["storefront"]
        for key in ("artifact", "platform_source", "current_platform_source", "current_platform_sha"):
            self.candidate.pop(key)
        self.candidate["images"] = {}
        old = self.candidate["previous"]["storefront"]
        for loaded in (True, False):
            with self.subTest(old_unit_available=loaded):
                self.events.clear()
                self.candidate["state"].update(phase="complete", storefront_attempted=True)
                self.command.side_effect = lambda command, **kwargs: (
                    b"unit listed" if "list-units" in command and (command[-1] == "new.service" or loaded)
                    else self.command_run(command, **kwargs))
                with mock.patch.object(deploy, "unit", return_value=old_unit()), mock.patch.object(
                        deploy, "storefront", return_value={key: old[key] for key in ("directory", "sha", "overlays")}), mock.patch.object(
                        self.operation, "start_storefront", side_effect=lambda selected, name:
                            deploy.Deployment.start_storefront(self.operation, selected, name)):
                    self.operation.rollback(deploy.identity(self.candidate))
                self.assertIn(["systemctl", "stop", "new.service"], self.events)
                if loaded:
                    self.assertIn(["systemctl", "start", "old.service"], self.events)
                    self.assertFalse(any(isinstance(event, list) and event[0] == "systemd-run" for event in self.events))
                else:
                    self.assertIn(["systemd-run", "--unit", "old.service", "--uid=luxe-pack-storefront-preview",
                        "--working-directory=/old", "--property=Restart=on-failure", "--property=KillMode=control-group",
                        "--property=TimeoutStopSec=1min 30s", "--setenv=NODE_ENV=development"] + startup("/old")["command"], self.events)
                self.assertNotIn("load", self.events)
                self.assertFalse(any(isinstance(event, list) and event[0] == "docker" for event in self.events))

    def test_storefront_rollback_rejects_changed_old_unit_before_stopping_new(self):
        self.candidate["services"] = ["storefront"]
        self.candidate["state"].update(phase="complete", storefront_attempted=True)
        old = self.candidate["previous"]["storefront"]
        self.command.return_value = b"unit listed"
        self.command.side_effect = None
        changed = old_unit() | {"Restart": "always"}
        with mock.patch.object(deploy, "unit", return_value=changed), mock.patch.object(
                deploy, "storefront", return_value={key: old[key] for key in ("directory", "sha", "overlays")}):
            with self.assertRaisesRegex(deploy.Hold, "rollback_storefront_unit_changed"):
                self.operation.rollback(deploy.identity(self.candidate))
        self.assertFalse(any("stop" in call.args[0] for call in self.command.call_args_list))

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
        (repository / "scripts").mkdir()
        (repository / "scripts/verify-vendored-artifacts.mjs").touch()
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
            if "sha256sum" in arguments:
                return deploy.digest(b"synthetic-custom-archive").encode() + b"  archive.dump"
            return self.command_run(arguments, **kwargs)
        self.command.side_effect = backup_run
        self.operation.runtime["api"]["Config"]["Env"] = ["DB_DATABASE=synthetic", "DB_USERNAME=synthetic_user"]
        database = {"Config": {"Labels": {"com.docker.compose.project": "mig061a-v2-preview",
                    "com.docker.compose.service": "postgres"},
                    "Env": ["POSTGRES_DB=synthetic", "POSTGRES_USER=synthetic_user"]}}
        with mock.patch.object(deploy, "inspect", return_value=database):
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
        with mock.patch.object(deploy.subprocess, "run", return_value=subprocess.CompletedProcess(
                ["git"], 0, stdout=b"", stderr=b"")) as child:
            REAL_RUN(["git", "diff", "HEAD", "--name-only"])
            self.assertEqual(child.call_args.kwargs["env"]["GIT_OPTIONAL_LOCKS"], "0")
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

    def test_expired_artifact_is_rejected_without_download_or_mutation(self):
        metadata = {"task_id": "ARTIFACT-201", "pull_request": 12, "source_commit": SOURCE_SHA,
                    "workflow_run_id": 45}
        self.candidate["artifact"].update(id=123, digest="sha256:" + "4" * 64)
        (self.directory / "download-metadata.json").write_text(json.dumps(metadata))
        with mock.patch.object(Path, "stat", return_value=SimpleNamespace(st_uid=0, st_mode=0o40700)), mock.patch.object(
                deploy, "decoded", return_value=[]):
            with self.assertRaisesRegex(deploy.Hold, "artifact_rejected_or_expired"):
                self.operation.artifact()
        self.assertEqual(self.events, [])

    def test_existing_selected_image_uses_canonical_verifier_without_load(self):
        verifier = mock.Mock()
        metadata = mock.Mock(return_value={"image_id": NEW_IMAGE})
        decompress = mock.Mock()
        helper = {"verify_artifact": verifier, "require_target_host": mock.Mock(),
                  "image_metadata": metadata, "decompress_archive": decompress}
        self.candidate["artifact"]["authority"].update(task_id="ARTIFACT-201", source_pr=12)
        image = {"Config": {"Env": []}}
        config = {"services": {"api": {"environment": {}, "networks": {"private": {}}}},
                  "networks": {"private": {"name": "old_v2_private"}}}
        with mock.patch.object(deploy.runpy, "run_path", return_value=helper), mock.patch.object(
                deploy, "decoded", side_effect=[[image], config]):
            deploy.Deployment.load(self.operation)
        verifier.assert_called_once()
        decompress.assert_not_called()
        self.command.assert_not_called()

    def test_switch_preserves_original_chain_and_private_health_before_egress(self):
        private, egress = "old_v2_private", "old_v2_api_egress"
        self.operation.runtime["api"]["NetworkSettings"] = {"Networks": {private: {}, egress: {}}}
        self.candidate["previous"]["api"].update(networks=sorted([private, egress]), compose=["docker", "compose", "-f", "/original.json"])
        ready = {"Image": NEW_IMAGE, "State": {"Health": {"Status": "healthy"}},
                 "NetworkSettings": {"Networks": {private: {}, egress: {}}}}
        config = {"services": {"api": {"environment": {}}}}
        with mock.patch.object(deploy, "decoded", side_effect=[config, [{"Config": {"Env": []}}]]), mock.patch.object(
                deploy, "inspect", side_effect=lambda name: self.events.append("ready") or ready):
            deploy.Deployment.switch(self.operation, "api", NEW_IMAGE)
        disconnect = ["docker", "network", "disconnect", egress, "/api"]
        connect = ["docker", "network", "connect", egress, "/api"]
        self.assertLess(self.events.index(disconnect), self.events.index("ready"))
        self.assertLess(self.events.index("ready"), self.events.index(connect))
        switch = next(event for event in self.events if isinstance(event, list) and "up" in event)
        self.assertEqual(switch[:4], ["docker", "compose", "-f", "/original.json"])
        self.assertIn("--no-build", switch)

    def test_post_migration_rollback_checks_current_guard_before_stop(self):
        self.migration()
        self.candidate["rollback_schema_compatible"] = True
        self.candidate["state"].update(phase="complete", migration_attempted=True, schema_verified=True)
        with mock.patch.object(deploy, "inspect", return_value=self.runtime("api")), mock.patch.object(
                self.operation, "guard", side_effect=deploy.Hold("canonical_guard_failed")):
            with self.assertRaises(deploy.Hold):
                self.operation.rollback(deploy.identity(self.candidate))
        self.assertEqual(self.events, [])

    def test_candidate_cannot_be_written_inside_another_repository(self):
        (self.directory / ".git").touch()
        with self.assertRaisesRegex(deploy.Hold, "outside_repository_required"):
            deploy.destination(self.directory / "candidate.json")

    def test_failure_before_storefront_stop_does_not_restart_untouched_storefront(self):
        self.candidate["services"] = ["api", "storefront"]
        self.candidate["state"]["phase"] = "switch"
        with mock.patch.object(deploy, "inspect", return_value=self.runtime("api")), mock.patch.object(
                deploy, "storefront", return_value={"directory": "/old", "sha": BASE_SHA, "overlays": {}}):
            self.operation.rollback(deploy.identity(self.candidate))
        self.assertFalse(any(isinstance(event, tuple) and event[0] == "storefront" for event in self.events))
        self.assertNotIn(["systemctl", "stop", "new.service"], self.events)

    def test_rollback_rejects_changed_runner_before_service_operations(self):
        self.candidate["state"]["phase"] = "switch"
        self.runner.write_text(self.runner.read_text() + "unreviewed_logic\n")
        with mock.patch.object(deploy, "inspect", return_value=self.runtime("api")):
            with self.assertRaisesRegex(deploy.Hold, "rollback_f2b_runner_changed"):
                self.operation.rollback(deploy.identity(self.candidate))
        self.assertEqual(self.events, [])


class GitMigrationRegressionTest(unittest.TestCase):
    def test_real_git_migrations_ignore_readme_and_prepare_without_migration(self):
        common = Path(REAL_RUN(["git", "-C", str(ROOT), "rev-parse", "--path-format=absolute", "--git-common-dir"]).decode().strip())
        for revision, count in (("b5c28015ef94cb80f6702a61b1237cacd91d9863", 87),
                                ("77723309fdd9f27039f7b5cbe804fa3e2a50fbc1", 92)):
            with self.subTest(revision=revision), tempfile.TemporaryDirectory() as temporary:
                checkout = Path(temporary)
                git = checkout / ".git"
                (git / "refs").mkdir(parents=True)
                (git / "objects").symlink_to(common / "objects", target_is_directory=True)
                (git / "HEAD").write_text(revision + "\n")
                names = REAL_RUN(["git", "-C", str(ROOT), "ls-tree", "-r", "--name-only", revision,
                                  deploy.MIGRATIONS, "scripts/db/v2_database.py"]).decode().splitlines()
                self.assertIn(deploy.MIGRATIONS + "/README.md", names)
                for relative in names:
                    selected = checkout / relative
                    selected.parent.mkdir(parents=True, exist_ok=True)
                    selected.write_bytes(deploy.source_bytes(ROOT, revision, relative))
                migrations = deploy.migration_files(checkout, revision)
                self.assertEqual(len(migrations), count)
                self.assertEqual(migrations, sorted(name for name in names if name.endswith(".php")))
                candidate = {"version": 1, "target": "old-test", "task_id": "OLD-DEPLOY-P2-01", "services": ["api"],
                    "migrations": [], "artifact": {"authority": {"source_sha": revision}},
                    "platform_source": str(checkout), "current_platform_source": str(checkout), "current_platform_sha": revision}
                operation = deploy.Deployment(candidate, checkout / "candidate.json")
                operation.runtime = {"api": {"Image": OLD_IMAGE, "Config": {"Env": [], "Labels": {
                    "com.docker.compose.service": "api"}}}}
                with mock.patch.object(operation, "artifact", return_value={"api": {"image_id": NEW_IMAGE}}), mock.patch.object(
                        operation, "snapshot", return_value={}), mock.patch.object(operation, "guard") as guard, mock.patch.object(
                        deploy, "compose", return_value=(["docker", "compose"], {})), mock.patch.object(
                        deploy, "decoded", side_effect=[{"services": {"api": {}}}, [{"Config": {"Env": []}}]]):
                    operation.preflight(preparing=True)
                    guard.assert_not_called()
                self.assertEqual(candidate["images"]["api"]["image_id"], NEW_IMAGE)
                changed = checkout / migrations[0]
                changed.write_bytes(changed.read_bytes() + b"\n")
                with self.assertRaisesRegex(deploy.Hold, "guard_source_modified"):
                    deploy.migration_files(checkout, revision)


if __name__ == "__main__":
    unittest.main()
