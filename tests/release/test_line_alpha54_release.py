import json
import runpy
import subprocess
import tempfile
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

    def test_published_alpha54_source_cannot_be_republished(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        released = Path(directory.name) / 'alpha54'
        subprocess.run(['git', 'clone', '--quiet', '--shared', '--no-checkout',
                        str(ROOT), str(released)], check=True)
        subprocess.run(['git', 'checkout', '--quiet', '--detach',
                        '776264704734e1282802ff5695c6abcab87df6ed'], cwd=released, check=True)
        value = ARTIFACT['governance'](released)
        self.assertEqual(value['latest_immutable']['bundle_version'], '2.0.0-alpha.54')
        self.assertEqual(value['latest_immutable'], value['immutable_history'][-1])
        self.assertIsNone(value['candidate'])
        self.assertEqual(ARTIFACT['validate_source'](released)['release_state'], 'released')
        with self.assertRaisesRegex(ARTIFACT['ArtifactError'], 'no pending Storefront artifact candidate'):
            ARTIFACT['pending_candidate'](released)

    def test_stripe_candidate_and_rejection_of_every_published_version(self):
        value = ARTIFACT['governance'](ROOT)
        history = value['immutable_history']
        versions = [row['bundle_version'] for row in history]
        latest = versions[-1]
        target = ARTIFACT['verification_target'](value)
        source = ARTIFACT['validate_source'](ROOT)
        self.assertEqual(source['bundle_version'], target['bundle_version'])
        if value['candidate'] is None:
            self.assertEqual(source['release_state'], 'released')
            self.assertEqual(target['bundle_version'], latest)
            with self.assertRaisesRegex(ARTIFACT['ArtifactError'], 'no pending Storefront artifact candidate'):
                ARTIFACT['pending_candidate'](ROOT)
        else:
            self.assertEqual(source['release_state'], 'pending')
            self.assertEqual(ARTIFACT['pending_candidate'](ROOT), target)
            self.assertEqual(target['predecessor_bundle_version'], latest)
            self.assertNotIn(target['bundle_version'], versions)

        # The immediate successor to alpha.54 moves from candidate to history at publication.
        successor = versions.index('2.0.0-alpha.54') + 1
        if successor == len(history):
            self.assertIsNotNone(value['candidate'])
            stripe_version = target['bundle_version']
            public_operations = target['public_api_operation_count']
        else:
            stripe_version = history[successor]['bundle_version']
            public_operations = history[successor]['public_openapi']['operation_count']
        self.assertEqual(stripe_version, '2.0.0-alpha.55')
        self.assertEqual(public_operations, 100)
        for row in value['immutable_history']:
            version = row['bundle_version']
            with self.subTest(version=version):
                reissue = {**value, 'candidate': {**target, 'release_state': 'pending',
                           'predecessor_bundle_version': latest, 'bundle_version': version}}
                with self.assertRaisesRegex(ARTIFACT['ArtifactError'], 'immutable existing version reissue prohibited'):
                    ARTIFACT['validate_governance'](reissue)

    def test_stripe_alpha55_matches_published_artifact_and_predecessors(self):
        history = ARTIFACT['governance'](ROOT)['immutable_history']
        source = json.loads(subprocess.check_output([
            'git', 'show', '8696021fb45b968ff2f77aa8915e2ab967764adf:manifests/storefront-contract-releases.json',
        ], cwd=ROOT))
        index = len(source['immutable_history'])
        self.assertEqual(history[:index], source['immutable_history'])
        release = history[index]
        self.assertEqual(release['bundle_version'], '2.0.0-alpha.55')
        self.assertEqual(release['source_commit'], '8696021fb45b968ff2f77aa8915e2ab967764adf')
        self.assertEqual(release['manifest_sha256'], '1b819d2401ef23bb19fa0747eec5419b703082809fba35853dbe9267911cca2d')
        self.assertEqual(release['publication']['artifact_id'], 15185489903)
        self.assertEqual(release['publication']['github_digest'], 'sha256:425d7d945f51e589d75cb49275d9a16d5149c7d6ac29c46b62dbf5d4ea746cb9')
        self.assertEqual(release['publication']['sha256sums_sha256'], '58fd9d86624c1cd6203ef8d7326510f7e76c003f2e00c8c47491ea7ecc086fa7')
        self.assertEqual(release['public_openapi']['sha256'], 'c6b772ec130d18e2293cd0d23b44a7aa922abcf91ffeedc868e519e58bc626a1')
        self.assertEqual(release['packages']['@oripa/storefront-client']['sha256'], 'f1cb940e1e712fc7e2d41d3a63595d289fc084d4299205454169972dd6e2e6b6')
        self.assertEqual(release['packages']['@oripa/storefront-testkit']['sha256'], '3d94069ddeeba8fa59ccc893d07fde59731544cec8d3ad7f8a85af4f2c887cac')
        self.assertEqual(release['packages']['@oripa/site-schema'], source['latest_immutable']['packages']['@oripa/site-schema'])

    def test_pending_payment_alpha56_matches_published_artifact_and_predecessors(self):
        history = ARTIFACT['governance'](ROOT)['immutable_history']
        source = json.loads(subprocess.check_output([
            'git', 'show', '536a02e8f600c1ee68696245f9133558601973f8:manifests/storefront-contract-releases.json',
        ], cwd=ROOT))
        index = len(source['immutable_history'])
        self.assertEqual(history[:index], source['immutable_history'])
        release = history[index]
        self.assertEqual(release['bundle_version'], '2.0.0-alpha.56')
        self.assertEqual(release['source_commit'], '536a02e8f600c1ee68696245f9133558601973f8')
        self.assertEqual(release['manifest_sha256'], 'ca4b577f9c25d33269a002f2899ddd72498678aaf666417e628d5ed59b12531b')
        self.assertEqual(release['publication']['artifact_id'], 15210212345)
        self.assertEqual(release['publication']['github_digest'], 'sha256:da317cd1c67395b9027127776fa794fd0cdc6f5eaa272d259cb8467e2231fc01')
        self.assertEqual(release['publication']['sha256sums_sha256'], '8bf1df43a9ba812934e031c6737d7634950cf361bff5b53d243e3aa6394f8685')
        self.assertEqual(release['public_openapi']['sha256'], 'a880f2fb866b230c39d4781048b87dc81f511360cbdc0104e2bd135d8878d2c5')
        self.assertEqual(release['packages']['@oripa/storefront-client']['sha256'], '86f1a7b3a4495993ba4d3c9c366d00b0f2b443eebc8cfb02dd2f0a10d4686220')
        self.assertEqual(release['packages']['@oripa/storefront-testkit']['sha256'], '91626babe41664d03a3eb6d5eca1f6a822406cc5267a48496b9dc2dc0acaeaf6')
        self.assertEqual(release['packages']['@oripa/site-schema'], source['latest_immutable']['packages']['@oripa/site-schema'])
