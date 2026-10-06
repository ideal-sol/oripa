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
        update = schemas['AdminPrizeExternalIdUpdate']
        self.assertFalse(update['additionalProperties'])
        self.assertEqual(set(update['required']), {'external_id', 'expected_revision', 'expected_version_revision'})
        self.assertEqual(set(update['properties']), set(update['required']))
        operation = admin['paths']['/catalog/gachas/{gacha_id}/versions/{gacha_version_id}/ranks/{rank_id}/prizes/{prize_id}']['put']
        self.assertEqual(operation['operationId'], 'updateAdminGachaRankPrize')
        self.assertEqual(operation['requestBody']['content']['application/json']['schema']['oneOf'],
                         [{'$ref': '#/components/schemas/AdminGachaVersionPrizeUpdate'},
                          {'$ref': '#/components/schemas/AdminPrizeExternalIdUpdate'}])
        self.assertEqual(operation['x-idempotency'], 'required')
        self.assertEqual(operation['security'], [{'adminSession': []}])
        self.assertNotIn('更新時省略は既存値維持', external['description'])
        self.assertIn('引き継がない', schemas['AdminCompositionPrize']['description'])
