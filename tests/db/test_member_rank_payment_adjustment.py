import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest import mock


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("rank_adjustment_database", ROOT / "scripts/db/v2_database.py")
DATABASE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(DATABASE)
FILENAME = "2026_10_20_000094_add_v2_member_rank_payment_adjustments.php"


class MemberRankPaymentAdjustmentSchemaTest(unittest.TestCase):
    def test_inventory_and_forward_only_guard(self):
        inventory = DATABASE.EXPECTED_V2_SCHEMA_INVENTORY
        self.assertEqual(sorted(set(inventory)), inventory)
        self.assertIn("public.member_rank_payment_adjustments", inventory)
        with self.assertRaises(DATABASE.GuardFailure):
            DATABASE.validate_schema_inventory([name for name in inventory if name != "public.member_rank_payment_adjustments"])
        with tempfile.TemporaryDirectory() as directory:
            repository = Path(directory)
            migrations = repository / DATABASE.MIGRATION_PATH
            migrations.mkdir(parents=True)
            (migrations / FILENAME).touch()
            with mock.patch.object(DATABASE, "migration_rows", return_value=b"94") as ledger, mock.patch.object(
                DATABASE, "schema_dump", return_value=b"schema"
            ) as schema, mock.patch.object(DATABASE, "run", return_value=b"FORWARD_ONLY_REJECTION_PASS") as command:
                self.assertEqual(DATABASE.rollback_and_reapply_latest(["docker", "compose"], repository, True), "FORWARD_ONLY_REJECTION_PASS")
                self.assertEqual(ledger.call_count, 2)
                self.assertEqual(schema.call_count, 2)
                self.assertIn("Member rank payment adjustments require a forward-only migration.", command.call_args.args[0][-1])

    def test_adjustment_success_business_key_and_deferred_causality(self):
        source = (ROOT / DATABASE.MIGRATION_PATH / FILENAME).read_text()
        for fragment in [
            "payment_adjustments_one_success_per_payment",
            "origin_payment_id bigint UNIQUE REFERENCES payments",
            "applied_delta BETWEEN requested_delta AND 0",
            "v2_member_rank_adjustment_success_consistent",
            "DEFERRABLE INITIALLY DEFERRED",
            "v2_member_rank_payment_adjustments_immutable",
            "v2_member_rank_payment_adjustments_no_truncate",
            "canceled payment cannot be backfilled into rank points",
        ]:
            self.assertIn(fragment, source)
