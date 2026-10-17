import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('rank_purchase_database', ROOT / 'scripts/db/v2_database.py')
DATABASE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(DATABASE)


class MemberRankPurchaseSchemaTest(unittest.TestCase):
    def test_failed_phpunit_diagnostics_precede_large_performance_json_and_remain_redacted(self):
        performance = '{"f3_performance":{"constraints":"' + 'x' * 5000 + '"}}\n'
        output = performance + (
            'There was 1 failure:\n'
            '1) Tests\\V2\\SyntheticTest::test_expected_origin\n'
            'Failed asserting that 2 is identical to 1.\n'
            'error: password=do-not-report\n'
            'error: Authorization Bearer do-not-report\n'
            'error: session_token=do-not-report\n'
            'error: Cookie do-not-report\n'
            'error: private_key=do-not-report\n'
            'Failed asserting that synthetic.person@example.test is valid.\n'
            'error: address 192.0.2.10\n'
            'error: phone +81 90 1234 5678\n'
            'FAILURES!\n'
            'Tests: 1489, Assertions: 10000, Failures: 1.\n'
        )
        failure = subprocess.CalledProcessError(1, ['docker', 'exec'], output=output.encode(), stderr=b'')
        with mock.patch.object(DATABASE.subprocess, 'run', side_effect=failure) as runner:
            with self.assertRaises(DATABASE.GuardFailure) as raised:
                DATABASE.run(['docker', 'exec'], cwd=ROOT, capture=False)
        detail = str(raised.exception)
        self.assertIn('SyntheticTest::test_expected_origin', detail)
        self.assertIn('Failed asserting that 2 is identical to 1.', detail)
        self.assertIn('Tests: 1489, Assertions: 10000, Failures: 1.', detail)
        self.assertLess(detail.index('SyntheticTest::test_expected_origin'), detail.index('f3_performance'))
        self.assertLessEqual(len(detail), len('docker operation failed during exec: ') + 2400)
        for private in ['do-not-report', 'synthetic.person@example.test', '192.0.2.10', '+81 90 1234 5678']:
            self.assertNotIn(private, detail)
        self.assertIs(raised.exception.__cause__, failure)
        self.assertTrue(runner.call_args.kwargs['check'])

    def test_fatal_error_is_prioritized_without_changing_successful_capture(self):
        failure = subprocess.CalledProcessError(
            1, ['docker', 'exec'], output=b'{"constraints":"' + b'x' * 5000 + b'"}\n',
            stderr=b'PHP Fatal error: synthetic fixture failure\n',
        )
        with mock.patch.object(DATABASE.subprocess, 'run', side_effect=failure):
            with self.assertRaisesRegex(DATABASE.GuardFailure, 'synthetic fixture failure'):
                DATABASE.run(['docker', 'exec'], cwd=ROOT)
        completed = subprocess.CompletedProcess(['docker', 'exec'], 0, stdout=b'unchanged', stderr=b'')
        with mock.patch.object(DATABASE.subprocess, 'run', return_value=completed):
            self.assertEqual(b'unchanged', DATABASE.run(['docker', 'exec'], cwd=ROOT))
            self.assertEqual(b'', DATABASE.run(['docker', 'exec'], cwd=ROOT, capture=False))

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
