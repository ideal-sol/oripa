import json
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[3]


class BannerModelContractTest(unittest.TestCase):
    def test_admin_fields_are_optional_nullable_and_public_does_not_expose_models(self):
        admin = json.loads((ROOT / 'openapi/bundled/admin.openapi.json').read_text())
        schemas = admin['components']['schemas']
        for name, fields in [('AdminShippingItem', ['external_id', 'model_number', 'image']),
                             ('AdminBannerImportRow', ['model_number'])]:
            schema = schemas[name]
            for field in fields:
                self.assertNotIn(field, schema.get('required', []))
                prop = schema['properties'][field]
                if field == 'image':
                    self.assertIn({'type': 'null'}, prop['anyOf'])
                else:
                    self.assertIn('null', prop['type'])
        for surface in ['public', 'webhook']:
            self.assertNotIn('model_number', (ROOT / f'openapi/bundled/{surface}.openapi.json').read_text())
