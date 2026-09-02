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


def package_archive(
    path: Path,
    name: str,
    version: str,
    runtime_version=None,
) -> None:
    content = json.dumps({"name": name, "version": version}).encode()
    with tarfile.open(path, mode="w:gz") as archive:
        info = tarfile.TarInfo("package/package.json")
        info.size = len(content)
        archive.addfile(info, io.BytesIO(content))
        if name == "@oripa/storefront-client":
            constants = (
                "export const STOREFRONT_CLIENT_VERSION = "
                f'"{runtime_version or version}";\n'
            ).encode()
            constants_info = tarfile.TarInfo("package/dist/constants.js")
            constants_info.size = len(constants)
            archive.addfile(constants_info, io.BytesIO(constants))


class StorefrontContractArtifactTest(unittest.TestCase):
    def governance(self):
        return artifact.load_json(ROOT / artifact.GOVERNANCE_PATH)

    def next_candidate_governance(self):
        return copy.deepcopy(self.governance())

    def schema_candidate_governance(self):
        value = self.next_candidate_governance()
        candidate = value["candidate"]
        candidate["release_mode"] = "contract-additive"
        candidate["contract_versions"] = {
            "public": "2.0.0-alpha.31",
            "admin": "2.0.0-alpha.31",
            "webhook": "2.0.0-alpha.31",
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
        ] = "2.0.0-alpha.31"
        return value

    def breaking_candidate_governance(self):
        value = self.next_candidate_governance()
        value["candidate"]["release_mode"] = "contract-breaking"
        value["candidate"]["breaking_change"] = True
        return artifact.validate_governance(value)

    def settled_governance(self, value, manifest, output: Path):
        candidate = copy.deepcopy(value["candidate"])
        rows = {row["name"]: row for row in manifest["packages"]}
        packages = {}
        for name, details in candidate["packages"].items():
            record = copy.deepcopy(details)
            disposition = record.pop("disposition")
            if disposition == "publish":
                record["sha256"] = rows[name]["sha256"]
            packages[name] = record
        released = {
            "bundle_version": candidate["bundle_version"],
            "manifest_sha256": artifact.sha256_file(output / "artifact-manifest.json"),
            "source_commit": manifest["source_commit"],
            "handoff_status": "released",
            "release_mode": candidate["release_mode"],
            "platform_version": candidate["platform_version"],
            "application_versions": candidate["application_versions"],
            "contract_versions": candidate["contract_versions"],
            "public_openapi": {
                "version": candidate["contract_versions"]["public"],
                "sha256": candidate["public_openapi_sha256"],
                "operation_count": candidate["public_api_operation_count"],
            },
            "packages": packages,
            "publication": {
                "workflow_run_id": 1,
                "workflow_run_attempt": 1,
                "artifact_id": 1,
                "artifact_name": (
                    f"oripa-storefront-contract-{candidate['bundle_version']}"
                ),
                "github_digest": "sha256:" + "1" * 64,
                "sha256sums_sha256": "2" * 64,
            },
        }
        if "breaking_change" in candidate:
            released["breaking_change"] = candidate["breaking_change"]
        value["immutable_history"].append(released)
        value["latest_immutable"] = copy.deepcopy(released)
        value["candidate"] = None
        return artifact.validate_governance(value)

    def test_git_utc_timestamp_is_supported(self):
        parsed = artifact.parse_git_time("2026-08-24T13:08:57Z")
        self.assertEqual(parsed.isoformat(), "2026-08-24T13:08:57+00:00")

    def test_alpha_34_is_released_and_alpha_35_is_pending(self):
        value = artifact.validate_governance(self.governance())
        latest = value["latest_immutable"]
        alpha_32 = value["immutable_history"][-3]
        canonical = lambda item: hashlib.sha256(
            json.dumps(item, sort_keys=True, separators=(",", ":")).encode()
        ).hexdigest()

        self.assertEqual(latest["bundle_version"], "2.0.0-alpha.34")
        self.assertEqual(value["immutable_history"][-1], value["latest_immutable"])
        self.assertEqual(latest["handoff_status"], "released")
        self.assertEqual(value["candidate"]["bundle_version"], "2.0.0-alpha.35")
        self.assertEqual(value["candidate"]["release_mode"], "contract-additive")
        self.assertFalse(value["candidate"]["breaking_change"])
        self.assertEqual(latest["source_commit"], "576c35137946e5effcda63d6bf750d5ecc41150f")
        self.assertEqual(latest["manifest_sha256"], "42f4bee68b787dac16d07accee1c6154c7cea392c521c41b14461d6b56221464")
        self.assertEqual(latest["release_mode"], "contract-breaking")
        self.assertTrue(latest["breaking_change"])
        self.assertEqual(latest["public_openapi"]["sha256"], "27d0cdcee9194989058573d7e198066fa4af62017a0f301117ea4af034e733f0")
        self.assertEqual(latest["public_openapi"]["operation_count"], 75)
        self.assertEqual(latest["packages"]["@oripa/storefront-client"]["sha256"], "3363ebf849e3c7165b89ea9f037c681ab889d16539ce290383cad41d31c134c6")
        self.assertEqual(latest["packages"]["@oripa/storefront-testkit"]["sha256"], "07916ff69e2e6882aa0e62ee676a65652382413f14f65459ba4e773a41f8a440")
        self.assertEqual(canonical(value["immutable_history"][:-3]), "5e286877a462d29e643b2fc4e2a0040221e42be9687e31f378e857b28a51026c")
        self.assertEqual(canonical(alpha_32), "fdeee7026dccefe4d212516e2c692eefcf99940545623aa3227bda202768a0ae")
        self.assertEqual(alpha_32["handoff_status"], "retired")
        self.assertEqual(alpha_32["manifest_sha256"], "263955a5521a863635bf6ad23d604e52b1319e84052178288bad7b7c308de564")
        self.assertEqual(
            latest["packages"]["@oripa/site-schema"]["version"],
            "2.0.0-alpha.23",
        )
        self.assertEqual(
            latest["publication"],
            {
                "workflow_run_id": 33395772059,
                "workflow_run_attempt": 1,
                "artifact_id": 9759273312,
                "artifact_name": "oripa-storefront-contract-2.0.0-alpha.34",
                "github_digest": "sha256:c6927b367f9d1ad1a5602792873da481405dc8c3d9c1ba12bbca1954c4e4c8fb",
                "sha256sums_sha256": "555ae3637e71a57bff447aa084d21e649b598c878f64766b9f044d1e59f75355",
            },
        )

    def test_pending_alpha_35_target_preserves_additive_metadata(self):
        target = artifact.verification_target(self.governance())

        self.assertFalse(target["breaking_change"])

    def test_settled_breaking_release_missing_metadata_fails_closed(self):
        value = copy.deepcopy(self.governance())
        value["latest_immutable"].pop("breaking_change")
        value["immutable_history"][-1].pop("breaking_change")

        with self.assertRaisesRegex(
            artifact.ArtifactError, "immutable release evidence invalid"
        ):
            artifact.validate_governance(value)

    def test_alpha_34_publication_digest_format_tamper_is_rejected(self):
        value = copy.deepcopy(self.governance())
        for release in (value["latest_immutable"], value["immutable_history"][-1]):
            release["publication"]["github_digest"] = "sha256:" + "0" * 63
        with self.assertRaisesRegex(
            artifact.ArtifactError, "immutable publication evidence invalid"
        ):
            artifact.validate_governance(value)

    def test_alpha_34_publication_evidence_cannot_be_removed(self):
        value = copy.deepcopy(self.governance())
        for release in (value["latest_immutable"], value["immutable_history"][-1]):
            release.pop("publication")
        with self.assertRaisesRegex(
            artifact.ArtifactError, "immutable publication evidence missing"
        ):
            artifact.validate_governance(value)

    def test_retired_latest_requires_a_pending_successor(self):
        value = copy.deepcopy(self.governance())
        value["latest_immutable"]["handoff_status"] = "retired"
        value["immutable_history"][-1]["handoff_status"] = "retired"
        value["candidate"] = None
        with self.assertRaisesRegex(
            artifact.ArtifactError, "latest immutable release is not adoptable"
        ):
            artifact.validate_governance(value)

    def test_reconciled_alpha_34_duplicate_is_rejected(self):
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

    def test_latest_alpha_34_evidence_must_match_immutable_history(self):
        value = copy.deepcopy(self.governance())
        value["latest_immutable"]["source_commit"] = "0" * 40
        with self.assertRaisesRegex(artifact.ArtifactError, "latest immutable release mismatch"):
            artifact.validate_governance(value)

    def test_arbitrary_published_package_mismatch_is_rejected(self):
        value = self.next_candidate_governance()
        value["candidate"]["packages"]["@oripa/storefront-client"]["version"] = (
            "2.0.0-alpha.36"
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

    def test_canonical_breaking_contract_candidate_is_explicit(self):
        value = self.schema_candidate_governance()
        value["candidate"]["release_mode"] = "contract-breaking"
        value["candidate"]["breaking_change"] = True
        value["candidate"]["public_api_operation_count"] = 1
        value["candidate"]["packages"]["@oripa/storefront-testkit"][
            "public_api_operation_count"
        ] = 1

        result = artifact.validate_governance(value)

        self.assertTrue(result["candidate"]["breaking_change"])

    def test_contract_breaking_candidate_requires_explicit_truthful_marker(self):
        value = self.schema_candidate_governance()
        value["candidate"]["release_mode"] = "contract-breaking"

        with self.assertRaisesRegex(
            artifact.ArtifactError, "contract-breaking candidate authority mismatch"
        ):
            artifact.validate_governance(value)

    def test_candidate_preserves_independent_platform_contract_and_schema(self):
        result = artifact.validate_governance(self.next_candidate_governance())["candidate"]
        self.assertEqual(result["bundle_version"], "2.0.0-alpha.35")
        self.assertEqual(result["platform_version"], "2.0.0-alpha.23")
        self.assertEqual(result["contract_versions"]["public"], "2.0.0-alpha.31")
        self.assertEqual(result["packages"]["@oripa/site-schema"]["version"], "2.0.0-alpha.23")
        self.assertEqual(result["packages"]["@oripa/storefront-client"]["version"], "2.0.0-alpha.35")
        self.assertEqual(result["packages"]["@oripa/storefront-testkit"]["version"], "2.0.0-alpha.35")

    def test_source_client_runtime_version_matches_package_metadata(self):
        result = artifact.validate_source(ROOT)
        self.assertEqual(
            result["packages"]["@oripa/storefront-client"],
            "2.0.0-alpha.35",
        )
        with mock.patch.object(
            artifact,
            "read_source_client_runtime_version",
            return_value="2.0.0-alpha.32",
        ):
            with self.assertRaisesRegex(
                artifact.ArtifactError, "client source runtime version mismatch"
            ):
                artifact.validate_source(ROOT)

    def valid_output(self, output: Path, governance=None, source_commit=None) -> dict:
        governance = governance or self.next_candidate_governance()
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
                source_commit or "1" * 40,
                "2026-08-20T00:00:00Z",
                assets,
            )
        artifact.write_json(output / "artifact-manifest.json", manifest)
        artifact.write_checksums(output)
        return governance

    def test_alpha_35_ledger_has_one_pending_candidate(self):
        candidate = artifact.pending_candidate(ROOT)

        self.assertEqual(candidate["bundle_version"], "2.0.0-alpha.35")
        self.assertEqual(candidate["predecessor_bundle_version"], "2.0.0-alpha.34")

    def test_nonbreaking_candidate_and_settled_manifest_verification(self):
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
            settled = self.settled_governance(governance, manifest, output)
            self.assertFalse(artifact.verification_target(settled)["breaking_change"])
            with mock.patch.object(artifact, "governance", return_value=settled):
                artifact.verify_manifest(ROOT, output)

    def test_breaking_candidate_and_settled_manifest_verification(self):
        with tempfile.TemporaryDirectory() as temporary:
            output = Path(temporary)
            governance = self.breaking_candidate_governance()
            source_commit = governance["latest_immutable"]["source_commit"]
            governance = self.valid_output(output, governance, source_commit)
            with mock.patch.object(artifact, "governance", return_value=governance):
                manifest = artifact.verify_manifest(ROOT, output)
            self.assertTrue(manifest["public_openapi"]["breaking_change"])
            settled = self.settled_governance(governance, manifest, output)
            self.assertTrue(artifact.verification_target(settled)["breaking_change"])
            with mock.patch.object(artifact, "governance", return_value=settled):
                artifact.verify_manifest(ROOT, output)

    def test_client_tarball_runtime_mismatch_is_rejected(self):
        with tempfile.TemporaryDirectory() as temporary:
            output = Path(temporary)
            governance = self.valid_output(output)
            client_path = next(output.glob("*storefront-client*.tgz"))
            package_archive(
                client_path,
                "@oripa/storefront-client",
                "2.0.0-alpha.35",
                runtime_version="2.0.0-alpha.34",
            )
            manifest = artifact.load_json(output / "artifact-manifest.json")
            client_row = next(
                row
                for row in manifest["packages"]
                if row["name"] == "@oripa/storefront-client"
            )
            client_row["sha256"] = artifact.sha256_file(client_path)
            artifact.write_json(output / "artifact-manifest.json", manifest)
            artifact.write_checksums(output)
            with mock.patch.object(artifact, "governance", return_value=governance):
                with self.assertRaisesRegex(
                    artifact.ArtifactError, "tarball runtime version mismatch"
                ):
                    artifact.verify_manifest(ROOT, output)

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
        self.assertIn("pnpm storefront:check", workflow)
        self.assertIn("pnpm site-schema:build", workflow)
        self.assertIn("pnpm testkit:check", workflow)
        self.assertLess(
            workflow.index("pnpm site-schema:build"),
            workflow.index("pnpm testkit:check"),
        )
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
