import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]


class LineFriendContractTest(unittest.TestCase):
    def test_reward_and_recheck_are_additive_and_private_data_is_absent(self):
        schemas = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())['components']['schemas']
        presentation = schemas['LineFriendStatePresentation']
        self.assertNotIn('reward', presentation['required'])
        self.assertNotIn('secondary_action', presentation['required'])
        reward = presentation['properties']['reward']
        self.assertEqual(set(reward['required']), {'enabled', 'point_amount', 'granted'})
        self.assertIn('null', reward['properties']['point_amount']['type'])
        self.assertIn('start_friendship_recheck', schemas['LineFriendStateActionCode']['enum'])
        self.assertIn({'type': 'null'}, presentation['properties']['secondary_action']['anyOf'])
        for forbidden in ['subject_ciphertext', 'subject_hash', 'access_token', 'rewarded_amount']:
            self.assertNotIn(forbidden, json.dumps(presentation))
