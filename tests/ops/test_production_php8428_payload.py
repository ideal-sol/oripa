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
H = M['PHP8428']


class ProductionPHP8428PayloadTest(unittest.TestCase):
    def setUp(self):
        self.spec = H['specification'](ROOT)
        self.head = M['git'](ROOT, 'rev-parse', 'HEAD')
        self.authority = json.loads(M['git'](ROOT, 'show', '3442dfb875630ed2009896657092032db12a6de4:' + M['AUTHORITY_PATH']))
        self.bad = None

    def get(self, path):
        if path.endswith('/branches/main'):
            return {'protected': self.bad != 'unprotected', 'commit': {'sha': self.head}}
        if '/pulls/' in path:
            kind = {688: 'base', 692: 'wallet', 705: 'fix', 715: 'php'}[int(path.rsplit('/', 1)[1])]
            return {'merged': self.bad != kind + '-unmerged', 'state': 'closed',
                    'merge_commit_sha': self.spec[kind + '_sha'] if self.bad != kind + '-merge' else self.head,
                    'base': {'ref': 'main', 'repo': {'full_name': M['REPOSITORY']}},
                    'head': {'sha': self.head if self.bad == 'reviewed' else self.spec[kind + '_reviewed_sha'],
                             'repo': {'full_name': 'foreign/repo' if self.bad == 'foreign' else M['REPOSITORY']}}}
        if '/git/commits/' in path:
            sha = path.rsplit('/', 1)[1]
            return {'sha': sha, 'tree': {'sha': '0' * 40 if self.bad == 'tree' else next(
                self.spec[k + '_tree'] for k in ('base', 'wallet', 'fix', 'php') if self.spec[k + '_reviewed_sha'] == sha)}}
        if '/check-runs?' in path:
            sha = path.split('/commits/')[1].split('/')[0]
            return {'total_count': 5, 'check_runs': [
                {'name': n, 'id': i + 1, 'head_sha': sha, 'status': 'completed',
                 'conclusion': 'failure' if self.bad == sha else 'success', 'started_at': '2026-11-18T00:00:00Z',
                 'app': {'id': 15368, 'slug': 'github-actions', 'owner': {'login': 'github'}}}
                for i, n in enumerate(sorted(M['REQUIRED_CHECKS']))]}
        raise AssertionError(path)

    def authorize(self):
        real = M['git']
        def git(repository, *args):
            if args == ('show', self.head + ':' + M['AUTHORITY_PATH']):
                return json.dumps(self.authority)
            return real(repository, *args)
        with patch.dict(M['authorize'].__globals__, git=git):
            return M['authorize'](ROOT, H['SOURCE'], self.head, H['CHANGE'], 715, self.get)

    def payload(self, directory):
        p = Path(directory) / 'payload'
        subprocess.run(['git', 'clone', '-q', '--shared', '--no-checkout', str(ROOT), str(p)], check=True)
        M['git'](p, 'checkout', '-q', '--detach', self.spec['base_sha'])
        return p

    def test_reproduce_exact_php_only_payload(self):
        evidence = self.authorize()
        self.assertEqual(set(evidence['reviewed_sources']), {'base', 'wallet', 'fix', 'php'})
        self.assertEqual(evidence['pr_number'], 715)
        self.assertEqual(evidence['activation_scope'], ['api'])
        self.assertFalse(evidence['activation_authorized'])
        with tempfile.TemporaryDirectory() as t:
            p = self.payload(t)
            H['materialize'](ROOT, p, evidence)
            self.assertEqual(M['git'](p, 'rev-parse', 'HEAD'), H['SOURCE'])
            self.assertEqual(M['git'](p, 'rev-parse', 'HEAD^{tree}'), self.spec['source_tree'])
            self.assertEqual(hashlib.sha256(subprocess.check_output(['git', '-C', str(p), 'ls-tree', '-r', '-z', 'HEAD'])).hexdigest(), self.spec['inventory_sha256'])
            self.assertEqual(set(M['git'](p, 'diff', '--name-only', self.spec['existing_source'], 'HEAD').splitlines()), set(self.spec['files']))
            docker = (p / 'infra/docker/backend/Dockerfile').read_text()
            self.assertIn('linux-libc-dev=6.1.190-1', docker)
            self.assertNotIn('linux-libc-dev=6.1.192-2', docker)
            self.assertEqual(M['git'](p, 'diff', '--name-only', self.spec['existing_source'], 'HEAD', '--', 'apps', 'openapi', 'packages'), '')

    def test_authority_and_pr_provenance_fail_closed(self):
        for bad in ['unprotected', 'base-unmerged', 'wallet-unmerged', 'fix-unmerged', 'base-merge',
                    'wallet-merge', 'fix-merge', 'php-unmerged', 'php-merge', 'foreign', 'reviewed', 'tree', self.head,
                    *(self.spec[k + '_reviewed_sha'] for k in ('base', 'wallet', 'fix', 'php'))]:
            self.bad = bad
            with self.subTest(bad=bad), self.assertRaises(ValueError):
                self.authorize()
        self.bad = None
        for key in ['source_sha', 'pr_number', 'change_id', 'payload_manifest_sha256', 'activation_authorized']:
            original = self.authority[key]
            self.authority[key] = None
            with self.subTest(key=key), self.assertRaises(ValueError):
                self.authorize()
            self.authority[key] = original
        original_get = self.get
        calls = []
        def moving(path):
            response = original_get(path)
            if path.endswith('/branches/main'):
                calls.append(path)
                if len(calls) > 1:
                    response['commit']['sha'] = 'f' * 40
            return response
        self.get = moving
        with self.assertRaisesRegex(ValueError, 'protected main moved'):
            self.authorize()

    def test_tampering_and_dirty_payload_rejected(self):
        for field in ['source_tree', 'inventory_sha256', 'commit_content', 'files', 'existing_source', 'replacements']:
            changed = copy.deepcopy(self.spec)
            if field == 'files':
                next(iter(changed[field].values()))['after'] = '0' * 40
            elif field == 'replacements':
                changed[field][next(iter(changed[field]))] = 'unapproved'
            else:
                changed[field] = '0' * 40
            with tempfile.TemporaryDirectory() as t:
                p = self.payload(t)
                evidence = {'composition': changed, 'source_sha': H['SOURCE'], 'change_id': H['CHANGE'], 'pr_number': 715, 'source_tree': changed['source_tree']}
                with self.subTest(field=field), patch.dict(H['materialize'].__globals__, specification=lambda _: changed), self.assertRaises((ValueError, subprocess.CalledProcessError)):
                    H['materialize'](ROOT, p, evidence)
        with tempfile.TemporaryDirectory() as t:
            p = self.payload(t)
            (p / 'extra').write_text('unapproved')
            with self.assertRaisesRegex(ValueError, 'pristine'):
                H['materialize'](ROOT, p, {'composition': self.spec, 'source_sha': H['SOURCE'], 'source_tree': self.spec['source_tree'], 'change_id': H['CHANGE'], 'pr_number': 715})
        with patch.dict(H['specification'].__globals__, git=lambda *a: b'{}'), self.assertRaisesRegex(ValueError, 'specification digest mismatch'):
            H['specification'](ROOT)

    def test_actual_control_classification_and_four_source_checks(self):
        from scripts.release.readiness.change import classify, validate_change
        from scripts.release.readiness.adapters import verify_change_authority
        base = '4fb1d80a5fca4d8f12829a26972aa2c9402a92c3'
        # Exercise the actual immutable Production Control, independently of
        # later OLD Test Source Authority updates in the current checkout.
        with tempfile.TemporaryDirectory() as temporary:
            control = self.payload(temporary)
            revision = '3442dfb875630ed2009896657092032db12a6de4'
            M['git'](control, 'checkout', '-q', '--detach', revision)
            change = validate_change(classify(control, base, revision))
        self.assertEqual(change['authority_records'][0]['provenance'], 'EXACT_PHP8428_DERIVED_PAYLOAD')
        self.assertEqual(change['authority_records'][0]['source_pr'], 715)
        original = self.get
        def get(path):
            if path.endswith('/branches/main'):
                return {'protected': True, 'commit': {'sha': base}}
            return original(path)
        self.assertEqual(len(verify_change_authority(change, get)['source_checks']), 4)
        for key in ('source_sha', 'source_tree', 'inventory_sha256', 'reviewed_sources'):
            changed = copy.deepcopy(change)
            changed['authority_records'][0][key] = [] if key == 'reviewed_sources' else '0' * 40
            with self.subTest(key=key), self.assertRaises(ValueError):
                verify_change_authority(changed, get)
        for key in ('pr_number', 'payload_manifest_sha256', 'source_sha', 'activation_authorized'):
            from scripts.release.readiness.change import validate_authority, git
            changed = copy.deepcopy(self.authority)
            changed[key] = True if key == 'activation_authorized' else None
            real = git
            def fake(repository, *args):
                if args == ('show', self.head + ':' + M['AUTHORITY_PATH']):
                    return json.dumps(changed).encode()
                return real(repository, *args)
            with self.subTest(key=key), patch.dict(validate_authority.__globals__, git=fake), self.assertRaises(ValueError):
                validate_authority(ROOT, self.head, M['AUTHORITY_PATH'], base)



if __name__ == '__main__':
    unittest.main()
