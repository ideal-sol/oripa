import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]


def resolve(contract, schema):
    while '$ref' in schema:
        schema = contract['components']['schemas'][schema['$ref'].rsplit('/', 1)[1]]
    return schema


class AdvertisingAttributionContractTests(unittest.TestCase):
    def test_anonymous_validity_is_minimal_and_uncached(self):
        contract = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())
        operation = contract['paths']['/advertising-code-validation']['get']
        self.assertEqual(operation['operationId'], 'validateAdvertisingCode')
        self.assertEqual(operation['security'], [])
        self.assertEqual(operation['x-cache-policy'], 'no-store')
        response = resolve(contract, operation['responses']['200']['content']['application/json']['schema'])
        self.assertEqual(response['required'], ['valid'])
        self.assertEqual(response['properties'], {'valid': {'type': 'boolean'}})
        self.assertFalse(response['additionalProperties'])

    def test_optional_candidates_preserve_existing_requests_and_registration_response(self):
        contract = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())
        for path in ['/auth/register', '/auth/external/google/start', '/auth/external/line/start']:
            operation = contract['paths'][path]['post']
            request = resolve(contract, operation['requestBody']['content']['application/json']['schema'])
            self.assertNotIn('advertising_code', request.get('required', []))
            candidate = resolve(contract, request['properties']['advertising_code'])
            self.assertEqual(candidate['type'], 'string')
            self.assertNotIn('pattern', candidate)
        response = resolve(contract, contract['paths']['/auth/register']['post']['responses']['202']['content']['application/json']['schema'])
        self.assertEqual(set(response['properties']), {'status', 'user_id'})
