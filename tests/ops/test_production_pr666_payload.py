import copy
import hashlib
import json
from pathlib import Path
import runpy
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
M = runpy.run_path(str(ROOT / 'scripts/ops/production_source_authority.py'))
H = M['LIMITED']


class ProductionLimitedPayloadTest(unittest.TestCase):
    def setUp(self):
        self.spec = H['specification'](ROOT)
        self.head = M['git'](ROOT, 'rev-parse', 'HEAD')
        self.authority = json.loads((ROOT / M['AUTHORITY_PATH']).read_text())
        self.bad = None

    def get(self, path):
        if path.endswith('/branches/main'):
            return {'protected': self.bad != 'unprotected', 'commit': {'sha': self.head}}
        if '/pulls/' in path:
            kind = 'base' if path.endswith('/659') else 'fix'
            sha = self.spec[kind + '_sha']
            return {'merged': self.bad != kind + '-unmerged', 'state': 'closed',
                    'merge_commit_sha': sha if self.bad != kind + '-merge' else self.head,
                    'base': {'ref': 'main', 'repo': {'full_name': M['REPOSITORY']}},
                    'head': {'sha': sha, 'repo': {'full_name': 'foreign/repo' if self.bad == 'foreign' else M['REPOSITORY']}}}
        if '/git/commits/' in path:
            sha = path.rsplit('/', 1)[1]
            return {'sha': sha, 'tree': {'sha': '0' * 40 if self.bad == 'tree' else M['git'](ROOT, 'rev-parse', sha + '^{tree}')}}
        if '/check-runs?' in path:
            sha = path.split('/commits/')[1].split('/')[0]
            runs = [{'name': n, 'id': i + 1, 'head_sha': sha, 'status': 'completed',
                     'conclusion': 'failure' if self.bad == sha else 'success',
                     'started_at': '2026-11-05T00:00:00Z',
                     'app': {'id': 15368, 'slug': 'github-actions', 'owner': {'login': 'github'}}}
                    for i, n in enumerate(sorted(M['REQUIRED_CHECKS']))]
            return {'total_count': len(runs), 'check_runs': runs}
        raise AssertionError(path)

    def authorize(self):
        real = M['git']
        def git(repository, *args):
            if args == ('show', self.head + ':' + M['AUTHORITY_PATH']):
                return json.dumps(self.authority)
            return real(repository, *args)
        with patch.dict(M['authorize'].__globals__, git=git):
            return M['authorize'](ROOT, H['SOURCE'], self.head, H['CHANGE'], 666, self.get)

    def test_exact_payload_and_both_reviewed_sources(self):
        result = self.authorize()
        self.assertEqual(result['source_sha'], self.spec['source_sha'])
        self.assertEqual(result['source_tree'], self.spec['source_tree'])
        self.assertEqual(result['inventory_sha256'], self.spec['inventory_sha256'])
        self.assertEqual(set(result['reviewed_sources']), {'base', 'fix'})
        self.assertEqual(result['checkout_sha'], self.spec['base_sha'])

    def test_bad_evidence_fails_closed(self):
        for bad in ['unprotected', 'base-unmerged', 'fix-unmerged', 'base-merge', 'fix-merge',
                    'foreign', 'tree', self.spec['base_sha'], self.spec['fix_sha'], self.head]:
            self.bad = bad
            with self.subTest(bad=bad), self.assertRaises(ValueError):
                self.authorize()

    def test_authority_identity_is_not_extensible(self):
        for key in ['source_sha', 'change_id', 'pr_number', 'payload_manifest_sha256', 'activation_authorized']:
            old = self.authority[key]
            self.authority[key] = None
            with self.subTest(key=key), self.assertRaises(ValueError):
                self.authorize()
            self.authority[key] = old

    def test_manifest_tampering_and_main_movement_rejected(self):
        for raw in (b'{}', json.dumps({**self.spec, 'source_sha': self.head}).encode()):
            with patch.dict(H['specification'].__globals__, git=lambda *args: raw), self.assertRaisesRegex(ValueError, 'specification mismatch'):
                H['specification'](ROOT)
        original_get = self.get
        calls = []
        def moving_get(path):
            result = original_get(path)
            if path.endswith('/branches/main'):
                calls.append(path)
                if len(calls) > 1:
                    result['commit']['sha'] = 'f' * 40
            return result
        self.get = moving_get
        with self.assertRaisesRegex(ValueError, 'protected main moved'):
            self.authorize()

    def test_materialize_without_remote_derived_ref_and_detect_corruption(self):
        evidence = {'composition': self.spec, 'source_sha': H['SOURCE'], 'change_id': H['CHANGE'],
                    'pr_number': 666, 'source_tree': self.spec['source_tree']}
        with tempfile.TemporaryDirectory() as temp:
            payload = Path(temp) / 'payload'
            subprocess.run(['git', 'clone', '-q', '--shared', '--no-checkout', str(ROOT), str(payload)], check=True)
            M['git'](payload, 'checkout', '-q', '--detach', self.spec['base_sha'])
            for field in ['source_tree', 'inventory_sha256', 'commit_content', 'files']:
                changed = copy.deepcopy(self.spec)
                if field == 'files':
                    next(iter(changed[field].values()))['after'] = '0' * 40
                else:
                    changed[field] = '0' * 40
                with self.subTest(field=field), patch.dict(H['materialize'].__globals__, specification=lambda _: changed), self.assertRaises((ValueError, subprocess.CalledProcessError)):
                    H['materialize'](ROOT, payload, {**evidence, 'composition': changed, 'source_tree': changed['source_tree']})
            (payload / 'extra').write_text('unapproved')
            with self.assertRaisesRegex(ValueError, 'pristine'):
                H['materialize'](ROOT, payload, evidence)
            (payload / 'extra').unlink()
            H['materialize'](ROOT, payload, evidence)
            self.assertEqual(M['git'](payload, 'rev-parse', 'HEAD'), H['SOURCE'])
            self.assertEqual(set(M['git'](payload, 'diff', '--name-only', self.spec['base_sha'], 'HEAD').splitlines()), set(self.spec['files']))
            inventory = subprocess.check_output(['git', '-C', str(payload), 'ls-tree', '-r', '-z', 'HEAD'])
            self.assertEqual(hashlib.sha256(inventory).hexdigest(), self.spec['inventory_sha256'])


if __name__ == '__main__':
    unittest.main()
