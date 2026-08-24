import copy
import importlib.util
import io
import json
from pathlib import Path
import shutil
import tarfile
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "release" / "storefront_contract_artifact.py"
SPEC = importlib.util.spec_from_file_location("storefront_contract_artifact", SCRIPT)
artifact = importlib.util.module_from_spec(SPEC)
assert SPEC.loader
SPEC.loader.exec_module(artifact)


def package_archive(path: Path, name: str, version: str) -> None:
    content = json.dumps({"name": name, "version": version}).encode()
    with tarfile.open(path, mode="w:gz") as archive:
        info = tarfile.TarInfo("package/package.json")
        info.size = len(content)
        archive.addfile(info, io.BytesIO(content))


class StorefrontContractArtifactTest(unittest.TestCase):
    def governance(self):
        return artifact.load_json(ROOT / artifact.GOVERNANCE_PATH)

    def test_declared_additive_contract_release_is_valid(self):
        value = artifact.validate_governance(self.governance())
        self.assertEqual(value["candidate"]["bundle_version"], "2.0.0-alpha.24")
        self.assertEqual(value["candidate"]["platform_version"], "2.0.0-alpha.23")
        self.assertEqual(value["candidate"]["release_mode"], "contract-additive")
        self.assertEqual(
            value["candidate"]["packages"]["@oripa/site-schema"]["disposition"],
            "reference",
        )

    def test_existing_alpha_23_bundle_reissue_is_rejected(self):
        value = copy.deepcopy(self.governance())
        value["candidate"]["bundle_version"] = "2.0.0-alpha.23"
        with self.assertRaisesRegex(
            artifact.ArtifactError, "immutable existing version reissue prohibited"
        ):
            artifact.validate_governance(value)

    def test_latest_alpha_23_evidence_must_match_immutable_history(self):
        value = copy.deepcopy(self.governance())
        value["latest_immutable"]["source_commit"] = "0" * 40
        with self.assertRaisesRegex(artifact.ArtifactError, "candidate predecessor mismatch"):
            artifact.validate_governance(value)

    def test_arbitrary_published_package_mismatch_is_rejected(self):
        value = copy.deepcopy(self.governance())
        value["candidate"]["packages"]["@oripa/storefront-client"]["version"] = (
            "2.0.0-alpha.25"
        )
        with self.assertRaisesRegex(
            artifact.ArtifactError, "published package version must equal bundle version"
        ):
            artifact.validate_governance(value)

    def test_referenced_schema_digest_mismatch_is_rejected(self):
        value = copy.deepcopy(self.governance())
        value["candidate"]["packages"]["@oripa/site-schema"]["sha256"] = "0" * 64
        with self.assertRaisesRegex(
            artifact.ArtifactError, "immutable package reference mismatch"
        ):
            artifact.validate_governance(value)

    def test_source_versions_preserve_independent_platform_contract_and_schema(self):
        result = artifact.validate_source(ROOT)
        self.assertEqual(result["bundle_version"], "2.0.0-alpha.24")
        self.assertEqual(result["platform_version"], "2.0.0-alpha.23")
        self.assertEqual(result["contracts"]["public"]["version"], "2.0.0-alpha.24")
        self.assertEqual(result["packages"]["@oripa/site-schema"], "2.0.0-alpha.23")
        self.assertEqual(result["packages"]["@oripa/storefront-client"], "2.0.0-alpha.24")
        self.assertEqual(result["packages"]["@oripa/storefront-testkit"], "2.0.0-alpha.24")

    def valid_output(self, output: Path) -> None:
        governance = artifact.governance(ROOT)
        candidate = governance["candidate"]
        assets = {}
        for name in ("@oripa/storefront-client", "@oripa/storefront-testkit"):
            filename = name.removeprefix("@oripa/").replace("/", "-")
            path = output / f"oripa-{filename}-{candidate['packages'][name]['version']}.tgz"
            package_archive(path, name, candidate["packages"][name]["version"])
            assets[name] = {
                "file": path.name,
                "sha256": artifact.sha256_file(path),
                "browser_compatible": True,
            }
        shutil.copyfile(
            ROOT / artifact.PUBLIC_OPENAPI_PATH,
            output / artifact.PUBLIC_OPENAPI_PATH.name,
        )
        manifest = artifact.create_manifest(
            ROOT,
            "1" * 40,
            "2026-08-20T00:00:00Z",
            assets,
        )
        artifact.write_json(output / "artifact-manifest.json", manifest)
        artifact.write_checksums(output)

    def test_release_manifest_and_file_inventory_are_consistent(self):
        with tempfile.TemporaryDirectory() as temporary:
            output = Path(temporary)
            self.valid_output(output)
            manifest = artifact.verify_manifest(ROOT, output)
            dispositions = {
                row["name"]: row["disposition"] for row in manifest["packages"]
            }
            self.assertEqual(
                dispositions,
                {
                    "@oripa/storefront-client": "published",
                    "@oripa/site-schema": "referenced",
                    "@oripa/storefront-testkit": "published",
                },
            )

    def test_referenced_alpha_23_schema_must_not_be_reissued(self):
        with tempfile.TemporaryDirectory() as temporary:
            output = Path(temporary)
            self.valid_output(output)
            package_archive(
                output / "oripa-site-schema-2.0.0-alpha.23.tgz",
                "@oripa/site-schema",
                "2.0.0-alpha.23",
            )
            with self.assertRaisesRegex(artifact.ArtifactError, "file inventory mismatch"):
                artifact.verify_manifest(ROOT, output)

    def test_workflow_invokes_validator_build_and_verify_without_skip(self):
        workflow = (ROOT / ".github/workflows/preview-image-build.yml").read_text()
        self.assertIn("storefront_contract_artifact.py validate-source", workflow)
        self.assertIn("storefront_contract_artifact.py build", workflow)
        self.assertIn("storefront_contract_artifact.py verify", workflow)
        self.assertNotIn("package version mismatch", workflow)


if __name__ == "__main__":
    unittest.main()
