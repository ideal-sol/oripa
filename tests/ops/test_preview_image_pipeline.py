import hashlib
import importlib.util
from importlib.machinery import SourceFileLoader
import io
import json
from pathlib import Path
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
    directory: Path, name: str, *, oci_config_path: bool = False
) -> dict:
    reference = f"oripa-v2-{name}:preview-OPS-005-aaaaaaaaaaaa"
    labels = {
        **BASE_LABELS,
        "org.opencontainers.image.title": (
            "Oripa V2 API" if name == "api" else "Oripa V2 Admin"
        ),
    }
    config = {
        "architecture": "amd64",
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
    archive = directory / artifact.ARCHIVE_NAMES[name]
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
        "architecture": "amd64",
        "os": "linux",
        "labels": labels,
    }


def create_artifact(directory: Path) -> None:
    images = [create_docker_archive(directory, name) for name in artifact.IMAGE_NAMES]
    manifest = {
        "schema_version": artifact.SCHEMA_VERSION,
        "task_id": TASK,
        "repository": "ideal-sol/oripa",
        "pull_request": PR,
        "source_commit": HEAD,
        "created_at": "2026-08-12T00:00:00Z",
        "platform": "linux/amd64",
        "images": images,
    }
    manifest_path = directory / "manifest.json"
    manifest_path.write_bytes(artifact.canonical_json(manifest))
    paths = [directory / item["archive"] for item in images] + [manifest_path]
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

    def test_load_refuses_non_amd64_host_before_docker_load(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            create_artifact(directory)
            arguments = mock.Mock(
                directory=directory, task_id=TASK, pr_number=PR, source_sha=HEAD
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


if __name__ == "__main__":
    unittest.main()
