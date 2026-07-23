import datetime
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[3]


def load(name, relative):
    spec = importlib.util.spec_from_file_location(name, ROOT / relative)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


quality_gate = load("quality_gate", "scripts/ci/quality_gate.py")
lint_baseline = load("lint_baseline", "scripts/ci/lint_baseline.py")


class QualityGateTest(unittest.TestCase):
    def test_invalid_json_fails(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "bad.json").write_text("{", encoding="utf-8")
            with self.assertRaises(quality_gate.QualityFailure):
                quality_gate.validate_json(root, ["bad.json"])

    def test_new_lint_finding_fails_exact_baseline(self):
        report = [
            {
                "filePath": "/workspace/frontend/example.tsx",
                "messages": [
                    {
                        "line": 1,
                        "column": 1,
                        "endLine": 1,
                        "endColumn": 2,
                        "ruleId": "example/rule",
                        "severity": 2,
                        "message": "new finding",
                    }
                ],
            }
        ]
        baseline = {
            "schema_version": "1.0",
            "management": {
                "owner": "platform-maintainers",
                "reason": "fixture",
                "removal_condition": "fixture",
                "expires_at": "2099-01-01",
                "tracking_task": "FIXTURE-001",
            },
            "findings": [],
        }
        with self.assertRaises(lint_baseline.BaselineFailure):
            lint_baseline.validate_baseline(
                report, baseline, datetime.date(2026, 7, 23)
            )


if __name__ == "__main__":
    unittest.main()
