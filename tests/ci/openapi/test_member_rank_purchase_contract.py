import json
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[3]


class MemberRankPurchaseContractTest(unittest.TestCase):
    def test_additions_are_optional_nullable_and_separate_from_existing_totals(self):
        public = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())
        schemas = public['components']['schemas']
        for name, target in [('Payment', 'MemberRankPurchaseBonus'),
                             ('PointProduct', 'MemberRankPurchaseBonusEstimate')]:
            schema = schemas[name]
            self.assertNotIn('member_rank_purchase_bonus', schema['required'])
            self.assertEqual(schema['properties']['member_rank_purchase_bonus']['oneOf'],
                             [{'type': 'null'}, {'$ref': '#/components/schemas/' + target}])
        self.assertEqual(schemas['PaymentGrant']['required'],
                         ['paid_points', 'bonus_points', 'limited_bonus_points', 'total_points'])
        for name in ['MemberRankPurchaseBonus', 'MemberRankPurchaseBonusEstimate']:
            schema = schemas[name]
            self.assertFalse(schema['additionalProperties'])
            self.assertEqual(schema['properties']['point_type'], {'const': 'free'})
            self.assertEqual(schema['properties']['rate_basis_points']['maximum'], 10000)
            self.assertEqual(schema['properties']['bonus_points']['minimum'], 0)
            self.assertNotIn('setting_revision', schema['properties'])
            self.assertNotIn('user_id', schema['properties'])
        estimate = schemas['MemberRankPurchaseBonusEstimate']['properties']
        self.assertEqual(estimate['is_estimate'], {'const': True})
        self.assertIn('お支払い完了時のランク', estimate['notice']['const'])
        self.assertNotIn('還元', estimate['notice']['const'] + estimate['label']['const'])
