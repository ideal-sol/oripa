import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest import mock


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('shipping_minimum_database', ROOT / 'scripts/db/v2_database.py')
DATABASE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(DATABASE)
FILENAME = '2026_10_23_000098_add_v2_shipping_minimum_points.php'
MESSAGE = 'Shipping minimum settings and snapshots require a forward-only migration.'


class ShippingMinimumSchemaTest(unittest.TestCase):
    def test_task_marker_registration_is_exact(self):
        self.assertEqual(DATABASE.task_marker("SHIPPINGMIN-20261023"), "shippingmin20261023")
        for value in ["SHIPPINGMIN-20261024", "SHIPPINGMIN-20261023-extra", "shippingmin20261023"]:
            with self.assertRaises(DATABASE.GuardFailure):
                DATABASE.task_marker(value)

    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.repository = Path(temporary.name)
        directory = self.repository / DATABASE.MIGRATION_PATH
        directory.mkdir(parents=True)
        (directory / FILENAME).touch()

    def test_exact_runtime_rejection_preserves_migration_schema_and_ledger(self):
        source = (ROOT / DATABASE.MIGRATION_PATH / FILENAME).read_text()
        self.assertEqual(source.split('public function down(): void', 1)[1].strip(),
            "{\n        throw new LogicException('" + MESSAGE + "');\n    }\n};")
        for one_shot in [True, False]:
            with self.subTest(one_shot=one_shot), mock.patch.object(
                DATABASE, 'migration_rows', return_value=b'93'
            ) as ledger, mock.patch.object(DATABASE, 'schema_dump', return_value=b'schema') as schema, mock.patch.object(
                DATABASE, 'run', return_value=b'FORWARD_ONLY_REJECTION_PASS'
            ) as command:
                self.assertEqual(DATABASE.rollback_and_reapply_latest(['docker', 'compose'], self.repository, one_shot),
                    'FORWARD_ONLY_REJECTION_PASS')
                self.assertEqual(ledger.call_count, 2)
                self.assertEqual(schema.call_count, 2)
                verification = command.call_args.args[0][-1]
                self.assertIn('catch (LogicException $error)', verification)
                self.assertIn("$error->getMessage() !== '" + MESSAGE + "'", verification)
                self.assertIn('$migration->down(); exit(1);', verification)
                self.assertNotIn('catch (Throwable', verification)
                self.assertNotIn('catch (RuntimeException', verification)
                self.assertNotIn('migrate:rollback', command.call_args.args[0])

    def test_missing_rejection_marker_fails(self):
        with mock.patch.object(DATABASE, 'migration_rows', return_value=b'93'), mock.patch.object(
            DATABASE, 'schema_dump', return_value=b'schema'
        ), mock.patch.object(DATABASE, 'run', return_value=b''):
            with self.assertRaisesRegex(DATABASE.GuardFailure, 'rollback rejection failed'):
                DATABASE.rollback_and_reapply_latest(['docker', 'compose'], self.repository, False)

    def test_schema_or_ledger_change_fails(self):
        for changed in ['schema', 'ledger']:
            with self.subTest(changed=changed), mock.patch.object(
                DATABASE, 'migration_rows', side_effect=[b'93', b'92' if changed == 'ledger' else b'93']
            ), mock.patch.object(DATABASE, 'schema_dump', side_effect=[b'schema', b'changed' if changed == 'schema' else b'schema']), mock.patch.object(
                DATABASE, 'run', return_value=b'FORWARD_ONLY_REJECTION_PASS'
            ):
                with self.assertRaisesRegex(DATABASE.GuardFailure, 'verification changed the database'):
                    DATABASE.rollback_and_reapply_latest(['docker', 'compose'], self.repository, False)

    def test_unexpected_php_failure_propagates(self):
        with mock.patch.object(DATABASE, 'migration_rows', return_value=b'93'), mock.patch.object(
            DATABASE, 'schema_dump', return_value=b'schema'
        ), mock.patch.object(DATABASE, 'run', side_effect=DATABASE.GuardFailure('unexpected PHP failure')):
            with self.assertRaisesRegex(DATABASE.GuardFailure, 'unexpected PHP failure'):
                DATABASE.rollback_and_reapply_latest(['docker', 'compose'], self.repository, False)
