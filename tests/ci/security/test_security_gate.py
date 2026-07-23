import datetime
import importlib.util
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


if __name__ == "__main__":
    unittest.main()
