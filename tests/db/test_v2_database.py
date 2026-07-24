import base64
import importlib.util
import os
from pathlib import Path
import tempfile
import unittest


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
            "V2_DB_HOST": "postgres",
            "V2_DB_PORT": "5432",
            "V2_DB_DATABASE": "oripa_v2_dev",
            "V2_DB_USERNAME": "oripa_v2_dev_user",
            "V2_DB_PASSWORD": "a" * 32,
            "V2_REDIS_HOST": "redis",
            "V2_REDIS_PORT": "6379",
            "V2_REDIS_PASSWORD": "b" * 32,
        }

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

    def test_point_schema_inventory_is_explicit_and_payment_reservation_is_deferred(self):
        self.assertIn("public.wallets", v2_database.EXPECTED_V2_SCHEMA_INVENTORY)
        self.assertIn(
            "public.point_ledger_entries",
            v2_database.EXPECTED_V2_SCHEMA_INVENTORY,
        )
        self.assertIn(
            "public.idempotency_records",
            v2_database.EXPECTED_V2_SCHEMA_INVENTORY,
        )
        self.assertNotIn(
            "public.point_lot_reservations",
            v2_database.EXPECTED_V2_SCHEMA_INVENTORY,
        )
        self.assertNotIn(
            "public.payment_adjustments",
            v2_database.EXPECTED_V2_SCHEMA_INVENTORY,
        )


if __name__ == "__main__":
    unittest.main()
