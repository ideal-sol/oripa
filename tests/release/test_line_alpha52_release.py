import json
import runpy
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ARTIFACT = runpy.run_path(str(ROOT / 'scripts/release/storefront_contract_artifact.py'))


class LineAlpha52ReleaseTest(unittest.TestCase):
    def test_measured_release_preserves_all_predecessors_and_rejects_reissue(self):
        value = ARTIFACT['governance'](ROOT)
        value['immutable_history'] = [row for row in value['immutable_history']
                                      if int(row['bundle_version'].rsplit('.', 1)[1]) <= 52]
        value['latest_immutable'] = value['immutable_history'][-1]
        source = json.loads(subprocess.check_output(['git','show',
            '8d56c5e1d450e4bc9ee07c1c97886aad054c02ee:manifests/storefront-contract-releases.json'], cwd=ROOT))
        self.assertEqual(value['immutable_history'][:-1], source['immutable_history'])
        latest = value['latest_immutable']
        self.assertEqual(latest, value['immutable_history'][-1])
        self.assertEqual(latest['bundle_version'], '2.0.0-alpha.52')
        self.assertEqual(latest['contract_versions'], {'public':'2.0.0-alpha.48','admin':'2.0.0-alpha.47','webhook':'2.0.0-alpha.47'})
        self.assertEqual(latest['release_mode'], 'contract-additive')
        self.assertFalse(latest['breaking_change'])
        self.assertEqual(latest['packages']['@oripa/site-schema'], source['latest_immutable']['packages']['@oripa/site-schema'])
        with tempfile.TemporaryDirectory() as directory:
            released = Path(directory) / 'alpha52'
            subprocess.run(['git', 'clone', '--quiet', '--shared', '--no-checkout', str(ROOT), str(released)], check=True)
            subprocess.run(['git', 'checkout', '--quiet', '--detach', '5bc7a904'], cwd=released, check=True)
            self.assertEqual(ARTIFACT['validate_source'](released)['release_state'], 'released')
            with self.assertRaisesRegex(ARTIFACT['ArtifactError'], 'no pending Storefront artifact candidate'):
                ARTIFACT['pending_candidate'](released)
        self.assertEqual(latest["source_commit"], '8d56c5e1d450e4bc9ee07c1c97886aad054c02ee')
        self.assertEqual(latest["manifest_sha256"], '72bd3b18fb36bac68b948e4f936a7b64fce03cc1de95df1b3eedc826e54cee54')
        self.assertEqual(latest["public_openapi"]["sha256"], '74101c0195be55d8ad63be342e009814d958d4160eb3d1bd75686965765b8c9a')
        self.assertEqual(latest["packages"]["@oripa/storefront-client"]["sha256"], '1f436091bc3c2a7dbb284a1275d61a053bb372d7134ef425f14427691c330341')
        self.assertEqual(latest["packages"]["@oripa/storefront-testkit"]["sha256"], 'c154643dc017a861a451baf0f7c3ec223aa5be21ecf54109bad925b2fa8e5f5d')
        self.assertEqual(latest["publication"]["github_digest"], 'sha256:c08147d9dacc5c78c4edd7f548c3ad66fca9affbed432b47cb216609150cb4dd')
        self.assertEqual(latest["publication"]["artifact_id"], 13742019147)
        self.assertEqual(latest["publication"]["workflow_run_id"], 42772928571)
        self.assertEqual(latest["publication"]["sha256sums_sha256"], 'f5574339a423a75eeaacd73bd702071217dd89438a16fad4ec01f2611e4bdef0')
