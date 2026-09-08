import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]

class AgencyContractTests(unittest.TestCase):
    def test_agency_surface_has_only_own_scope_and_strict_inputs(self):
        contract = json.loads((ROOT / 'openapi/bundled/agency.openapi.json').read_text())
        self.assertEqual(contract['servers'], [{'url': '/agency/api/v2'}])
        self.assertEqual(len(contract['paths']), 9)
        self.assertFalse(any('{' in path for path in contract['paths']))
        schemas = contract['components']['schemas']
        self.assertEqual(set(schemas['AgencyContact']['properties']), {'contact_name', 'phone'})
        self.assertFalse(schemas['AgencyContact']['additionalProperties'])
        self.assertNotIn('memo', schemas['AgencyProfile']['properties'])
        self.assertNotIn('password_hash', schemas['AgencyProfile']['properties'])
        self.assertEqual(contract['components']['securitySchemes']['agencySession']['name'], '__Host-oripa_agency_session')

    def test_aggregates_expose_only_code_and_metrics_with_session_scope(self):
        contract = json.loads((ROOT / 'openapi/bundled/agency.openapi.json').read_text())
        schemas = contract['components']['schemas']
        for kind, fields in [('User', {'temporary_users', 'full_users'}), ('Sales', {'temporary_paying_users', 'temporary_amount', 'full_paying_users', 'full_amount'})]:
            row = schemas['Agency' + kind + 'AggregateRow']
            self.assertEqual(set(row['properties']), {'advertising_code'} | fields)
            self.assertFalse(row['additionalProperties'])
            operation = contract['paths']['/aggregates/' + ('users' if kind == 'User' else 'sales')]['get']
            self.assertEqual(operation['security'], [{'agencySession': []}])
            self.assertEqual({parameter['name'] for parameter in operation['parameters']}, {'month', 'start_date', 'end_date', 'cursor', 'limit'})
            self.assertNotIn('requestBody', operation)

    def test_portal_reuses_visuals_without_admin_authentication(self):
        sources = '\n'.join(path.read_text() for path in (ROOT / 'apps/agency/src').rglob('*.tsx'))
        self.assertIn('admin/src/app/globals.css', sources)
        self.assertIn('admin/src/components/shell/admin-page-header', sources)
        self.assertIn('agency-theme', sources)
        for forbidden in ['useAdminAuth', 'AdminAuthProvider', 'PermissionProvider', '/admin/api/', '/api/v2/me']:
            self.assertNotIn(forbidden, sources)
