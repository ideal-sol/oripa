import importlib.util
import json
from pathlib import Path
import shutil
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[3]
FIXTURES = Path(__file__).resolve().parent / "fixtures"


spec = importlib.util.spec_from_file_location(
    "site_template_gate", ROOT / "scripts/ci/site_template_gate.py"
)
site_template_gate = importlib.util.module_from_spec(spec)
assert spec.loader is not None
spec.loader.exec_module(site_template_gate)


class SiteTemplateGateTest(unittest.TestCase):
    def test_positive_fixture_passes(self):
        summary = site_template_gate.validate_template(FIXTURES / "positive")
        self.assertEqual(summary["canonical_template"], False)
        self.assertEqual(summary["source_files"], 1)

    def test_negative_fixture_fails(self):
        with self.assertRaises(site_template_gate.SiteTemplateFailure):
            site_template_gate.validate_template(FIXTURES / "negative")

    def test_first_party_version_must_be_exact(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / "site"
            shutil.copytree(FIXTURES / "positive", target)
            package_path = target / "package.json"
            package = json.loads(package_path.read_text(encoding="utf-8"))
            package["dependencies"]["@oripa/storefront-client"] = "^2.0.0"
            package_path.write_text(
                json.dumps(package, indent=2) + "\n", encoding="utf-8"
            )
            with self.assertRaises(site_template_gate.SiteTemplateFailure):
                site_template_gate.validate_template(target)

    def test_direct_api_fetch_fails(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / "site"
            shutil.copytree(FIXTURES / "positive", target)
            (target / "src/page.ts").write_text(
                "fetch('/api/v2/draw');\n", encoding="utf-8"
            )
            with self.assertRaises(site_template_gate.SiteTemplateFailure):
                site_template_gate.validate_template(target)

    def test_sensitive_environment_name_fails(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / "site"
            shutil.copytree(FIXTURES / "positive", target)
            (target / ".env.example").write_text(
                "SITE_API_TOKEN=replace-me\n", encoding="utf-8"
            )
            with self.assertRaises(site_template_gate.SiteTemplateFailure):
                site_template_gate.validate_template(target)

    def test_copied_platform_directory_fails(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / "site"
            shutil.copytree(FIXTURES / "positive", target)
            (target / "backend").mkdir()
            with self.assertRaises(site_template_gate.SiteTemplateFailure):
                site_template_gate.validate_template(target)


if __name__ == "__main__":
    unittest.main()
