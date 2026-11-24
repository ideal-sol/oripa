import json
import runpy
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ARTIFACT = runpy.run_path(str(ROOT / 'scripts/release/storefront_contract_artifact.py'))


class LineAlpha54ReleaseTest(unittest.TestCase):
    def test_verified_release_preserves_predecessors_and_exact_identity(self):
        value = ARTIFACT['governance'](ROOT)
        source = json.loads(subprocess.check_output(['git', 'show',
            '457ca5edd918441368717e4974f588dcf0079bba:manifests/storefront-contract-releases.json'], cwd=ROOT))
        history = [row for row in value['immutable_history']
                   if int(row['bundle_version'].rsplit('.', 1)[1]) <= 54]
        self.assertEqual(history[:-1], source['immutable_history'])
        latest = history[-1]
        self.assertEqual(latest['bundle_version'], '2.0.0-alpha.54')
        self.assertEqual(latest['source_commit'], '457ca5edd918441368717e4974f588dcf0079bba')
        self.assertEqual(latest['handoff_status'], 'released')
        self.assertEqual(latest['contract_versions'], {'public': '2.0.0-alpha.50', 'admin': '2.0.0-alpha.47', 'webhook': '2.0.0-alpha.47'})
        self.assertEqual(latest['public_openapi']['operation_count'], 97)
        self.assertEqual(latest['release_mode'], 'contract-additive')
        self.assertFalse(latest['breaking_change'])
        self.assertEqual(latest['packages']['@oripa/site-schema'], source['latest_immutable']['packages']['@oripa/site-schema'])
        self.assertEqual(latest['manifest_sha256'], 'a7cf5e66d5e9ca883a35a90fc7c99716cc63a8d9d0198449dab67a1f9d36f3ab')
        self.assertEqual(latest['public_openapi']['sha256'], 'e78444e7671b3bc5a21d0702a298965ec43c3bfc9690941081f599c048b739ac')
        self.assertEqual(latest['packages']['@oripa/storefront-client']['sha256'], '5e67bb1dfad593a735d132eb646673c87406209ce8bd19aa049a71a432bf1a91')
        self.assertEqual(latest['packages']['@oripa/storefront-testkit']['sha256'], 'f98f313c3d1441f46bc66f97f4d89555a1b6f7bb74a4b89bd2f7add8eba65eb2')
        self.assertEqual(latest['publication'], {'workflow_run_id': 45406520533, 'workflow_run_attempt': 1, 'artifact_id': 14926246450, 'artifact_name': 'oripa-storefront-contract-2.0.0-alpha.54', 'github_digest': 'sha256:5c198214e0ebf989a68280897c832a06f27ecaaaf09ee18c4d07300314f6fb7a', 'sha256sums_sha256': 'b214c82d2aef8516e4e12dee6fe7f0e921694da2e7d84d0e63e8abcde80dc7ef'})

    def test_current_released_source_cannot_be_republished(self):
        value = ARTIFACT['governance'](ROOT)
        self.assertEqual(value['latest_immutable'], value['immutable_history'][-1])
        self.assertIsNone(value['candidate'])
        self.assertEqual(ARTIFACT['validate_source'](ROOT)['release_state'], 'released')
        with self.assertRaisesRegex(ARTIFACT['ArtifactError'], 'no pending Storefront artifact candidate'):
            ARTIFACT['pending_candidate'](ROOT)
