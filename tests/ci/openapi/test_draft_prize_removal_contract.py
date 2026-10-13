import json
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[3]


class DraftPrizeRemovalContractTest(unittest.TestCase):
    def test_removal_requires_admin_session_occ_and_idempotency(self):
        admin = json.loads((ROOT / 'openapi/bundled/admin.openapi.json').read_text())
        path = '/catalog/gachas/{gacha_id}/versions/{gacha_version_id}/prizes/{prize_id}'
        operation = admin['paths'][path]['delete']
        self.assertEqual(operation['operationId'], 'removeAdminGachaDraftPrize')
        self.assertEqual(operation['security'], [{'adminSession': []}])
        self.assertEqual(operation['x-idempotency'], 'required')
        self.assertIn({'$ref': '#/components/parameters/IdempotencyKey'}, operation['parameters'])
        self.assertTrue(operation['requestBody']['required'])
        self.assertIn('409', operation['responses'])
        for code in ['CATALOG_REVISION_CONFLICT', 'CATALOG_PRIZE_REMOVAL_INVENTORY_HISTORY', 'CATALOG_PRIZE_REMOVAL_REFERENCED']:
            self.assertIn(code, operation['description'])
        schemas = admin['components']['schemas']
        request = schemas['AdminGachaDraftPrizeRemove']
        self.assertEqual(request['required'], ['expected_version_revision'])
        self.assertFalse(request['additionalProperties'])
        self.assertEqual(request['properties']['expected_version_revision'], {'type': 'integer', 'minimum': 1})
        result = schemas['AdminGachaDraftPrizeRemovalResult']
        self.assertEqual(result['required'], ['data', 'idempotent_replay'])
        self.assertIn('gacha_version_revision', result['properties']['data']['required'])
        for surface in ['public', 'webhook']:
            other = json.loads((ROOT / f'openapi/bundled/{surface}.openapi.json').read_text())
            self.assertNotIn(path, other['paths'])
