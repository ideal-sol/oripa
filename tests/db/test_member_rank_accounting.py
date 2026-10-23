import importlib.util
from pathlib import Path
import re
import tempfile
import unittest
from unittest import mock


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('rank_database', ROOT / 'scripts/db/v2_database.py')
DATABASE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(DATABASE)


class MemberRankAccountingSchemaTest(unittest.TestCase):
    def test_complete_inventory_and_latest_forward_only_rejection_preserve_schema_and_ledger(self):
        filename = '2026_10_16_000089_create_v2_member_rank_accounting.php'
        source = (ROOT / DATABASE.MIGRATION_PATH / filename).read_text()
        tables = sorted('public.' + table for table in re.findall(r'CREATE TABLE (member_rank_[a-z_]+)', source))
        inventory = DATABASE.EXPECTED_V2_SCHEMA_INVENTORY
        self.assertEqual(len(tables), 6)
        later_tables = {'public.member_rank_monthly_jobs', 'public.member_rank_manual_changes', 'public.member_rank_bonus_recoveries', 'public.member_rank_purchase_bonuses', 'public.member_rank_payment_adjustments',
                        'public.member_rank_prelaunch_authorizations', 'public.member_rank_prelaunch_excluded_origins', 'public.member_rank_prelaunch_runs'}
        self.assertEqual(tables, [table for table in inventory if table.startswith('public.member_rank_') and not table.startswith('public.member_rank_setting') and table not in later_tables])
        self.assertEqual(sorted(set(inventory)), inventory)
        with self.assertRaises(DATABASE.GuardFailure):
            DATABASE.validate_schema_inventory([table for table in inventory if table not in tables])
        with tempfile.TemporaryDirectory() as directory:
            repository = Path(directory)
            migration_root = repository / DATABASE.MIGRATION_PATH
            migration_root.mkdir(parents=True)
            (migration_root / filename).touch()
            with mock.patch.object(DATABASE, 'migration_rows', return_value=b'89') as ledger, mock.patch.object(
                DATABASE, 'schema_dump', return_value=b'schema'
            ) as schema, mock.patch.object(DATABASE, 'run', return_value=b'FORWARD_ONLY_REJECTION_PASS') as command:
                self.assertEqual(DATABASE.rollback_and_reapply_latest(['docker', 'compose'], repository, True), 'FORWARD_ONLY_REJECTION_PASS')
                self.assertEqual(ledger.call_count, 2)
                self.assertEqual(schema.call_count, 2)
                self.assertIn('Member rank accounting history requires a forward-only migration.', command.call_args.args[0][-1])
