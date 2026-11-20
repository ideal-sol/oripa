import importlib.util
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("php8428", ROOT / "scripts/ops/production_php8428_design.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class PHP8428DesignTest(unittest.TestCase):
    def test_reproduces_previous_composition_and_exact_limited_tree(self):
        result = module.reproduce()
        self.assertEqual(result["source_sha"], "0254792e8dc0baafbef45c6f2ca861ee91cfa191")
        self.assertEqual(result["source_tree"], "39cfb3d2a290b93711738596728c25127a94ba4d")
        self.assertEqual(result["changed_paths"], ["infra/docker/backend/Dockerfile", "scripts/ci/policy_gate.py"])
        self.assertFalse(result["build_authorized"])
        self.assertFalse(result["activation_authorized"])

    def test_rejects_changed_design_before_any_materialization(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            path = root / module.MANIFEST
            path.parent.mkdir()
            path.write_bytes((ROOT / module.MANIFEST).read_bytes().replace(b'80428', b'80427'))
            with self.assertRaisesRegex(ValueError, 'digest mismatch'):
                module.reproduce(root)
