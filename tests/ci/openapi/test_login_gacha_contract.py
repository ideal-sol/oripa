import json
from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[3]


class LoginGachaContractTest(unittest.TestCase):
    def test_standard_catalog_keeps_numeric_capacity_and_login_has_no_total(self):
        document = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())
        schemas = document['components']['schemas']
        self.assertEqual(schemas['GachaSummary']['properties']['total_count']['type'], 'integer')
        self.assertNotIn('total_count', schemas['LoginGachaSummary']['properties'])
        self.assertNotIn('remaining_count', schemas['LoginGachaSummary']['properties'])
        self.assertNotIn('rate_units', json.dumps(schemas['LoginGachaSummary']))
        self.assertEqual(document['paths']['/login-gachas']['get']['security'], [])
        self.assertEqual(document['paths']['/login-gachas/{gacha_id}']['get']['security'], [{'userSession': []}])
        self.assertEqual(schemas['DrawResponse']['properties']['point_cost_total']['minimum'], 0)

    def test_admin_rates_are_exact_decimal_strings_and_copy_is_read_only(self):
        document = json.loads((ROOT / 'openapi/bundled/admin.openapi.json').read_text())
        schemas = document['components']['schemas']
        rate = schemas['AdminFixedPercentage']
        self.assertEqual(rate['type'], 'string')
        self.assertRegex('0.0000000001', rate['pattern'])
        self.assertIsNone(re.fullmatch(rate['pattern'], '0.00000000001'))
        self.assertEqual(set(document['paths']['/catalog/gachas/{gacha_id}/copy']), {'get'})
        self.assertEqual(schemas['AdminGachaUsageHistoryDetail']['properties']['consumed_points']['minimum'], 0)
        self.assertEqual(schemas['AdminCatalogGachaCoreCreate']['properties']['price_points']['minimum'], 1)
