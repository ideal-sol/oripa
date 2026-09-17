import hashlib
import importlib.util
from importlib.machinery import SourceFileLoader
import io
import json
import os
from pathlib import Path
import re
import subprocess
import tarfile
import tempfile
import unittest
from unittest import mock
import zipfile


ROOT = Path(__file__).resolve().parents[2]


def load_module(name: str, path: Path):
    spec = importlib.util.spec_from_loader(name, SourceFileLoader(name, str(path)))
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


artifact = load_module(
    "preview_image_artifact", ROOT / "scripts/ops/preview_image_artifact.py"
)
wrapper = load_module(
    "oripa_github_app_api", ROOT / "infrastructure/github-app/oripa-github-app-api"
)
readiness = load_module(
    "agency_production_readiness", ROOT / "scripts/ops/agency_production_readiness.py"
)


TASK = "OPS-005"
PR = 241
HEAD = "a" * 40
BASE_LABELS = {
    "org.opencontainers.image.created": "2026-08-12T00:00:00Z",
    "org.opencontainers.image.revision": HEAD,
    "org.opencontainers.image.source": "https://github.com/ideal-sol/oripa",
    "org.opencontainers.image.version": "preview-OPS-005",
}


def create_docker_archive(
    directory: Path,
    name: str,
    *,
    architecture: str = "amd64",
    artifact_kind: str = "preview",
    oci_config_path: bool = False,
    revision: str = HEAD,
    title: str = "",
) -> dict:
    reference, version = artifact.image_identity(
        name,
        artifact_kind=artifact_kind,
        architecture=architecture,
        task_id=TASK,
        source_sha=HEAD,
    )
    labels = {
        **BASE_LABELS,
        "org.opencontainers.image.revision": revision,
        "org.opencontainers.image.version": version,
        "org.opencontainers.image.title": title or {
            "api": "Oripa V2 API",
            "admin": "Oripa V2 Admin",
            "agency": "Oripa V2 Agency",
        }[name],
    }
    config = {
        "architecture": architecture,
        "os": "linux",
        "config": {"Labels": labels},
        "rootfs": {"type": "layers", "diff_ids": []},
    }
    config_bytes = json.dumps(config, sort_keys=True, separators=(",", ":")).encode()
    config_digest = hashlib.sha256(config_bytes).hexdigest()
    config_name = (
        f"blobs/sha256/{config_digest}"
        if oci_config_path
        else f"{config_digest}.json"
    )
    raw = directory / f"{name}.tar"
    with tarfile.open(raw, "w") as bundle:
        config_info = tarfile.TarInfo(config_name)
        config_info.size = len(config_bytes)
        bundle.addfile(config_info, io.BytesIO(config_bytes))
        manifest_bytes = json.dumps(
            [{"Config": config_name, "RepoTags": [reference], "Layers": []}]
        ).encode()
        manifest_info = tarfile.TarInfo("manifest.json")
        manifest_info.size = len(manifest_bytes)
        bundle.addfile(manifest_info, io.BytesIO(manifest_bytes))
    raw_size = raw.stat().st_size
    archive = directory / artifact.archive_names(architecture)[name]
    subprocess.run(
        ["zstd", "--quiet", "--force", str(raw), "-o", str(archive)], check=True
    )
    raw.unlink()
    return {
        "name": name,
        "reference": reference,
        "archive": archive.name,
        "archive_sha256": artifact.sha256_file(archive),
        "archive_bytes": archive.stat().st_size,
        "archive_uncompressed_bytes": raw_size,
        "image_id": f"sha256:{config_digest}",
        "architecture": architecture,
        "os": "linux",
        "labels": labels,
    }


def create_artifact(
    directory: Path,
    image_names=artifact.IMAGE_NAMES,
    *,
    architecture: str = "amd64",
    artifact_kind: str = "preview",
    legacy: bool = False,
    revision: str = HEAD,
) -> None:
    images = [
        create_docker_archive(
            directory,
            name,
            architecture=architecture,
            artifact_kind=artifact_kind,
            revision=revision,
        )
        for name in image_names
    ]
    manifest = {
        "schema_version": (
            artifact.LEGACY_SCHEMA_VERSION if legacy else artifact.SCHEMA_VERSION
        ),
        "task_id": TASK,
        "repository": "ideal-sol/oripa",
        "pull_request": PR,
        "source_commit": HEAD,
        "created_at": "2026-08-12T00:00:00Z",
        "platform": f"linux/{architecture}",
        "images": images,
    }
    if not legacy:
        manifest["artifact_kind"] = artifact_kind
        manifest["architecture"] = architecture
    write_artifact_metadata(directory, manifest)


def write_artifact_metadata(directory: Path, manifest: dict) -> None:
    manifest_path = directory / "manifest.json"
    manifest_path.write_bytes(artifact.canonical_json(manifest))
    paths = [directory / item["archive"] for item in manifest["images"]] + [manifest_path]
    (directory / "SHA256SUMS").write_text(
        "".join(f"{artifact.sha256_file(path)}  {path.name}\n" for path in paths),
        encoding="ascii",
    )


class PreviewImageArtifactTest(unittest.TestCase):
    def test_valid_artifact_verifies_image_identity_platform_and_revision(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            create_artifact(directory)
            result = artifact.verify_artifact(
                directory, task_id=TASK, pr_number=PR, source_sha=HEAD
            )
        self.assertEqual(result["status"], "verified")
        self.assertEqual(result["platform"], "linux/amd64")
        self.assertEqual([item["name"] for item in result["images"]], ["api", "admin"])

    def test_default_target_and_preview_artifact_remain_amd64(self):
        parsed = artifact.parser().parse_args(["target"])
        self.assertEqual(parsed.architecture, "amd64")
        self.assertEqual(artifact.target_platform(parsed.architecture), "linux/amd64")
        with self.assertRaisesRegex(artifact.ArtifactError, "preview_architecture_invalid"):
            artifact.validate_target("preview", "arm64")

    def test_production_candidate_arm64_artifact_verifies(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            create_artifact(
                directory,
                architecture="arm64",
                artifact_kind="production-candidate",
            )
            result = artifact.verify_artifact(
                directory,
                task_id=TASK,
                pr_number=PR,
                source_sha=HEAD,
                artifact_kind="production-candidate",
                architecture="arm64",
            )
        self.assertEqual(result["architecture"], "arm64")
        self.assertEqual(result["platform"], "linux/arm64")

    def test_production_agency_artifact_verifies_all_three_exact_arm64_images(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            create_artifact(directory, ("api", "admin", "agency"),
                            architecture="arm64", artifact_kind="production-candidate")
            result = artifact.verify_artifact(
                directory, task_id=TASK, pr_number=PR, source_sha=HEAD,
                architecture="arm64", artifact_kind="production-candidate",
            )
            self.assertEqual([image["name"] for image in result["images"]], ["api", "admin", "agency"])
            manifest = artifact.load_json(directory / "manifest.json")
            manifest["images"][2] = create_docker_archive(
                directory, "agency", architecture="arm64",
                artifact_kind="production-candidate", revision="b" * 40,
            )
            write_artifact_metadata(directory, manifest)
            with self.assertRaisesRegex(artifact.ArtifactError, "oci_revision_mismatch"):
                artifact.verify_artifact(
                    directory, task_id=TASK, pr_number=PR, source_sha=HEAD,
                    architecture="arm64", artifact_kind="production-candidate",
                )

    def test_legacy_preview_v1_artifact_remains_accepted_only_as_amd64(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            create_artifact(directory, legacy=True)
            result = artifact.verify_artifact(
                directory, task_id=TASK, pr_number=PR, source_sha=HEAD
            )
            self.assertEqual(result["platform"], "linux/amd64")
            with self.assertRaisesRegex(
                artifact.ArtifactError, "legacy_artifact_target_invalid"
            ):
                artifact.verify_artifact(
                    directory,
                    task_id=TASK,
                    pr_number=PR,
                    source_sha=HEAD,
                    artifact_kind="production-candidate",
                    architecture="arm64",
                )

    def test_api_only_artifact_verifies_without_admin_archive(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            create_artifact(directory, ("api",))
            result = artifact.verify_artifact(
                directory, task_id=TASK, pr_number=PR, source_sha=HEAD
            )
        self.assertEqual([item["name"] for item in result["images"]], ["api"])

    def test_admin_only_artifact_is_rejected(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            create_artifact(directory, ("admin",))
            with self.assertRaisesRegex(artifact.ArtifactError, "manifest_images_invalid"):
                artifact.verify_artifact(
                    directory, task_id=TASK, pr_number=PR, source_sha=HEAD
                )

    def test_agency_artifact_verifies_all_three_and_rejects_missing_api(self):
        self.assertIn('org.opencontainers.image.title="Oripa V2 Agency"', (ROOT / "apps/agency/Dockerfile").read_text())
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            create_artifact(directory, ("api", "admin", "agency"))
            result = artifact.verify_artifact(directory, task_id=TASK, pr_number=PR, source_sha=HEAD)
            self.assertEqual([item["name"] for item in result["images"]], ["api", "admin", "agency"])
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            create_artifact(directory, ("admin", "agency"))
            with self.assertRaisesRegex(artifact.ArtifactError, "manifest_images_invalid"):
                artifact.verify_artifact(directory, task_id=TASK, pr_number=PR, source_sha=HEAD)

    def test_agency_archive_rejects_other_realm_titles(self):
        for title in ("Oripa V2 Admin", "Oripa V2 API"):
            with self.subTest(title=title), tempfile.TemporaryDirectory() as temporary:
                directory = Path(temporary)
                create_artifact(directory, ("api", "admin", "agency"))
                manifest = artifact.load_json(directory / "manifest.json")
                manifest["images"][2] = create_docker_archive(directory, "agency", title=title)
                write_artifact_metadata(directory, manifest)
                with self.assertRaisesRegex(artifact.ArtifactError, "oci_title_mismatch"):
                    artifact.verify_artifact(directory, task_id=TASK, pr_number=PR, source_sha=HEAD)

    def test_package_parser_rejects_unknown_image_mode(self):
        with self.assertRaises(SystemExit):
            artifact.parser().parse_args(
                [
                    "package",
                    "--output",
                    "/tmp/output",
                    "--task-id",
                    TASK,
                    "--pr-number",
                    str(PR),
                    "--source-sha",
                    HEAD,
                    "--created-at",
                    "2026-08-12T00:00:00Z",
                    "--image-mode",
                    "admin-only",
                    "--api-image",
                    "api",
                ]
            )

    def test_docker_archive_accepts_oci_content_store_config_path(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            image = create_docker_archive(directory, "api", oci_config_path=True)
            result = artifact.docker_archive_metadata(
                directory / artifact.ARCHIVE_NAMES["api"],
                image["archive_uncompressed_bytes"],
            )
        self.assertEqual(result["image_id"], image["image_id"])
        self.assertEqual(result["architecture"], "amd64")

    def test_checksum_tampering_is_rejected_before_load(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            create_artifact(directory)
            with (directory / artifact.ARCHIVE_NAMES["api"]).open("ab") as stream:
                stream.write(b"tampered")
            with self.assertRaisesRegex(artifact.ArtifactError, "checksum_mismatch"):
                artifact.verify_artifact(
                    directory, task_id=TASK, pr_number=PR, source_sha=HEAD
                )

    def test_source_sha_mismatch_is_rejected(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            create_artifact(directory)
            with self.assertRaisesRegex(artifact.ArtifactError, "manifest_identity_mismatch"):
                artifact.verify_artifact(
                    directory, task_id=TASK, pr_number=PR, source_sha="b" * 40
                )

    def test_missing_or_invalid_manifest_architecture_is_rejected(self):
        for value in (None, "riscv64"):
            with self.subTest(value=value), tempfile.TemporaryDirectory() as temporary:
                directory = Path(temporary)
                create_artifact(
                    directory,
                    architecture="arm64",
                    artifact_kind="production-candidate",
                )
                manifest = artifact.load_json(directory / "manifest.json")
                if value is None:
                    manifest.pop("architecture")
                else:
                    manifest["architecture"] = value
                write_artifact_metadata(directory, manifest)
                with self.assertRaisesRegex(
                    artifact.ArtifactError,
                    "manifest_schema_invalid|manifest_identity_mismatch",
                ):
                    artifact.verify_artifact(
                        directory,
                        task_id=TASK,
                        pr_number=PR,
                        source_sha=HEAD,
                        artifact_kind="production-candidate",
                        architecture="arm64",
                    )

    def test_arm64_artifact_claimed_as_amd64_is_rejected(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            create_artifact(
                directory,
                architecture="arm64",
                artifact_kind="production-candidate",
            )
            with self.assertRaisesRegex(artifact.ArtifactError, "manifest_identity_mismatch"):
                artifact.verify_artifact(
                    directory,
                    task_id=TASK,
                    pr_number=PR,
                    source_sha=HEAD,
                    artifact_kind="production-candidate",
                    architecture="amd64",
                )

    def test_oci_revision_mismatch_is_rejected(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            create_artifact(directory, revision="b" * 40)
            with self.assertRaisesRegex(artifact.ArtifactError, "oci_revision_mismatch"):
                artifact.verify_artifact(
                    directory, task_id=TASK, pr_number=PR, source_sha=HEAD
                )

    def test_image_digest_mismatch_is_rejected(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            create_artifact(directory)
            manifest = artifact.load_json(directory / "manifest.json")
            manifest["images"][0]["image_id"] = "sha256:" + "0" * 64
            write_artifact_metadata(directory, manifest)
            with self.assertRaisesRegex(artifact.ArtifactError, "image_metadata_mismatch"):
                artifact.verify_artifact(
                    directory, task_id=TASK, pr_number=PR, source_sha=HEAD
                )

    def test_load_refuses_non_amd64_host_before_docker_load(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            create_artifact(directory)
            arguments = mock.Mock(
                directory=directory,
                task_id=TASK,
                pr_number=PR,
                source_sha=HEAD,
                artifact_kind="preview",
                architecture="amd64",
            )
            with mock.patch.object(
                artifact, "verify_artifact", return_value={"status": "verified"}
            ):
                with mock.patch.object(
                    artifact,
                    "host_architectures",
                    return_value={"machine": "arm64", "docker": "arm64"},
                ):
                    with mock.patch.object(artifact.subprocess, "Popen") as popen:
                        with self.assertRaisesRegex(artifact.ArtifactError, "host_architecture_mismatch"):
                            artifact.load_artifact(arguments)
            popen.assert_not_called()

    def test_arm64_artifact_refuses_amd64_host_before_docker_load(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            create_artifact(
                directory,
                architecture="arm64",
                artifact_kind="production-candidate",
            )
            arguments = mock.Mock(
                directory=directory,
                task_id=TASK,
                pr_number=PR,
                source_sha=HEAD,
                artifact_kind="production-candidate",
                architecture="arm64",
            )
            with mock.patch.object(
                artifact, "verify_artifact", return_value={"status": "verified"}
            ), mock.patch.object(
                artifact,
                "host_architectures",
                return_value={"machine": "amd64", "docker": "amd64"},
            ), mock.patch.object(artifact.subprocess, "Popen") as popen:
                with self.assertRaisesRegex(
                    artifact.ArtifactError, "host_architecture_mismatch"
                ):
                    artifact.load_artifact(arguments)
            popen.assert_not_called()

    def test_host_helper_has_no_image_build_command(self):
        source = (ROOT / "scripts/ops/preview_image_artifact.py").read_text()
        self.assertNotIn("docker build", source)
        self.assertIn('["docker", "image", "load"', source)

    def test_target_architecture_is_canonical_for_host_guard(self):
        self.assertEqual(artifact.TARGET_ARCHITECTURE, "amd64")
        self.assertEqual(artifact.TARGET_PLATFORM, "linux/amd64")
        with mock.patch.object(
            artifact,
            "host_architectures",
            return_value={"machine": "amd64", "docker": "amd64"},
        ):
            self.assertEqual(
                artifact.require_target_host(),
                {"machine": "amd64", "docker": "amd64"},
            )


class GitHubAppArtifactBoundaryTest(unittest.TestCase):
    def policy(self):
        return {
            "task_id": TASK,
            "branch": "ci/OPS-005-preview-image-build-architecture",
            "base_branch": "main",
            "pr_title": "[OPS-005] Preview Image Build Architecture Fix",
        }

    def pull(self):
        return {
            "state": "open",
            "merged": False,
            "merge_commit_sha": None,
            "title": self.policy()["pr_title"],
            "head": {
                "sha": HEAD,
                "ref": self.policy()["branch"],
                "repo": {"full_name": "ideal-sol/oripa"},
            },
            "base": {"ref": "main"},
        }

    def test_exact_internal_pr_head_is_accepted(self):
        with mock.patch.object(wrapper, "api_get", return_value=self.pull()):
            result = wrapper.validated_pr(self.policy(), str(PR), HEAD)
        self.assertEqual(result["head"]["sha"], HEAD)

    def test_merged_internal_pr_exact_head_is_accepted(self):
        pull = self.pull()
        pull["state"] = "closed"
        pull["merged"] = True
        pull["merge_commit_sha"] = "b" * 40
        with mock.patch.object(wrapper, "api_get", return_value=pull):
            result = wrapper.validated_pr(self.policy(), str(PR), HEAD)
        self.assertTrue(result["merged"])

    def test_closed_unmerged_pr_is_rejected(self):
        pull = self.pull()
        pull["state"] = "closed"
        with mock.patch.object(wrapper, "api_get", return_value=pull):
            with self.assertRaisesRegex(wrapper.WrapperError, "pull_request_identity_mismatch"):
                wrapper.validated_pr(self.policy(), str(PR), HEAD)

    def test_external_pr_or_head_mismatch_is_rejected(self):
        pull = self.pull()
        pull["head"]["repo"]["full_name"] = "external/fork"
        with mock.patch.object(wrapper, "api_get", return_value=pull):
            with self.assertRaisesRegex(wrapper.WrapperError, "pull_request_identity_mismatch"):
                wrapper.validated_pr(self.policy(), str(PR), HEAD)
        with mock.patch.object(wrapper, "api_get", return_value=self.pull()):
            with self.assertRaisesRegex(wrapper.WrapperError, "pull_request_identity_mismatch"):
                wrapper.validated_pr(self.policy(), str(PR), "b" * 40)

    def test_outer_artifact_digest_tampering_is_rejected(self):
        with self.assertRaisesRegex(wrapper.WrapperError, "outer_artifact_digest_mismatch"):
            wrapper.require_outer_digest("sha256:" + "a" * 64, "b" * 64)

    def test_artifact_zip_path_traversal_is_rejected(self):
        with tempfile.TemporaryDirectory() as temporary:
            source = Path(temporary) / "artifact.zip"
            destination = Path(temporary) / "payload"
            destination.mkdir()
            with zipfile.ZipFile(source, "w") as archive_file:
                archive_file.writestr("../manifest.json", "{}")
            with self.assertRaisesRegex(wrapper.WrapperError, "artifact_file_set_invalid"):
                wrapper.safe_extract(source, destination)

    def test_wrapper_extracts_only_canonical_amd64_artifact_files(self):
        with tempfile.TemporaryDirectory() as temporary:
            source = Path(temporary) / "artifact.zip"
            destination = Path(temporary) / "payload"
            destination.mkdir()
            expected = {
                "manifest.json",
                "SHA256SUMS",
                *artifact.ARCHIVE_NAMES.values(),
            }
            with zipfile.ZipFile(source, "w") as archive_file:
                for filename in expected:
                    archive_file.writestr(filename, b"placeholder")
            wrapper.safe_extract(source, destination)
            self.assertEqual({path.name for path in destination.iterdir()}, expected)

    def test_wrapper_extracts_canonical_api_only_artifact_files(self):
        with tempfile.TemporaryDirectory() as temporary:
            source = Path(temporary) / "artifact.zip"
            destination = Path(temporary) / "payload"
            destination.mkdir()
            expected = {
                "manifest.json",
                "SHA256SUMS",
                artifact.ARCHIVE_NAMES["api"],
            }
            with zipfile.ZipFile(source, "w") as archive_file:
                for filename in expected:
                    archive_file.writestr(filename, b"placeholder")
            wrapper.safe_extract(source, destination)
            self.assertEqual({path.name for path in destination.iterdir()}, expected)

    def test_wrapper_rejects_admin_only_artifact_files(self):
        with tempfile.TemporaryDirectory() as temporary:
            source = Path(temporary) / "artifact.zip"
            destination = Path(temporary) / "payload"
            destination.mkdir()
            with zipfile.ZipFile(source, "w") as archive_file:
                for filename in (
                    "manifest.json",
                    "SHA256SUMS",
                    artifact.ARCHIVE_NAMES["admin"],
                ):
                    archive_file.writestr(filename, b"placeholder")
            with self.assertRaisesRegex(wrapper.WrapperError, "artifact_file_set_invalid"):
                wrapper.safe_extract(source, destination)

    def test_failed_or_wrong_workflow_run_is_rejected(self):
        run = {
            "event": "workflow_dispatch",
            "status": "completed",
            "conclusion": "failure",
            "path": ".github/workflows/preview-image-build.yml",
            "head_branch": "main",
        }
        with mock.patch.object(wrapper, "api_get", return_value=run):
            with self.assertRaisesRegex(wrapper.WrapperError, "workflow_run_rejected"):
                wrapper.validated_run(123)

    def test_successful_task_branch_workflow_run_is_not_canonical(self):
        run = {
            "event": "workflow_dispatch",
            "status": "completed",
            "conclusion": "success",
            "path": ".github/workflows/preview-image-build.yml",
            "head_branch": "ci/OPS-005-preview-image-build-architecture",
        }
        with mock.patch.object(wrapper, "api_get", return_value=run):
            with self.assertRaisesRegex(wrapper.WrapperError, "workflow_run_rejected"):
                wrapper.validated_run(123)

    def test_required_checks_must_all_be_latest_successes(self):
        checks = [
            {
                "id": index,
                "name": name,
                "head_sha": HEAD,
                "status": "completed",
                "conclusion": "success",
                "started_at": f"2026-08-12T00:00:{index:02d}Z",
                "app": {
                    "id": 15368,
                    "slug": "github-actions",
                    "owner": {"login": "github"},
                },
            }
            for index, name in enumerate(sorted(wrapper.REQUIRED_CHECKS), start=1)
        ]
        with mock.patch.object(
            wrapper, "api_get", return_value={"total_count": len(checks), "check_runs": checks}
        ):
            wrapper.validate_required_checks(HEAD)
        checks[-1]["conclusion"] = "failure"
        with mock.patch.object(
            wrapper, "api_get", return_value={"total_count": len(checks), "check_runs": checks}
        ):
            with self.assertRaisesRegex(wrapper.WrapperError, "required_checks_not_successful"):
                wrapper.validate_required_checks(HEAD)


class PreviewImageWorkflowDefinitionTest(unittest.TestCase):
    def test_normal_default_and_api_only_admin_skip_are_guarded(self):
        workflow = (ROOT / ".github/workflows/preview-image-build.yml").read_text()
        self.assertIn("image_mode:", workflow)
        self.assertIn("default: normal", workflow)
        self.assertIn("- api-only", workflow)
        self.assertIn('image_mode not in {"normal", "api-only", "agency"}', workflow)
        self.assertIn("pull request is neither open nor merged", workflow)
        self.assertEqual(workflow.count("--file infra/docker/backend/Dockerfile"), 1)
        self.assertEqual(workflow.count("--file apps/admin/Dockerfile"), 1)
        guard = re.search(
            r'if \[\[ "\$INPUT_IMAGE_MODE" == "normal" \|\| "\$INPUT_IMAGE_MODE" == "agency" \]\]; then(?P<body>.*?)\n\s*fi',
            workflow,
            re.DOTALL,
        )
        self.assertIsNotNone(guard)
        self.assertIn("--file apps/admin/Dockerfile", guard.group("body"))
        self.assertIn('--admin-image "$admin_image"', guard.group("body"))
        self.assertNotIn("storefront_contract_artifact.py", workflow)
        self.assertNotIn("storefront-contract", workflow)

    def test_preview_remains_amd64_while_arm64_paths_are_additive(self):
        preview = (ROOT / ".github/workflows/preview-image-build.yml").read_text()
        platform_ci = (ROOT / ".github/workflows/platform-ci.yml").read_text()
        production = (
            ROOT / ".github/workflows/platform-production-arm64-artifact.yml"
        ).read_text()

        self.assertIn("name: build-preview-images-amd64", preview)
        self.assertIn("runs-on: ubuntu-24.04", preview)
        self.assertNotIn("ubuntu-24.04-arm", preview)
        self.assertIn("production-arm64-artifact:", platform_ci)
        self.assertIn("oripa-platform-arm64-verification-${source_sha}", platform_ci)
        self.assertIn("source SHA is not current protected main", production)
        self.assertIn("required checks not successful", production)
        self.assertIn("--artifact-kind production-candidate", production)
        self.assertIn("--architecture arm64", production)
        self.assertIn("--target production", production)
        self.assertNotIn("ssh", production.lower())


class ProductionImageDefinitionTest(unittest.TestCase):
    def test_production_dispatch_requires_domain_inputs_without_defaults(self):
        workflow = (ROOT / ".github/workflows/platform-production-arm64-artifact.yml").read_text()
        for field, name in readiness.DOMAIN_ENVIRONMENT_AUTHORITY.items():
            block = re.search(r"      " + field + r":\n(?P<body>(?:        .*\n)+)", workflow)
            self.assertIsNotNone(block)
            self.assertIn("required: true", block.group("body"))
            self.assertNotIn("default:", block.group("body"))
            self.assertIn(name + ": ${{ inputs." + field + " }}", workflow)
        self.assertNotIn("ori-poke.com", workflow)
        self.assertNotIn("oripa-z.com", workflow)
        for filename in ("platform-ci.yml", "platform-production-arm64-artifact.yml"):
            content = (ROOT / ".github/workflows" / filename).read_text()
            self.assertIn('--evidence "$RUNNER_TEMP/production-readiness.json"', content)
            self.assertIn('"$RUNNER_TEMP/platform-production-arm64/production-readiness.json"', content)

    def test_both_arm64_workflows_require_agency_and_offline_production_checks(self):
        for filename in ("platform-ci.yml", "platform-production-arm64-artifact.yml"):
            with self.subTest(workflow=filename):
                workflow = (ROOT / ".github/workflows" / filename).read_text()
                self.assertIn('agency_image="oripa-v2-agency:production-candidate-', workflow)
                self.assertIn("--file apps/agency/Dockerfile", workflow)
                self.assertIn('--tag "$agency_image"', workflow)
                self.assertIn("--image-mode agency", workflow)
                self.assertEqual(workflow.count('--agency-image "$agency_image"'), 2)
                self.assertIn("scripts/ops/agency_production_readiness.py", workflow)
                self.assertIn("runs-on: ubuntu-24.04-arm", workflow)

    def test_api_and_admin_have_explicit_production_targets(self):
        api = (ROOT / "infra/docker/backend/Dockerfile").read_text()
        admin = (ROOT / "apps/admin/Dockerfile").read_text()
        apache = (ROOT / "infra/docker/backend/apache-production.conf").read_text()

        self.assertIn("AS production", api)
        self.assertIn("composer install", api)
        self.assertIn("--no-dev", api)
        self.assertIn("USER www-data", api)
        self.assertIn("EXPOSE 8000", api)
        self.assertIn("FallbackResource /index.php", apache)
        self.assertIn("AS preview", api)
        self.assertIn("AS production", admin)
        self.assertIn("pnpm install --frozen-lockfile", admin)
        self.assertIn("USER node", admin)
        self.assertIn("EXPOSE 3000", admin)


class AgencyProductionReadinessTest(unittest.TestCase):
    def setUp(self):
        self.environment = {
            "V2_PUBLIC_ORIGIN": "https://public.customer.example",
            "V2_ADMIN_ORIGIN": "https://admin.customer.example",
            "V2_AGENCY_ORIGIN": "https://agency.customer.example",
            "V2_AGENCY_LOGIN_URL": "https://agency.customer.example/login",
        }
        patcher = mock.patch.dict(os.environ, self.environment)
        patcher.start()
        self.addCleanup(patcher.stop)

    def test_checks_use_only_public_config_and_isolated_read_only_images(self):
        config = json.loads(readiness.CONFIG.read_text())
        self.assertEqual(config["domain_environment_authority"], readiness.DOMAIN_ENVIRONMENT_AUTHORITY)
        self.assertNotIn("https://", readiness.CONFIG.read_text())
        self.assertNotIn("required_api_environment", config)
        self.assertEqual(config["agency_api_prefix"], "/agency/api/v2")
        self.assertFalse(config["activation_authorized"])
        with mock.patch.object(readiness.subprocess, "run") as run:
            effective = readiness.image_checks("api-candidate", "admin-candidate", "agency-candidate")
        self.assertEqual(effective, {
            field: self.environment[name] for field, name in config["domain_environment_authority"].items()
        })
        self.assertEqual(run.call_count, 3)
        for call in run.call_args_list:
            command = call.args[0]
            self.assertEqual(command[:6], ["docker", "run", "--rm", "--read-only", "--network", "none"])
            self.assertTrue(call.kwargs["check"])
        self.assertIn("V2_AGENCY_LOGIN_URL=https://agency.customer.example/login", run.call_args_list[0].args[0])
        self.assertIn("APP_ENV=production", run.call_args_list[0].args[0])

    def test_every_authority_is_required_and_nonempty_before_any_image_runs(self):
        for name in self.environment:
            for value in (None, "", " "):
                with self.subTest(name=name, value=value), mock.patch.dict(os.environ, self.environment, clear=True):
                    if value is None:
                        del os.environ[name]
                    else:
                        os.environ[name] = value
                    with mock.patch.object(readiness.subprocess, "run") as run:
                        with self.assertRaises(ValueError):
                            readiness.image_checks("api", "admin", "agency")
                        run.assert_not_called()

    def test_invalid_https_values_are_rejected_for_every_authority(self):
        invalid = (
            "not-a-url", "http://agency.customer.example", "https://",
            "https://bad host.example", "https://-bad.example", "https://bad..example",
            "https://host.example:bad", "https://host.example:65536", "https://host.example:0",
            "https://host.example:", "https://user:password@host.example",
            "https://host.example\\evil", "\nhttps://host.example", "https://host.example\t",
            "https://[::1]evil", "https://host.example/%invalid", 'https://host.example/"',
        )
        for name in self.environment:
            for value in invalid:
                with self.subTest(name=name, value=value), mock.patch.dict(os.environ, {name: value}):
                    with self.assertRaises(ValueError):
                        readiness.resolve_config()

    def test_origins_forbid_all_path_query_and_fragment_components(self):
        for name in ("V2_PUBLIC_ORIGIN", "V2_ADMIN_ORIGIN", "V2_AGENCY_ORIGIN"):
            for suffix in ("/", "/login", "?value=1", "#fragment", "?", "#"):
                with self.subTest(name=name, suffix=suffix), mock.patch.dict(
                    os.environ, {name: self.environment[name] + suffix}
                ):
                    with self.assertRaises(ValueError):
                        readiness.resolve_config()

    def test_login_origin_must_match_host_and_effective_port(self):
        for value in ("https://other.customer.example/login", "https://agency.customer.example:444/login"):
            with self.subTest(value=value), mock.patch.dict(os.environ, {"V2_AGENCY_LOGIN_URL": value}):
                with self.assertRaisesRegex(ValueError, "must match"):
                    readiness.resolve_config()
        with mock.patch.dict(os.environ, {
            "V2_AGENCY_LOGIN_URL": "https://AGENCY.customer.example:443/login?next=account#login"
        }):
            readiness.resolve_config()

    def test_new_production_values_and_future_customer_values_pass(self):
        for domain in ("ori-poke.com", "another-customer.example"):
            environment = {
                "V2_PUBLIC_ORIGIN": f"https://{domain}",
                "V2_ADMIN_ORIGIN": f"https://admin.{domain}",
                "V2_AGENCY_ORIGIN": f"https://agent.{domain}",
                "V2_AGENCY_LOGIN_URL": f"https://agent.{domain}/login",
            }
            with self.subTest(domain=domain), mock.patch.dict(os.environ, environment):
                config, effective = readiness.resolve_config()
                self.assertEqual(config["required_api_environment"], environment)
                self.assertEqual(effective["agency_login_url"], environment["V2_AGENCY_LOGIN_URL"])

    def test_old_test_agency_login_policy_is_preserved(self):
        with mock.patch.dict(os.environ, {
            "V2_AGENCY_ORIGIN": "https://ad.luxe-pack.biz",
            "V2_AGENCY_LOGIN_URL": "https://ad.luxe-pack.biz/login",
        }):
            with self.assertRaisesRegex(ValueError, "Old Test"):
                readiness.resolve_config()

    def test_literal_or_remapped_config_cannot_replace_environment_authority(self):
        config = json.loads(readiness.CONFIG.read_text())
        for value in ("https://fixed.example", "OTHER_ENV"):
            config["domain_environment_authority"]["public_origin"] = value
            with mock.patch.object(readiness, "CONFIG") as path:
                path.read_text.return_value = json.dumps(config)
                with self.assertRaisesRegex(ValueError, "environment authority"):
                    readiness.resolve_config()

    def test_success_evidence_records_only_effective_domains_and_exact_source(self):
        with tempfile.TemporaryDirectory() as temporary:
            evidence = Path(temporary) / "readiness.json"
            arguments = ["readiness", "--api-image", "api", "--admin-image", "admin",
                         "--agency-image", "agency", "--evidence", str(evidence),
                         "--source-sha", HEAD]
            with mock.patch("sys.argv", arguments), mock.patch.object(readiness.subprocess, "run"):
                readiness.main()
            result = json.loads(evidence.read_text())
            self.assertEqual(result["source_commit"], HEAD)
            self.assertEqual(result["status"], "PASS")
            self.assertEqual(result["effective_domains"], {
                field: self.environment[name] for field, name in readiness.DOMAIN_ENVIRONMENT_AUTHORITY.items()
            })
            evidence.unlink()
            with mock.patch("sys.argv", arguments), mock.patch.object(
                readiness.subprocess, "run", side_effect=subprocess.CalledProcessError(1, "docker")
            ):
                with self.assertRaises(subprocess.CalledProcessError):
                    readiness.main()
            self.assertFalse(evidence.exists())

    def test_frontend_scanner_accepts_same_origin_and_rejects_each_old_test_value(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            (directory / ".next/static").mkdir(parents=True)
            (directory / ".next/server").mkdir()
            (directory / ".next/BUILD_ID").write_text("unit-fixture")
            asset = directory / ".next/static/app.js"
            asset.write_text("fetch('/agency/api/v2/auth/session')")
            command = ["node", "-e", readiness.FRONTEND_CHECK]
            options = dict(cwd=directory, capture_output=True, text=True,
                           env={**os.environ, "ORIPA_COMPONENT": "agency"})
            accepted = subprocess.run(command, **options)
            self.assertEqual(accepted.returncode, 0, accepted.stderr)
            self.assertEqual(json.loads(accepted.stdout)["old_test_values"], 0)
            for value in ("ad.luxe-pack.biz", "test.luxe-pack.biz", "127.0.0.1:8621"):
                with self.subTest(value=value):
                    asset.write_text("/agency/api/v2 " + value)
                    self.assertNotEqual(subprocess.run(command, **options).returncode, 0)
            asset.write_text("no Agency API")
            self.assertNotEqual(subprocess.run(command, **options).returncode, 0)

    def test_embedded_api_probe_has_valid_php_syntax(self):
        result = subprocess.run(["php", "-l"], input="<?php\n" + readiness.API_CHECK,
                                capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)


class PreviewActivationRunbookTest(unittest.TestCase):
    def test_api_only_activation_requires_stable_loopback_and_same_origin_smoke(self):
        runbook = (
            ROOT / "docs/operations/deployment/preview-image-build.md"
        ).read_text(encoding="utf-8")

        for required in (
            "API-only Activation Acceptance",
            "127.0.0.1:8611:8000",
            "http://127.0.0.1:8611/api/health",
            "https://test.luxe-pack.biz/api/v2/auth/session",
            "https://test.luxe-pack.biz/api/v2/gachas?limit=1",
            "https://test.luxe-pack.biz/api/v2/point-products",
            "https://luxe-pack.biz/api/v2/auth/session",
            "https://luxe-pack.biz/api/v2/gachas?limit=1",
            "https://luxe-pack.biz/api/v2/point-products",
            "https://luxe-pack.biz/points",
            "API-only Activation is incomplete if any same-origin smoke is omitted",
        ):
            self.assertIn(required, runbook)
        self.assertNotIn("preserve the existing fixed IP", runbook)
        self.assertNotRegex(
            runbook,
            r"http://(?:10\.|192\.168\.|172\.(?:1[6-9]|2\d|3[01])\.)",
        )

    def test_preview_nginx_runbook_uses_guarded_stable_activation(self):
        runbook = (
            ROOT / "docs/operations/deployment/preview-fincode-callbacks.md"
        ).read_text(encoding="utf-8")

        self.assertIn("http://127.0.0.1:8611", runbook)
        self.assertIn("preview_fincode_nginx.py activate", runbook)
        self.assertIn("runs `/usr/sbin/nginx -t`", runbook)
        self.assertIn("only after the config test passes", runbook)
        self.assertNotRegex(
            runbook,
            r"http://(?:10\.|192\.168\.|172\.(?:1[6-9]|2\d|3[01])\.)",
        )

    def test_live_nginx_runbook_uses_guarded_stable_activation(self):
        runbook = (
            ROOT
            / "docs/operations/deployment/luxe-pack-storefront-api-upstream.md"
        ).read_text(encoding="utf-8")

        for required in (
            "http://127.0.0.1:8611",
            "--server-name luxe-pack.biz",
            "/etc/nginx/conf.d/luxe-pack.biz.conf",
            "preview_fincode_nginx.py activate",
            "runs `/usr/sbin/nginx -t`",
            "only after the config test passes",
        ):
            self.assertIn(required, runbook)
        self.assertNotRegex(
            runbook,
            r"http://(?:10\.|192\.168\.|172\.(?:1[6-9]|2\d|3[01])\.)",
        )


if __name__ == "__main__":
    unittest.main()
