import base64
import copy
import json
from pathlib import Path
import runpy
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
MODULE = runpy.run_path(str(ROOT / 'scripts/ops/old_test_draft_preview.py'))
TASK, PR, HEAD, CONTROL, BASE = 'DRAFT-TEST', '123', 'a' * 40, 'b' * 40, 'c' * 40


class DraftAuthorityTest(unittest.TestCase):
    def setUp(self):
        self.plan = dict(task_id=TASK, source_pr=int(PR), branch='feat/' + TASK,
                         baseline_sha=BASE, image_mode='admin-only', migration='none',
                         allowed_paths=['apps/admin/src/example.tsx'], focused_commands=[['true']])
        self.pull = dict(state='open', draft=True, title=TASK,
                         head=dict(sha=HEAD, ref='feat/' + TASK, repo=dict(full_name='ideal-sol/oripa')),
                         base=dict(ref='main', repo=dict(full_name='ideal-sol/oripa')))
        self.files = [dict(filename='apps/admin/src/example.tsx', status='modified')]
        self.main = dict(protected=True, commit=dict(sha=CONTROL))
        self.workflow = '.github/workflows/platform-ci.yml'
        self.checks = []
        for i, name in enumerate(('policy-gate', 'security-gate'), 1):
            self.checks.append(dict(id=i, name=name, head_sha=HEAD, status='completed', conclusion='success',
                started_at='2026-11-19T00:00:00Z', completed_at='2026-11-19T00:01:00Z',
                app=dict(id=15368, slug='github-actions', owner=dict(login='github')),
                details_url=f'https://github.com/ideal-sol/oripa/actions/runs/1/job/{i}'))

    def get(self, path):
        if '/branches/main' in path:
            return copy.deepcopy(self.main)
        if '/contents/' in path:
            content = dict(schema_version='1.0', target='old-test', plans=[self.plan])
            return dict(encoding='base64', content=base64.b64encode(json.dumps(content).encode()).decode())
        if '/pulls/' in path:
            return copy.deepcopy(self.pull)
        if '/compare/' in path:
            return dict(status='ahead', merge_base_commit=dict(sha=BASE), files=self.files)
        if '/check-runs?' in path:
            return dict(total_count=len(self.checks), check_runs=copy.deepcopy(self.checks))
        if '/actions/runs/' in path:
            return dict(path=self.workflow, head_sha=self.pull['head']['sha'], event='pull_request')
        if '/git/commits/' in path:
            return dict(sha=self.pull['head']['sha'], tree=dict(sha='d' * 40))
        raise AssertionError(path)

    def authorize(self):
        return MODULE['authorize'](TASK, PR, HEAD, CONTROL, get=self.get)

    def test_success_without_full_db_ci(self):
        result = self.authorize()
        self.assertEqual(result['authority_mode'], 'draft-preview')
        self.assertFalse(result['activation_authorized'])
        self.assertEqual(result['source_sha'], HEAD)

    def test_a_feature_implementation_tests_and_spec_inventory(self):
        paths = ['apps/api/app/Example.php', 'apps/api/tests/Feature/ExampleTest.php',
                 'apps/admin/src/example.tsx', 'apps/admin/test/example.test.tsx',
                 'docs/operations/catalog-probability/example.md', 'worklogs/new_ver_main.md',
                 'docs/architecture/catalog-preview-adr.md', 'tests/ci/openapi/test_catalog_preview_contract.py']
        self.plan.update(image_mode='normal', allowed_paths=paths)
        self.files = [dict(filename=p, status='modified') for p in paths]
        result = self.authorize()
        self.assertEqual(result['changed_paths'], sorted(paths))
        self.assertEqual(len(result['file_inventory']), len(paths))
        self.assertEqual({r['role'] for r in result['file_inventory']}, {'runtime', 'test', 'spec'})
        self.assertTrue(self.pull['draft'])

    def test_b_next_draft_head_reuses_plan_without_waiting_for_full_ci(self):
        self.test_a_feature_implementation_tests_and_spec_inventory()
        next_head = 'e' * 40
        self.pull['head']['sha'] = next_head
        with self.assertRaises(ValueError):
            MODULE['authorize'](TASK, PR, HEAD, CONTROL, get=self.get)
        with self.assertRaisesRegex(ValueError, 'early_checks_required'):
            MODULE['authorize'](TASK, PR, next_head, CONTROL, get=self.get)
        for check in self.checks:
            check['head_sha'] = next_head
        result = MODULE['authorize'](TASK, PR, next_head, CONTROL, get=self.get)
        self.assertEqual(result['source_sha'], next_head)
        self.assertEqual(result['plan'], self.plan)
        self.assertEqual({c['name'] for c in result['early_checks']}, {'policy-gate', 'security-gate'})
        # Real focused execution is possible; no quality/integration/ci gate exists.
        with tempfile.TemporaryDirectory() as temporary:
            import os
            result['plan'] = dict(self.plan, focused_commands=[['python3', '-c', 'assert 2 + 2 == 4']])
            Path(temporary, 'source-authority.json').write_text(json.dumps(result))
            subprocess.run(['python3', str(ROOT / 'scripts/ops/old_test_draft_focus.py')], check=True,
                           env=os.environ | {'RUNNER_TEMP': temporary})
        self.test_selected_builds_in_real_shell_without_docker(
            head=next_head, selections=[('normal', ['approved-api.Dockerfile', 'apps/admin/Dockerfile'])])

    def test_contract_and_generated_files_are_in_the_exact_inventory(self):
        self.test_a_feature_implementation_tests_and_spec_inventory()
        paths = ['openapi/admin/openapi.yaml', 'openapi/bundled/admin.openapi.json',
                 'apps/admin/src/lib/admin-api/generated.ts']
        self.plan['allowed_paths'] += paths
        self.files += [dict(filename=p, status='modified') for p in paths]
        self.assertEqual(self.authorize()['contract_paths'], sorted(paths))

    def test_strict_paths_cannot_be_approved_as_feature_inputs(self):
        for path in ['apps/api/app/Domain/Auth/Service.php', 'apps/api/app/Http/Middleware/Guard.php',
                     'apps/api/config/auth.php', 'apps/api/database/migrations-v2/add.php',
                     'apps/admin/src/lib/session.ts', 'pnpm-lock.yaml', '.github/workflows/ci.yml',
                     'scripts/ci/security_gate.py', 'docs/architecture/V2_RELEASE_GATES_FINAL.md', 'openapi/redocly.yaml',
                     'tests/ci/openapi/test_openapi_contract_gate.py',
                     'apps/admin/test/setup.ts', 'apps/api/tests/TestCase.php',
                     'apps/admin/src/lib/admin-api/client.ts']:
            with self.subTest(path=path), self.assertRaises(ValueError):
                MODULE['validate_plan'](dict(self.plan, allowed_paths=[path]))

    def test_real_contents_api_wrapped_base64(self):
        def get(path):
            result = self.get(path)
            if '/contents/' in path:
                result['content'] = base64.encodebytes(base64.b64decode(result['content'])).decode()
                self.assertIn('\n', result['content'])
            return result
        self.assertEqual(MODULE['authorize'](TASK, PR, HEAD, CONTROL, get=get)['source_sha'], HEAD)

    def test_ci_mergeability_metadata_is_not_authority_drift(self):
        calls = []
        def get(path):
            result = self.get(path)
            if '/pulls/' in path:
                calls.append(path)
                result['mergeable_state'] = 'blocked' if len(calls) == 1 else 'clean'
            return result
        self.assertEqual(MODULE['authorize'](TASK, PR, HEAD, CONTROL, get=get)['source_sha'], HEAD)

    def test_modes(self):
        for mode, path in [('api-only', 'apps/api/app/Example.php'), ('normal', 'apps/admin/src/example.tsx')]:
            self.plan.update(image_mode=mode, allowed_paths=[path])
            self.files = [dict(filename=path, status='modified')]
            self.assertEqual(self.authorize()['image_mode'], mode)

    def test_required_worklog_can_accompany_approved_application_change(self):
        self.plan['allowed_paths'].append('worklogs/new_ver_main.md')
        self.files.append(dict(filename='worklogs/new_ver_main.md', status='modified'))
        self.assertIn('worklogs/new_ver_main.md', self.authorize()['changed_paths'])

    def test_worklog_only_does_not_trigger_application_build(self):
        self.plan['allowed_paths'] = ['worklogs/new_ver_main.md']
        self.files = [dict(filename='worklogs/new_ver_main.md', status='modified')]
        with self.assertRaisesRegex(ValueError, 'application_change_required'):
            self.authorize()

    def test_rejects_identity_scope_security_and_schema(self):
        mutations = [
            lambda: self.pull.update(draft=False), lambda: self.pull.update(state='closed'),
            lambda: self.pull['head'].update(sha='e' * 40),
            lambda: self.pull['head']['repo'].update(full_name='attacker/oripa'),
            lambda: self.pull['base'].update(ref='production'),
            lambda: self.main.update(protected=False),
            lambda: self.plan.update(source_pr=456), lambda: self.plan.update(migration='forward-only'),
            lambda: self.plan.update(image_mode='production'), lambda: self.plan.update(focused_commands=[]),
            lambda: self.plan.update(allowed_paths='apps/admin/src/example.tsx'),
            lambda: self.plan.update(allowed_paths={'apps/admin/src/example.tsx': True}),
            lambda: self.plan.update(allowed_paths=[None]),
            lambda: self.plan.update(focused_commands='true'),
            lambda: self.plan.update(unapproved_skip=True),
            lambda: self.files[0].update(filename='.github/workflows/platform-ci.yml'),
            lambda: self.files[0].update(filename='apps/api/database/migrations-v2/new.php'),
            lambda: self.files[0].update(status='renamed'),
            lambda: self.files.extend(self.files * 300),
            lambda: self.checks[0].update(conclusion='failure'),
            lambda: self.checks[0].update(status='in_progress'),
            lambda: self.checks[0]['app'].update(id=42),
            lambda: self.checks[0].update(head_sha='e' * 40),
            lambda: setattr(self, 'workflow', '.github/workflows/unapproved.yml'),
        ]
        for mutate in mutations:
            with self.subTest(mutation=mutate):
                self.setUp()
                mutate()
                with self.assertRaises(ValueError):
                    self.authorize()

    def test_rejects_drift_during_authorization(self):
        calls = []
        def get(path):
            result = self.get(path)
            if '/pulls/' in path:
                calls.append(path)
                if len(calls) == 2:
                    result['head']['sha'] = 'e' * 40
            return result
        with self.assertRaisesRegex(ValueError, 'authority_moved'):
            MODULE['authorize'](TASK, PR, HEAD, CONTROL, get=get)

    def test_empty_committed_plan_disables_activation(self):
        def get(path):
            if '/contents/' in path:
                return dict(encoding='base64', content=base64.b64encode(json.dumps(dict(
                    schema_version='1.0', target='old-test', plans=[])).encode()).decode())
            return self.get(path)
        with self.assertRaisesRegex(ValueError, 'approved_plan_required'):
            MODULE['authorize'](TASK, PR, HEAD, CONTROL, get=get)

    def test_focused_command_failure_stops_build(self):
        with tempfile.TemporaryDirectory() as temporary:
            Path(temporary, 'source-authority.json').write_text(json.dumps(dict(plan=dict(
                focused_commands=[['python3', '-c', 'raise SystemExit(7)']]))))
            import os
            result = subprocess.run(['python3', str(ROOT / 'scripts/ops/old_test_draft_focus.py')],
                env=os.environ | {'RUNNER_TEMP': temporary}, capture_output=True)
            self.assertNotEqual(result.returncode, 0)

    def test_focused_commands_do_not_receive_auth_token(self):
        with tempfile.TemporaryDirectory() as temporary:
            command = ['python3', '-c', 'import os; assert "GH_TOKEN" not in os.environ and "GITHUB_TOKEN" not in os.environ']
            Path(temporary, 'source-authority.json').write_text(json.dumps(dict(plan=dict(focused_commands=[command]))))
            import os
            subprocess.run(['python3', str(ROOT / 'scripts/ops/old_test_draft_focus.py')], check=True,
                env=os.environ | {'RUNNER_TEMP': temporary, 'GH_TOKEN': 'synthetic', 'GITHUB_TOKEN': 'synthetic'})

    def test_draft_and_merged_artifacts_have_distinct_identity(self):
        wrapper = runpy.run_path(str(ROOT / 'infrastructure/github-app/oripa-github-app-api'))
        self.assertNotEqual(wrapper['artifact_name'](TASK, HEAD, True),
                            wrapper['artifact_name'](TASK, HEAD, True, True))
        run = dict(event='workflow_dispatch', status='completed', conclusion='success',
                   path='.github/workflows/old-test-main-artifact.yml', head_branch='main')
        with patch.dict(wrapper['validated_run'].__globals__, api_get=lambda _: run):
            with self.assertRaises(wrapper['WrapperError']):
                wrapper['validated_run'](1, True, True)

    def test_selected_builds_in_real_shell_without_docker(self, head=HEAD, selections=None):
        workflow = (ROOT / '.github/workflows/old-test-draft-preview.yml').read_text()
        script = workflow.split('      - name: Build and verify approved OLD Test AMD64 images', 1)[1]
        script = script.split('        run: |\n', 1)[1].split('\n      - name:', 1)[0]
        script = '\n'.join(line[10:] for line in script.splitlines())
        stub = '''#!/usr/bin/python3
import json,os,pathlib,subprocess,sys
name=pathlib.Path(sys.argv[0]).name
args=sys.argv[1:]
if name=='python3' and args[0]=='-c':
    sys.exit(subprocess.call(['/usr/bin/python3',*args]))
with open(os.environ['CALLS'],'a') as log: log.write(json.dumps([name,*args])+'\\n')
if name=='git': print(os.environ['INPUT_SOURCE_SHA'] if args[0]=='rev-parse' else '2026-11-19T00:00:00Z')
if name=='python3' and 'package' in args:
    pathlib.Path(os.environ['RUNNER_TEMP'],'old-test-images').mkdir()
'''
        for mode, expected in selections or [('api-only', ['approved-api.Dockerfile']),
                               ('admin-only', ['apps/admin/Dockerfile']),
                               ('normal', ['approved-api.Dockerfile', 'apps/admin/Dockerfile'])]:
            with self.subTest(mode=mode), tempfile.TemporaryDirectory() as temporary:
                import os
                root = Path(temporary)
                bin_dir = root / 'bin'; bin_dir.mkdir()
                for name in ('python3', 'docker', 'git', 'zstd'):
                    path = bin_dir / name; path.write_text(stub); path.chmod(0o755)
                recipe = root / 'control/infra/docker/backend/old-test-line-698.Dockerfile'
                recipe.parent.mkdir(parents=True); recipe.write_text('synthetic recipe')
                (root / 'source-authority.json').write_text(json.dumps(dict(image_mode=mode)))
                env = os.environ | dict(PATH=str(bin_dir) + ':/usr/bin:/bin', CALLS=str(root / 'calls'),
                    GITHUB_WORKSPACE=temporary, RUNNER_TEMP=temporary, INPUT_TASK_ID=TASK,
                    INPUT_PR_NUMBER=PR, INPUT_SOURCE_SHA=head)
                result = subprocess.run(['bash', '-euo', 'pipefail', '-c', script],
                    cwd=root, env=env, capture_output=True, text=True)
                self.assertEqual(result.returncode, 0, result.stderr)
                calls = [json.loads(line) for line in (root / 'calls').read_text().splitlines()]
                builds = [call for call in calls if call[:3] == ['docker', 'buildx', 'build']]
                self.assertEqual([Path(call[call.index('--file') + 1]).name for call in builds],
                                 [Path(path).name for path in expected])
                self.assertTrue(all('--load' in call and '--cache-from' in call for call in builds))
                self.assertTrue(all('OCI_REVISION=' + head in call for call in builds))

    def test_draft_migration_and_storefront_hold_before_runtime_reads(self):
        helper = runpy.run_path(str(ROOT / 'scripts/ops/old_test_deploy.py'))
        for services, migrations in [(['api'], ['new.php']), (['storefront'], [])]:
            candidate = dict(version=1, target='old-test', authority_mode='draft-preview',
                             services=services, migrations=migrations)
            deployment = helper['Deployment'](candidate, Path('/unused'))
            with self.assertRaisesRegex(helper['Hold'], 'draft_no_schema_or_storefront'):
                deployment.preflight()


class DraftContractFocusedTest(unittest.TestCase):
    def test_real_contract_comparison_accepts_addition_and_rejects_break_or_missing_base(self):
        focus = runpy.run_path(str(ROOT / 'scripts/ops/old_test_draft_focus.py'))
        for kind in ('additive', 'breaking', 'missing-base', 'bundle-drift', 'generator-failure', 'security-boundary'):
            with self.subTest(kind=kind), tempfile.TemporaryDirectory() as temporary:
                gate = runpy.run_path(str(ROOT / 'scripts/ci/openapi_contract_gate.py'))
                payload = Path(temporary)
                generated = {}
                for surface, data in gate['SURFACES'].items():
                    document = json.loads((ROOT / data['bundle']).read_text())
                    if surface == 'public':
                        if kind == 'breaking':
                            document['paths'].pop(next(iter(document['paths'])))
                        else:
                            document['info']['description'] = document['info'].get('description', '') + '\nDraft additive documentation.'
                            document['components']['schemas']['ProblemDetails'].setdefault('properties', {})['draft_preview_note'] = {'type': 'string'}
                        if kind == 'security-boundary':
                            document['security'] = [{'draftUnapproved': []}]
                    committed = payload / data['bundle']
                    committed.parent.mkdir(parents=True, exist_ok=True)
                    committed.write_text(json.dumps(document))
                    output = payload / (surface + '.json')
                    output.write_text(json.dumps(document) + ('\n' if kind == 'bundle-drift' else ''))
                    generated[surface] = output
                gate['generate_bundles'] = lambda *_: generated
                gate['previous_bundle'] = lambda _, __, relative: (
                    None if kind == 'missing-base' else json.loads((ROOT / relative).read_text()))
                authority = dict(plan=dict(baseline_sha=BASE), contract_paths=['openapi/public/openapi.yaml'])
                runner_globals = focus['verify_contracts'].__globals__
                with patch.object(runner_globals['subprocess'], 'run') as execute:
                    if kind == 'generator-failure':
                        execute.side_effect = subprocess.CalledProcessError(1, ['generated-check'])
                    if kind == 'additive':
                        focus['verify_contracts'](authority, payload, ROOT, {}, gate)
                        self.assertEqual(execute.call_args.args[0],
                            ['pnpm', '--dir', 'packages/storefront-client', 'generate:check'])
                    else:
                        with self.assertRaises((ValueError, gate['ContractFailure'], subprocess.CalledProcessError)):
                            focus['verify_contracts'](authority, payload, ROOT, {}, gate)


if __name__ == '__main__':
    unittest.main()
