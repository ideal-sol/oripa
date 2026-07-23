import importlib.util
import json
from pathlib import Path
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[3]
SCRIPT = ROOT / "scripts/ci/policy_gate.py"
FIXTURES = Path(__file__).parent / "fixtures"
SPEC = importlib.util.spec_from_file_location("policy_gate", SCRIPT)
policy_gate = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(policy_gate)


def fixture(name):
    return json.loads((FIXTURES / name).read_text(encoding="utf-8"))


class PolicyGateTest(unittest.TestCase):
    def test_positive_pull_request_fixture_passes(self):
        data = fixture("positive.json")
        policy_gate.validate_pr_body(
            data["pr_body"],
            data["title"],
            data["changed_paths"],
            data["base_sha"],
        )
        policy_gate.validate_workflow_text("fixture.yml", data["workflow"])
        policy_gate.validate_dangerous_paths(data["tracked_paths"])

    def test_missing_metadata_fixture_fails(self):
        data = fixture("negative_missing_metadata.json")
        with self.assertRaisesRegex(policy_gate.PolicyFailure, "Risk"):
            policy_gate.validate_pr_body(
                data["pr_body"],
                data["title"],
                data["changed_paths"],
                data["base_sha"],
            )

    def test_floating_action_fixture_fails(self):
        data = fixture("negative_floating_action.json")
        with self.assertRaisesRegex(policy_gate.PolicyFailure, "full SHA"):
            policy_gate.validate_workflow_text("fixture.yml", data["workflow"])

    def test_secret_path_fixture_fails(self):
        data = fixture("negative_secret_path.json")
        with self.assertRaisesRegex(policy_gate.PolicyFailure, "dangerous tracked"):
            policy_gate.validate_dangerous_paths(data["tracked_paths"])

    def test_codeql_job_can_upload_security_events(self):
        workflow = """\
permissions:
  contents: read
concurrency:
  group: codeql
jobs:
  analyze:
    timeout-minutes: 30
    permissions:
      contents: read
      security-events: write
    steps:
      - uses: github/codeql-action/analyze@e4fba868fa4b1b91e1fdab776edc8cfbe6e9fb81 # v4
"""
        policy_gate.validate_workflow_text(".github/workflows/codeql.yml", workflow)

    def test_non_codeql_security_events_write_fails(self):
        workflow = """\
permissions:
  contents: read
concurrency:
  group: unsafe
jobs:
  analyze:
    timeout-minutes: 30
    permissions:
      contents: read
      security-events: write
    steps:
      - uses: actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683 # v4.2.2
"""
        with self.assertRaisesRegex(policy_gate.PolicyFailure, "write workflow permission"):
            policy_gate.validate_workflow_text(".github/workflows/unsafe.yml", workflow)

    def make_workspace(self, root):
        paths = set(policy_gate.WORKSPACE_REQUIRED_FILES)
        paths.update({"package.json", "pnpm-workspace.yaml"})
        for relative in paths:
            path = root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            if relative.endswith("README.md"):
                path.write_text(
                    """# Fixture

## Responsibility

This fixture defines one clear repository responsibility.

## Ownership

Platform Codex follows the nearest AGENTS.md.

## Planned Components

Only a future V2 component belongs here.

## Allowed Scope

Documentation and approved V2 implementation are allowed.

## Forbidden Scope

V1 Code copying and Production use are forbidden.

## Status

This is a non-Production Skeleton and contains no application implementation.
""",
                    encoding="utf-8",
                )
            elif relative.endswith("AGENTS.md"):
                path.write_text("# Fixture AGENTS\n", encoding="utf-8")

        (root / "package.json").write_text(
            json.dumps(
                {
                    "name": "@oripa/platform-workspace",
                    "version": "2.0.0-alpha.1",
                    "private": True,
                    "packageManager": "pnpm@10.12.1",
                }
            ),
            encoding="utf-8",
        )
        (root / "pnpm-workspace.yaml").write_text(
            "packages:\n  - apps/admin\n  - packages/*\n",
            encoding="utf-8",
        )
        semantic_version = {
            "type": "string",
            "pattern": policy_gate.SEMANTIC_VERSION.pattern,
        }
        release_properties = {
            field: {"type": "string"}
            for field in policy_gate.RELEASE_MANIFEST_REQUIRED
        }
        release_properties["schema_version"] = {"const": "1.0"}
        release_properties["platform_version"] = {
            "$ref": "#/$defs/semantic_version"
        }
        release_properties["api_contract_version"] = {
            "$ref": "#/$defs/semantic_version"
        }
        release_properties["package_versions"] = {
            "type": "object",
            "additionalProperties": {"$ref": "#/$defs/semantic_version"},
        }
        deployment_properties = {
            field: {"type": "string"}
            for field in policy_gate.DEPLOYMENT_MANIFEST_REQUIRED
        }
        deployment_properties["schema_version"] = {"const": "1.0"}
        deployment_properties["platform_version"] = {
            "$ref": "#/$defs/semantic_version"
        }
        deployment_properties["package_versions"] = {
            "type": "object",
            "additionalProperties": {"$ref": "#/$defs/semantic_version"},
        }
        for relative, required, properties in (
            (
                "manifests/schemas/release-manifest.schema.json",
                policy_gate.RELEASE_MANIFEST_REQUIRED,
                release_properties,
            ),
            (
                "manifests/schemas/deployment-manifest.schema.json",
                policy_gate.DEPLOYMENT_MANIFEST_REQUIRED,
                deployment_properties,
            ),
        ):
            (root / relative).write_text(
                json.dumps(
                    {
                        "$schema": "https://json-schema.org/draft/2020-12/schema",
                        "type": "object",
                        "additionalProperties": False,
                        "required": sorted(required),
                        "properties": properties,
                        "$defs": {"semantic_version": semantic_version},
                    }
                ),
                encoding="utf-8",
            )
        (root / "manifests/examples/release-manifest.example.json").write_text(
            json.dumps(
                {
                    "schema_version": "1.0",
                    "platform_version": "2.0.0-alpha.1",
                    "package_versions": {"@oripa/platform": "2.0.0-alpha.1"},
                    "api_contract_version": "2.0.0-alpha.1",
                    "migration_revision": "fixture",
                    "source_commit": "0" * 40,
                    "image_digest": "sha256:" + "0" * 64,
                    "sbom_reference": "fixture",
                    "created_at": "1970-01-01T00:00:00Z",
                }
            ),
            encoding="utf-8",
        )
        (root / "manifests/examples/deployment-manifest.example.json").write_text(
            json.dumps(
                {
                    "schema_version": "1.0",
                    "site_id": "fixture-site",
                    "environment": "platform-staging",
                    "platform_version": "2.0.0-alpha.1",
                    "package_versions": {"@oripa/platform": "2.0.0-alpha.1"},
                    "image_digest": "sha256:" + "0" * 64,
                    "migration_revision": "fixture",
                    "deployed_at": "1970-01-01T00:00:00Z",
                    "approved_by": "fixture",
                    "source_release_manifest": "fixture",
                }
            ),
            encoding="utf-8",
        )
        return paths

    def test_workspace_skeleton_fixture_passes(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            policy_gate.validate_workspace_skeleton(root, paths)

    def test_deferred_root_workspace_configuration_passes(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            for relative in ("package.json", "pnpm-workspace.yaml"):
                (root / relative).unlink()
                paths.remove(relative)
            policy_gate.validate_workspace_skeleton(root, paths)

    def test_workspace_missing_readme_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            paths.remove("apps/admin/README.md")
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "required workspace files missing"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_v1_frontend_workspace_member_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            (root / "pnpm-workspace.yaml").write_text(
                "packages:\n  - apps/admin\n  - packages/*\n  - frontend\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "workspace members"):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_manifest_required_field_removal_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            schema_path = root / "manifests/schemas/release-manifest.schema.json"
            schema = json.loads(schema_path.read_text(encoding="utf-8"))
            schema["required"].remove("source_commit")
            schema_path.write_text(json.dumps(schema), encoding="utf-8")
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "required manifest fields"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_v1_content_copy_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            content = "const copiedV1Implementation = true;\n" * 4
            for relative in ("frontend/source.ts", "apps/admin/source.ts"):
                path = root / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(content, encoding="utf-8")
                paths.add(relative)
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "V1 content copied"):
                policy_gate.validate_workspace_skeleton(root, paths)


if __name__ == "__main__":
    unittest.main()
