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
    def test_paidy_candidate_preserves_production_api_scope(self):
        source = '4a03489c2e5cdde97c75b6330e5fd65167230833'
        old = H['SOURCE']
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        payload = Path(temporary.name) / 'payload'
        subprocess.run(['git', 'clone', '-q', '--shared', '--no-checkout', str(ROOT), str(payload)], check=True)
        spec = H['specification'](ROOT)
        M['git'](payload, 'checkout', '-q', '--detach', spec['base_sha'])
        H['materialize'](ROOT, payload, {'composition': spec, 'source_sha': old,
                          'change_id': H['CHANGE'], 'pr_number': 666,
                          'source_tree': spec['source_tree']})
        def git(repository, *args):
            return M['git'](payload, *args)
        allowed = set()
        for revision in ('417b5558', 'c1305466', '4a03489c', 'a6affd68'):
            allowed.update(git(ROOT, 'diff-tree', '--no-commit-id', '--name-only', '-r', revision, '--', 'apps/api').splitlines())
        actual = set(git(ROOT, 'diff', '--name-only', old, source, '--', 'apps/api').splitlines())
        self.assertEqual(len(actual), 20)
        self.assertEqual(actual - allowed, set())
        self.assertEqual(git(ROOT, 'diff', '--name-only', old, source, '--',
                             'apps/api/database', 'infra/docker', '.dockerignore'), '')
        for path, blobs in H['specification'](ROOT)['files'].items():
            self.assertEqual(git(ROOT, 'rev-parse', source + ':' + path), blobs['after'])
            self.assertEqual(git(ROOT, 'rev-parse', old + ':' + path), blobs['after'])

    def setUp(self):
        self.spec = H['specification'](ROOT)
        self.head = M['git'](ROOT, 'rev-parse', 'HEAD')
        self.authority = json.loads(M['git'](ROOT, 'show', '8eadec09a187e3f220dc09d36e9a14b98511931b:' + M['AUTHORITY_PATH']))
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

    def test_ci_classifier_and_both_source_evidence_gates(self):
        from scripts.release.readiness.change import classify, validate_change
        from scripts.release.readiness.adapters import verify_change_authority
        base = '24b7c85080cecd7c7925abf3e92d90d8ce0dc3dc'
        # This is the historical PR666 Production approval, not future OLD Test
        # approvals. Keep its actual base/head pair and the checkout guard.
        historical = '8eadec09a187e3f220dc09d36e9a14b98511931b'
        with tempfile.TemporaryDirectory() as temp:
            repository = Path(temp) / 'authority'
            subprocess.run(['git', 'clone', '-q', '--shared', '--no-checkout',
                            str(ROOT), str(repository)], check=True)
            M['git'](repository, 'checkout', '-q', '--detach', historical)
            result = validate_change(classify(repository, base, historical))
        self.assertEqual(result['validation_path'], 'NORMAL_STRICT_CI')
        authority, = result['authority_records']
        self.assertEqual(authority['source_sha'], H['SOURCE'])
        self.assertEqual(authority['inventory_sha256'], self.spec['inventory_sha256'])
        def get(path):
            if path.endswith('/branches/main'):
                return {'protected': True, 'commit': {'sha': base}}
            return self.get(path)
        self.assertEqual(len(verify_change_authority(result, get)['source_checks']), 2)
        for bad in ['base-unmerged', 'fix-unmerged', 'tree', self.spec['base_sha'], self.spec['fix_sha']]:
            self.bad = bad
            with self.subTest(bad=bad), self.assertRaises(ValueError):
                verify_change_authority(result, get)

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
