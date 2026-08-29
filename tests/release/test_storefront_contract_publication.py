import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest import mock
import zipfile


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts/release/storefront_contract_publication.py"
SPEC = importlib.util.spec_from_file_location("storefront_contract_publication", SCRIPT)
publication = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(publication)

SOURCE_SHA = "a" * 40
REVIEWED_HEAD_SHA = "b" * 40
TREE_SHA = "c" * 40
TASK_ID = "MIG-999"
VERSION = "2.0.0-alpha.31"


def successful_checks():
    return {
        "check_runs": [
            {
                "id": index,
                "name": name,
                "status": "completed",
                "conclusion": "success",
            }
            for index, name in enumerate(sorted(publication.REQUIRED_CHECKS), start=1)
        ]
    }


def merged_pull(head_sha=REVIEWED_HEAD_SHA, merge_sha=SOURCE_SHA):
    return {
        "number": 417,
        "state": "closed",
        "merged": True,
        "merge_commit_sha": merge_sha,
        "title": f"[{TASK_ID}] Contract source",
        "head": {
            "sha": head_sha,
            "ref": f"feat/{TASK_ID}-contract-source",
            "repo": {"full_name": publication.REPOSITORY},
        },
        "base": {"ref": "main"},
    }


def api_payload(path, _token=None):
    if path.endswith("/branches/main"):
        return {"name": "main", "protected": True, "commit": {"sha": SOURCE_SHA}}
    if path.endswith(f"/commits/{SOURCE_SHA}/pulls?per_page=100"):
        return [{"number": 417}]
    if path.endswith("/pulls/417"):
        return merged_pull()
    if path.endswith(f"/git/commits/{SOURCE_SHA}"):
        return {"tree": {"sha": TREE_SHA}}
    if path.endswith(f"/git/commits/{REVIEWED_HEAD_SHA}"):
        return {"tree": {"sha": TREE_SHA}}
    if path.endswith(f"/commits/{REVIEWED_HEAD_SHA}/check-runs?per_page=100"):
        return successful_checks()
    if "/actions/artifacts?" in path:
        return {"total_count": 0, "artifacts": []}
    raise AssertionError(path)


class PublicationAuthorityTest(unittest.TestCase):
    def authorize(self):
        with mock.patch.object(publication, "api_get", side_effect=api_payload):
            with mock.patch.object(
                publication.artifact_contract,
                "pending_candidate",
                return_value={"bundle_version": VERSION},
            ):
                with mock.patch.object(
                    publication.artifact_contract, "validate_source"
                ):
                    return publication.authorize(
                        ROOT,
                        publication.REPOSITORY,
                        publication.MAIN_REF,
                        SOURCE_SHA,
                        SOURCE_SHA,
                        TASK_ID,
                        "token",
                    )

    def test_exact_protected_main_and_reviewed_squash_tree_are_authorized(self):
        self.assertEqual(
            self.authorize(),
            {
                "source_sha": SOURCE_SHA,
                "source_pr": "417",
                "reviewed_head_sha": REVIEWED_HEAD_SHA,
                "artifact_version": VERSION,
                "artifact_name": f"oripa-storefront-contract-{VERSION}",
            },
        )

    def test_arbitrary_branch_is_rejected_before_github_lookup(self):
        with mock.patch.object(publication, "api_get") as api_get:
            with self.assertRaisesRegex(
                publication.PublicationError, "protected_main_ref_required"
            ):
                publication.authorize(
                    ROOT,
                    publication.REPOSITORY,
                    "refs/heads/feature",
                    SOURCE_SHA,
                    SOURCE_SHA,
                    TASK_ID,
                    "token",
                )
        api_get.assert_not_called()

    def test_checkout_event_sha_mismatch_is_rejected(self):
        with self.assertRaisesRegex(
            publication.PublicationError, "workflow_event_sha_mismatch"
        ):
            publication.authorize(
                ROOT,
                publication.REPOSITORY,
                publication.MAIN_REF,
                REVIEWED_HEAD_SHA,
                SOURCE_SHA,
                TASK_ID,
                "token",
            )

    def test_stale_or_non_main_sha_is_rejected(self):
        def stale_main(path, _token=None):
            if path.endswith("/branches/main"):
                return {
                    "name": "main",
                    "protected": True,
                    "commit": {"sha": REVIEWED_HEAD_SHA},
                }
            raise AssertionError(path)

        with mock.patch.object(publication, "api_get", side_effect=stale_main):
            with self.assertRaisesRegex(
                publication.PublicationError, "stale_main_sha_rejected"
            ):
                publication.authorize(
                    ROOT,
                    publication.REPOSITORY,
                    publication.MAIN_REF,
                    SOURCE_SHA,
                    SOURCE_SHA,
                    TASK_ID,
                    "token",
                )

    def test_pr_head_cannot_be_used_as_merged_source(self):
        def pr_head_payload(path, _token=None):
            if path.endswith("/branches/main"):
                return {
                    "name": "main",
                    "protected": True,
                    "commit": {"sha": REVIEWED_HEAD_SHA},
                }
            if path.endswith(f"/commits/{REVIEWED_HEAD_SHA}/pulls?per_page=100"):
                return [{"number": 417}]
            if path.endswith("/pulls/417"):
                return merged_pull(head_sha=REVIEWED_HEAD_SHA, merge_sha=SOURCE_SHA)
            raise AssertionError(path)

        with mock.patch.object(publication, "api_get", side_effect=pr_head_payload):
            with self.assertRaisesRegex(
                publication.PublicationError, "exact_merged_source_pull_required"
            ):
                publication.authorize(
                    ROOT,
                    publication.REPOSITORY,
                    publication.MAIN_REF,
                    REVIEWED_HEAD_SHA,
                    REVIEWED_HEAD_SHA,
                    TASK_ID,
                    "token",
                )

    def test_reviewed_and_merged_tree_mismatch_is_rejected(self):
        def mismatch(path, _token=None):
            payload = api_payload(path)
            if path.endswith(f"/git/commits/{REVIEWED_HEAD_SHA}"):
                return {"tree": {"sha": "d" * 40}}
            return payload

        with mock.patch.object(publication, "api_get", side_effect=mismatch):
            with mock.patch.object(
                publication.artifact_contract,
                "pending_candidate",
                return_value={"bundle_version": VERSION},
            ):
                with self.assertRaisesRegex(
                    publication.PublicationError,
                    "reviewed_and_merged_tree_mismatch",
                ):
                    publication.authorize(
                        ROOT,
                        publication.REPOSITORY,
                        publication.MAIN_REF,
                        SOURCE_SHA,
                        SOURCE_SHA,
                        TASK_ID,
                        "token",
                    )

    def test_duplicate_immutable_version_is_rejected_even_if_expired(self):
        def duplicate(path, _token=None):
            if "/actions/artifacts?" in path:
                return {
                    "total_count": 1,
                    "artifacts": [
                        {
                            "name": f"oripa-storefront-contract-{VERSION}",
                            "expired": True,
                        }
                    ],
                }
            return api_payload(path)

        with mock.patch.object(publication, "api_get", side_effect=duplicate):
            with mock.patch.object(
                publication.artifact_contract,
                "pending_candidate",
                return_value={"bundle_version": VERSION},
            ):
                with mock.patch.object(
                    publication.artifact_contract, "validate_source"
                ):
                    with self.assertRaisesRegex(
                        publication.PublicationError,
                        "immutable_artifact_version_already_exists",
                    ):
                        publication.authorize(
                            ROOT,
                            publication.REPOSITORY,
                            publication.MAIN_REF,
                            SOURCE_SHA,
                            SOURCE_SHA,
                            TASK_ID,
                            "token",
                        )

    def test_candidate_source_authority_conflict_is_rejected(self):
        with mock.patch.object(publication, "api_get", side_effect=api_payload):
            with mock.patch.object(
                publication.artifact_contract,
                "pending_candidate",
                return_value={
                    "bundle_version": VERSION,
                    "source_commit": REVIEWED_HEAD_SHA,
                },
            ):
                with mock.patch.object(
                    publication.artifact_contract, "validate_source"
                ):
                    with self.assertRaisesRegex(
                        publication.PublicationError,
                        "candidate_source_authority_conflict",
                    ):
                        publication.authorize(
                            ROOT,
                            publication.REPOSITORY,
                            publication.MAIN_REF,
                            SOURCE_SHA,
                            SOURCE_SHA,
                            TASK_ID,
                            "token",
                        )


class PublicationReadbackTest(unittest.TestCase):
    def test_uploaded_bundle_is_downloaded_and_all_digests_are_reported(self):
        outer_digest = "d" * 64
        package_digest = "e" * 64
        public_digest = "f" * 64

        def download(_artifact_id, _token, destination):
            with zipfile.ZipFile(destination, "w") as archive:
                archive.writestr("artifact-manifest.json", b"manifest")
                archive.writestr("SHA256SUMS", b"checksums")
                archive.writestr("public.openapi.json", b"openapi")
                archive.writestr("oripa-storefront-client.tgz", b"client")
                archive.writestr("oripa-storefront-testkit.tgz", b"testkit")
            return outer_digest

        manifest = {
            "task_id": TASK_ID,
            "source_commit": SOURCE_SHA,
            "bundle": {"version": VERSION},
            "public_openapi": {"sha256": public_digest},
            "packages": [
                {
                    "name": "@oripa/storefront-client",
                    "disposition": "published",
                    "sha256": package_digest,
                },
                {
                    "name": "@oripa/storefront-testkit",
                    "disposition": "published",
                    "sha256": package_digest,
                },
            ],
        }
        metadata = {
            "id": 123,
            "name": f"oripa-storefront-contract-{VERSION}",
            "expired": False,
            "digest": f"sha256:{outer_digest}",
            "workflow_run": {"id": 456},
        }
        with tempfile.TemporaryDirectory() as temporary:
            output = Path(temporary) / "readback"
            with mock.patch.object(publication, "api_get", return_value=metadata):
                with mock.patch.object(
                    publication, "download_artifact", side_effect=download
                ):
                    with mock.patch.object(
                        publication.artifact_contract, "verify_manifest"
                    ):
                        with mock.patch.object(
                            publication.artifact_contract,
                            "load_json",
                            return_value=manifest,
                        ):
                            result = publication.readback(
                                ROOT,
                                "123",
                                f"oripa-storefront-contract-{VERSION}",
                                outer_digest,
                                SOURCE_SHA,
                                VERSION,
                                TASK_ID,
                                "456",
                                output,
                                "token",
                            )
            self.assertTrue(output.is_dir())
        self.assertEqual(result["outer_sha256"], outer_digest)
        self.assertEqual(result["source_commit"], SOURCE_SHA)
        self.assertEqual(result["public_openapi_sha256"], public_digest)
        self.assertEqual(result["client_sha256"], package_digest)
        self.assertEqual(result["testkit_sha256"], package_digest)
        self.assertRegex(result["manifest_sha256"], r"^[0-9a-f]{64}$")
        self.assertRegex(result["sha256sums_sha256"], r"^[0-9a-f]{64}$")

    def test_partial_or_wrong_upload_cannot_be_reported_as_success(self):
        metadata = {
            "id": 123,
            "name": f"oripa-storefront-contract-{VERSION}",
            "expired": False,
            "digest": "sha256:" + "0" * 64,
            "workflow_run": {"id": 456},
        }
        with tempfile.TemporaryDirectory() as temporary:
            with mock.patch.object(publication, "api_get", return_value=metadata):
                with self.assertRaisesRegex(
                    publication.PublicationError,
                    "uploaded_artifact_identity_mismatch",
                ):
                    publication.readback(
                        ROOT,
                        "123",
                        f"oripa-storefront-contract-{VERSION}",
                        "d" * 64,
                        SOURCE_SHA,
                        VERSION,
                        TASK_ID,
                        "456",
                        Path(temporary) / "readback",
                        "token",
                    )

    def test_archive_with_missing_payload_is_rejected(self):
        with tempfile.TemporaryDirectory() as temporary:
            source = Path(temporary) / "artifact.zip"
            destination = Path(temporary) / "payload"
            destination.mkdir()
            with zipfile.ZipFile(source, "w") as archive:
                archive.writestr("artifact-manifest.json", b"manifest")
            with self.assertRaisesRegex(
                publication.PublicationError, "artifact_file_set_invalid"
            ):
                publication.safe_extract(source, destination)


if __name__ == "__main__":
    unittest.main()
