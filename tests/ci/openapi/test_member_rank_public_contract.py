import json
from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[3]


class MemberRankPublicContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.contract = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())
        cls.schemas = cls.contract['components']['schemas']

    def test_authentication_and_cache_boundaries(self):
        personal = self.contract['paths']['/me/member-rank']['get']
        public = self.contract['paths']['/member-ranks']['get']
        self.assertEqual(personal['operationId'], 'getMyMemberRank')
        self.assertEqual(personal['security'], [{'userSession': []}])
        self.assertEqual(personal['x-sms-verification'], 'required')
        self.assertEqual(personal['x-cache-policy'], 'private-no-store')
        self.assertEqual(public['operationId'], 'listMemberRanks')
        self.assertEqual(public['security'], [])
        self.assertEqual(public['x-cache-policy'], 'no-store')
        self.assertNotIn('parameters', personal)

    def test_disabled_requires_nulls_and_public_list_can_be_empty(self):
        disabled = self.schemas['MyMemberRankDisabled']
        self.assertFalse(disabled['additionalProperties'])
        self.assertEqual(disabled['properties']['enabled'], {'const': False})
        self.assertEqual(set(disabled['required']), set(disabled['properties']))
        for name, schema in disabled['properties'].items():
            if name != 'enabled':
                self.assertEqual(schema, {'type': 'null'})
        collection = self.schemas['MemberRankCollection']['properties']['data']
        self.assertEqual(collection.get('minItems', 0), 0)
        self.assertEqual(collection['maxItems'], 7)

    def test_enabled_progress_decimal_precision_and_no_internal_fields(self):
        enabled = self.schemas['MyMemberRankEnabled']
        self.assertFalse(enabled['additionalProperties'])
        self.assertEqual(set(enabled['properties']), {'enabled', 'rank', 'period', 'rank_points',
                         'next_rank', 'maintain', 'demotion_protection', 'benefits', 'as_of',
                         'point_basis', 'breakdown', 'transferred', 'promotion_bonus'})
        properties = enabled['properties']
        self.assertEqual(properties['rank_points']['minimum'], 0)
        for name in ['period', 'maintain', 'demotion_protection']:
            self.assertEqual(properties[name], {'type': 'null'})
        self.assertEqual(properties['point_basis'], {'const': 'cumulative'})
        self.assertEqual(properties['breakdown']['properties']['recent']['maxItems'], 3)
        self.assertEqual(properties['breakdown']['properties']['recent']['items']['properties']['kind'], {'const': 'purchase'})
        for name in ['next_rank']:
            self.assertIn('null', properties[name]['type'])
            self.assertFalse(properties[name]['additionalProperties'])
        for name in ['next_rank']:
            self.assertEqual(properties[name]['properties']['remaining']['minimum'], 0)
        bonus = self.schemas['MemberRankPurchaseBenefit']['properties']
        self.assertEqual(bonus['rate_percent']['type'], 'string')
        self.assertEqual(bonus['point_type'], {'const': 'free'})
        pattern = bonus['rate_percent']['pattern']
        for rate in ['0.00', '0.01', '1.01', '99.99', '100.00']:
            self.assertIsNotNone(re.fullmatch(pattern, rate))
        for rate in ['1.001', '101.00', '-1.00']:
            self.assertIsNone(re.fullmatch(pattern, rate))

    def test_released_operation_inventory_and_public_disclosure(self):
        operations = [operation['operationId'] for path in self.contract['paths'].values()
                      for operation in path.values() if isinstance(operation, dict) and 'operationId' in operation]
        self.assertEqual(len(operations), 93)
        ledger = json.loads((ROOT / 'manifests/storefront-contract-releases.json').read_text())
        self.assertEqual(ledger['candidate']['bundle_version'], '2.0.0-alpha.50')
        self.assertEqual(ledger['candidate']['release_state'], 'pending')
        self.assertNotIn(ledger['candidate']['bundle_version'],
                         [row['bundle_version'] for row in ledger['immutable_history']])
        self.assertTrue(ledger['latest_immutable']['breaking_change'])
        self.assertEqual(ledger['latest_immutable']['release_mode'], 'contract-breaking')
        self.assertEqual(ledger['latest_immutable'], ledger['immutable_history'][-1])
        self.assertEqual(ledger['latest_immutable']['handoff_status'], 'released')
        self.assertEqual(ledger['latest_immutable']['public_openapi']['operation_count'], len(operations))
        self.assertEqual(ledger['latest_immutable']['bundle_version'], '2.0.0-alpha.49')
        self.assertEqual(len(ledger['immutable_history']), 29)
        self.assertEqual(set(self.schemas['MemberRankPublicSetting']['properties']), {
            'code', 'name', 'level', 'reach_line', 'maintain_line', 'promotion_bonus', 'purchase_bonus'})
