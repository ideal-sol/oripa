import copy
import os
from pathlib import Path
import re
import subprocess
import textwrap
import unittest

from scripts.ci import platform_ci_plan as planner
from scripts.release.readiness import change, records
from tests.ci.policy import test_change_evidence as fixtures


class PlatformCIPlanTests(unittest.TestCase):
    setUp = fixtures.ChangeEvidenceTests.setUp
    git = fixtures.ChangeEvidenceTests.git
    write = fixtures.ChangeEvidenceTests.write
    authority = fixtures.ChangeEvidenceTests.authority

    def commit(self):
        self.git('add', '.')
        self.git('commit', '--allow-empty', '-qm', 'synthetic plan fixture')
        return self.git('rev-parse', 'HEAD')

    def plan(self, paths=(), **context):
        for path in paths:
            self.write(path, 'changed fixture bytes\n')
        record = change.classify(self.repository, self.base, self.commit())
        return planner.build_plan(record, **{
            'lane_key': 'strict', 'activation': 'none', 'event_name': 'pull_request',
            'validation_path': record['validation_path'], **context,
        })

    def assert_required(self, plan, *scopes):
        for scope in scopes:
            self.assertNotEqual(plan['scopes'][scope]['status'], 'NOT_APPLICABLE', scope)

    def assert_na(self, plan, *scopes):
        for scope in scopes:
            self.assertEqual(plan['scopes'][scope]['status'], 'NOT_APPLICABLE', scope)

    def results(self, plan):
        return {'policy-gate': {'result': 'success'}, 'quality-gate': {'result': 'success'},
                **{job: {'result': 'success' if value['required'] else 'skipped'}
                   for job, value in plan['jobs'].items()}}

    def test_feature_keeps_full_backend_and_container_db_checks(self):
        plan = self.plan(('apps/api/app/Domain/Catalog/Services/Asset.php',
                          'apps/api/bootstrap/app.php', 'apps/api/config/filesystems.php',
                          'apps/admin/src/app/layout.tsx', 'scripts/ci/policy_gate.py',
                          'tests/ci/policy/test_policy_gate.py'))
        self.assert_required(plan, 'backend', 'database', 'admin', 'contract', 'release_artifact', 'arm64_artifact')
        self.assert_na(plan, 'migration', 'legacy_frontend', 'storefront_client', 'testkit', 'site_schema')
        self.assertTrue(all(job['required'] for job in plan['jobs'].values()))

    def test_critical_backend_changes_never_select_test_subset(self):
        for domain in ('Auth', 'Point', 'Payment', 'Draw', 'Inventory'):
            with self.subTest(domain=domain):
                plan = self.plan((f'apps/api/app/Domain/{domain}/Service.php',))
                self.assert_required(plan, 'backend', 'database', 'contract', 'arm64_artifact')

    def test_migration_requires_both_backend_and_smoke(self):
        plan = self.plan(('apps/api/database/migrations-v2/fixture.php',))
        self.assert_required(plan, 'migration', 'database', 'backend')

    def test_backend_test_only_still_runs_full_backend_and_db(self):
        plan = self.plan(('apps/api/tests/V2/PaymentTest.php',))
        self.assertEqual(plan['component_deltas']['application_delta'], 'NONE')
        self.assert_required(plan, 'backend', 'database', 'arm64_artifact')

    def test_admin_change_retains_container_health_smoke(self):
        plan = self.plan(('apps/admin/src/app/page.tsx',))
        self.assert_required(plan, 'admin', 'database', 'arm64_artifact')
        self.assert_na(plan, 'backend', 'legacy_frontend', 'testkit')

    def test_contract_fans_out_to_all_consumers(self):
        plan = self.plan(('openapi/public.yaml',))
        self.assert_required(plan, 'contract', 'backend', 'admin', 'agency', 'storefront_client', 'testkit', 'site_schema')

    def test_package_tests_affect_consumers_even_without_contract_delta(self):
        plan = self.plan(('packages/storefront-client/test/fixture.ts',))
        self.assertEqual(plan['component_deltas']['contract_delta'], 'NONE')
        self.assert_required(plan, 'storefront_client', 'testkit', 'site_schema', 'backend')

    def test_legacy_still_builds_when_affected(self):
        plan = self.plan(('legacy/v1-frontend/src/app/page.tsx',))
        self.assert_required(plan, 'legacy_frontend')
        self.assertTrue(plan['jobs']['integration-support']['required'])

    def test_dependency_change_forces_all_scopes(self):
        plan = self.plan(('pnpm-lock.yaml',))
        self.assert_required(plan, *planner.SCOPES)

    def test_ci_execution_change_cannot_self_exempt(self):
        for path in ('.github/workflows/platform-ci.yml', 'scripts/ci/platform_ci_plan.py',
                     'scripts/ci/lane_policy.py', 'scripts/ci/security_gate.py',
                     'scripts/release/readiness/change.py', 'infra/docker/backend/Dockerfile'):
            with self.subTest(path=path):
                self.assert_required(self.plan((path,)), *planner.SCOPES)

    def test_authority_focused_preserved(self):
        self.authority()
        plan = self.plan(('worklogs/new_ver_main.md',))
        self.assert_na(plan, *planner.SCOPES)
        self.assertFalse(any(job['required'] for job in plan['jobs'].values()))
        planner.verify_results(plan, self.results(plan), plan['record_digest'])

    def test_authority_with_application_delta_not_exempt(self):
        self.authority()
        plan = self.plan(('apps/api/app/Example.php',))
        self.assert_required(plan, 'backend', 'database', 'arm64_artifact')

    def test_source_only_authority_deferred_preserves_activation_and_skips_jobs(self):
        self.authority()
        plan = self.plan(('tests/ops/test_production_source_authority.py',
                          'worklogs/new_ver_main.md'), activation='deferred')
        self.assertEqual(plan['activation'], 'deferred')
        self.assertEqual(plan['fallback'], 'NONE')
        self.assert_na(plan, *planner.SCOPES)
        self.assertFalse(any(job['required'] for job in plan['jobs'].values()))
        planner.verify_results(plan, self.results(plan), plan['record_digest'])
        self.assert_arm64_alignment(plan, 'NOT_APPLICABLE')

    def test_authority_does_not_hide_database_or_uncovered_tests(self):
        for path in ('tests/db/test_smoke.py', 'apps/api/tests/V2/PaymentTest.php',
                     'tests/ops/test_unknown.py', 'scripts/ci/platform_ci_plan.py'):
            with self.subTest(path=path):
                self.setUp()
                self.authority()
                plan = self.plan((path,), activation='deferred')
                self.assert_required(plan, *planner.SCOPES)
                self.assertFalse(planner.source_only_authority(
                    change.classify(self.repository, self.base, self.git('rev-parse', 'HEAD'))))

    def test_authority_immediate_non_pr_and_normal_path_are_not_exempt(self):
        self.authority()
        for context in ({'activation': 'immediate'}, {'event_name': 'push'},
                        {'activation': 'deferred', 'validation_path': 'NORMAL_STRICT_CI'}):
            with self.subTest(context=context):
                plan = self.plan(**context)
                self.assertTrue(all(job['required'] for job in plan['jobs'].values()))

    def test_operation_wrapper_and_focused_test_skip_integration(self):
        plan = self.plan((*sorted(planner.OPERATION_WRAPPER_PATHS), 'worklogs/new_ver_main.md'))
        self.assertEqual(plan['validation_path'], 'NORMAL_STRICT_CI')
        self.assertEqual(plan['component_deltas']['ci_governance_delta'], 'PRESENT')
        self.assert_na(plan, *planner.SCOPES)
        self.assertFalse(any(job['required'] for job in plan['jobs'].values()))
        planner.verify_results(plan, self.results(plan), plan['record_digest'])
        self.assert_arm64_alignment(plan, 'NOT_APPLICABLE')

    def test_decision_wrappers_and_codex_settings_remain_full(self):
        for path in ('infrastructure/github-app/provision_git_wrapper.py',
                     'infrastructure/github-app/oripa-github-app-git',
                     'infrastructure/github-app/oripa-github-app-api',
                     'infrastructure/github-app/check_run_gate.py',
                     'infrastructure/github-app/self_review_policy.py',
                     '.codex/config.toml', '.codex/rules/governance.rules'):
            with self.subTest(path=path):
                self.setUp()
                self.assert_required(self.plan((path,)), *planner.SCOPES)

    def test_wrapper_mixed_unknown_and_db_changes_remain_required(self):
        for path in ('apps/api/app/Example.php', 'scripts/db/v2_database.py',
                     'tests/db/test_smoke.py', 'apps/api/database/migrations-v2/fixture.php',
                     'apps/api/config/database.php', 'pnpm-lock.yaml', 'unknown-hook'):
            with self.subTest(path=path):
                self.setUp()
                plan = self.plan(('infrastructure/github-app/task_branch_base_sync.py', path))
                self.assert_required(plan, 'backend', 'database', 'arm64_artifact')

    def test_wrapper_deferred_and_non_pr_remain_full(self):
        for context in ({'activation': 'deferred'}, {'activation': 'immediate'},
                        {'event_name': 'push'}, {'event_name': 'workflow_dispatch'}):
            with self.subTest(context=context):
                plan = self.plan(tuple(planner.OPERATION_WRAPPER_PATHS), **context)
                self.assertTrue(all(job['required'] for job in plan['jobs'].values()))

    def test_deleted_and_renamed_db_inputs_cannot_hide_in_wrapper(self):
        for rename in (False, True):
            with self.subTest(rename=rename):
                self.setUp()
                target = 'infrastructure/github-app/task_branch_base_sync.py'
                self.write(target, 'fixture\n')
                if rename:
                    self.git('mv', '-f', 'apps/api/app/Example.php', target)
                else:
                    self.git('rm', 'apps/api/app/Example.php')
                self.assert_required(self.plan(), 'backend', 'database', 'arm64_artifact')

    def test_symlink_wrapper_fails_closed(self):
        target = self.repository / 'infrastructure/github-app/task_branch_base_sync.py'
        target.parent.mkdir(parents=True)
        target.symlink_to('../../apps/api/app/Example.php')
        self.assert_required(self.plan(), *planner.SCOPES)

    def test_tampered_source_evidence_cannot_skip(self):
        self.authority()
        record = change.classify(self.repository, self.base, self.commit())
        for key in ('base_sha', 'head_sha', 'tree_sha', 'record_digest'):
            with self.subTest(key=key):
                forged = copy.deepcopy(record)
                forged[key] = 'f' * 40
                plan = planner.build_plan(forged, lane_key='strict', activation='deferred',
                                          event_name='pull_request', validation_path='AUTHORITY_FOCUSED')
                self.assert_required(plan, *planner.SCOPES)

    def test_na_jobs_reject_success_failure_and_missing_results(self):
        self.authority()
        plan = self.plan(activation='deferred')
        for job in planner.JOBS:
            for result in ('success', 'failure', 'cancelled', None):
                with self.subTest(job=job, result=result):
                    needs = self.results(plan)
                    needs[job]['result'] = result
                    with self.assertRaises(records.RecordError):
                        planner.verify_results(plan, needs, plan['record_digest'])

    def test_unknown_source_forces_full_validation_even_for_lite(self):
        for lane in ('strict', 'lite'):
            plan = self.plan(('unknown-hook',), lane_key=lane)
            self.assertEqual(plan['fallback'], 'NORMAL_STRICT_CI')
            self.assertTrue(all(job['required'] for job in plan['jobs'].values()))
            self.assertEqual({scope['status'] for scope in plan['scopes'].values()}, {'UNKNOWN'})

    def test_unknown_application_surface_falls_back(self):
        plan = self.plan(('apps/new-service/src/main.ts',))
        self.assertEqual(plan['component_deltas']['application_delta'], 'PRESENT')
        self.assertEqual(plan['fallback'], 'NORMAL_STRICT_CI')
        self.assert_required(plan, *planner.SCOPES)

    def test_rename_out_of_scope_cannot_hide_removed_inputs(self):
        (self.repository / 'docs').mkdir()
        self.git('mv', 'apps/api/app/Example.php', 'docs/Example.php')
        self.assert_required(self.plan(), 'backend', 'database')

    def test_activation_always_runs_full(self):
        for context in ({'activation': 'immediate'}, {'activation': 'deferred'}):
            with self.subTest(context=context):
                self.assert_required(self.plan(('docs/fixture.md',), **context), *planner.SCOPES)

    def workflow(self):
        return (Path(__file__).resolve().parents[3] / '.github/workflows/platform-ci.yml').read_text()

    def arm64_executes(self, plan):
        block = self.workflow().split('  production-arm64-artifact:\n', 1)[1].split('    runs-on:', 1)[0]
        condition = re.search(r'^    if: (.+)$', block, re.MULTILINE).group(1)
        self.assertEqual(condition, "fromJSON(needs.policy-gate.outputs.plan_scopes).arm64_artifact != 'NOT_APPLICABLE'")
        return plan['scopes']['arm64_artifact']['status'] != 'NOT_APPLICABLE'

    def arm64_gate(self, status, result, validation_path='NORMAL_STRICT_CI'):
        gate = self.workflow().split('  ci-gate:\n', 1)[1]
        self.assertIn('ARM64_STATUS: ${{ fromJSON(needs.policy-gate.outputs.plan_scopes).arm64_artifact }}', gate)
        script = textwrap.dedent(gate.split('        run: |\n', 1)[1])
        return subprocess.run(['bash', '-e', '-c', script], capture_output=True, text=True, env={
            **os.environ, 'POLICY_GATE_RESULT': 'success', 'QUALITY_GATE_RESULT': 'success',
            'SECURITY_GATE_RESULT': 'success', 'INTEGRATION_GATE_RESULT': 'success',
            'ARM64_STATUS': status, 'PRODUCTION_ARM64_RESULT': result, 'VALIDATION_PATH': validation_path,
        }).returncode

    def assert_arm64_alignment(self, plan, expected):
        status = plan['scopes']['arm64_artifact']['status']
        self.assertEqual(status, expected)
        executes = self.arm64_executes(plan)
        self.assertEqual(executes, expected != 'NOT_APPLICABLE')
        self.assertEqual(self.arm64_gate(status, 'success' if executes else 'skipped', plan['validation_path']), 0)

    def test_arm64_runtime_pr_required_in_every_lane(self):
        for lane in ('strict', 'standard', 'lite'):
            with self.subTest(lane=lane):
                plan = self.plan(('apps/admin/src/app/page.tsx',), lane_key=lane)
                self.assertEqual(plan['component_deltas']['application_delta'], 'PRESENT')
                self.assert_arm64_alignment(plan, 'APPLICABLE')

    def test_arm64_authority_focused_skipped(self):
        self.authority()
        self.assert_arm64_alignment(self.plan(('worklogs/new_ver_main.md',)), 'NOT_APPLICABLE')

    def test_arm64_proven_non_runtime_inputs_skipped(self):
        plan = self.plan(('docs/fixture.md', 'tests/ci/policy/test_policy_gate.py'))
        for component in ('application_delta', 'dependency_delta', 'runtime_config_delta'):
            self.assertEqual(plan['component_deltas'][component], 'NONE')
        self.assertFalse(plan['scopes']['arm64_artifact']['evidence'])
        self.assert_arm64_alignment(plan, 'NOT_APPLICABLE')

    def test_arm64_unknown_required_in_every_lane(self):
        for lane in ('strict', 'standard', 'lite'):
            with self.subTest(lane=lane):
                self.assert_arm64_alignment(self.plan(('unknown-hook',), lane_key=lane), 'UNKNOWN')

    def test_non_pr_full_validation_preserves_arm64_pr_only_boundary(self):
        for event in ('push', 'workflow_dispatch'):
            for path in ('docs/fixture.md', 'apps/admin/src/app/page.tsx', 'unknown-hook'):
                with self.subTest(event=event, path=path):
                    plan = self.plan((path,), event_name=event)
                    self.assert_required(plan, *(scope for scope in planner.SCOPES if scope != 'arm64_artifact'))
                    self.assert_arm64_alignment(plan, 'NOT_APPLICABLE')
                    self.assertEqual(plan['scopes']['arm64_artifact']['reason'], 'PR_ONLY_ARM64_BOUNDARY')

    def test_arm64_applicable_and_unknown_require_success(self):
        for status in ('APPLICABLE', 'UNKNOWN'):
            for result in ('skipped', 'failure', 'cancelled', 'running', ''):
                with self.subTest(status=status, result=result):
                    self.assertNotEqual(self.arm64_gate(status, result), 0)
            self.assertEqual(self.arm64_gate(status, 'success'), 0)

    def test_arm64_na_rejects_unexpected_execution(self):
        for result in ('success', 'running', 'failure', 'cancelled', ''):
            with self.subTest(result=result):
                self.assertNotEqual(self.arm64_gate('NOT_APPLICABLE', result), 0)
        self.assertEqual(self.arm64_gate('NOT_APPLICABLE', 'skipped'), 0)

    def test_arm64_invalid_plan_status_fails_closed(self):
        for status in ('', 'false', 'INVALID'):
            for result in ('success', 'skipped'):
                with self.subTest(status=status, result=result):
                    self.assertNotEqual(self.arm64_gate(status, result), 0)

    def test_required_five_contexts_remain_unchanged(self):
        names = re.findall(r'^    name: (.+)$', self.workflow(), re.MULTILINE)
        self.assertEqual({name for name in names if name.endswith('-gate')}, {
            'policy-gate', 'quality-gate', 'security-gate', 'integration-gate', 'ci-gate',
        })

    def test_lite_keeps_existing_focused_quality_boundary(self):
        plan = self.plan(('apps/admin/src/app/page.tsx',), lane_key='lite')
        self.assertFalse(any(job['required'] for job in plan['jobs'].values()))

    def test_missing_failed_cancelled_skipped_required_jobs_block(self):
        plan = self.plan(('apps/api/app/Example.php',))
        for job in (*planner.JOBS, 'quality-gate', 'policy-gate'):
            for status in ('failure', 'cancelled', 'skipped', None):
                with self.subTest(job=job, status=status):
                    needs = self.results(plan)
                    if status is None:
                        del needs[job]
                    else:
                        needs[job]['result'] = status
                    with self.assertRaises(records.RecordError):
                        planner.verify_results(plan, needs, plan['record_digest'])

    def test_missing_na_result_and_digest_drift_block(self):
        plan = self.plan(('docs/fixture.md',))
        needs = self.results(plan)
        planner.verify_results(plan, needs, plan['record_digest'])
        del needs['integration-suite']
        with self.assertRaises(records.RecordError):
            planner.verify_results(plan, needs, plan['record_digest'])
        with self.assertRaises(records.RecordError):
            planner.verify_results(plan, self.results(plan), 'sha256:' + '0' * 64)

    def test_plan_binds_source_tree_components_and_paths(self):
        plan = self.plan(('apps/api/app/Example.php',))
        self.assertEqual(plan['head_sha'], self.git('rev-parse', 'HEAD'))
        self.assertEqual(plan['tree_sha'], self.git('rev-parse', 'HEAD^{tree}'))
        records.check_digest(plan, 'record_digest')
        for scope in plan['scopes'].values():
            self.assertEqual(scope['changed_paths_digest'], plan['changed_paths_digest'])
            self.assertEqual(scope['component_deltas'], plan['component_deltas'])
        forged = copy.deepcopy(plan)
        forged['jobs']['integration-suite']['required'] = False
        with self.assertRaises(records.RecordError):
            records.check_digest(forged, 'record_digest')

    def test_workflow_parallelism_and_quality_authority_are_explicit(self):
        workflow = (Path(__file__).resolve().parents[3] / '.github/workflows/platform-ci.yml').read_text()
        for job in planner.JOBS:
            block = workflow.split('  ' + job + ':\n', 1)[1].split('\n  ', 1)[0]
            self.assertTrue(block)
            self.assertIn('fromJSON(needs.policy-gate.outputs.plan_jobs).' + job, workflow)
            self.assertIn('      - ' + job + '\n', workflow)
        gate = workflow.split('  integration-gate:\n', 1)[1].split('  ci-gate:\n', 1)[0]
        self.assertIn('      - quality-gate\n', gate)
        self.assertIn('--expected-digest "$EXPECTED_PLAN_DIGEST"', gate)
        self.assertIn('toJSON(needs)', gate)
        for command in ('pnpm admin:build', 'pnpm storefront:check', 'pnpm site-schema:check', 'pnpm testkit:check'):
            self.assertEqual(workflow.count(command), 1)
        self.assertIn('php artisan test\n', workflow)
        self.assertIn('python3 scripts/db/v2_database.py smoke', workflow)
        self.assertNotIn('continue-on-error', workflow)


if __name__ == '__main__':
    unittest.main()
