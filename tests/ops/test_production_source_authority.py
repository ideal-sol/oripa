import json
from pathlib import Path
import runpy
import subprocess
import tempfile
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[2]
MODULE = runpy.run_path(str(ROOT / "scripts/ops/production_source_authority.py"))
AUTHORIZE = MODULE["authorize"]
APPROVED = "e3121034c5dc7b9184673b1be64bb5076e9d3a90"
PROTECTED = subprocess.check_output(
    ["git", "-C", str(ROOT), "rev-parse", "HEAD"], text=True,
).strip()
SOURCE_CHANGE = "CATALOG-20260924"
SOURCE_PR = 503


class ProductionSourceAuthorityTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.repository = Path(self.directory.name)
        self.git("init", "-q")
        self.git("config", "user.email", "test@example.invalid")
        self.git("config", "user.name", "Source authority test")
        self.git("commit", "--allow-empty", "-qm", "source")
        self.source = self.git("rev-parse", "HEAD")
        self.tree = self.git("rev-parse", "HEAD^{tree}")
        self.authority = json.loads((ROOT / MODULE["AUTHORITY_PATH"]).read_text())
        self.authority["source_sha"] = self.source
        authority_path = self.repository / MODULE["AUTHORITY_PATH"]
        authority_path.parent.mkdir()
        authority_path.write_text(json.dumps(self.authority))
        self.git("add", ".")
        self.git("commit", "-qm", "protected approval")
        self.workflow = self.git("rev-parse", "HEAD")
        self.reviewed = "a" * 40
        self.pull = {
            "merged": True, "merge_commit_sha": self.source, "title": SOURCE_CHANGE,
            "base": {"ref": "main"},
            "head": {"sha": self.reviewed, "repo": {"full_name": MODULE["REPOSITORY"]}},
        }
        self.check_override = None
        self.protected = True

    def git(self, *arguments):
        return subprocess.check_output(
            ["git", "-C", str(self.repository), *arguments], text=True,
            stderr=subprocess.DEVNULL,
        ).strip()

    def get(self, path):
        if path.endswith("/branches/main"):
            return {"protected": self.protected, "commit": {"sha": self.workflow}}
        if path.endswith(f"/pulls/{SOURCE_PR}"):
            return self.pull
        if path.endswith("/git/commits/" + self.reviewed):
            return {"tree": {"sha": self.tree}}
        if "/check-runs?" in path:
            sha = path.split("/commits/")[1].split("/")[0]
            checks = [{
                "name": name, "id": index + 1, "head_sha": sha,
                "status": "completed", "conclusion": "success",
                "started_at": "2026-09-24T00:00:00Z",
                "app": {"id": 15368, "slug": "github-actions", "owner": {"login": "github"}},
            } for index, name in enumerate(sorted(MODULE["REQUIRED_CHECKS"]))]
            if self.check_override:
                self.check_override(checks, sha)
            return {"total_count": len(checks), "check_runs": checks}
        raise AssertionError(path)

    def authorize(self, source=None):
        return AUTHORIZE(self.repository, source or self.source, self.workflow,
                         SOURCE_CHANGE, SOURCE_PR, self.get)

    def test_exact_approved_ancestor_preserves_runtime_source(self):
        result = self.authorize()
        self.assertEqual(result["source_sha"], self.source)
        self.assertEqual(result["workflow_sha"], self.workflow)
        self.assertNotEqual(result["source_sha"], result["workflow_sha"])

    def test_current_main_is_not_automatically_runtime_authority(self):
        with self.assertRaisesRegex(ValueError, "Human-approved source authority mismatch"):
            self.authorize(self.workflow)

    def test_nonexistent_commit_fails(self):
        with self.assertRaisesRegex(ValueError, "source commit missing"):
            self.authorize("0" * 40)

    def test_nonancestor_commit_fails_even_when_approved(self):
        unrelated = self.git("commit-tree", self.tree, "-m", "unrelated")
        self.source = unrelated
        with self.assertRaisesRegex(ValueError, "not protected-main ancestor"):
            self.authorize()

    def test_malformed_sha_fails(self):
        for sha in ["main", "e16f655", "A" * 40, "$(false)", "a" * 41]:
            with self.subTest(sha=sha), self.assertRaisesRegex(ValueError, "invalid source SHA"):
                self.authorize(sha)

    def test_arbitrary_ancestor_not_in_authority_fails(self):
        self.authority["source_sha"] = "b" * 40
        path = self.repository / MODULE["AUTHORITY_PATH"]
        path.write_text(json.dumps(self.authority))
        self.git("add", ".")
        self.git("commit", "-qm", "different approval")
        self.workflow = self.git("rev-parse", "HEAD")
        with self.assertRaisesRegex(ValueError, "Human-approved source authority mismatch"):
            self.authorize()

    def test_uncommitted_authority_cannot_authorize_source(self):
        (self.repository / MODULE["AUTHORITY_PATH"]).write_text("{}")
        self.assertEqual(self.authorize()["source_sha"], self.source)

    def test_previous_approval_and_metadata_pull_cannot_authorize_source(self):
        for change_id, pr_number in [("PRIZEIMAGE-20260924", 500), ("PRODAUTH-20260924", 502)]:
            with self.subTest(pr_number=pr_number), self.assertRaisesRegex(
                ValueError, "Human-approved source authority mismatch"
            ):
                AUTHORIZE(self.repository, self.source, self.workflow, change_id, pr_number, self.get)

    def test_unprotected_main_fails(self):
        self.protected = False
        with self.assertRaisesRegex(ValueError, "protected main required"):
            self.authorize()

    def test_reviewed_tree_mismatch_fails(self):
        self.tree = "b" * 40
        with self.assertRaisesRegex(ValueError, "reviewed source tree mismatch"):
            self.authorize()

    def test_wrong_source_pull_fails(self):
        self.pull["merge_commit_sha"] = self.workflow
        with self.assertRaisesRegex(ValueError, "merged pull request authority mismatch"):
            self.authorize()

    def test_current_and_reviewed_checks_fail_closed(self):
        for target in [self.workflow, self.reviewed]:
            for conclusion in ["failure", "skipped", "cancelled", None]:
                with self.subTest(target=target, conclusion=conclusion):
                    def override(checks, sha):
                        if sha == target:
                            checks[0]["conclusion"] = conclusion
                    self.check_override = override
                    with self.assertRaisesRegex(ValueError, "required checks not successful"):
                        self.authorize()

    def test_forged_check_source_fails(self):
        self.check_override = lambda checks, sha: checks[0].update(app={"slug": "untrusted"})
        with self.assertRaisesRegex(ValueError, "required checks not successful"):
            self.authorize()

    def test_main_movement_fails(self):
        calls = []
        def get(path):
            result = self.get(path)
            if path.endswith("/branches/main"):
                calls.append(path)
                if len(calls) > 1:
                    result["commit"]["sha"] = "b" * 40
            return result
        with self.assertRaisesRegex(ValueError, "protected main moved"):
            AUTHORIZE(self.repository, self.source, self.workflow, SOURCE_CHANGE, SOURCE_PR, get)

    def test_human_approved_real_source_and_base_lineage(self):
        subprocess.run(["git", "-C", str(ROOT), "merge-base", "--is-ancestor", APPROVED, PROTECTED], check=True)
        authority = json.loads((ROOT / MODULE["AUTHORITY_PATH"]).read_text())
        self.assertEqual(authority["source_sha"], APPROVED)
        self.assertEqual(authority["change_id"], SOURCE_CHANGE)
        self.assertEqual(authority["pr_number"], SOURCE_PR)
        self.assertIs(authority["activation_authorized"], False)
        real_git = MODULE["git"]
        self.source = APPROVED
        self.workflow = PROTECTED
        self.tree = real_git(ROOT, "rev-parse", f"{APPROVED}^{{tree}}")
        self.pull["merge_commit_sha"] = APPROVED
        def candidate_authority(repository, *arguments):
            if arguments == ("rev-parse", "HEAD"):
                return PROTECTED
            if arguments == ("show", f"{PROTECTED}:{MODULE['AUTHORITY_PATH']}"):
                return json.dumps(authority)
            return real_git(repository, *arguments)
        with patch.dict(AUTHORIZE.__globals__, git=candidate_authority):
            result = AUTHORIZE(ROOT, APPROVED, PROTECTED, SOURCE_CHANGE, SOURCE_PR, self.get)
            for unapproved in [
                "be1a8f3f822d23f3251d32e616fb0b2fe422714e",
                "69d58449c9524a018b372e39a6a4efbe0cace48d",
            ]:
                with self.subTest(unapproved=unapproved), self.assertRaisesRegex(
                    ValueError, "Human-approved source authority mismatch"
                ):
                    AUTHORIZE(ROOT, unapproved, PROTECTED, SOURCE_CHANGE, SOURCE_PR, self.get)
        self.assertEqual(result["source_sha"], APPROVED)
        self.assertEqual(result["workflow_sha"], PROTECTED)

    def test_workflow_exact_source_labels_and_manifest(self):
        workflow = (ROOT / ".github/workflows/platform-production-arm64-artifact.yml").read_text()
        self.assertIn("ref: ${{ steps.authority.outputs.source_sha }}", workflow)
        self.assertEqual(workflow.count('--build-arg "OCI_REVISION=${SOURCE_SHA}"'), 3)
        self.assertIn('--source-sha "$SOURCE_SHA"', workflow)
        self.assertIn("production-source-authority.json", workflow)
        self.assertIn("path: build-authority", workflow)
        self.assertNotIn("workflow_dispatch:", (ROOT / MODULE["AUTHORITY_PATH"]).read_text())


if __name__ == "__main__":
    unittest.main()
