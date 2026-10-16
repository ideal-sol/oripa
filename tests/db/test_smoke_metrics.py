import argparse
from contextlib import ExitStack
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest import mock

from scripts.db import v2_database as database


class SmokeMetricsTest(unittest.TestCase):
    def setUp(self):
        self.stack = ExitStack()
        self.addCleanup(self.stack.close)
        self.directory = Path(self.stack.enter_context(tempfile.TemporaryDirectory()))
        self.stack.enter_context(mock.patch("sys.stdout", new_callable=io.StringIO))
        self.stack.enter_context(mock.patch.object(database, "migration_checksum", return_value=(86, "digest")))
        self.run = self.stack.enter_context(mock.patch.object(database, "run", return_value=b"a" * 40))
        self.metrics = database.SmokeMetrics(self.directory)

    def test_monotonic_duration_and_failed_outcome_without_exception_details(self):
        with mock.patch.object(database.time, "monotonic", side_effect=[10.0, 12.5]):
            with self.assertRaises(ValueError):
                self.metrics.call("identity", mock.Mock(side_effect=ValueError("private value")))
        self.assertEqual(self.metrics.phases, [{"name": "identity", "duration_seconds": 2.5, "outcome": "FAIL"}])
        self.metrics.write(self.directory / "timing.json", "FAIL")
        self.assertNotIn("private value", (self.directory / "timing.json").read_text())

    def test_only_numeric_ci_metadata_is_exported(self):
        with mock.patch.dict(database.os.environ, {"GITHUB_RUN_ID": "untrusted value", "GITHUB_RUN_ATTEMPT": "2"}):
            metrics = database.SmokeMetrics(self.directory)
        self.assertIsNone(metrics.metadata["workflow_run_id"])
        self.assertEqual(metrics.metadata["workflow_run_attempt"], "2")

    def test_cache_observation_is_not_reused_as_build_authority(self):
        self.metrics.observe(["docker", "compose", "build", "api"], b"", b"#1 CACHED\n#2 DONE 1.0s\n")
        self.metrics.observe(["docker", "compose", "build", "api"], b"", b"#1 DONE 2.0s\n")
        self.assertEqual([record["classification"] for record in self.metrics.build_cache], ["warm", "cold-or-unreported"])
        self.assertEqual(self.metrics.build_cache[0]["cached_steps"], 1)

    def test_build_cache_progress_on_stdout_is_also_recorded(self):
        self.metrics.observe(["docker", "compose", "build", "api"], b"#1 CACHED\n#2 DONE 1.0s\n", b"")
        self.assertEqual(self.metrics.build_cache[0]["cached_steps"], 1)

    def test_bytecode_probe_enables_only_process_local_validated_cache(self):
        for available in (b"loaded", b"available"):
            with self.subTest(available=available):
                self.run.reset_mock()
                self.run.side_effect = [available, b"BYTECODE_MISS_HIT_INVALIDATION_PASS"]
                result = database.identity_bytecode_options(["docker", "compose"], self.directory)
                self.assertEqual(result["status"], "PROCESS_LOCAL_MISS_HIT_INVALIDATION_PASS")
                for setting in ("opcache.file_cache=", "opcache.validate_timestamps=1", "opcache.revalidate_freq=0", "opcache.optimization_level=0", "opcache.jit=disable"):
                    self.assertIn(setting, result["options"])
                self.assertEqual("zend_extension=opcache" in result["options"], available == b"available")
                self.assertIn("BYTECODE_MISS_HIT_INVALIDATION_PASS", self.run.call_args.args[0][-1])

    def test_unavailable_or_invalid_bytecode_cache_falls_back_to_normal_execution(self):
        for responses in ([b"unavailable"], [b"unknown"], [b"available", b"invalid"], [b"available", database.GuardFailure("probe failure")]):
            with self.subTest(responses=responses):
                self.run.side_effect = responses
                self.assertEqual(database.identity_bytecode_options([], self.directory)["options"], [])

    def test_identity_command_keeps_suite_and_memory_limit_and_never_retries(self):
        options = ["-d", "opcache.enable_cli=1"]
        self.run.reset_mock()
        database.run_identity_tests([], self.directory, False, php_options=options)
        command = self.run.call_args.args[0]
        self.assertEqual(command, ["exec", "-T", "api", "php", "-d", "memory_limit=512M", *options, "vendor/bin/phpunit", "--configuration", "phpunit.v2.xml"])
        self.run.reset_mock()
        self.run.side_effect = database.GuardFailure("test failure")
        with self.assertRaises(database.GuardFailure):
            database.run_identity_tests([], self.directory, False, php_options=options)
        self.run.assert_called_once()

    def test_persistent_identity_command_does_not_enable_bytecode_cache(self):
        database.run_identity_tests([], self.directory, True)
        self.assertEqual(self.run.call_args.args[0], ["run", "--rm", "--no-deps", "api", "php", "-d", "memory_limit=512M", "vendor/bin/phpunit", "--configuration", "phpunit.v2.xml"])

    def test_phpunit_counts_exclude_raw_test_output(self):
        self.metrics.observe(["vendor/bin/phpunit"], b"private fixture\nOK (10 tests, 32 assertions)\n", b"")
        self.assertEqual(self.metrics.tests[0]["counts"], {"tests": 10, "assertions": 32})
        self.metrics.observe(["vendor/bin/phpunit"], b"Tests: 20, Assertions: 80, Skipped: 3.\n", b"")
        self.assertEqual(self.metrics.tests[1]["counts"]["skipped"], 3)
        self.assertNotIn("private fixture", json.dumps(self.metrics.tests))

    def smoke(self, failure=None, mismatch=None):
        args = argparse.Namespace(repository=str(self.directory), compose_file="docker-compose.v2.yml", evidence_dir=str(self.directory / "evidence"), project_prefix="mig054-v2-metrics-test", migration_path=database.MIGRATION_PATH, task_id=None, purpose=None, identity_bytecode_cache=True)
        functions = {
            "create_env_file": None, "validate": {}, "migrate_fresh": None,
            "rollback_and_reapply_latest": "FORWARD_ONLY_REJECTION_PASS", "migration_status": None,
            "run_identity_tests": None, "run_draw_load_tests": None, "run_qa_draw_load_tests": None,
            "run_reporting_performance_tests": None, "run_content_contact_performance_tests": {},
            "schema_inventory": database.EXPECTED_V2_SCHEMA_INVENTORY, "schema_dump": b"schema",
            "migration_rows": b"ledger", "backup_database": b"backup", "restore_database": None,
            "cleanup_project": None,
            "identity_bytecode_options": {"status": "UNAVAILABLE_NORMAL_EXECUTION", "options": []},
        }
        mocks = {}
        for name, value in functions.items():
            mocks[name] = self.stack.enter_context(mock.patch.object(database, name, return_value=value))
        if failure:
            mocks[failure].side_effect = RuntimeError("injected")
        if mismatch:
            mocks[mismatch].side_effect = [functions[mismatch], b"different" if mismatch != "schema_inventory" else []]
        return args, mocks

    def test_full_sequence_preserves_two_fresh_runs_and_build_on_cache_miss(self):
        args, mocks = self.smoke()
        result = database.run_smoke(args)
        self.assertEqual(result["resource_cleanup"], "PASS")
        self.assertEqual(mocks["migrate_fresh"].call_count, 2)
        self.assertEqual(mocks["cleanup_project"].call_count, 2)
        commands = [call.args[0] for call in self.run.call_args_list]
        self.assertTrue(any(command[-3:] == ["build", "api", "admin"] for command in commands))
        self.assertTrue(any("--no-build" in command for command in commands))
        evidence = json.loads((Path(args.evidence_dir) / "smoke-timing.json").read_text())
        self.assertEqual(evidence["outcome"], "PASS")
        self.assertEqual(evidence["cleanup_outcome"], "PASS")

    def test_every_required_verification_runs_before_success(self):
        args, mocks = self.smoke()
        database.run_smoke(args)
        for name in (
            "rollback_and_reapply_latest", "migration_status", "run_identity_tests",
            "run_draw_load_tests", "run_qa_draw_load_tests", "run_reporting_performance_tests",
            "run_content_contact_performance_tests", "backup_database", "restore_database",
        ):
            with self.subTest(name=name):
                mocks[name].assert_called_once()
        for name in ("schema_inventory", "schema_dump", "migration_rows"):
            self.assertEqual(mocks[name].call_count, 2)
        commands = [call.args[0] for call in self.run.call_args_list]
        self.assertEqual(sum("http://localhost:8000/api/health" in command for command in commands), 1)
        self.assertEqual(sum("http://127.0.0.1:3000/api/health" in command for command in commands), 1)
        phases = json.loads((Path(args.evidence_dir) / "smoke-timing.json").read_text())["phases"]
        self.assertEqual(next(phase for phase in phases if phase["name"] == "exact_restore_comparison")["outcome"], "PASS")

    def test_smoke_passes_verified_options_only_to_identity_and_records_mode(self):
        args, mocks = self.smoke()
        options = ["-d", "opcache.enable_cli=1"]
        mocks["identity_bytecode_options"].return_value = {"status": "PROCESS_LOCAL_MISS_HIT_INVALIDATION_PASS", "options": options}
        database.run_smoke(args)
        self.assertEqual(mocks["run_identity_tests"].call_args.kwargs["php_options"], options)
        for name in ("run_draw_load_tests", "run_qa_draw_load_tests", "run_reporting_performance_tests", "run_content_contact_performance_tests"):
            self.assertEqual(mocks[name].call_args.kwargs, {})
        evidence = json.loads((Path(args.evidence_dir) / "smoke-timing.json").read_text())
        self.assertEqual(evidence["identity_bytecode_cache"], "PROCESS_LOCAL_MISS_HIT_INVALIDATION_PASS")

    def test_explicit_uncached_baseline_still_runs_every_test(self):
        args, mocks = self.smoke()
        args.identity_bytecode_cache = False
        database.run_smoke(args)
        mocks["identity_bytecode_options"].assert_not_called()
        self.assertEqual(mocks["run_identity_tests"].call_args.kwargs["php_options"], [])
        for name in ("run_draw_load_tests", "run_qa_draw_load_tests", "run_reporting_performance_tests", "run_content_contact_performance_tests"):
            mocks[name].assert_called_once()

    def test_build_failure_is_not_retried_or_replaced_by_an_old_image(self):
        args, mocks = self.smoke()

        def command_result(command, **keywords):
            if "build" in command:
                raise database.GuardFailure("build failed")
            return b"a" * 40

        self.run.side_effect = command_result
        with self.assertRaises(database.GuardFailure):
            database.run_smoke(args)
        commands = [call.args[0] for call in self.run.call_args_list]
        self.assertEqual(sum("build" in command for command in commands), 1)
        self.assertFalse(any("up" in command for command in commands))
        mocks["run_identity_tests"].assert_not_called()

    def test_restore_failure_cleans_both_projects(self):
        args, mocks = self.smoke(failure="restore_database")
        with self.assertRaises(RuntimeError):
            database.run_smoke(args)
        self.assertEqual(mocks["cleanup_project"].call_count, 2)

    def test_validation_failure_records_failure_without_starting_resources(self):
        args, mocks = self.smoke(failure="validate")
        with self.assertRaises(RuntimeError):
            database.run_smoke(args)
        mocks["cleanup_project"].assert_not_called()
        self.assertEqual(json.loads((Path(args.evidence_dir) / "smoke-timing.json").read_text())["outcome"], "FAIL")

    def test_failure_mid_suite_still_cleans_source_and_records_failure(self):
        args, mocks = self.smoke(failure="run_draw_load_tests")
        with self.assertRaises(RuntimeError):
            database.run_smoke(args)
        mocks["cleanup_project"].assert_called_once()
        evidence = json.loads((Path(args.evidence_dir) / "smoke-timing.json").read_text())
        self.assertEqual(evidence["outcome"], "FAIL")
        self.assertEqual(evidence["cleanup_outcome"], "PASS")

    def test_restore_cleanup_failure_does_not_prevent_source_cleanup(self):
        args, mocks = self.smoke(failure="cleanup_project")
        with self.assertRaises(RuntimeError):
            database.run_smoke(args)
        self.assertEqual(mocks["cleanup_project"].call_count, 2)
        evidence = json.loads((Path(args.evidence_dir) / "smoke-timing.json").read_text())
        self.assertEqual(evidence["outcome"], "FAIL")
        self.assertEqual(evidence["cleanup_outcome"], "FAIL_OR_NOT_STARTED")

    def test_schema_inventory_and_migration_mismatches_fail_and_clean_both(self):
        for mismatch in ("schema_inventory", "schema_dump", "migration_rows"):
            with self.subTest(mismatch=mismatch):
                args, mocks = self.smoke(mismatch=mismatch)
                with self.assertRaises(database.GuardFailure):
                    database.run_smoke(args)
                self.assertEqual(mocks["cleanup_project"].call_count, 2)

    def test_keyboard_interrupt_propagates_after_cleanup_and_evidence(self):
        args, mocks = self.smoke()
        mocks["run_identity_tests"].side_effect = KeyboardInterrupt()
        with self.assertRaises(KeyboardInterrupt):
            database.run_smoke(args)
        mocks["cleanup_project"].assert_called_once()
        self.assertEqual(json.loads((Path(args.evidence_dir) / "smoke-timing.json").read_text())["outcome"], "FAIL")


if __name__ == "__main__":
    unittest.main()
