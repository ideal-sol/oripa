import json
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[3]


class PrizeImportContractTest(unittest.TestCase):
    def test_import_is_admin_only_and_apply_requires_idempotency(self):
        admin = json.loads((ROOT / 'openapi/bundled/admin.openapi.json').read_text())
        public = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())
        self.assertNotIn('prize-imports', json.dumps(public))
        self.assertNotIn('external_id', json.dumps(public))
        path = '/catalog/gachas/{gacha_id}/versions/{gacha_version_id}/prize-imports'
        for suffix, method, operation, idempotency in [
            ('/preview', 'post', 'previewAdminGachaPrizeImport', 'safe'),
            ('', 'post', 'applyAdminGachaPrizeImport', 'required'),
            ('', 'get', 'listAdminGachaPrizeImports', 'safe'),
        ]:
            actual = admin['paths'][path + suffix][method]
            self.assertEqual(actual['operationId'], operation)
            self.assertEqual(actual['security'], [{'adminSession': []}])
            self.assertEqual(actual['x-idempotency'], idempotency)
        schemas = admin['components']['schemas']
        self.assertEqual(schemas['AdminPrizeImportInput']['properties']['content_base64']['maxLength'], 1398104)
        self.assertIn('plan_checksum', schemas['AdminPrizeImportApplyInput']['required'])
        self.assertEqual(schemas['AdminPrizeImportValidation']['properties']['errors']['maxItems'], 200)
        self.assertIn('error_count', schemas['AdminPrizeImportValidation']['required'])
