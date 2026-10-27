import json
import copy
import unittest
from pathlib import Path
from scripts.ci.openapi_contract_gate import is_authorized_univapay_inline_extension

ROOT = Path(__file__).resolve().parents[3]


class UnivapayContractTest(unittest.TestCase):
    def test_only_the_authorized_nullable_placeholder_extension_is_accepted(self):
        current = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())
        previous = copy.deepcopy(current)
        previous['components']['schemas']['PaymentMethodOption']['properties']['inline_form'] = {'type': 'null'}
        self.assertTrue(is_authorized_univapay_inline_extension('public', previous, current))
        self.assertFalse(is_authorized_univapay_inline_extension('admin', previous, current))
        for mutate in (
            lambda doc: doc['components']['schemas']['PaymentMethodOption']['properties']['inline_form']['oneOf'].pop(0),
            lambda doc: doc['components']['schemas']['PaymentUnivapayInlineForm']['properties']['options']['properties']['tokenType'].update({'const': 'recurring'}),
            lambda doc: doc['paths'].pop('/payments'),
            lambda doc: doc['components']['schemas']['Payment']['properties']['amount'].update({'type': 'string'}),
        ):
            invalid = copy.deepcopy(current)
            mutate(invalid)
            self.assertFalse(is_authorized_univapay_inline_extension('public', previous, invalid))

    def test_report_is_owned_csrf_and_no_store(self):
        doc = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())
        op = doc['paths']['/payments/{payment_id}/univapay-charges']['post']
        self.assertEqual([{'userSession': []}], op['security'])
        self.assertEqual('private-no-store', op['x-cache-policy'])
        self.assertIn({'$ref': '#/components/parameters/XsrfToken'}, op['parameters'])
        request = doc['components']['schemas']['UnivapayChargeReport']
        self.assertEqual(['charge_id'], request['required'])
        self.assertFalse(request['additionalProperties'])

    def test_inline_is_additive_and_never_saves_cards(self):
        schemas = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())['components']['schemas']
        actions = schemas['Payment']['properties']['next_action']['oneOf']
        for name in ['PaymentUnivapayInlineAction', 'PaymentCardComponentAction', 'PaymentRedirectAction']:
            self.assertIn({'$ref': '#/components/schemas/' + name}, actions)
        options = schemas['PaymentUnivapayInlineForm']['properties']['options']
        self.assertFalse(options['additionalProperties'])
        self.assertEqual('one_time', options['properties']['tokenType']['const'])
        self.assertTrue(options['properties']['inline']['const'])
        self.assertEqual(100000, options['properties']['amount']['maximum'])
        self.assertNotIn('univapayCustomerId', options['properties'])
        self.assertNotIn('api_secret', options['properties'])
