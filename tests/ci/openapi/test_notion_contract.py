import json
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[3]


class NotionContractTest(unittest.TestCase):
    def test_authorized_admin_only_sync_and_secret_free_responses(self):
        admin = json.loads((ROOT / 'openapi/bundled/admin.openapi.json').read_text())
        paths = ['/integrations/notion/sync-runs', '/integrations/notion/sync-runs/{id}', '/integrations/notion/status']
        for path in paths:
            for method, operation in admin['paths'][path].items():
                self.assertEqual(operation['security'], [{'adminSession': []}])
                self.assertIn('catalog.manage' if method == 'post' else 'catalog.read', operation['description'])
                self.assertEqual(operation['x-cache-policy'], 'no-store')
                if method == 'post':
                    self.assertEqual(operation['x-idempotency'], 'required')
                    self.assertIn({'$ref': '#/components/parameters/IdempotencyKey'}, operation['parameters'])
        for name in ['AdminNotionStatus', 'AdminNotionSyncRun']:
            schema = admin['components']['schemas'][name]
            self.assertFalse(schema['additionalProperties'])
            self.assertTrue({'token', 'authorization', 'data_source_id', 'raw_response'}.isdisjoint(schema['properties']))
        for surface in ['public', 'webhook']:
            contract = json.loads((ROOT / f'openapi/bundled/{surface}.openapi.json').read_text())
            self.assertTrue(set(paths).isdisjoint(contract['paths']))
