import datetime
import importlib.util
import json
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[3]
SPEC = importlib.util.spec_from_file_location(
    "security_gate", ROOT / "scripts/ci/security_gate.py"
)
security_gate = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(security_gate)


class SecurityGateTest(unittest.TestCase):
    def test_private_key_candidate_is_detected_without_value_output(self):
        data = b"-----BEGIN " + b"PRIVATE KEY-----\\nredacted\\n"
        categories = [
            name
            for name, pattern in security_gate.SECRET_PATTERNS.items()
            if pattern.search(data)
        ]
        self.assertEqual(categories, ["private-key"])

    def test_new_dependency_advisory_fails_exact_baseline(self):
        baseline = {
            "schema_version": "1.0",
            "management": {
                "owner": "security-owners",
                "reason": "fixture",
                "removal_condition": "fixture",
                "expires_at": "2099-01-01",
                "tracking_task": "FIXTURE-001",
            },
            "composer": [],
            "pnpm": [],
        }
        finding = {
            "source": "composer",
            "advisory_id": "FIXTURE",
            "package": "example/package",
            "version": "1.0.0",
            "severity": "high",
            "cve": None,
        }
        with self.assertRaises(security_gate.SecurityFailure):
            security_gate.validate_dependency_baseline(
                [finding], [], baseline, datetime.date(2026, 7, 23)
            )

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

    def test_dependency_baseline_expiration_boundary_is_fail_closed(self):
        baseline = {
            "schema_version": "1.0",
            "management": {
                "owner": "security-owners",
                "reason": "fixture",
                "removal_condition": "fixture",
                "expires_at": "2026-08-07",
                "tracking_task": "FIXTURE-001",
            },
            "composer": [],
            "pnpm": [],
        }

        summary = security_gate.validate_dependency_baseline(
            [], [], baseline, datetime.date(2026, 8, 7)
        )
        self.assertEqual(summary["expires_at"], "2026-08-07")

        with self.assertRaisesRegex(
            security_gate.SecurityFailure, "dependency advisory baseline has expired"
        ):
            security_gate.validate_dependency_baseline(
                [], [], baseline, datetime.date(2026, 8, 8)
            )

    def test_repository_baseline_does_not_allow_pnpm_findings(self):
        baseline = json.loads(
            (ROOT / ".ci/baselines/dependency-advisories.json").read_text(
                encoding="utf-8"
            )
        )

        self.assertEqual(baseline["pnpm"], [])
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
