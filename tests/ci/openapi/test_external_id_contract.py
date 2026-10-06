import json
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[3]


class ExternalIdContractTest(unittest.TestCase):
    def test_external_id_is_confined_to_admin_contract(self):
        admin = json.loads((ROOT / 'openapi/bundled/admin.openapi.json').read_text())
        public = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())
        self.assertNotIn('external_id', json.dumps(public))
        schemas = admin['components']['schemas']
        external = schemas['AdminExternalId']
        self.assertEqual(external['type'], ['string', 'null'])
        self.assertEqual(external['minLength'], 1)
        self.assertEqual(external['maxLength'], 64)
        self.assertEqual(external['pattern'], '^[A-Za-z0-9._-]{1,64}$')
        for name in ['AdminManagedBannerInput', 'AdminManagedBanner', 'AdminCompositionPrize',
                     'AdminGachaVersionPrizeCreate', 'AdminGachaVersionPrizeUpdate',
                     'AdminCatalogPrize', 'AdminGachaVersionPrize']:
            self.assertEqual(schemas[name]['properties']['external_id'],
                             {'$ref': '#/components/schemas/AdminExternalId'})
            self.assertNotIn('external_id', schemas[name].get('required', []))
        listing = admin['paths']['/banner-management/banners']['get']
        self.assertEqual(sum(parameter.get('name') == 'external_id'
                             for parameter in listing['parameters']), 1)
        detail = admin['paths']['/banner-management/banners/{banner_id}']['get']
        self.assertEqual(detail['operationId'], 'getManagedAdminBanner')
        self.assertEqual(detail['security'], [{'adminSession': []}])
