import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]


class PaymentFoundationContractTest(unittest.TestCase):
    def test_method_discovery_and_reference_events_require_user_session(self):
        doc = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())
        for path, method in [('/payment-methods', 'get'), ('/payments/{payment_id}/client-events', 'post')]:
            operation = doc['paths'][path][method]
            self.assertEqual([{'userSession': []}], operation['security'])
            self.assertEqual('private-no-store', operation['x-cache-policy'])
        event = doc['components']['schemas']['PaymentClientEvent']
        self.assertFalse(event['additionalProperties'])
        self.assertFalse(event['properties']['detail']['additionalProperties'])
        self.assertNotIn('succeeded', event['properties']['type']['enum'])

    def test_additive_action_and_labels_preserve_fincode(self):
        doc = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())
        schemas = doc['components']['schemas']
        self.assertEqual(['credit_card', 'paypay', 'konbini', 'virtual_account', 'netridecash', 'paidy'], schemas['PaymentMethod']['enum'])
        action = schemas['PaymentFormPostAction']
        self.assertEqual('^https://', action['properties']['url']['pattern'])
        self.assertEqual('array', action['properties']['fields']['type'])
        choices = schemas['Payment']['properties']['next_action']['oneOf']
        for name in ['PaymentFormPostAction', 'PaymentRedirectAction', 'PaymentCardComponentAction']:
            self.assertIn({'$ref': '#/components/schemas/' + name}, choices)
        self.assertIn('method_label', schemas['Payment']['properties'])
