import json
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[3]


class ShippingOnlyContractTest(unittest.TestCase):
    def test_public_conditions_are_explicit_and_do_not_add_a_separate_deadline(self):
        schemas = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())['components']['schemas']
        for name in ['UserPrize', 'GachaLineupPrize']:
            self.assertEqual(schemas[name]['properties']['shipping_only']['type'], 'boolean')
            self.assertNotIn('shipping_only_expires_at', schemas[name]['properties'])
        self.assertIn('shipping_only', schemas['UserPrizeActionUnavailableReason']['enum'])
        self.assertIn('expired', schemas['UserPrizeStatus']['enum'])
        self.assertIn('storage_expires_at', schemas['UserPrize']['properties'])

    def test_admin_create_update_and_reads_use_one_boolean(self):
        schemas = json.loads((ROOT / 'openapi/bundled/admin.openapi.json').read_text())['components']['schemas']
        for name in ['AdminGachaVersionPrizeCreate', 'AdminGachaVersionPrizeUpdate', 'AdminCatalogPrize', 'AdminGachaVersionPrize', 'AdminUserPrizeSummary']:
            self.assertEqual(schemas[name]['properties']['shipping_only']['type'], 'boolean')
            self.assertFalse(schemas[name]['additionalProperties'])
        self.assertFalse(schemas['AdminGachaVersionPrizeCreate']['properties']['shipping_only']['default'])
        self.assertNotIn('shipping_only', schemas['AdminGachaVersionPrizeUpdate']['required'])
