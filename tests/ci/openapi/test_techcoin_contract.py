import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]


class TechcoinContractTest(unittest.TestCase):
    def test_returns_are_safe_and_keep_fincode(self):
        doc = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())
        for kind in ['complete', 'failure']:
            operation = doc['paths']['/payment-returns/techcoin/' + kind]['get']
            self.assertEqual([], operation['security'])
            self.assertEqual('safe', operation['x-idempotency'])
            self.assertEqual('no-store', operation['x-cache-policy'])
            headers = operation['responses']['303']['headers']
            self.assertEqual('no-referrer', headers['Referrer-Policy']['schema']['const'])
        self.assertIn('post', doc['paths']['/payment-returns/fincode/normal'])
        self.assertIn('PaymentFormPostAction', doc['components']['schemas'])

    def test_unsigned_notification_has_strict_fields_and_no_invented_signature(self):
        doc = json.loads((ROOT / 'openapi/bundled/webhook.openapi.json').read_text())
        operation = doc['paths']['/techcoin/{token}']['post']
        self.assertEqual(['token'], [p['name'] for p in operation['parameters']])
        self.assertEqual(['application/x-www-form-urlencoded', 'application/json'], list(operation['requestBody']['content']))
        schema = doc['components']['schemas']['TechcoinKickback']
        self.assertFalse(schema['additionalProperties'])
        self.assertNotIn('order_cpt', schema['required'])
        self.assertEqual('^[0-9a-f]{32}$', schema['properties']['cookie']['pattern'])
        self.assertEqual(999999, schema['properties']['price']['oneOf'][1]['maximum'])
        self.assertIn('500', operation['responses'])
        self.assertIn('/fincode', doc['paths'])
