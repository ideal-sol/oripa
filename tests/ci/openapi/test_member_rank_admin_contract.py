import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]


class MemberRankAdminContractTest(unittest.TestCase):
    def setUp(self):
        self.admin = json.loads((ROOT / 'openapi/bundled/admin.openapi.json').read_text())
        self.schemas = self.admin['components']['schemas']

    def test_admin_routes_permissions_idempotency_and_public_separation(self):
        public = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())
        for path, method, permission in [
            ('/member-ranks/summary', 'get', 'member_rank.settings.read'),
            ('/member-ranks/manual-changes', 'get', 'member_rank.settings.read'),
            ('/users/{user_id}/member-rank', 'put', 'member_rank.user.manage'),
            ('/users/{user_id}/member-rank/preview', 'get', 'member_rank.user.manage'),
            ('/users/{user_id}/member-rank-point-adjustments', 'post', 'member_rank.user.manage'),
        ]:
            operation = self.admin['paths'][path][method]
            self.assertNotIn(path, public['paths'])
            self.assertEqual([{'adminSession': []}], operation['security'])
            self.assertEqual(permission, operation['x-required-permission'])
            if method != 'get':
                self.assertEqual('required', operation['x-idempotency'])
                self.assertIn({'$ref': '#/components/parameters/IdempotencyKey'}, operation['parameters'])
        for name in ['AdminMemberRankWrite', 'AdminMemberRankPointWrite']:
            self.assertTrue({'expected_revision', 'preview_token'} <= set(self.schemas[name]['required']))
            self.assertFalse(self.schemas[name]['additionalProperties'])
        for code in ['MEMBER_RANK_QUERY_INVALID', 'MEMBER_RANK_INPUT_INVALID', 'MEMBER_RANK_POINTS_OUT_OF_RANGE']:
            self.assertEqual(422, self.admin['x-oripa-error-codes'][code]['status'])

    def accepts_keys(self, schema, keys):
        if '$ref' in schema:
            return self.accepts_keys(self.schemas[schema['$ref'].split('/')[-1]], keys)
        if 'allOf' in schema:
            return all(self.accepts_keys(branch, keys) for branch in schema['allOf'])
        return set(schema.get('required', [])) <= keys and (
            schema.get('additionalProperties') is not False or keys <= set(schema.get('properties', {})))

    def test_actual_user_detail_shape_is_accepted_but_unknown_fields_remain_forbidden(self):
        detail = self.schemas['AdminUserDetail']
        keys = {'id', 'display_name', 'email', 'email_verified_at', 'sms_verified', 'phone', 'verified_at',
                'status', 'state_revision', 'point_balance', 'tag_assignment_revision', 'tags', 'created_at', 'updated_at',
                'member_rank', 'member_rank_points', 'member_rank_transferred', 'member_rank_detail'}
        self.assertTrue(self.accepts_keys(detail, keys))
        self.assertFalse(self.accepts_keys(detail, keys | {'provider_secret'}))
        self.assertTrue(set(self.schemas['AdminUserSummary']['properties']) <= set(detail['properties']))
        self.assertIsNotNone(re.fullmatch(detail['properties']['phone']['pattern'], '+819012345678'))
        self.assertIsNone(re.fullmatch(detail['properties']['phone']['pattern'], '819012345678'))
