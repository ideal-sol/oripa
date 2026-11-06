import copy
import json
import runpy
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ARTIFACT = runpy.run_path(str(ROOT / 'scripts/release/storefront_contract_artifact.py'))


class PaidyAlpha51CandidateTest(unittest.TestCase):
    def test_pending_authority_and_immutable_history(self):
        value = ARTIFACT['governance'](ROOT)
        base = json.loads(subprocess.check_output(['git', 'show',
            '8eadec09a187e3f220dc09d36e9a14b98511931b:manifests/storefront-contract-releases.json'], cwd=ROOT))
        self.assertEqual(value['immutable_history'], base['immutable_history'])
        self.assertEqual(value['latest_immutable'], base['latest_immutable'])
        c = value['candidate']
        self.assertEqual(c['bundle_version'], '2.0.0-alpha.51')
        self.assertEqual(c['predecessor_bundle_version'], '2.0.0-alpha.50')
        self.assertEqual(c['release_mode'], 'contract-breaking')
        self.assertTrue(c['breaking_change'])
        self.assertEqual(c['contract_versions'], dict.fromkeys(['public', 'admin', 'webhook'], '2.0.0-alpha.47'))
        self.assertEqual(c['public_api_operation_count'], 93)
        for field in ['source_commit', 'manifest_sha256', 'publication']:
            self.assertNotIn(field, c)
        self.assertEqual(ARTIFACT['validate_source'](ROOT)['release_state'], 'pending')
        for mutation in ['reissue', 'breaking_flag', 'webhook_version']:
            bad = copy.deepcopy(value)
            if mutation == 'reissue': bad['candidate']['bundle_version'] = '2.0.0-alpha.50'
            elif mutation == 'breaking_flag': bad['candidate']['breaking_change'] = False
            else: bad['candidate']['contract_versions']['webhook'] = '2.0.0-alpha.45'
            with self.subTest(mutation=mutation), self.assertRaises(ARTIFACT['ArtifactError']):
                ARTIFACT['validate_governance'](bad)
