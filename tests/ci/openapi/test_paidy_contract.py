import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]


class PaidyContractTest(unittest.TestCase):
    def test_v23_buyer_data_required_and_optional_fields(self):
        public = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())
        data = public['components']['schemas']['PaymentPaidyCheckoutAction']['properties']['payload']['properties']['buyer_data']
        self.assertFalse(data['additionalProperties'])
        self.assertIn('account_registration_date', data['required'])
        self.assertEqual(data['properties']['account_registration_date']['format'], 'date')
        self.assertIn('previous_payment_methods', data['required'])
        methods = data['properties']['previous_payment_methods']
        self.assertFalse(methods['additionalProperties'])
        self.assertEqual(methods['required'], ['credit_card_used'])
        self.assertEqual(set(methods['properties']), {'credit_card_used', 'convenience_store_prepayment_used', 'bank_transfer_used'})
        for field in ['delivery_locn_type', 'days_since_first_transaction', 'order_item_categories']:
            self.assertIn(field, data['properties'])
            self.assertNotIn(field, data['required'])

    def test_authorization_is_owned_authenticated_csrf_idempotent_and_no_store(self):
        public = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())
        operation = public['paths']['/payments/{payment_id}/paidy-authorizations']['post']
        self.assertEqual(operation['operationId'], 'confirmPaidyAuthorization')
        self.assertEqual(operation['security'], [{'userSession': []}])
        self.assertEqual(operation['x-idempotency'], 'required')
        self.assertEqual(operation['x-cache-policy'], 'private-no-store')
        self.assertEqual(operation['x-sms-verification'], 'required')
        self.assertEqual(len(operation['parameters']), 3)
        request = public['components']['schemas']['PaymentPaidyAuthorizationRequest']
        self.assertFalse(request['additionalProperties'])
        self.assertEqual(request['required'], ['paidy_payment_id'])
        self.assertEqual(set(request['properties']), {'paidy_payment_id'})

    def test_checkout_is_public_key_only_and_preserves_existing_actions(self):
        public = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())
        schemas = public['components']['schemas']
        action = schemas['PaymentPaidyCheckoutAction']
        self.assertFalse(action['additionalProperties'])
        self.assertEqual(action['properties']['script_url']['const'], 'https://apps.paidy.com/')
        payload = action['properties']['payload']['properties']
        self.assertEqual(payload['currency']['const'], 'JPY')
        self.assertNotIn('shipping_address', payload)
        self.assertNotIn('name1', payload['buyer']['required'])
        self.assertEqual(payload['buyer_data']['properties']['age']['minimum'], 0)
        self.assertIn('ltv', payload['buyer_data']['required'])
        self.assertNotIn('secret', json.dumps(action))
        variants = schemas['Payment']['properties']['next_action']['oneOf']
        self.assertEqual(len(variants), 6)
        self.assertIn({'$ref': '#/components/schemas/PaymentUnivapayInlineAction'}, variants)
        self.assertIn({'$ref': '#/components/schemas/PaymentCardComponentAction'}, variants)

    def test_webhook_is_durable_trigger_and_separate_from_public(self):
        webhook = json.loads((ROOT / 'openapi/bundled/webhook.openapi.json').read_text())
        operation = webhook['paths']['/paidy/{token}']['post']
        self.assertEqual(operation['operationId'], 'receivePaidyPaymentWebhook')
        self.assertEqual(operation['security'], [])
        self.assertIn('503', operation['responses'])
        self.assertIn('404', operation['responses'])
        self.assertEqual(webhook['info']['version'], '2.0.0-alpha.45')
