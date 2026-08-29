import copy
import hashlib
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
            {"version": "2.0.0-alpha.32", "disposition": "publish"}
        )
        packages["@oripa/storefront-client"].pop("sha256")
        packages["@oripa/site-schema"]["disposition"] = "reference"
        packages["@oripa/storefront-testkit"].update(
            {
                "version": "2.0.0-alpha.32",
                "disposition": "publish",
                "storefront_client_version": "2.0.0-alpha.32",
            }
        )
        packages["@oripa/storefront-testkit"].pop("sha256")
        value["candidate"] = {
            "release_state": "pending",
            "bundle_version": "2.0.0-alpha.32",
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
            "public": "2.0.0-alpha.29",
            "admin": "2.0.0-alpha.29",
            "webhook": "2.0.0-alpha.29",
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
        ] = "2.0.0-alpha.29"
        return value

    def test_git_utc_timestamp_is_supported(self):
        parsed = artifact.parse_git_time("2026-08-24T13:08:57Z")
        self.assertEqual(parsed.isoformat(), "2026-08-24T13:08:57+00:00")

    def test_alpha_31_reconciliation_preserves_released_history(self):
        value = artifact.validate_governance(self.governance())
        latest = value["latest_immutable"]
        alpha_30 = value["immutable_history"][-2]
        canonical = lambda item: hashlib.sha256(
            json.dumps(item, sort_keys=True, separators=(",", ":")).encode()
        ).hexdigest()

        self.assertEqual(latest["bundle_version"], "2.0.0-alpha.31")
        self.assertEqual(value["immutable_history"][-1], value["latest_immutable"])
        self.assertIsNone(value["candidate"])
        self.assertEqual(latest["source_commit"], "ad078ecd1eebd68cd2443b347d387433177fd686")
        self.assertEqual(latest["manifest_sha256"], "c11894fbfadaf3dd4e00c7f94973ede1bb00f580ece5e109d0118c74c3b69f74")
        self.assertEqual(latest["release_mode"], "contract-additive")
        self.assertEqual(latest["public_openapi"]["sha256"], "60a14073f7ee52d91b919c69fbc7444bf6afe391a887121bb4af5e45fbb85626")
        self.assertEqual(latest["public_openapi"]["operation_count"], 71)
        self.assertEqual(latest["packages"]["@oripa/storefront-client"]["sha256"], "0caf5e8ac829a1f13d1790298ba4a2fef3c50fe6ae11cad63329ab327cea40cf")
        self.assertEqual(latest["packages"]["@oripa/storefront-testkit"]["sha256"], "932cc4cc6560aa595e01bb5d929320f8d2f70dda32d5a8dd70ec91e84acb8716")
        self.assertEqual(canonical(value["immutable_history"][:-1]), "a03b7ca032dd7406ab117b2b2f59d6f8c10c8f0414a299cea469025297437f48")
        self.assertEqual(canonical(alpha_30), "180dffe8a85f3d45052ca9088af6f031bd23e7232c04695ea2ac710a712e7c69")
        self.assertEqual(
            latest["packages"]["@oripa/site-schema"]["version"],
            "2.0.0-alpha.23",
        )

    def test_reconciled_alpha_31_duplicate_is_rejected(self):
        value = copy.deepcopy(self.governance())
        value["immutable_history"].append(copy.deepcopy(value["latest_immutable"]))
        with self.assertRaisesRegex(
            artifact.ArtifactError, "immutable history version inventory invalid"
        ):
            artifact.validate_governance(value)

    def test_existing_alpha_28_bundle_reissue_is_rejected(self):
        value = self.next_candidate_governance()
        value["candidate"]["bundle_version"] = "2.0.0-alpha.28"
        with self.assertRaisesRegex(
            artifact.ArtifactError, "immutable existing version reissue prohibited"
        ):
            artifact.validate_governance(value)

    def test_latest_alpha_31_evidence_must_match_immutable_history(self):
        value = copy.deepcopy(self.governance())
        value["latest_immutable"]["source_commit"] = "0" * 40
        with self.assertRaisesRegex(artifact.ArtifactError, "latest immutable release mismatch"):
            artifact.validate_governance(value)

    def test_arbitrary_published_package_mismatch_is_rejected(self):
        value = self.next_candidate_governance()
        value["candidate"]["packages"]["@oripa/storefront-client"]["version"] = (
            "2.0.0-alpha.33"
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

    def test_settled_source_preserves_independent_platform_contract_and_schema(self):
        result = artifact.validate_governance(self.governance())["latest_immutable"]
        self.assertEqual(result["bundle_version"], "2.0.0-alpha.31")
        self.assertEqual(result["platform_version"], "2.0.0-alpha.23")
        self.assertEqual(result["contract_versions"]["public"], "2.0.0-alpha.28")
        self.assertEqual(result["packages"]["@oripa/site-schema"]["version"], "2.0.0-alpha.23")
        self.assertEqual(result["packages"]["@oripa/storefront-client"]["version"], "2.0.0-alpha.31")
        self.assertEqual(result["packages"]["@oripa/storefront-testkit"]["version"], "2.0.0-alpha.31")

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

    def test_settled_alpha_31_has_no_publishable_candidate(self):
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

    def test_dedicated_workflow_invokes_validator_build_verify_and_readback(self):
        workflow = (
            ROOT / ".github/workflows/storefront-contract-artifact-publish.yml"
        ).read_text()
        self.assertIn("publication_mode:", workflow)
        self.assertIn("default: contract-only", workflow)
        self.assertEqual(workflow.count("actions/checkout@"), 3)
        self.assertIn('test "$(git rev-parse HEAD)"', workflow)
        self.assertIn("storefront_contract_artifact.py validate-source", workflow)
        self.assertIn("storefront_contract_artifact.py build", workflow)
        self.assertIn("storefront_contract_artifact.py verify", workflow)
        self.assertIn("storefront_contract_publication.py readback", workflow)
        self.assertIn("overwrite: false", workflow)
        self.assertNotIn("docker build", workflow)
        self.assertNotIn("docker push", workflow)
        self.assertNotIn("apps/admin/Dockerfile", workflow)
        self.assertNotIn("package version mismatch", workflow)


if __name__ == "__main__":
    unittest.main()
