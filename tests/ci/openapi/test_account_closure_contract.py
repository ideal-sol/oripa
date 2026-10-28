import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]


class AccountClosureContractTest(unittest.TestCase):
    def test_closure_is_authenticated_sms_gated_and_mutations_require_csrf(self):
        doc = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())
        for path, method in [('/me/account-closure', 'get'), ('/me/account-closure', 'post'), ('/me/account-closure/email-code', 'post')]:
            operation = doc['paths'][path][method]
            self.assertEqual(operation['security'], [{'userSession': []}])
            self.assertEqual(operation['x-sms-verification'], 'required')
            self.assertEqual(operation['x-cache-policy'], 'private-no-store')
            if method == 'post':
                self.assertIn('419', operation['responses'])
        request = doc['components']['schemas']['AccountClosureRequest']
        self.assertFalse(request['additionalProperties'])
        self.assertEqual(len(request['oneOf']), 2)
        self.assertTrue(request['properties']['password']['writeOnly'])
        self.assertTrue(request['properties']['email_code']['writeOnly'])
        preview = doc['components']['schemas']['AccountClosurePreviewResponse']['properties']['data']
        self.assertNotIn('closure_kind', preview['properties'])
        self.assertNotIn('phone_hmac', preview['properties'])

    def test_released_history_is_not_reissued(self):
        ledger = json.loads((ROOT / 'manifests/storefront-contract-releases.json').read_text())
        self.assertEqual(ledger['latest_immutable']['bundle_version'], '2.0.0-alpha.48')
        self.assertIsNone(ledger['candidate'])
        self.assertFalse(ledger['latest_immutable']['breaking_change'])
