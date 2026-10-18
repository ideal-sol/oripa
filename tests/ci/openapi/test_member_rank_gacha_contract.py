import json
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[3]


class MemberRankGachaContractTest(unittest.TestCase):
    def test_conditions_and_snapshots_are_additive_and_public_safe(self):
        public = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())
        schemas = public['components']['schemas']
        for name in ['GachaSummary', 'GachaPresentationState', 'LoginGachaSummary']:
            self.assertIn('member_rank_condition', schemas[name]['properties'])
            self.assertNotIn('member_rank_condition', schemas[name]['required'])
        for name in ['DrawResponse', 'DrawHistoryEntry']:
            self.assertIn('member_rank', schemas[name]['properties'])
            self.assertNotIn('member_rank', schemas[name]['required'])
        self.assertEqual(set(schemas['MemberRankSnapshot']['properties']), {'code', 'name', 'level'})
        self.assertIn('member_rank_not_eligible', schemas['GachaPresentationReason']['enum'])
        self.assertIn('member_rank_not_eligible', schemas['LoginGachaEligibility']['properties']['reason']['enum'])
        parameters = public['paths']['/gachas']['get']['parameters']
        self.assertTrue(any(parameter.get('name') == 'member_rank_eligible_only' for parameter in parameters))

    def test_admin_keeps_audience_independent_and_signup_unrestricted(self):
        admin = json.loads((ROOT / 'openapi/bundled/admin.openapi.json').read_text())['components']['schemas']
        properties = admin['AdminGachaComposition']['properties']
        self.assertEqual(properties['member_rank_mode']['enum'], ['none', 'at_least', 'only', 'at_most'])
        self.assertEqual(properties['member_rank_level']['maximum'], 7)
        self.assertEqual(properties['audience_code']['enum'], ['all_users', 'first_time_users', 'line_users'])
        signup = next(entry['then']['properties'] for entry in admin['AdminGachaCompositionSave']['allOf']
                      if entry.get('if', {}).get('properties', {}).get('gacha_type', {}).get('const') == 'signup_once')
        self.assertEqual(signup['member_rank_mode'], {'const': 'none'})
        self.assertEqual(signup['member_rank_level'], {'type': 'null'})
