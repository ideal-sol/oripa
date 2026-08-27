import copy
import importlib.util
import io
import json
from pathlib import Path
import shutil
import tarfile
import tempfile
import unittest
from unittest import mock


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

    def next_candidate_governance(self):
        value = copy.deepcopy(self.governance())
        latest = value["latest_immutable"]
        packages = copy.deepcopy(latest["packages"])
        packages["@oripa/storefront-client"].update(
            {"version": "2.0.0-alpha.30", "disposition": "publish"}
        )
        packages["@oripa/storefront-client"].pop("sha256")
        packages["@oripa/site-schema"]["disposition"] = "reference"
        packages["@oripa/storefront-testkit"].update(
            {
                "version": "2.0.0-alpha.30",
                "disposition": "publish",
                "storefront_client_version": "2.0.0-alpha.30",
            }
        )
        packages["@oripa/storefront-testkit"].pop("sha256")
        value["candidate"] = {
            "release_state": "pending",
            "bundle_version": "2.0.0-alpha.30",
            "predecessor_bundle_version": latest["bundle_version"],
            "release_mode": "package-only",
            "platform_version": latest["platform_version"],
            "application_versions": latest["application_versions"],
            "contract_versions": latest["contract_versions"],
            "public_openapi_sha256": latest["public_openapi"]["sha256"],
            "public_api_operation_count": latest["public_openapi"]["operation_count"],
            "packages": packages,
        }
        return value

    def schema_candidate_governance(self):
        value = self.next_candidate_governance()
        candidate = value["candidate"]
        candidate["release_mode"] = "contract-additive"
        candidate["contract_versions"] = {
            "public": "2.0.0-alpha.28",
            "admin": "2.0.0-alpha.28",
            "webhook": "2.0.0-alpha.28",
        }
        candidate["public_openapi_sha256"] = "0" * 64
        candidate["public_api_operation_count"] = value["latest_immutable"][
            "public_openapi"
        ]["operation_count"]
        candidate["packages"]["@oripa/storefront-testkit"][
            "public_api_operation_count"
        ] = candidate["public_api_operation_count"]
        candidate["packages"]["@oripa/storefront-client"][
            "minimum_public_api_contract"
        ] = "2.0.0-alpha.28"
        return value

    def test_git_utc_timestamp_is_supported(self):
        parsed = artifact.parse_git_time("2026-08-24T13:08:57Z")
        self.assertEqual(parsed.isoformat(), "2026-08-24T13:08:57+00:00")

    def test_released_alpha_29_bundle_is_valid(self):
        value = artifact.validate_governance(self.governance())
        self.assertEqual(value["latest_immutable"]["bundle_version"], "2.0.0-alpha.29")
        self.assertEqual(value["immutable_history"][-1], value["latest_immutable"])
        self.assertIsNone(value["candidate"])
        self.assertEqual(value["latest_immutable"]["public_openapi"]["operation_count"], 65)
        self.assertEqual(value["latest_immutable"]["release_mode"], "package-only")
        self.assertEqual(
            value["latest_immutable"]["packages"]["@oripa/site-schema"]["version"],
            "2.0.0-alpha.23",
        )

    def test_existing_alpha_28_bundle_reissue_is_rejected(self):
        value = self.next_candidate_governance()
        value["candidate"]["bundle_version"] = "2.0.0-alpha.28"
        with self.assertRaisesRegex(
            artifact.ArtifactError, "immutable existing version reissue prohibited"
        ):
            artifact.validate_governance(value)

    def test_latest_alpha_29_evidence_must_match_immutable_history(self):
        value = copy.deepcopy(self.governance())
        value["latest_immutable"]["source_commit"] = "0" * 40
        with self.assertRaisesRegex(artifact.ArtifactError, "latest immutable release mismatch"):
            artifact.validate_governance(value)

    def test_arbitrary_published_package_mismatch_is_rejected(self):
        value = self.next_candidate_governance()
        value["candidate"]["packages"]["@oripa/storefront-client"]["version"] = (
            "2.0.0-alpha.31"
        )
        with self.assertRaisesRegex(
            artifact.ArtifactError, "published package version must equal bundle version"
        ):
            artifact.validate_governance(value)

    def test_referenced_schema_digest_mismatch_is_rejected(self):
        value = self.next_candidate_governance()
        value["candidate"]["packages"]["@oripa/site-schema"]["sha256"] = "0" * 64
        with self.assertRaisesRegex(
            artifact.ArtifactError, "immutable package reference mismatch"
        ):
            artifact.validate_governance(value)

    def test_schema_only_additive_contract_keeps_operation_count(self):
        value = artifact.validate_governance(self.schema_candidate_governance())
        self.assertEqual(
            value["candidate"]["public_api_operation_count"],
            value["latest_immutable"]["public_openapi"]["operation_count"],
        )

    def test_contract_candidate_cannot_reduce_operation_count(self):
        value = self.schema_candidate_governance()
        value["candidate"]["public_api_operation_count"] -= 1
        value["candidate"]["packages"]["@oripa/storefront-testkit"][
            "public_api_operation_count"
        ] -= 1
        with self.assertRaisesRegex(
            artifact.ArtifactError, "additive contract candidate mismatch"
        ):
            artifact.validate_governance(value)

    def test_source_versions_preserve_independent_platform_contract_and_schema(self):
        result = artifact.validate_source(ROOT)
        self.assertEqual(result["bundle_version"], "2.0.0-alpha.29")
        self.assertEqual(result["release_state"], "released")
        self.assertEqual(result["platform_version"], "2.0.0-alpha.23")
        self.assertEqual(result["contracts"]["public"]["version"], "2.0.0-alpha.27")
        self.assertEqual(result["packages"]["@oripa/site-schema"], "2.0.0-alpha.23")
        self.assertEqual(result["packages"]["@oripa/storefront-client"], "2.0.0-alpha.29")
        self.assertEqual(result["packages"]["@oripa/storefront-testkit"], "2.0.0-alpha.29")

    def valid_output(self, output: Path) -> dict:
        governance = self.next_candidate_governance()
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
        with mock.patch.object(artifact, "governance", return_value=governance):
            manifest = artifact.create_manifest(
                ROOT,
                "1" * 40,
                "2026-08-20T00:00:00Z",
                assets,
            )
        artifact.write_json(output / "artifact-manifest.json", manifest)
        artifact.write_checksums(output)
        return governance

    def test_settled_alpha_29_has_no_publishable_candidate(self):
        with self.assertRaisesRegex(
            artifact.ArtifactError, "no pending Storefront artifact candidate"
        ):
            artifact.pending_candidate(ROOT)

    def test_release_manifest_and_file_inventory_are_consistent(self):
        with tempfile.TemporaryDirectory() as temporary:
            output = Path(temporary)
            governance = self.valid_output(output)
            with mock.patch.object(artifact, "governance", return_value=governance):
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
            governance = self.valid_output(output)
            package_archive(
                output / "oripa-site-schema-2.0.0-alpha.23.tgz",
                "@oripa/site-schema",
                "2.0.0-alpha.23",
            )
            with mock.patch.object(artifact, "governance", return_value=governance):
                with self.assertRaisesRegex(artifact.ArtifactError, "file inventory mismatch"):
                    artifact.verify_manifest(ROOT, output)

    def test_workflow_invokes_validator_build_and_verify_without_skip(self):
        workflow = (ROOT / ".github/workflows/preview-image-build.yml").read_text()
        self.assertIn("storefront_contract_artifact.py candidate-version", workflow)
        self.assertIn("storefront-contract-candidate.outputs.present == 'true'", workflow)
        self.assertIn("storefront_contract_artifact.py validate-source", workflow)
        self.assertIn("storefront_contract_artifact.py build", workflow)
        self.assertIn("storefront_contract_artifact.py verify", workflow)
        self.assertNotIn("package version mismatch", workflow)


if __name__ == "__main__":
    unittest.main()
