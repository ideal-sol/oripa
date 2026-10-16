import json
import copy
import runpy
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[3]


class PreRegistrationContractTest(unittest.TestCase):
    def test_password_exception_remains_write_only_and_request_named(self):
        gate = runpy.run_path(str(ROOT / 'scripts/ci/openapi_contract_gate.py'))
        public = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())
        for mutation in ('not_write_only', 'response_name'):
            altered = copy.deepcopy(public)
            schemas = altered['components']['schemas']
            if mutation == 'not_write_only':
                schemas['PreRegistrationRequest']['properties']['password']['writeOnly'] = False
            else:
                schemas['LeakedPreRegistrationResponse'] = schemas['PreRegistrationRequest']
            with self.subTest(mutation=mutation), self.assertRaises(gate['ContractFailure']):
                gate['validate_document']('public', altered)

    def test_dedicated_registration_and_private_status(self):
        public = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())
        registration = public['components']['schemas']['PreRegistrationRequest']
        self.assertEqual(set(registration['required']), {'full_name', 'birth_date', 'email', 'password'})
        self.assertFalse(registration['additionalProperties'])
        self.assertNotIn('pre_registered_at', registration['properties'])
        self.assertNotIn('redirect_path', registration['properties'])
        status = public['paths']['/me/pre-registration-bonus']['get']
        self.assertEqual(status['security'], [{'userSession': []}])
        self.assertEqual(status['x-cache-policy'], 'private-no-store')
        self.assertNotEqual(status.get('x-sms-verification'), 'required')
        bonus = public['components']['schemas']['PreRegistrationBonus']
        self.assertNotIn('phone_hmac', bonus['properties'])
        self.assertIn('awaiting_activation', bonus['properties']['status']['enum'])

    def test_admin_mutations_are_isolated_revisioned_and_idempotent(self):
        admin = json.loads((ROOT / 'openapi/bundled/admin.openapi.json').read_text())
        public = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())
        for path, method in [('/settings/pre-registration', 'put'), ('/settings/pre-registration/activate', 'post')]:
            self.assertNotIn(path, public['paths'])
            operation = admin['paths'][path][method]
            self.assertEqual(operation['x-idempotency'], 'required')
            self.assertEqual(operation['security'], [{'adminSession': []}])
        update = admin['components']['schemas']['AdminPreRegistrationUpdate']
        self.assertIn('expected_revision', update['required'])
        self.assertEqual(update['properties']['point_amount']['maximum'], 1000000)
