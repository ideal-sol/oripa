import json
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[3]


class BannerImportContractTest(unittest.TestCase):
    def test_admin_boundary_and_safe_results(self):
        admin = json.loads((ROOT / 'openapi/bundled/admin.openapi.json').read_text())
        public = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())
        self.assertNotIn('banner-management/imports', json.dumps(public))
        paths = admin['paths']
        base = '/banner-management/imports'
        self.assertEqual(paths[base]['post']['x-idempotency'], 'required')
        for suffix, method, permission in [('', 'post', 'content.manage'), ('', 'get', 'content.read'), ('/{import_id}', 'get', 'content.read'), ('/{import_id}/failures', 'get', 'content.read')]:
            operation = paths[base + suffix][method]
            self.assertEqual(operation['security'], [{'adminSession': []}])
            self.assertIn(permission, operation['description'])
            self.assertEqual(operation['x-cache-policy'], 'no-store')
        schemas = admin['components']['schemas']
        self.assertIn('200 data rows', schemas['AdminBannerImportInput']['description'])
        self.assertIn('legacy jobs', schemas['AdminBannerImportJob']['description'])
        self.assertEqual(schemas['AdminBannerImportInput']['properties']['content_base64']['maxLength'], 2796204)
        self.assertFalse(schemas['AdminBannerImportInput']['properties']['refresh_existing_images']['default'])
        self.assertEqual(schemas['AdminBannerImportValidation']['properties']['errors']['maxItems'], 200)
        for name in ['AdminBannerImportJob', 'AdminBannerImportRow']:
            self.assertFalse(schemas[name]['additionalProperties'])
            for prohibited in ['image_url', 'host', 'url_ciphertext', 'storage_identifier', 'session_id']:
                self.assertNotIn(prohibited, schemas[name]['properties'])
