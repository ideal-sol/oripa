import importlib.util
import hashlib
import io
import json
from pathlib import Path
import tarfile
import tempfile
import unittest


SCRIPT = (
    Path(__file__).resolve().parents[2]
    / "scripts"
    / "release"
    / "platform_artifact.py"
)
SPEC = importlib.util.spec_from_file_location("platform_artifact", SCRIPT)
platform_artifact = importlib.util.module_from_spec(SPEC)
assert SPEC.loader
SPEC.loader.exec_module(platform_artifact)


class PlatformArtifactTest(unittest.TestCase):
    def test_content_set_checksum_is_path_independent(self):
        with tempfile.TemporaryDirectory() as first_dir, tempfile.TemporaryDirectory() as second_dir:
            first = Path(first_dir)
            second = Path(second_dir)
            (first / "a.php").write_text("same-a\n", encoding="utf-8")
            (first / "b.php").write_text("same-b\n", encoding="utf-8")
            (second / "renamed-1.php").write_text("same-b\n", encoding="utf-8")
            (second / "renamed-2.php").write_text("same-a\n", encoding="utf-8")
            self.assertEqual(
                platform_artifact.content_set_checksum(first.glob("*.php")),
                platform_artifact.content_set_checksum(second.glob("*.php")),
            )

    def test_deterministic_tar_gz_repeats_byte_for_byte(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / "source"
            source.mkdir()
            (source / "b.txt").write_text("beta\n", encoding="utf-8")
            (source / "a.txt").write_text("alpha\n", encoding="utf-8")
            first = root / "first.tar.gz"
            second = root / "second.tar.gz"
            members = list(source.glob("*.txt"))
            platform_artifact.deterministic_tar_gz(source, members, first, 123456789)
            platform_artifact.deterministic_tar_gz(source, reversed(members), second, 123456789)
            self.assertEqual(first.read_bytes(), second.read_bytes())

    def test_checksum_verification_rejects_tamper(self):
        with tempfile.TemporaryDirectory() as temporary:
            assets = Path(temporary)
            target = assets / "artifact.txt"
            target.write_text("original\n", encoding="utf-8")
            platform_artifact.write_checksums(assets)
            platform_artifact.verify_checksums(assets)
            target.write_text("tampered\n", encoding="utf-8")
            with self.assertRaisesRegex(platform_artifact.ReleaseError, "checksum mismatch"):
                platform_artifact.verify_checksums(assets)

    def test_manifest_requires_alpha_production_boundary(self):
        value = {
            "schema_version": "2.0",
            "platform": {
                "version": platform_artifact.PLATFORM_VERSION,
                "compatibility_family": 2,
                "channel": "alpha",
                "tag": platform_artifact.RELEASE_TAG,
                "source_commit": "1" * 40,
                "created_at": "2026-07-27T00:00:00Z",
                "production_allowed": False,
                "data_retention_guaranteed": False,
            },
            "contracts": {name: {} for name in platform_artifact.CONTRACT_PATHS},
            "packages": {name: {} for name in platform_artifact.PACKAGE_PATHS},
            "images": {name: {} for name in platform_artifact.IMAGE_DEFINITIONS},
            "database": {},
            "runtimes": {},
            "rollback_classification": "alpha",
            "required_checks": platform_artifact.REQUIRED_CHECKS,
            "known_issues_asset": "KNOWN-ISSUES.md",
            "sbom_assets": [],
            "provenance_asset": "provenance.json",
            "secret_scan": {"result": "PASS", "candidate_count": 0},
            "production_go": {"required": True, "approved": False},
        }
        platform_artifact.validate_manifest_shape(value)
        value["platform"]["production_allowed"] = True
        with self.assertRaisesRegex(platform_artifact.ReleaseError, "platform identity"):
            platform_artifact.validate_manifest_shape(value)

    def test_bundle_compare_detects_mismatch(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            first = root / "first"
            second = root / "second"
            first.mkdir()
            second.mkdir()
            (first / "asset").write_text("one", encoding="utf-8")
            (second / "asset").write_text("two", encoding="utf-8")
            with self.assertRaisesRegex(platform_artifact.ReleaseError, "reproducibility"):
                platform_artifact.compare_bundles(first, second)

    def test_docker_archive_verification_follows_extensionless_config_blob(self):
        with tempfile.TemporaryDirectory() as temporary:
            archive_path = Path(temporary) / "image.tar.gz"
            labels = {
                "org.opencontainers.image.version": platform_artifact.PLATFORM_VERSION,
                "org.opencontainers.image.revision": "1" * 40,
            }
            config_bytes = json.dumps(
                {"config": {"Labels": labels}},
                sort_keys=True,
                separators=(",", ":"),
            ).encode()
            digest = hashlib.sha256(config_bytes).hexdigest()
            config_name = f"blobs/sha256/{digest}"
            manifest_bytes = json.dumps(
                [{"Config": config_name, "RepoTags": ["test:fixed"], "Layers": []}],
                sort_keys=True,
                separators=(",", ":"),
            ).encode()
            with tarfile.open(archive_path, mode="w:gz") as archive:
                for name, content in (
                    ("manifest.json", manifest_bytes),
                    (config_name, config_bytes),
                ):
                    info = tarfile.TarInfo(name)
                    info.size = len(content)
                    archive.addfile(info, io.BytesIO(content))

            platform_artifact.verify_docker_archive(
                archive_path,
                f"sha256:{digest}",
                labels,
            )

    def test_docker_archive_normalization_removes_header_variation(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            normalized = []
            for sequence, timestamp in (
                (("config", "manifest.json"), 100),
                (("manifest.json", "config"), 200),
            ):
                raw = root / f"raw-{timestamp}.tar"
                destination = root / f"normalized-{timestamp}.tar.gz"
                with tarfile.open(raw, mode="w") as archive:
                    for name in sequence:
                        content = name.encode()
                        info = tarfile.TarInfo(name)
                        info.mtime = timestamp
                        info.size = len(content)
                        archive.addfile(info, io.BytesIO(content))
                platform_artifact.normalize_docker_archive(
                    raw,
                    destination,
                    123456789,
                )
                normalized.append(destination.read_bytes())
            self.assertEqual(normalized[0], normalized[1])

    def test_trivy_report_normalization_removes_scan_time(self):
        with tempfile.TemporaryDirectory() as temporary:
            report = Path(temporary) / "scan.json"
            report.write_text(
                json.dumps({"SchemaVersion": 2, "CreatedAt": "variable", "Results": []}),
                encoding="utf-8",
            )
            platform_artifact.normalize_trivy_report(report)
            self.assertEqual(
                json.loads(report.read_text(encoding="utf-8")),
                {"SchemaVersion": 2, "Results": []},
            )

    def test_cyclonedx_normalization_removes_random_metadata(self):
        with tempfile.TemporaryDirectory() as temporary:
            sbom = Path(temporary) / "sbom.json"
            sbom.write_text(
                json.dumps(
                    {
                        "bomFormat": "CycloneDX",
                        "serialNumber": "urn:uuid:random",
                        "metadata": {"timestamp": "variable", "component": {"name": "api"}},
                    }
                ),
                encoding="utf-8",
            )
            platform_artifact.normalize_cyclonedx(sbom)
            self.assertEqual(
                json.loads(sbom.read_text(encoding="utf-8")),
                {
                    "bomFormat": "CycloneDX",
                    "metadata": {"component": {"name": "api"}},
                },
            )

    def test_cyclonedx_normalization_stabilizes_component_references(self):
        normalized = []
        for reference in (
            "10937fff-ece8-4500-8e6f-ccb879d7ba42",
            "cd8fbce6-93ef-4ab5-9731-09cdb0c233e1",
        ):
            with tempfile.TemporaryDirectory() as temporary:
                sbom = Path(temporary) / "sbom.json"
                sbom.write_text(
                    json.dumps(
                        {
                            "bomFormat": "CycloneDX",
                            "components": [{"bom-ref": reference, "name": "alpine"}],
                            "dependencies": [{"ref": reference, "dependsOn": []}],
                        }
                    ),
                    encoding="utf-8",
                )
                platform_artifact.normalize_cyclonedx(sbom)
                normalized.append(sbom.read_bytes())
        self.assertEqual(normalized[0], normalized[1])


if __name__ == "__main__":
    unittest.main()
