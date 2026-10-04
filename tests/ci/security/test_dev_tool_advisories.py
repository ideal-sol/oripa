import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[3]
SPEC = importlib.util.spec_from_file_location(
    "security_gate", ROOT / "scripts/ci/security_gate.py"
)
security_gate = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(security_gate)


class DevToolAdvisoryTest(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.repository = Path(directory.name)
        self.baseline = json.loads(
            (ROOT / ".ci/baselines/dependency-advisories.json").read_text()
        )
        self.exception = self.baseline["approved_dev_tool_advisories"][0]
        for path in (
            "apps/admin/package.json", "legacy/v1-frontend/package.json",
            "pnpm-lock.yaml", "legacy/v1-frontend/pnpm-lock.yaml",
        ):
            target = self.repository / path
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text((ROOT / path).read_text())
        composer = self.repository / "apps/api/composer.lock"
        composer.parent.mkdir(parents=True)
        composer.write_text(json.dumps({"packages": []}))
        self.audits = {"composer": {"advisories": []}}
        self.statuses = {"composer": 0}
        for scope in ("workspace", "legacy"):
            advisory = copy.deepcopy(self.exception["advisory_metadata"])
            advisory["findings"] = [{
                "version": "3.0.3", "paths": [self.exception["paths"][scope]],
            }]
            audit = self.clean_audit()
            audit["advisories"]["1240992"] = advisory
            audit["metadata"]["vulnerabilities"]["high"] = 1
            self.audits[scope + "-pnpm"] = audit
            self.audits[scope + "-prod"] = self.clean_audit()
            self.statuses[scope + "-pnpm"] = 1
            self.statuses[scope + "-prod"] = 0

    def clean_audit(self):
        return {
            "advisories": {}, "muted": [],
            "metadata": {"vulnerabilities": {
                "info": 0, "low": 0, "moderate": 0, "high": 0, "critical": 0,
            }},
        }

    def validate(self, audits=None, statuses=None):
        return security_gate.validate_dependency_audits(
            self.repository, self.audits if audits is None else audits,
            self.statuses if statuses is None else statuses, self.baseline,
        )

    def test_current_workspace_and_legacy_paths_pass_without_hiding_findings(self):
        summary = self.validate()
        self.assertEqual(summary["current_findings"], 2)
        self.assertEqual(summary["approved_dev_tool_advisories"], 1)
        self.assertEqual(summary["approved_exact_exceptions"], 2)
        self.assertEqual(summary["unapproved_findings"], 0)
        self.assertEqual(summary["workspace_pnpm_findings"], 1)
        self.assertEqual(summary["pnpm_findings"], 1)
        self.assertEqual(summary["pnpm"]["current_findings"], 1)
        self.assertEqual(summary["pnpm"]["approved_exact_exceptions"], 1)
        for scope in ("workspace", "legacy"):
            result = summary["dev_tool_scopes"][scope]
            self.assertEqual(result["current_findings"], 1)
            self.assertEqual(result["approved_exact_exceptions"], 1)
            self.assertEqual(result["runtime_findings"], 0)
            self.assertEqual(result["unapproved_findings"], 0)
            self.assertEqual(result["resolved_exception_entries"], 0)
            evidence = result["approved_dev_tool_advisories"][0]
            self.assertEqual(evidence["dependency_section"], "devDependencies")
            self.assertEqual(evidence["patched_versions"], "<0.0.0")

    def test_exact_workspace_only_and_legacy_only_pass(self):
        for resolved in ("workspace", "legacy"):
            with self.subTest(resolved=resolved):
                audits = copy.deepcopy(self.audits)
                audits[resolved + "-pnpm"] = self.clean_audit()
                statuses = {**self.statuses, resolved + "-pnpm": 0}
                summary = self.validate(audits, statuses)
                self.assertEqual(summary["current_findings"], 1)
                self.assertEqual(summary["approved_exact_exceptions"], 1)
                self.assertEqual(summary["dev_tool_scopes"][resolved]["resolved_exception_entries"], 1)

    def test_resolved_advisory_passes_without_requiring_old_dev_dependency(self):
        for scope in ("workspace", "legacy"):
            self.audits[scope + "-pnpm"] = self.clean_audit()
            self.statuses[scope + "-pnpm"] = 0
            path = self.repository / security_gate.DEV_TOOL_SCOPES[scope][0]
            path.write_text("{}")
        summary = self.validate()
        self.assertEqual(summary["current_findings"], 0)
        self.assertEqual(summary["approved_exact_exceptions"], 0)
        for scope in ("workspace", "legacy"):
            self.assertEqual(summary["dev_tool_scopes"][scope]["resolved_exception_entries"], 1)

    def test_different_advisory_package_version_path_or_severity_fails(self):
        for scope in ("workspace", "legacy"):
            for field in ("advisory", "package", "version", "path", "severity"):
                with self.subTest(scope=scope, field=field):
                    audits = copy.deepcopy(self.audits)
                    audit = audits[scope + "-pnpm"]
                    advisory = audit["advisories"]["1240992"]
                    if field == "advisory":
                        advisory["url"] = "https://github.com/advisories/GHSA-abcd-1234-5678"
                    elif field == "package":
                        advisory["module_name"] = "micromatch"
                        advisory["findings"][0]["version"] = "4.0.8"
                    elif field == "version":
                        advisory["findings"][0]["version"] = "3.0.4"
                    elif field == "path":
                        advisory["findings"][0]["paths"] = [".>next>braces"]
                    else:
                        advisory["severity"] = "critical"
                        audit["metadata"]["vulnerabilities"].update(high=0, critical=1)
                    with self.assertRaises(security_gate.SecurityFailure):
                        self.validate(audits)

    def test_changed_version_still_fails_when_present_in_changed_lock(self):
        for scope, path in (
            ("workspace", "pnpm-lock.yaml"), ("legacy", "legacy/v1-frontend/pnpm-lock.yaml")
        ):
            with self.subTest(scope=scope):
                audits = copy.deepcopy(self.audits)
                audits[scope + "-pnpm"]["advisories"]["1240992"]["findings"][0]["version"] = "3.0.4"
                lock = self.repository / path
                lock.write_text(lock.read_text().replace("braces@3.0.3:", "braces@3.0.4:"))
                with self.assertRaises(security_gate.SecurityFailure):
                    self.validate(audits)
                lock.write_text((ROOT / path).read_text())

    def test_patched_versions_and_important_metadata_changes_fail(self):
        changes = {
            "patched_versions": ">=3.0.4", "vulnerable_versions": "<4",
            "github_advisory_id": "GHSA-abcd-1234-5678", "id": 1234,
            "cves": ["CVE-2099-0001"], "cwe": ["CWE-400"],
            "cvss": {"score": 9.8}, "overview": "changed", "recommendation": "upgrade",
        }
        for scope in ("workspace", "legacy"):
            for field, value in changes.items():
                with self.subTest(scope=scope, field=field):
                    audits = copy.deepcopy(self.audits)
                    audits[scope + "-pnpm"]["advisories"]["1240992"][field] = value
                    with self.assertRaisesRegex(security_gate.SecurityFailure, "metadata changed"):
                        self.validate(audits)

    def test_root_dependency_cannot_move_to_runtime_optional_or_peer_scope(self):
        for scope, (path, _) in security_gate.DEV_TOOL_SCOPES.items():
            for section in ("dependencies", "optionalDependencies", "peerDependencies"):
                with self.subTest(scope=scope, section=section):
                    manifest = json.loads((ROOT / path).read_text())
                    manifest.setdefault(section, {})["eslint-config-next"] = manifest["devDependencies"].pop("eslint-config-next")
                    (self.repository / path).write_text(json.dumps(manifest))
                    with self.assertRaisesRegex(security_gate.SecurityFailure, "not dev-only"):
                        self.validate()
                    (self.repository / path).write_text((ROOT / path).read_text())

    def test_dev_and_runtime_duplicate_root_is_not_dev_only(self):
        path = self.repository / "apps/admin/package.json"
        manifest = json.loads(path.read_text())
        manifest["dependencies"]["eslint-config-next"] = manifest["devDependencies"]["eslint-config-next"]
        path.write_text(json.dumps(manifest))
        with self.assertRaisesRegex(security_gate.SecurityFailure, "not dev-only"):
            self.validate()

    def test_production_audit_finding_never_receives_exception(self):
        for scope in ("workspace", "legacy"):
            with self.subTest(scope=scope):
                audits = copy.deepcopy(self.audits)
                audits[scope + "-prod"] = copy.deepcopy(audits[scope + "-pnpm"])
                statuses = {**self.statuses, scope + "-prod": 1}
                with self.assertRaisesRegex(security_gate.SecurityFailure, "runtime audit contains findings"):
                    self.validate(audits, statuses)

    def test_additional_path_or_duplicate_finding_is_not_approved(self):
        for scope in ("workspace", "legacy"):
            for path in (".>next>braces", self.exception["paths"][scope]):
                with self.subTest(scope=scope, path=path):
                    audits = copy.deepcopy(self.audits)
                    audits[scope + "-pnpm"]["advisories"]["1240992"]["findings"][0]["paths"].append(path)
                    with self.assertRaises(security_gate.SecurityFailure):
                        self.validate(audits)

    def test_new_second_advisory_is_not_approved(self):
        for scope in ("workspace", "legacy"):
            with self.subTest(scope=scope):
                audits = copy.deepcopy(self.audits)
                audit = audits[scope + "-pnpm"]
                second = copy.deepcopy(audit["advisories"]["1240992"])
                second["url"] = "https://github.com/advisories/GHSA-abcd-1234-5678"
                audit["advisories"]["9999"] = second
                audit["metadata"]["vulnerabilities"]["high"] = 2
                with self.assertRaises(security_gate.SecurityFailure):
                    self.validate(audits)

    def test_unavailable_malformed_and_exit_mismatch_fail_closed(self):
        for source in ("workspace-pnpm", "legacy-pnpm", "workspace-prod", "legacy-prod"):
            with self.subTest(source=source):
                with self.assertRaises(security_gate.SecurityFailure):
                    self.validate({**self.audits, source: {"error": "unavailable"}})
                with self.assertRaises(security_gate.SecurityFailure):
                    self.validate(statuses={**self.statuses, source: 2})
                with self.assertRaises(security_gate.SecurityFailure):
                    self.validate(
                        {**self.audits, source: self.clean_audit()},
                        {**self.statuses, source: 1},
                    )
        with self.assertRaises(security_gate.SecurityFailure):
            self.validate(statuses={key: value for key, value in self.statuses.items() if key != "workspace-prod"})

    def test_unapproved_or_widened_policy_cannot_authorize_findings(self):
        original = copy.deepcopy(self.baseline)
        for field, value in (
            ("approval", {"status": "PENDING"}), ("version", "3.0.4"),
            ("paths", {"workspace": ".>next>braces"}),
        ):
            with self.subTest(field=field):
                self.baseline = copy.deepcopy(original)
                self.baseline["approved_dev_tool_advisories"][0][field] = value
                with self.assertRaisesRegex(security_gate.SecurityFailure, "Human-approved identity"):
                    self.validate()

    def test_generic_baseline_and_dependency_review_remain_unexpanded(self):
        self.assertEqual(self.baseline["composer"], [])
        self.assertEqual(self.baseline["pnpm"], [])
        self.assertEqual(len(self.baseline["approved_dev_tool_advisories"]), 1)
        self.assertNotIn("expires_at", json.dumps(self.baseline))
        workflow = (ROOT / ".github/workflows/dependency-review.yml").read_text()
        self.assertNotIn("allow-ghsas", workflow)
        self.assertNotIn("GHSA-vfj7-8cjw-p6xm", workflow)

    def test_workflow_requires_both_production_only_audits(self):
        workflow = (ROOT / ".github/workflows/platform-ci.yml").read_text()
        self.assertIn("pnpm audit --prod --audit-level low --json", workflow)
        self.assertIn('--workspace-prod-audit "$RUNNER_TEMP/workspace-prod-audit.json"', workflow)
        self.assertIn('--legacy-prod-audit "$RUNNER_TEMP/legacy-prod-audit.json"', workflow)


if __name__ == "__main__":
    unittest.main()
