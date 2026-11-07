import copy
import json
from pathlib import Path
import subprocess
import unittest

from scripts.ci import security_gate
from scripts.release.readiness import change, records
from tests.ci.security import test_delta_security

ROOT = Path(__file__).resolve().parents[3]
BASE = '8eadec09a187e3f220dc09d36e9a14b98511931b'
SOURCE_HEAD = '3602b5f6d482728711e229c9a8f6cc4e49e53b31'


class PaidyReleaseMetadataTest(unittest.TestCase):
    def setUp(self):
        self.fixture = test_delta_security.DeltaSecurityTests()
        self.fixture.setUp()
        self.addCleanup(self.fixture.doCleanups)
        self.source = self.fixture.source
        self.paths = ['packages/storefront-client/package.json',
                      'packages/storefront-testkit/package.json', 'pnpm-lock.yaml']
        for path in [*self.paths, 'apps/api/composer.json', 'apps/api/composer.lock']:
            self.source.write(path, self.blob(BASE, path))
        self.source.base = self.source.commit()
        for path in self.paths:
            self.source.write(path, self.blob(SOURCE_HEAD, path))
        self.fixture.audits['composer']['advisories'] = {}
        for key, (package, _, _) in security_gate.PAIDY_ALPHA51_EXISTING_FINDINGS.items():
            self.fixture.audits['composer']['advisories'].setdefault(package, []).append(
                {'advisoryId': key, 'severity': None, 'cve': None})
        self.fixture.statuses['composer'] = 1

    def blob(self, sha, path):
        return subprocess.check_output(['git', 'show', sha + ':' + path], cwd=ROOT).decode()

    def evaluate(self):
        if self.source.git('status', '--porcelain'):
            self.source.commit()
        classification = change.classify(self.source.repository, self.source.base,
                                         self.source.git('rev-parse', 'HEAD'))
        before = copy.deepcopy(classification)
        result = self.fixture.evaluate(classification)
        self.assertEqual(classification, before)
        self.assertEqual(result['dependency_delta'], 'PRESENT')
        self.assertEqual(result['dependency_fingerprint'], classification['head_components']['dependency'])
        return result

    def test_exact_metadata_keeps_all_findings_and_runtime_hold(self):
        result = self.evaluate()
        self.assertEqual(result['development_security_result'], 'PASS_INTERNAL_RELEASE_METADATA_ONLY')
        self.assertEqual(result['unapproved_findings'], 12)
        self.assertTrue(all(not row['approved'] and row['runtime_scope'] == 'RUNTIME'
                            for row in result['current_security_findings']))
        self.assertEqual(change.security_posture(result, {}), 'HOLD')
        self.assertTrue(result['security_maintenance_required'])
        for field, value in [('dependency_delta', 'NONE'), ('current_security_posture', 'PASS_APPROVED_POLICY'),
                             ('release_metadata_proof', {}), ('unapproved_findings', 0)]:
            invalid = copy.deepcopy(result)
            invalid[field] = value
            with self.subTest(field=field), self.assertRaises(records.RecordError):
                change.security_posture(records.seal(invalid, 'record_digest'), {})

    def test_external_dependencies_integrity_hooks_and_unknown_inputs_fail(self):
        mutations = [
            ('apps/api/composer.lock', lambda s: s + '\n'),
            ('apps/api/composer.json', lambda s: s + '\n'),
            ('pnpm-lock.yaml', lambda s: s.replace('integrity: sha512-', 'integrity: sha512-X', 1)),
            ('pnpm-lock.yaml', lambda s: s.replace('version: link:../storefront-client', 'version: 2.0.0-alpha.51')),
            ('packages/storefront-client/package.json', lambda s: s.replace('"private": true', '"private": false')),
            ('packages/storefront-client/package.json', lambda s: s.replace('9.39.4', '9.39.5')),
            ('packages/storefront-testkit/package.json', lambda s: s.replace('workspace:2.0.0-alpha.23', 'workspace:2.0.0-alpha.24')),
            ('packages/storefront-testkit/package.json', lambda s: s.replace('"scripts": {', '"scripts": {"postinstall": "unexpected",')),
            ('pnpm-workspace.yaml', lambda s: s + '\n'),
        ]
        for path, mutate in mutations:
            original = (self.source.repository / path).read_text()
            modified = mutate(original)
            self.assertNotEqual(original, modified)
            self.source.write(path, modified)
            with self.subTest(path=path):
                result = self.evaluate()
                self.assertEqual(result['development_security_result'], 'BLOCK_PR_INTRODUCED_SECURITY_FINDING')
                self.assertNotIn('release_metadata_proof', result)
            self.source.write(path, original)
        self.source.write('.npmrc', 'ignore-scripts=true\n')
        self.assertEqual(self.evaluate()['development_security_result'], 'BLOCK_PR_INTRODUCED_SECURITY_FINDING')

    def test_unknown_new_critical_or_missing_advisories_never_use_metadata_proof(self):
        original = copy.deepcopy(self.fixture.audits)
        for mutation in ['new', 'critical', 'missing', 'external']:
            self.fixture.audits = copy.deepcopy(original)
            rows = self.fixture.audits['composer']['advisories']['symfony/serializer']
            if mutation == 'new': rows.append({'advisoryId': 'PKSA-new-unknown', 'severity': 'low', 'cve': None})
            elif mutation == 'critical': rows[0]['severity'] = 'critical'
            elif mutation == 'missing': rows.pop()
            else: self.fixture.finding('sharp', '0.35.5', 'GHSA-1234-5678-9abc')
            with self.subTest(mutation=mutation):
                self.assertEqual(self.evaluate()['development_security_result'], 'BLOCK_PR_INTRODUCED_SECURITY_FINDING')

    def test_uncommitted_input_or_forged_provenance_cannot_prove_metadata_only(self):
        classification = self.source.classify()
        result = self.fixture.evaluate(classification)
        self.source.write('apps/api/composer.json', '{}\n')
        self.assertIsNone(security_gate.paidy_alpha51_metadata_proof(
            self.source.repository, classification, result['current_security_findings']))
        invalid = copy.deepcopy(classification)
        invalid['tree_sha'] = 'a' * 40
        self.assertIsNone(security_gate.paidy_alpha51_metadata_proof(
            self.source.repository, records.seal(invalid, 'record_digest'), result['current_security_findings']))
