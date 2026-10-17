import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest import mock


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('rank_purchase_database', ROOT / 'scripts/db/v2_database.py')
DATABASE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(DATABASE)


class MemberRankPurchaseSchemaTest(unittest.TestCase):
    def test_inventory_and_forward_only_rejection_preserve_schema_and_ledger(self):
        filename = '2026_10_17_000092_create_v2_member_rank_purchase_bonuses.php'
        inventory = DATABASE.EXPECTED_V2_SCHEMA_INVENTORY
        self.assertEqual(sorted(set(inventory)), inventory)
        self.assertIn('public.member_rank_purchase_bonuses', inventory)
        with self.assertRaises(DATABASE.GuardFailure):
            DATABASE.validate_schema_inventory([name for name in inventory if name != 'public.member_rank_purchase_bonuses'])
        with tempfile.TemporaryDirectory() as directory:
            repository = Path(directory)
            migration_root = repository / DATABASE.MIGRATION_PATH
            migration_root.mkdir(parents=True)
            (migration_root / filename).touch()
            with mock.patch.object(DATABASE, 'migration_rows', return_value=b'92') as ledger, mock.patch.object(
                DATABASE, 'schema_dump', return_value=b'schema'
            ) as schema, mock.patch.object(DATABASE, 'run', return_value=b'FORWARD_ONLY_REJECTION_PASS') as command:
                self.assertEqual(DATABASE.rollback_and_reapply_latest(['docker', 'compose'], repository, True), 'FORWARD_ONLY_REJECTION_PASS')
                self.assertEqual(ledger.call_count, 2)
                self.assertEqual(schema.call_count, 2)
                self.assertIn('Member rank purchase history requires a forward-only migration.', command.call_args.args[0][-1])
