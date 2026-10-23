import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]


class ShippingMinimumContractTest(unittest.TestCase):
    def test_public_policy_and_rejection_are_typed_and_private(self):
        doc = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())
        operation = doc['paths']['/me/shipping-policy']['get']
        self.assertEqual(operation['security'], [{'userSession': []}])
        self.assertEqual(operation['x-sms-verification'], 'required')
        self.assertEqual(operation['x-cache-policy'], 'no-store')
        schemas = doc['components']['schemas']
        self.assertEqual(schemas['ShippingPointDecimal']['type'], 'string')
        self.assertFalse(schemas['ShippingPolicy']['properties']['zero_total_allowed']['const'])
        error = schemas['ShippingMinimumPointsProblem']['allOf'][1]
        self.assertEqual(error['properties']['code']['const'], 'SHIPPING_MINIMUM_POINTS_NOT_MET')
        self.assertIn('shortfall_points', error['required'])
        self.assertIn('409', doc['paths']['/me/shipping-requests']['post']['responses'])

    def test_admin_setting_has_occ_idempotency_and_dedicated_permissions(self):
        doc = json.loads((ROOT / 'openapi/bundled/admin.openapi.json').read_text())
        update = doc['paths']['/settings/shipping-minimum-points']['put']
        self.assertEqual(update['x-idempotency'], 'required')
        self.assertEqual(update['security'], [{'adminSession': []}])
        schema = doc['components']['schemas']['AdminShippingMinimumPointSettingUpdate']
        self.assertFalse(schema['additionalProperties'])
        self.assertIn('expected_revision', schema['required'])
        self.assertEqual(schema['properties']['minimum_points']['type'], 'string')
        permissions = doc['components']['schemas']['AdminPermissionCode']['enum']
        self.assertIn('shipping.settings.read', permissions)
        self.assertIn('shipping.settings.manage', permissions)
