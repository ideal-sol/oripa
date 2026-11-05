import base64
import copy
import json
from pathlib import Path
import runpy
import subprocess
import tempfile
import unittest
from unittest import mock

from tests.ops.test_preview_image_pipeline import ROOT, wrapper
from scripts.ops.old_test_pr666_payload import materialize


class Pr666PayloadTest(unittest.TestCase):
    def setUp(self):
        self.raw = (ROOT / wrapper.PR666_MANIFEST).read_bytes()
        self.spec = json.loads(self.raw)
        self.control = "c" * 40
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
            kind = 'base' if path.endswith('/659') else 'fix'
            sha = self.spec[kind + '_sha']
            return {'state': 'closed', 'merged': self.bad != 'unmerged', 'merge_commit_sha': sha,
                    'base': {'ref': 'main', 'repo': {'full_name': wrapper.REPOSITORY}},
                    'head': {'sha': sha, 'repo': {'full_name': 'foreign/repo' if self.bad == 'foreign' else wrapper.REPOSITORY}}}
        if '/git/commits/' in path:
            return {'sha': path.rsplit('/', 1)[1], 'tree': {'sha': 'e' * 40}}
        raise AssertionError(path)

    def authority(self):
        return wrapper.authorize_old_test_source(self.spec['task_id'], '666', self.spec['source_sha'], self.control, get=self.get)

    def test_authority_requires_both_merged_sources_and_control_checks(self):
        with mock.patch.object(wrapper, 'validate_required_checks') as checks:
            result = self.authority()
        self.assertEqual([c.args[0] for c in checks.call_args_list],
                         [self.spec['base_sha'], self.spec['fix_sha'], self.control])
        self.assertEqual(result['composition'], self.spec)
        self.assertEqual(result['payload_kind'], 'task-limited-derived-payload')

    def test_wrong_manifest_identity_and_source_fail_closed(self):
        for field in self.spec:
            changed = copy.deepcopy(self.spec); changed[field] = None
            with self.subTest(field=field), self.assertRaises(wrapper.WrapperError):
                wrapper.pr666_manifest(json.dumps(changed).encode())
        for task, pr, sha in [('wrong', '666', self.spec['source_sha']),
                              (self.spec['task_id'], '659', self.spec['source_sha']),
                              (self.spec['task_id'], '666', self.spec['fix_sha'])]:
            with self.assertRaises(wrapper.WrapperError):
                wrapper.authorize_old_test_source(task, pr, sha, self.control, get=self.get)

    def test_unmerged_foreign_unprotected_diverged_and_failed_checks_rejected(self):
        for bad in ('unmerged', 'foreign', 'unprotected', 'ancestor'):
            self.bad = bad
            with self.subTest(bad=bad), mock.patch.object(wrapper, 'validate_required_checks'), self.assertRaises(wrapper.WrapperError):
                self.authority()
        self.bad = None
        with mock.patch.object(wrapper, 'validate_required_checks', side_effect=wrapper.WrapperError('failed')), self.assertRaises(wrapper.WrapperError):
            self.authority()

    def clone(self, directory):
        subprocess.run(['git', 'clone', '--quiet', '--shared', '--no-checkout', str(ROOT), str(directory)], check=True)
        subprocess.run(['git', '-C', str(directory), 'checkout', '--quiet', '--detach', self.spec['base_sha']], check=True)

    def test_real_materialization_exact_two_files_and_unchanged_everything_else(self):
        with mock.patch.object(wrapper, 'validate_required_checks'):
            authority = self.authority()
        with tempfile.TemporaryDirectory() as temp:
            payload = Path(temp) / 'payload'; self.clone(payload)
            materialize(ROOT, payload, authority)
            def git(*args):
                return subprocess.check_output(['git', '-C', str(payload), *args], text=True).strip()
            self.assertEqual(git('rev-parse', 'HEAD'), self.spec['source_sha'])
            self.assertEqual(git('rev-parse', 'HEAD^{tree}'), self.spec['source_tree'])
            self.assertEqual(set(git('diff', '--name-only', self.spec['base_sha'], 'HEAD').splitlines()), set(self.spec['files']))
            self.assertEqual(git('status', '--porcelain'), '')
            self.assertIn('NOT a merged commit', git('show', '-s', '--format=%B', 'HEAD'))

    def test_dirty_or_wrong_authority_rejected_before_materialization(self):
        with mock.patch.object(wrapper, 'validate_required_checks'):
            authority = self.authority()
        with tempfile.TemporaryDirectory() as temp:
            payload = Path(temp) / 'payload'; self.clone(payload)
            for key in ('source_sha', 'source_tree', 'task_id', 'composition', 'image_mode', 'payload_kind'):
                with self.subTest(key=key), self.assertRaises(ValueError):
                    materialize(ROOT, payload, {**authority, key: None})
            (payload / 'unapproved').write_text('extra')
            with self.assertRaisesRegex(ValueError, 'pristine'):
                materialize(ROOT, payload, authority)


if __name__ == '__main__':
    unittest.main()
