import copy
import json
import runpy
import subprocess
import unittest
from unittest import mock
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ARTIFACT = runpy.run_path(str(ROOT / 'scripts/release/storefront_contract_artifact.py'))


class PaidyAlpha51CandidateTest(unittest.TestCase):
    def test_pending_authority_and_immutable_history(self):
        value = json.loads(subprocess.check_output(['git', 'show',
            '705d03caab389c0f9600b3e85420532aa6d46439:manifests/storefront-contract-releases.json'], cwd=ROOT))
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
        with mock.patch.dict(ARTIFACT['validate_source'].__globals__, governance=lambda _: value):
            self.assertEqual(ARTIFACT['validate_source'](ROOT)['release_state'], 'pending')
        for mutation in ['reissue', 'breaking_flag', 'webhook_version']:
            bad = copy.deepcopy(value)
            if mutation == 'reissue': bad['candidate']['bundle_version'] = '2.0.0-alpha.50'
            elif mutation == 'breaking_flag': bad['candidate']['breaking_change'] = False
            else: bad['candidate']['contract_versions']['webhook'] = '2.0.0-alpha.45'
            with self.subTest(mutation=mutation), self.assertRaises(ARTIFACT['ArtifactError']):
                ARTIFACT['validate_governance'](bad)

    def test_released_alpha51_retains_exact_publication_and_blocks_reissue(self):
        value = ARTIFACT['governance'](ROOT)
        self.assertIsNone(value['candidate'])
        self.assertEqual(len(value['immutable_history']), 31)
        latest = value['latest_immutable']
        self.assertEqual(latest, value['immutable_history'][-1])
        self.assertEqual(latest['bundle_version'], '2.0.0-alpha.51')
        self.assertEqual(latest['release_mode'], 'contract-breaking')
        self.assertTrue(latest['breaking_change'])
        self.assertTrue(ARTIFACT['verification_target'](value)['breaking_change'])
        self.assertEqual(ARTIFACT['validate_source'](ROOT)['release_state'], 'released')
        with self.assertRaisesRegex(ARTIFACT['ArtifactError'], 'no pending Storefront artifact candidate'):
            ARTIFACT['pending_candidate'](ROOT)
        self.assertEqual(latest['source_commit'], '705d03caab389c0f9600b3e85420532aa6d46439')
        self.assertEqual(latest['manifest_sha256'], '2ab973443a4db6306a516944502da0ba2c3427167611c674c58be0ec6f7de502')
        self.assertEqual(latest['publication'], {'workflow_run_id': 42352571854, 'workflow_run_attempt': 1, 'artifact_id': 13566710138, 'artifact_name': 'oripa-storefront-contract-2.0.0-alpha.51', 'github_digest': 'sha256:ac2c528b8c434cd147322e327a86882120639e71a70cdc596b40a86b61375326', 'sha256sums_sha256': 'a8de5320cc027c14563d95e6994a0006ec89ab3ed52991f5a272d3dc7501f622'})
        base = json.loads(subprocess.check_output(['git', 'show',
            '8eadec09a187e3f220dc09d36e9a14b98511931b:manifests/storefront-contract-releases.json'], cwd=ROOT))
        self.assertEqual(value['immutable_history'][:-1], base['immutable_history'])
