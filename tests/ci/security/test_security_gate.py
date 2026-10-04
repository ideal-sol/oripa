import copy
import contextlib
import importlib.util
import inspect
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest import mock


ROOT = Path(__file__).resolve().parents[3]
SPEC = importlib.util.spec_from_file_location(
    "security_gate", ROOT / "scripts/ci/security_gate.py"
)
security_gate = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(security_gate)


class SecurityGateTest(unittest.TestCase):
    def baseline(self):
        return {
            "schema_version": "1.1",
            "management": {
                "owner": "security-owners",
                "reason": "fixture",
                "removal_condition": "fixture",
                "tracking_task": "FIXTURE-001",
            },
            "composer": [],
            "pnpm": [],
        }

    def finding(self, source="composer"):
        finding = {
            "source": source,
            "advisory_id": "GHSA-1234-5678-9abc",
            "package": "fixture",
            "version": "1.0.0",
            "severity": "moderate",
        }
        if source == "composer":
            finding["cve"] = None
        else:
            finding.update(audit_id="1", path=".>fixture")
        return finding

    def pnpm_audit(self):
        return {
            "advisories": {
                "1": {
                    "url": "https://github.com/advisories/GHSA-1234-5678-9abc",
                    "module_name": "fixture",
                    "severity": "moderate",
                    "findings": [{"version": "1.0.0", "paths": [".>fixture"]}],
                }
            },
            "metadata": {"vulnerabilities": {
                "info": 0, "low": 0, "moderate": 1, "high": 0, "critical": 0,
            }},
        }

    def pnpm_lock(self):
        return "lockfileVersion: '9.0'\nimporters:\n  .: {}\npackages:\n  fixture@1.0.0:\n"

    def test_clean_composer_audit_empty_list_is_accepted(self):
        self.assertEqual(
            security_gate.composer_findings({"advisories": []}, {"packages": []}),
            [],
        )

    def test_composer_audit_advisory_object_is_preserved(self):
        audit = {
            "advisories": {
                "example/package": [
                    {
                        "advisoryId": "PKSA-fixture",
                        "cve": "CVE-2026-0001",
                        "severity": "high",
                    }
                ]
            }
        }
        lock = {"packages": [{"name": "example/package", "version": "1.0.0"}]}

        self.assertEqual(
            security_gate.composer_findings(audit, lock),
            [
                {
                    "source": "composer",
                    "advisory_id": "PKSA-fixture",
                    "package": "example/package",
                    "version": "1.0.0",
                    "severity": "high",
                    "cve": "CVE-2026-0001",
                }
            ],
        )

    def test_composer_audit_missing_advisories_fails_closed(self):
        with self.assertRaisesRegex(
            security_gate.SecurityFailure, "advisories are missing"
        ):
            security_gate.composer_findings({}, {"packages": []})

    def test_composer_audit_nonempty_list_fails_closed(self):
        with self.assertRaisesRegex(
            security_gate.SecurityFailure, "advisories are malformed"
        ):
            security_gate.composer_findings(
                {"advisories": [{"advisoryId": "PKSA-fixture"}]},
                {"packages": []},
            )

    def test_private_key_candidate_is_detected_without_value_output(self):
        data = b"-----BEGIN " + b"PRIVATE KEY-----\\nredacted\\n"
        categories = [
            name
            for name, pattern in security_gate.SECRET_PATTERNS.items()
            if pattern.search(data)
        ]
        self.assertEqual(categories, ["private-key"])

    def test_new_dependency_advisory_fails_exact_baseline(self):
        for source in ("composer", "pnpm"):
            with self.subTest(source=source), self.assertRaisesRegex(
                security_gate.SecurityFailure, "baseline mismatch"
            ):
                actual = {"composer": [], "pnpm": []}
                actual[source] = [self.finding(source)]
                security_gate.validate_dependency_baseline(**actual, baseline=self.baseline())

    def test_v2_workspace_advisory_cannot_enter_v1_baseline(self):
        finding = {
            "source": "pnpm",
            "advisory_id": "GHSA-fixture",
            "audit_id": "1",
            "package": "fixture",
            "version": "1.0.0",
            "severity": "high",
            "path": "apps__admin>fixture",
        }
        with self.assertRaisesRegex(
            security_gate.SecurityFailure, "do not extend the V1 baseline"
        ):
            security_gate.validate_workspace_pnpm_audit([finding])

    def test_clean_v2_workspace_audit_passes(self):
        self.assertEqual(security_gate.validate_workspace_pnpm_audit([]), 0)

    def test_baseline_has_no_clock_input_or_expiry(self):
        self.assertEqual(
            list(inspect.signature(security_gate.validate_dependency_baseline).parameters),
            ["composer", "pnpm", "baseline"],
        )
        summary = security_gate.validate_dependency_baseline([], [], self.baseline())
        self.assertNotIn("expires_at", summary)
        self.assertEqual(summary["composer"]["new_findings"], 0)

    def test_known_and_resolved_advisories_pass(self):
        for source in ("composer", "pnpm"):
            baseline = self.baseline()
            baseline[source] = [self.finding(source)]
            for resolved in (False, True):
                with self.subTest(source=source, resolved=resolved):
                    actual = {"composer": [], "pnpm": []}
                    actual[source] = [] if resolved else baseline[source]
                    summary = security_gate.validate_dependency_baseline(**actual, baseline=baseline)
                    self.assertEqual(summary[source]["known_findings"], int(not resolved))
                    self.assertEqual(summary[source]["resolved_baseline_entries"], int(resolved))
                    self.assertEqual(summary[source]["new_findings"], 0)

    def test_changed_advisory_fingerprint_fails(self):
        for source in ("composer", "pnpm"):
            for key, value in {
                "severity": "high", "advisory_id": "different", "package": "different",
                "version": "2.0.0", **({"path": "new>fixture"} if source == "pnpm" else {}),
            }.items():
                with self.subTest(source=source, key=key), self.assertRaisesRegex(
                    security_gate.SecurityFailure, "baseline mismatch"
                ):
                    baseline = self.baseline()
                    baseline[source] = [self.finding(source)]
                    actual = {"composer": [], "pnpm": []}
                    actual[source] = [{**self.finding(source), key: value}]
                    security_gate.validate_dependency_baseline(**actual, baseline=baseline)

    def test_baseline_schema_metadata_and_findings_fail_closed(self):
        for key, value in (("schema_version", "1.0"), ("management", None), ("composer", None), ("pnpm", [{}])):
            with self.subTest(key=key), self.assertRaises(security_gate.SecurityFailure):
                security_gate.validate_dependency_baseline([], [], {**self.baseline(), key: value})
        for key in self.baseline()["management"]:
            for value in (None, "", [], 123):
                with self.subTest(key=key, value=value), self.assertRaises(security_gate.SecurityFailure):
                    baseline = self.baseline()
                    baseline["management"][key] = value
                    security_gate.validate_dependency_baseline([], [], baseline)

    def test_pnpm_audit_normalizes_and_binds_locked_version(self):
        self.assertEqual(
            security_gate.pnpm_findings(self.pnpm_audit(), self.pnpm_lock()),
            [self.finding("pnpm")],
        )
        with self.assertRaisesRegex(security_gate.SecurityFailure, "absent from lock"):
            security_gate.pnpm_findings(self.pnpm_audit(), self.pnpm_lock().replace("1.0.0", "2.0.0"))

    def test_pnpm_missing_or_malformed_audit_fails_closed(self):
        cases = [None, [], {}, {"error": "unavailable"}, {"advisories": {}},
                 {**self.pnpm_audit(), "advisories": {}}, {**self.pnpm_audit(), "muted": ["suppressed"]}]
        for key, value in (("findings", []), ("findings", [{}]), ("severity", None), ("url", "")):
            audit = copy.deepcopy(self.pnpm_audit())
            audit["advisories"]["1"][key] = value
            cases.append(audit)
        for audit in cases:
            with self.subTest(audit=audit), self.assertRaises(security_gate.SecurityFailure):
                security_gate.pnpm_findings(audit, self.pnpm_lock())

    def test_clean_pnpm_audit_has_explicit_zero_counts(self):
        audit = self.pnpm_audit()
        audit["advisories"] = {}
        audit["metadata"]["vulnerabilities"]["moderate"] = 0
        self.assertEqual(security_gate.pnpm_findings(audit, self.pnpm_lock()), [])

    def test_composer_lock_mismatch_and_incomplete_identity_fail_closed(self):
        for package, advisory in (("absent", {"advisoryId": "fixture"}), ("fixture", {})):
            with self.subTest(package=package), self.assertRaises(security_gate.SecurityFailure):
                security_gate.composer_findings(
                    {"advisories": {package: [advisory]}},
                    {"packages": [{"name": "fixture", "version": "1.0.0"}]},
                )

    def test_audit_command_exit_status_cannot_be_swallowed(self):
        for source in ("composer", "workspace-pnpm", "legacy-pnpm"):
            security_gate.validate_audit_status(0, [], source)
            security_gate.validate_audit_status(1, [self.finding()], source)
            for status, findings in ((1, []), (2, []), (2, [self.finding()]), (0, [self.finding()]), (None, [])):
                with self.subTest(source=source, status=status), self.assertRaises(security_gate.SecurityFailure):
                    security_gate.validate_audit_status(status, findings, source)

    def test_workflow_supplies_audit_statuses_without_removing_audits(self):
        workflow = (ROOT / ".github/workflows/platform-ci.yml").read_text()
        for command in ("composer --working-dir=apps/api audit --locked --format=json",
                        "pnpm audit --audit-level low --json",
                        "pnpm --dir legacy/v1-frontend --ignore-workspace audit",
                        '--audit-statuses "$RUNNER_TEMP/audit-statuses.json"'):
            self.assertIn(command, workflow)

    def test_malformed_json_and_unavailable_audit_fail_cli(self):
        with tempfile.TemporaryDirectory() as directory:
            audit = Path(directory) / "audit.json"
            arguments = [
                "security_gate.py", "--repository", str(ROOT),
                "--baseline", str(ROOT / ".ci/baselines/dependency-advisories.json"),
                "--composer-audit", str(audit), "--pnpm-audit", str(audit),
                "--workspace-pnpm-audit", str(audit), "--audit-statuses", str(audit),
            ]
            for content in (None, "{", "{}"):
                with self.subTest(content=content):
                    if content is not None:
                        audit.write_text(content, encoding="utf-8")
                    output = io.StringIO()
                    with mock.patch("sys.argv", arguments), contextlib.redirect_stderr(output):
                        self.assertEqual(security_gate.main(), 1)
                    self.assertIn("security-gate: FAIL", output.getvalue())

    def test_repository_baseline_does_not_allow_pnpm_findings(self):
        baseline = json.loads(
            (ROOT / ".ci/baselines/dependency-advisories.json").read_text(
                encoding="utf-8"
            )
        )

        self.assertEqual(baseline["pnpm"], [])
        self.assertEqual(baseline["schema_version"], "1.1")
        self.assertNotIn("expires_at", baseline["management"])
        self.assertEqual(
            [
                (finding["package"], finding["severity"])
                for finding in baseline["composer"]
            ],
            [],
        )
        self.assertFalse(
            any(
                finding.get("severity") in {"critical", "high"}
                for finding in baseline["composer"]
            )
        )


if __name__ == "__main__":
    unittest.main()
