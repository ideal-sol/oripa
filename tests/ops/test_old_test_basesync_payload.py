import base64
import copy
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock

from tests.ops.test_preview_image_pipeline import ROOT, wrapper
from scripts.ops.old_test_basesync_payload import materialize


class BasesyncPayloadTest(unittest.TestCase):
    def setUp(self):
        self.raw = (ROOT / wrapper.BASESYNC_MANIFEST).read_bytes()
        self.spec = json.loads(self.raw)
        self.control = 'c' * 40
        self.bad = None

    def get(self, path):
        if path.endswith('/branches/main'):
            return {'protected': self.bad != 'unprotected', 'commit': {'sha': self.control}}
        if '/contents/' in path:
            return {'encoding': 'base64', 'content': base64.b64encode(self.raw).decode()}
        if '/compare/' in path:
            sha = path.split('/compare/')[1].split('...')[0]
            return {'status': 'diverged' if self.bad == 'ancestor' else 'ahead',
                    'base_commit': {'sha': sha}, 'merge_base_commit': {'sha': sha}}
        if '/pulls/' in path:
            kind = next(k for k in ('base', 'line', 'fix') if path.endswith('/' + str(self.spec[k + '_pr'])))
            return {'state': 'closed', 'merged': self.bad != 'unmerged',
                    'merge_commit_sha': self.spec[kind + '_sha'],
                    'base': {'ref': 'main', 'repo': {'full_name': wrapper.REPOSITORY}},
                    'head': {'sha': self.spec[kind + '_sha'], 'repo': {
                        'full_name': 'foreign/repo' if self.bad == 'foreign' else wrapper.REPOSITORY}}}
        if '/git/commits/' in path:
            sha = path.rsplit('/', 1)[1]
            kind = next(k for k in ('base', 'line', 'fix') if self.spec[k + '_sha'] == sha)
            return {'sha': sha, 'tree': {'sha': 'e' * 40 if self.bad == 'tree' else self.spec[kind + '_tree']}}
        raise AssertionError(path)

    def authority(self):
        return wrapper.authorize_old_test_source(self.spec['task_id'], '692',
            self.spec['source_sha'], self.control, get=self.get)

    def test_three_merged_sources_and_control_require_checks(self):
        with mock.patch.object(wrapper, 'validate_required_checks') as checks:
            result = self.authority()
        self.assertEqual([c.args[0] for c in checks.call_args_list],
                         [self.spec[k + '_sha'] for k in ('base', 'line', 'fix')] + [self.control])
        self.assertEqual(result['composition'], self.spec)
        self.assertEqual((result['target'], result['platform'], result['image_mode']),
                         ('old-test', 'linux/amd64', 'api-only'))

    def test_fail_closed(self):
        for bad in ('unprotected', 'ancestor', 'unmerged', 'foreign', 'tree'):
            self.bad = bad
            with self.subTest(bad=bad), mock.patch.object(wrapper, 'validate_required_checks'), self.assertRaises(wrapper.WrapperError):
                self.authority()
        self.bad = None
        with mock.patch.object(wrapper, 'validate_required_checks', side_effect=wrapper.WrapperError('failed')), self.assertRaises(wrapper.WrapperError):
            self.authority()
        for key in self.spec:
            spec = copy.deepcopy(self.spec); spec[key] = None
            with self.subTest(key=key), self.assertRaises(wrapper.WrapperError):
                wrapper.basesync_manifest(json.dumps(spec).encode())

    def test_real_payload_and_wrong_authority(self):
        with mock.patch.object(wrapper, 'validate_required_checks'):
            authority = self.authority()
        with tempfile.TemporaryDirectory() as temporary:
            payload = Path(temporary) / 'payload'
            subprocess.run(['git', 'clone', '--quiet', '--shared', '--no-checkout', str(ROOT), str(payload)], check=True)
            def git(*args):
                return subprocess.check_output(['git', '-C', str(payload), *args])
            git('checkout', '--quiet', '--detach', self.spec['base_sha'])
            for key in ('source_sha', 'source_tree', 'task_id', 'source_pr', 'target', 'platform', 'image_mode', 'payload_kind', 'composition'):
                with self.subTest(key=key), self.assertRaises(ValueError):
                    materialize(ROOT, payload, {**authority, key: None})
            (payload / 'unexpected').write_text('dirty')
            with self.assertRaisesRegex(ValueError, 'pristine'):
                materialize(ROOT, payload, authority)
            (payload / 'unexpected').unlink()
            materialize(ROOT, payload, authority)
            self.assertEqual(git('rev-parse', 'HEAD').decode().strip(), self.spec['source_sha'])
            self.assertEqual(hashlib.sha256(git('ls-tree', '-r', '-z', 'HEAD')).hexdigest(), self.spec['inventory_sha256'])
            self.assertEqual(set(git('diff', '--name-only', self.spec['base_sha'], 'HEAD').decode().splitlines()), set(self.spec['files']))
            migrations = git('ls-tree', '-r', '--name-only', 'HEAD', 'apps/api/database/migrations-v2').decode().splitlines()
            self.assertEqual({Path(p).name.split('_')[3] for p in migrations if p.endswith('.php')}, {str(n).zfill(6) for n in range(1, 108)})
            self.assertEqual(git('status', '--porcelain'), b'')
