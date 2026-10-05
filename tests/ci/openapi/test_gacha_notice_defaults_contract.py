import json
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[3]


class GachaNoticeDefaultsContractTest(unittest.TestCase):
    def test_atomic_admin_only_contract(self):
        admin = json.loads((ROOT / 'openapi/bundled/admin.openapi.json').read_text())
        public = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())
        route = '/settings/gacha-notices'
        self.assertNotIn(route, public['paths'])
        self.assertEqual(set(admin['paths'][route]), {'get', 'put'})
        self.assertEqual(admin['paths'][route]['put']['x-idempotency'], 'required')
        schemas = admin['components']['schemas']
        for name in ['AdminGachaNoticeDefaults', 'AdminGachaNoticeDefaultsUpdate']:
            self.assertEqual(set(schemas[name]['required']), {'standard', 'login'})
            self.assertEqual(set(schemas[name]['properties']), {'standard', 'login'})
            self.assertFalse(schemas[name]['additionalProperties'])
        for name, revision in [('AdminGachaNoticeDefault', 'revision'), ('AdminGachaNoticeDefaultUpdate', 'expected_revision')]:
            self.assertFalse(schemas[name]['additionalProperties'])
            self.assertEqual(set(schemas[name]['required']), {'default_notices', revision})
            self.assertEqual(schemas[name]['properties']['default_notices']['maxLength'], 10000)
            self.assertEqual(schemas[name]['properties']['default_notices']['type'], ['string', 'null'])
            self.assertEqual(schemas[name]['properties'][revision]['minimum'], 1)
