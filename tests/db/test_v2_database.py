import base64
import argparse
import importlib.util
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock


MODULE_PATH = (
    Path(__file__).resolve().parents[2] / "scripts" / "db" / "v2_database.py"
)
SPEC = importlib.util.spec_from_file_location("v2_database", MODULE_PATH)
v2_database = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(v2_database)


class V2DatabaseGuardTest(unittest.TestCase):
    def setUp(self):
        self.repository = Path(tempfile.mkdtemp())
        migration_root = (
            self.repository / "apps" / "api" / "database" / "migrations-v2"
        )
        migration_root.mkdir(parents=True)
        self.addCleanup(
            lambda: __import__("shutil").rmtree(self.repository, ignore_errors=True)
        )
        self.values = {
            "COMPOSE_PROJECT_NAME": "oripa-v2-dev",
            "V2_APP_ENV": "local",
            "V2_APP_KEY": "base64:"
            + base64.b64encode(bytes(range(32))).decode(),
            "V2_AUDIT_HMAC_KEY": "base64:"
            + base64.b64encode(bytes(reversed(range(32)))).decode(),
            "V2_PII_CORRELATION_KEY": "base64:"
            + base64.b64encode(bytes(range(31, -1, -1))).decode(),
            "V2_DB_HOST": "postgres",
            "V2_DB_PORT": "5432",
            "V2_DB_DATABASE": "oripa_v2_dev",
            "V2_DB_USERNAME": "oripa_v2_dev_user",
            "V2_DB_PASSWORD": "a" * 32,
            "V2_REDIS_HOST": "redis",
            "V2_REDIS_PORT": "6379",
            "V2_REDIS_PASSWORD": "b" * 32,
        }

    def valid_compose_config(self):
        project = self.values["COMPOSE_PROJECT_NAME"]
        return {
            "services": {
                "api": {"networks": {"v2_private": {}}},
                "admin": {"networks": {"v2_private": {}}},
                "postgres": {
                    "image": "postgres:17-alpine",
                    "networks": {"v2_private": {}},
                },
                "redis": {
                    "image": "redis:7-alpine",
                    "networks": {"v2_private": {}},
                },
            },
            "networks": {
                "v2_private": {
                    "name": f"{project}_v2_private",
                    "internal": True,
                    "ipam": {"config": [{"subnet": "192.168.61.0/24"}]},
                },
            },
            "volumes": {
                "v2_api_assets": {"name": f"{project}_v2_api_assets"},
                "v2_postgres": {"name": f"{project}_v2_postgres"},
                "v2_redis": {"name": f"{project}_v2_redis"},
            },
        }

    def validate_compose_config(self, config):
        compose_file = self.repository / "docker-compose.v2.yml"
        compose_file.write_text("services: {}\n", encoding="utf-8")
        env_file = self.repository / "runtime.env"
        with mock.patch.object(
            v2_database,
            "run",
            return_value=__import__("json").dumps(config).encode(),
        ):
            return v2_database.validate_compose(
                self.repository,
                compose_file,
                env_file,
                self.values["COMPOSE_PROJECT_NAME"],
                self.values,
            )

    def test_valid_boundary_passes(self):
        v2_database.validate_project("oripa-v2-dev")
        v2_database.validate_project("mig041-v2-123456-1-source")
        v2_database.validate_values(self.values, "oripa-v2-dev")
        path = v2_database.validate_migration_path(
            self.repository, "apps/api/database/migrations-v2"
        )
        self.assertEqual(
            path, self.repository / "apps" / "api" / "database" / "migrations-v2"
        )
        self.assertEqual(
            self.valid_compose_config(),
            self.validate_compose_config(self.valid_compose_config()),
        )

    def test_private_network_must_remain_internal(self):
        config = self.valid_compose_config()
        config["networks"]["v2_private"]["internal"] = False
        with self.assertRaisesRegex(v2_database.GuardFailure, "Network isolation"):
            self.validate_compose_config(config)

    def test_database_create_phase_must_remain_private_only(self):
        config = self.valid_compose_config()
        config["services"]["postgres"]["networks"]["v2_api_egress"] = {}
        with self.assertRaisesRegex(v2_database.GuardFailure, "Network isolation"):
            self.validate_compose_config(config)

    def test_api_create_phase_must_remain_private_only(self):
        config = self.valid_compose_config()
        config["services"]["api"]["networks"]["v2_api_egress"] = {}
        with self.assertRaisesRegex(v2_database.GuardFailure, "Network isolation"):
            self.validate_compose_config(config)

    def test_unused_egress_network_must_not_enter_resolved_create_config(self):
        config = self.valid_compose_config()
        config["networks"]["v2_api_egress"] = {
            "name": f"{self.values['COMPOSE_PROJECT_NAME']}_v2_api_egress",
            "driver": "bridge",
        }
        with self.assertRaisesRegex(v2_database.GuardFailure, "Network isolation"):
            self.validate_compose_config(config)

    def test_admin_create_phase_must_remain_private_only(self):
        config = self.valid_compose_config()
        config["services"]["admin"]["networks"]["v2_api_egress"] = {}
        with self.assertRaisesRegex(v2_database.GuardFailure, "Network isolation"):
            self.validate_compose_config(config)

    def test_unapproved_task_project_is_rejected(self):
        with self.assertRaisesRegex(v2_database.GuardFailure, "allowlist"):
            v2_database.validate_project("task-v2-unscoped-source")

    def test_production_environment_is_rejected(self):
        self.values["V2_APP_ENV"] = "production"
        with self.assertRaisesRegex(v2_database.GuardFailure, "Production"):
            v2_database.validate_values(self.values, "oripa-v2-dev")

    def test_v1_database_name_is_rejected(self):
        self.values["V2_DB_DATABASE"] = "oripa"
        with self.assertRaisesRegex(v2_database.GuardFailure, "V2 namespace"):
            v2_database.validate_values(self.values, "oripa-v2-dev")

    def test_v1_migration_path_is_rejected(self):
        with self.assertRaisesRegex(v2_database.GuardFailure, "V1 Migration"):
            v2_database.validate_migration_path(
                self.repository, "apps/api/database/migrations"
            )

    def test_missing_migration_path_is_rejected(self):
        with self.assertRaisesRegex(v2_database.GuardFailure, "required"):
            v2_database.validate_migration_path(self.repository, None)

    def test_v1_project_is_rejected(self):
        with self.assertRaisesRegex(v2_database.GuardFailure, "V1 Compose"):
            v2_database.validate_project("oripa")

    def test_unexpected_database_host_is_rejected(self):
        self.values["V2_DB_HOST"] = "127.0.0.1"
        with self.assertRaisesRegex(v2_database.GuardFailure, "Unexpected Database"):
            v2_database.validate_values(self.values, "oripa-v2-dev")

    def test_short_audit_hmac_key_is_rejected(self):
        self.values["V2_AUDIT_HMAC_KEY"] = "base64:" + base64.b64encode(b"short").decode()
        with self.assertRaisesRegex(v2_database.GuardFailure, "Audit HMAC key"):
            v2_database.validate_values(self.values, "oripa-v2-dev")

    def test_short_pii_correlation_key_is_rejected(self):
        self.values["V2_PII_CORRELATION_KEY"] = (
            "base64:" + base64.b64encode(b"short").decode()
        )
        with self.assertRaisesRegex(v2_database.GuardFailure, "PII correlation key"):
            v2_database.validate_values(self.values, "oripa-v2-dev")

    def test_shared_redis_host_is_rejected(self):
        self.values["V2_REDIS_HOST"] = "shared-redis"
        with self.assertRaisesRegex(v2_database.GuardFailure, "Unexpected Database"):
            v2_database.validate_values(self.values, "oripa-v2-dev")

    def test_group_readable_env_file_is_rejected(self):
        env_file = self.repository / "dev.env"
        env_file.write_text(
            "".join(f"{key}={value}\n" for key, value in self.values.items()),
            encoding="utf-8",
        )
        os.chmod(env_file, 0o640)
        with self.assertRaisesRegex(v2_database.GuardFailure, "group or other"):
            v2_database.parse_env_file(env_file)

    def test_symlink_env_file_is_rejected(self):
        target = self.repository / "target.env"
        target.write_text("not-used\n", encoding="utf-8")
        os.chmod(target, 0o600)
        link = self.repository / "dev.env"
        link.symlink_to(target)
        with self.assertRaisesRegex(v2_database.GuardFailure, "symlink"):
            v2_database.parse_env_file(link)

    def test_production_credential_path_is_rejected(self):
        directory = self.repository / "production"
        directory.mkdir()
        env_file = directory / "db.env"
        env_file.write_text(
            "".join(f"{key}={value}\n" for key, value in self.values.items()),
            encoding="utf-8",
        )
        os.chmod(env_file, 0o600)
        with self.assertRaisesRegex(v2_database.GuardFailure, "Production Credential"):
            v2_database.parse_env_file(env_file)

    def test_unexpected_credential_field_is_rejected(self):
        env_file = self.repository / "dev.env"
        env_file.write_text(
            "".join(f"{key}={value}\n" for key, value in self.values.items())
            + "DATABASE_URL=not-allowed\n",
            encoding="utf-8",
        )
        os.chmod(env_file, 0o600)
        with self.assertRaisesRegex(v2_database.GuardFailure, "unexpected credential"):
            v2_database.parse_env_file(env_file)

    def test_schema_dump_nonce_is_normalized(self):
        first = b"header\n\\restrict first\nbody\n\\unrestrict first\n"
        second = b"header\n\\restrict second\nbody\n\\unrestrict second\n"
        self.assertEqual(
            v2_database.normalize_schema_dump(first),
            v2_database.normalize_schema_dump(second),
        )

    def test_payment_schema_inventory_is_explicit(self):
        self.assertIn(
            "public.admin_authentication_policy",
            v2_database.EXPECTED_V2_SCHEMA_INVENTORY,
        )
        self.assertIn("public.wallets", v2_database.EXPECTED_V2_SCHEMA_INVENTORY)
        self.assertIn(
            "public.point_ledger_entries",
            v2_database.EXPECTED_V2_SCHEMA_INVENTORY,
        )
        self.assertIn(
            "public.idempotency_records",
            v2_database.EXPECTED_V2_SCHEMA_INVENTORY,
        )
        self.assertIn(
            "public.point_lot_reservations",
            v2_database.EXPECTED_V2_SCHEMA_INVENTORY,
        )
        self.assertIn(
            "public.payment_adjustments",
            v2_database.EXPECTED_V2_SCHEMA_INVENTORY,
        )
        self.assertIn(
            "public.payment_limited_bonus_snapshots",
            v2_database.EXPECTED_V2_SCHEMA_INVENTORY,
        )
        self.assertIn(
            "public.payment_provider_events",
            v2_database.EXPECTED_V2_SCHEMA_INVENTORY,
        )
        self.assertIn(
            "public.point_purchase_plan_limited_bonus_campaigns",
            v2_database.EXPECTED_V2_SCHEMA_INVENTORY,
        )
        self.assertEqual(
            sorted(v2_database.EXPECTED_V2_SCHEMA_INVENTORY),
            v2_database.EXPECTED_V2_SCHEMA_INVENTORY,
        )

    def test_mail_template_schema_inventory_is_explicit(self):
        for table in ("public.mail_deliveries", "public.mail_templates"):
            self.assertIn(table, v2_database.EXPECTED_V2_SCHEMA_INVENTORY)
        self.assertEqual(
            sorted(v2_database.EXPECTED_V2_SCHEMA_INVENTORY),
            v2_database.EXPECTED_V2_SCHEMA_INVENTORY,
        )

    def test_catalog_schema_inventory_is_explicit(self):
        for table in (
            "public.catalog_categories",
            "public.catalog_gacha_publish_schedules",
            "public.catalog_tags",
            "public.catalog_ranks",
            "public.catalog_presentation_assets",
            "public.catalog_prizes",
            "public.catalog_gachas",
            "public.catalog_gacha_version_ranks",
            "public.catalog_gacha_version_tags",
            "public.catalog_gacha_versions",
            "public.catalog_probability_versions",
            "public.catalog_probability_stages",
            "public.catalog_probability_entries",
            "public.catalog_minimum_guarantees",
            "public.catalog_import_runs",
        ):
            self.assertIn(table, v2_database.EXPECTED_V2_SCHEMA_INVENTORY)

    def test_operational_inventory_schema_inventory_is_explicit(self):
        for table in (
            "public.prize_inventories",
            "public.prize_inventory_adjustments",
        ):
            self.assertIn(table, v2_database.EXPECTED_V2_SCHEMA_INVENTORY)
        self.assertEqual(
            sorted(v2_database.EXPECTED_V2_SCHEMA_INVENTORY),
            v2_database.EXPECTED_V2_SCHEMA_INVENTORY,
        )

    def test_referral_point_schema_inventory_is_explicit(self):
        for table in (
            "public.referral_point_settings",
            "public.user_referrals",
        ):
            self.assertIn(table, v2_database.EXPECTED_V2_SCHEMA_INVENTORY)

    def test_user_tag_schema_inventory_is_explicit_and_sorted(self):
        for table in (
            "public.user_tag_assignments",
            "public.user_tags",
        ):
            self.assertIn(table, v2_database.EXPECTED_V2_SCHEMA_INVENTORY)
        self.assertEqual(
            sorted(v2_database.EXPECTED_V2_SCHEMA_INVENTORY),
            v2_database.EXPECTED_V2_SCHEMA_INVENTORY,
        )

    def test_banner_management_schema_inventory_is_explicit(self):
        self.assertIn(
            "public.content_banner_categories",
            v2_database.EXPECTED_V2_SCHEMA_INVENTORY,
        )
        self.assertEqual(
            sorted(v2_database.EXPECTED_V2_SCHEMA_INVENTORY),
            v2_database.EXPECTED_V2_SCHEMA_INVENTORY,
        )

    def test_page_management_schema_inventory_is_explicit(self):
        self.assertIn(
            "public.content_page_categories",
            v2_database.EXPECTED_V2_SCHEMA_INVENTORY,
        )
        self.assertEqual(
            sorted(v2_database.EXPECTED_V2_SCHEMA_INVENTORY),
            v2_database.EXPECTED_V2_SCHEMA_INVENTORY,
        )

    def test_task_database_marker_and_actual_target_are_required(self):
        values = dict(self.values)
        values["COMPOSE_PROJECT_NAME"] = "mig060k-v2-scheduled-publish"
        values["V2_DB_DATABASE"] = "oripa_v2_mig060k_task"
        values["V2_DB_USERNAME"] = "oripa_v2_mig060k_task_user"
        with mock.patch.object(
            v2_database,
            "compose_exec",
            side_effect=[b"oripa_v2_mig060k_task|public|5432\n", b"f\n"],
        ):
            evidence = v2_database.assert_database_target(
                ["docker", "compose"],
                self.repository,
                values,
                "MIG-060K",
                "v2-task-ephemeral",
                "empty",
            )
        self.assertEqual("PASS", evidence["status"])
        self.assertEqual("public", evidence["schema"])

    def test_task_database_name_mismatch_fails_closed(self):
        values = dict(self.values)
        values["COMPOSE_PROJECT_NAME"] = "mig060k-v2-scheduled-publish"
        values["V2_DB_DATABASE"] = "oripa_v2_other_task"
        values["V2_DB_USERNAME"] = "oripa_v2_other_task_user"
        with self.assertRaisesRegex(v2_database.GuardFailure, "Task ID"):
            v2_database.assert_database_target(
                ["docker", "compose"],
                self.repository,
                values,
                "MIG-060K",
                "v2-task-ephemeral",
                "empty",
            )

    def test_task_and_purpose_markers_must_be_paired(self):
        with self.assertRaisesRegex(v2_database.GuardFailure, "together"):
            v2_database.require_database_markers(
                argparse.Namespace(task_id="MIG-060K", purpose=None)
            )

    def test_prize_shipping_schema_inventory_is_explicit(self):
        for table in (
            "public.prize_exchange_requests",
            "public.prize_exchange_request_items",
            "public.shipping_addresses",
            "public.shipping_requests",
            "public.shipping_request_items",
            "public.shipping_request_status_histories",
            "public.user_prize_status_histories",
        ):
            self.assertIn(table, v2_database.EXPECTED_V2_SCHEMA_INVENTORY)

    def test_qa_draw_schema_inventory_and_load_runner_are_explicit(self):
        for table in (
            "public.qa_test_user_modes",
            "public.qa_draw_plans",
            "public.qa_draw_plan_assignments",
            "public.qa_draw_plan_items",
            "public.qa_draw_executions",
            "public.qa_gacha_guarantee_assignments",
        ):
            self.assertIn(table, v2_database.EXPECTED_V2_SCHEMA_INVENTORY)
        source = MODULE_PATH.read_text(encoding="utf-8")
        self.assertIn("run_qa_draw_load_tests", source)
        self.assertIn("V2_QA_DRAW_LOAD_TEST", source)

    def test_reporting_schema_inventory_and_performance_runner_are_explicit(self):
        self.assertIn(
            "public.export_jobs",
            v2_database.EXPECTED_V2_SCHEMA_INVENTORY,
        )
        source = MODULE_PATH.read_text(encoding="utf-8")
        self.assertIn("run_reporting_performance_tests", source)
        self.assertIn("V2_REPORTING_PERFORMANCE_TEST", source)

    def test_content_contact_schema_inventory_is_explicit(self):
        for table in (
            "public.content_banners",
            "public.content_notices",
            "public.content_static_pages",
            "public.content_versions",
            "public.content_version_assets",
            "public.contact_inquiries",
            "public.contact_status_histories",
            "public.contact_internal_notes",
            "public.contact_reply_requests",
        ):
            self.assertIn(table, v2_database.EXPECTED_V2_SCHEMA_INVENTORY)
        source = MODULE_PATH.read_text(encoding="utf-8")
        self.assertIn("run_content_contact_performance_tests", source)
        self.assertIn("V2_CONTENT_CONTACT_PERFORMANCE_TEST", source)
        self.assertIn("rollback_and_reapply_latest", source)
        self.assertIn("migrate:rollback", source)
        self.assertIn("--step=1", source)

    def test_identity_recovery_schema_inventory_is_explicit(self):
        for table in (
            "public.password_reset_tokens",
            "public.sms_verification_challenges",
            "public.user_phone_numbers",
        ):
            self.assertIn(table, v2_database.EXPECTED_V2_SCHEMA_INVENTORY)

    def test_line_messaging_schema_inventory_is_explicit_and_sorted(self):
        for table in (
            "public.line_friendships",
            "public.line_messaging_settings",
            "public.line_pending_follows",
            "public.line_webhook_events",
        ):
            self.assertIn(table, v2_database.EXPECTED_V2_SCHEMA_INVENTORY)
        self.assertEqual(
            sorted(v2_database.EXPECTED_V2_SCHEMA_INVENTORY),
            v2_database.EXPECTED_V2_SCHEMA_INVENTORY,
        )

    def test_content_contact_performance_marker_is_required(self):
        marker = b"\nMIG056_CONTENT_CONTACT_PERFORMANCE={\"p95_ms\":12.5}\n"
        with mock.patch.object(v2_database, "run", return_value=marker):
            self.assertEqual(
                {"p95_ms": 12.5},
                v2_database.run_content_contact_performance_tests(
                    ["docker", "compose"], MODULE_PATH.parents[2]
                ),
            )

    def test_compose_up_failure_reports_safe_diagnostic_and_redacts_secrets(self):
        failure = subprocess.CalledProcessError(
            1,
            ["docker", "compose", "up"],
            output=b"container api is unhealthy\n",
            stderr=b"error: health check failed\npassword=do-not-report\n",
        )
        with mock.patch.object(
            v2_database.subprocess, "run", side_effect=failure
        ) as runner:
            with self.assertRaisesRegex(
                v2_database.GuardFailure,
                "during up.*health check failed",
            ) as raised:
                v2_database.run(
                    ["docker", "compose", "up"],
                    cwd=MODULE_PATH.parents[2],
                )
        self.assertNotIn("do-not-report", str(raised.exception))
        self.assertEqual(
            "false",
            runner.call_args.kwargs["env"]["COMPOSE_BAKE"],
        )
        with mock.patch.object(v2_database, "run", return_value=b"no metrics"):
            with self.assertRaisesRegex(
                v2_database.GuardFailure, "evidence marker is missing"
            ):
                v2_database.run_content_contact_performance_tests(
                    ["docker", "compose"], MODULE_PATH.parents[2]
                )


if __name__ == "__main__":
    unittest.main()
