import copy
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

from scripts.ci import lane_policy
from scripts.release.readiness import change, records
from scripts.release.readiness.adapters import verify_change_authority


class ChangeEvidenceTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.repository = Path(temporary.name)
        self.git("init", "-q")
        self.git("config", "user.email", "fixture@example.invalid")
        self.git("config", "user.name", "Synthetic fixture")
        for path in sorted(change.REQUIRED_DEPENDENCIES):
            self.write(path, "{}\n" if path.endswith(".json") else "fixture lock bytes\n")
        self.write("apps/api/app/Example.php", "<?php\n")
        self.base = self.commit()

    def git(self, *arguments):
        return subprocess.check_output(["git", "-C", str(self.repository), *arguments], stderr=subprocess.DEVNULL).decode().strip()

    def write(self, path, content):
        target = self.repository / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content)

    def commit(self):
        self.git("add", ".")
        self.git("commit", "-qm", "synthetic test change")
        return self.git("rev-parse", "HEAD")

    def classify(self):
        return change.validate_change(change.classify(self.repository, self.base, self.commit()))

    def authority(self, **overrides):
        payload = {
            "schema_version": "1.0", "repository": "ideal-sol/oripa", "source_sha": self.base,
            "change_id": "FIXTURE-001", "pr_number": 1,
            "authority": "Human-approved exact Runtime Source: synthetic only", "activation_authorized": False,
            **overrides,
        }
        self.write("manifests/platform-production-approved-source.json", json.dumps(payload))

    def test_authority_only_all_nonruntime_deltas_proven(self):
        self.authority()
        self.write("tests/ops/test_source.py", "synthetic test\n")
        self.write("worklogs/new_ver_main.md", "synthetic record\n")
        result = self.classify()
        self.assertEqual(result["change_classes"], ["AUTHORITY_ONLY"])
        self.assertEqual(result["validation_path"], "AUTHORITY_FOCUSED")
        self.assertEqual(result["deltas"]["dependency_delta"], "NONE")
        self.assertEqual(result["deltas"]["test_delta"], "PRESENT")
        self.assertEqual(result["authority_records"][0]["source_sha"], self.base)

    def test_all_dependency_inputs_are_byte_sensitive(self):
        for path in sorted(change.REQUIRED_DEPENDENCIES):
            with self.subTest(path=path):
                self.write(path, "changed bytes\n")
                result = self.classify()
                self.assertEqual(result["deltas"]["dependency_delta"], "PRESENT")
                self.assertNotEqual(result["base_components"]["dependency"], result["head_components"]["dependency"])

    def test_new_manifest_override_patch_and_workspace_importers(self):
        for path in ("apps/new/package.json", "pnpm-workspace.yaml", "patches/dependency.patch", ".npmrc"):
            with self.subTest(path=path):
                self.write(path, '{"pnpm":{"overrides":{"fixture":"2"}}}\n')
                self.assertEqual(self.classify()["deltas"]["dependency_delta"], "PRESENT")

    def test_deleted_lock_is_unknown_not_none(self):
        (self.repository / "pnpm-lock.yaml").unlink()
        with self.assertRaisesRegex(records.RecordError, "DEPENDENCY_INPUT_MISSING"):
            self.classify()

    def test_rename_cannot_hide_dependency_change(self):
        self.git("mv", "apps/api/composer.json", "apps/api/renamed.json")
        with self.assertRaisesRegex(records.RecordError, "DEPENDENCY_INPUT_MISSING"):
            self.classify()

    def test_mode_symlink_and_submodule_fail_closed(self):
        (self.repository / "pnpm-lock.yaml").chmod(0o755)
        with self.assertRaisesRegex(records.RecordError, "DEPENDENCY_INPUT_MODE_INVALID"):
            self.classify()

    def test_unknown_changed_path_never_becomes_authority_only(self):
        self.authority()
        self.write("unknown-hook", "unknown\n")
        result = change.classify(self.repository, self.base, self.commit())
        self.assertNotIn("AUTHORITY_ONLY", result["change_classes"])
        self.assertEqual(set(result["deltas"].values()), {"UNKNOWN"})
        with self.assertRaisesRegex(records.RecordError, "CLASSIFICATION_UNKNOWN"):
            change.validate_change(result)

    def test_gate_change_cannot_self_exempt(self):
        self.authority()
        self.write("scripts/ci/security_gate.py", "synthetic gate change\n")
        result = self.classify()
        self.assertTrue({"CI_GOVERNANCE", "SECURITY_POLICY"} <= set(result["change_classes"]))
        self.assertEqual(result["validation_path"], "NORMAL_STRICT_CI")

    def test_application_contract_migration_runtime_and_write_path(self):
        paths = {
            "apps/api/app/Example.php": {"application", "api_write_path"},
            "apps/api/database/migrations-v2/example.php": {"migration", "application"},
            "apps/api/config/auth.php": {"runtime_config", "security_policy"},
            "openapi/public.openapi.yaml": {"contract"},
        }
        for path, expected in paths.items():
            self.assertEqual(change.path_components(path), expected)

    def test_sensitive_lane_boundaries_preserved(self):
        for name in ("Auth", "Payment", "Point", "Draw", "Inventory"):
            self.assertEqual(lane_policy.required_lane([f"apps/api/app/Modules/{name}/Service.php"]), "Strict Change")
        self.assertEqual(lane_policy.required_lane(["apps/api/database/migrations-v2/fixture.php"]), "Strict Change")

    def test_authority_unknown_fields_activation_and_unmerged_source_rejected(self):
        for mutation in ({"unexpected": "value"}, {"activation_authorized": True}, {"source_sha": "f" * 40}):
            with self.subTest(mutation=mutation), self.assertRaises(records.RecordError):
                self.authority(**mutation)
                self.classify()

    def test_duplicate_authority_fields_are_not_accepted(self):
        self.authority()
        path = "manifests/platform-production-approved-source.json"
        self.write(path, (self.repository / path).read_text().replace('{', '{"activation_authorized": true,', 1))
        with self.assertRaisesRegex(records.RecordError, "AUTHORITY_DUPLICATE_FIELD"):
            self.classify()

    def test_tampered_fingerprint_or_delta_fails_even_when_resealed(self):
        self.write("docs/example.md", "fixture\n")
        valid = self.classify()
        for field, value in (("dependency_delta", "UNKNOWN"), ("dependency_delta", "PRESENT")):
            invalid = copy.deepcopy(valid)
            invalid["deltas"][field] = value
            with self.assertRaises(records.RecordError):
                change.validate_change(records.seal(invalid, "record_digest"))
        invalid = copy.deepcopy(valid)
        invalid["dependency_inputs"]["head"]["package.json"]["sha256"] = "malformed"
        with self.assertRaises(records.RecordError):
            change.validate_change(records.seal(invalid, "record_digest"))

    def test_resync_component_identity_is_proven_but_reuse_is_not_assumed(self):
        self.write("apps/api/app/Example.php", "<?php echo 'changed';\n")
        previous = self.classify()
        self.write("docs/example.md", "base sync fixture\n")
        current = self.classify()
        result = change.compare_evidence(previous, current)
        self.assertTrue(result["same_components"]["application"])
        self.assertTrue(result["same_components"]["dependency"])
        self.assertEqual(result["invalidated"], ["docs"])
        self.assertEqual(result["reuse"], "NOT_PROVEN")
        self.write("apps/api/app/Example.php", "<?php echo 'another change';\n")
        self.assertIn("application", change.compare_evidence(current, self.classify())["invalidated"])

    def test_authority_event_invalidates_previous_authority_evidence(self):
        self.authority()
        previous = self.classify()
        self.authority(authority="Human-approved exact Runtime Source: updated synthetic record")
        result = change.compare_evidence(previous, self.classify())
        self.assertIn("authority", result["invalidated"])
        self.assertEqual(result["authority_continuity"], "REFRESH_REQUIRED")

    def test_resync_proves_pr_bytes_while_invalidating_changed_component(self):
        self.write("apps/api/app/Example.php", "<?php echo 'feature';\n")
        previous = self.classify()
        self.git("checkout", "--detach", self.base)
        self.write("apps/api/app/Upstream.php", "<?php echo 'upstream';\n")
        self.base = self.commit()
        self.git("merge", "--no-edit", previous["head_sha"])
        current = change.validate_change(change.classify(self.repository, self.base, self.git("rev-parse", "HEAD")))
        compared = change.compare_evidence(previous, current)
        self.assertTrue(compared["same_delta_components"]["application"])
        self.assertFalse(compared["same_components"]["application"])
        self.assertIn("application", compared["invalidated"])
        self.assertEqual(compared["reuse"], "NOT_PROVEN")

    def test_authority_api_mismatch_fails_closed(self):
        self.authority()
        classification = self.classify()
        def get(path):
            if path.endswith("/branches/main"):
                return {"protected": True, "commit": {"sha": self.base}}
            return {"merged": False}
        with self.assertRaisesRegex(records.RecordError, "AUTHORITY_PR_MISMATCH"):
            verify_change_authority(classification, get)

    def test_authority_provenance_and_continuity_are_refreshed(self):
        self.authority()
        classification = self.classify()
        source_tree = classification["authority_records"][0]["source_tree"]
        reviewed_sha = "d" * 40
        main = {"protected": True, "commit": {"sha": self.base}}
        pull = {"merged": True, "merge_commit_sha": self.base, "base": {"ref": "main"},
                "head": {"sha": reviewed_sha, "repo": {"full_name": "ideal-sol/oripa"}}}
        commit = {"sha": reviewed_sha, "tree": {"sha": source_tree}}
        def get(path):
            if path.endswith("/branches/main"):
                return main
            if "/pulls/" in path:
                return pull
            if "/git/commits/" in path:
                return commit
            return {"total_count": 5, "check_runs": [
                {"id": index, "name": name, "head_sha": reviewed_sha, "status": "completed",
                 "conclusion": "success", "started_at": "2026-01-01T00:00:00Z",
                 "app": {"id": 15368, "slug": "github-actions", "owner": {"login": "github"}}}
                for index, name in enumerate(("policy-gate", "quality-gate", "security-gate", "integration-gate", "ci-gate"), 1)
            ]}
        self.assertEqual(verify_change_authority(classification, get)["continuity"], "CONFIRMED_AT_VALIDATION")
        commit["tree"]["sha"] = "f" * 40
        with self.assertRaisesRegex(records.RecordError, "AUTHORITY_PROVENANCE_MISMATCH"):
            verify_change_authority(classification, get)
        main["commit"]["sha"] = "f" * 40
        with self.assertRaisesRegex(records.RecordError, "AUTHORITY_BASE_MOVED"):
            verify_change_authority(classification, get)

    def test_secret_candidate_blocks_independently_of_dependency_identity(self):
        self.write("docs/example.md", "credential: " + "ghp_" + "x" * 30 + "\n")
        head = self.commit()
        with self.assertRaises(lane_policy.LanePolicyFailure):
            lane_policy.scan_added_diff(self.repository, ["docs/example.md"], self.base, head)

    def test_workflow_preserves_contexts_and_full_validation_for_gate_changes(self):
        workflow = (Path(__file__).resolve().parents[3] / ".github/workflows/platform-ci.yml").read_text()
        for context in ("policy-gate", "quality-gate", "security-gate", "integration-gate", "ci-gate"):
            self.assertIn("    name: " + context + "\n", workflow)
        self.assertNotIn("continue-on-error", workflow)
        self.assertIn('"$VALIDATION_PATH" != "AUTHORITY_FOCUSED"', workflow)
        self.assertIn('--change-evidence "$RUNNER_TEMP/canonical-change.json"', workflow)
