import base64
import copy
import json
import hashlib
import shutil
import subprocess
from pathlib import Path
import tempfile
import unittest
from unittest import mock
import zipfile

from tests.ops.test_preview_image_pipeline import (
    ROOT, TASK, PR, HEAD, artifact, wrapper, create_artifact,
)


PAYLOAD = "62c3c813081cf0ea526c66e7e4131c6816de2449"
CONTROL = "c" * 40
REVIEWED = "d" * 40
TREE = "e" * 40
TASK_ID = "OPS-20260929"


class OldTestSourceAuthorityTest(unittest.TestCase):
    def setUp(self):
        self.approval = json.loads((ROOT / wrapper.OLD_AUTHORITY_PATH).read_text())
        self.main = {"protected": True, "commit": {"sha": CONTROL}}
        self.pull = {
            "state": "closed", "merged": True, "merge_commit_sha": PAYLOAD,
            "base": {"ref": "main", "repo": {"full_name": wrapper.REPOSITORY}},
            "head": {"sha": REVIEWED, "repo": {"full_name": wrapper.REPOSITORY}},
        }
        self.comparison = {
            "status": "ahead", "merge_base_commit": {"sha": PAYLOAD},
            "base_commit": {"sha": PAYLOAD},
        }
        self.check_conclusion = "success"
        self.check_app = "github-actions"

    def get(self, path):
        if path.endswith("/branches/main"):
            return copy.deepcopy(self.main)
        if "/contents/" in path:
            return {"encoding": "base64", "content": base64.b64encode(json.dumps(self.approval).encode()).decode()}
        if "/compare/" in path:
            return self.comparison
        if path.endswith("/pulls/515"):
            return self.pull
        if "/git/commits/" in path:
            return {"sha": path.rsplit("/", 1)[1], "tree": {"sha": TREE}}
        if "/check-runs?" in path:
            sha = path.split("/commits/")[1].split("/")[0]
            return {"total_count": 5, "check_runs": [
                {"id": index, "name": name, "head_sha": sha,
                 "status": "completed", "conclusion": self.check_conclusion,
                 "started_at": "2026-09-29T00:00:00Z",
                 "app": {"id": 15368, "slug": self.check_app, "owner": {"login": "github"}}}
                for index, name in enumerate(sorted(wrapper.REQUIRED_CHECKS), start=1)
            ]}
        raise AssertionError(path)

    def authorize(self, source=PAYLOAD, control=CONTROL):
        return wrapper.authorize_old_test_source(TASK_ID, "515", source, control, get=self.get)

    def test_approved_payload_is_allowed_as_protected_main_ancestor(self):
        result = self.authorize()
        self.assertEqual(result["source_sha"], PAYLOAD)
        self.assertEqual(result["control_sha"], CONTROL)
        self.assertNotEqual(result["source_sha"], result["control_sha"])
        self.assertEqual(result["source_tree"], TREE)

    def test_approved_current_main_is_allowed(self):
        self.main["commit"]["sha"] = PAYLOAD
        self.comparison["status"] = "identical"
        self.assertEqual(self.authorize(control=PAYLOAD)["source_sha"], PAYLOAD)

    def test_malformed_unknown_and_unapproved_sha_rejected_before_build(self):
        for source in ("main", "", "abc123", "f" * 40, "../main"):
            with self.subTest(source=source), self.assertRaises(wrapper.WrapperError):
                self.authorize(source=source)

    def test_pr_only_commit_and_diverged_history_rejected_even_if_manifest_names_it(self):
        for status in ("behind", "diverged"):
            self.comparison["status"] = status
            with self.subTest(status=status), self.assertRaisesRegex(wrapper.WrapperError, "not_main_ancestor"):
                self.authorize()
        self.comparison["status"] = "ahead"
        self.comparison["merge_base_commit"]["sha"] = REVIEWED
        with self.assertRaisesRegex(wrapper.WrapperError, "not_main_ancestor"):
            self.authorize()

    def test_unprotected_main_and_wrong_control_rejected(self):
        self.main["protected"] = False
        with self.assertRaises(wrapper.WrapperError):
            self.authorize()
        self.main["protected"] = True
        with self.assertRaises(wrapper.WrapperError):
            self.authorize(control="a" * 40)

    def test_target_architecture_identity_and_activation_scope_fail_closed(self):
        for field, value in (("target", "production"), ("target", "new-server"),
                             ("platform", "linux/arm64"), ("image_mode", "agency"),
                             ("task_id", "OTHER-123"), ("source_pr", 514),
                             ("activation_authorized", True), ("authority", "arbitrary")):
            original = self.approval[field]
            self.approval[field] = value
            with self.subTest(field=field, value=value), self.assertRaisesRegex(wrapper.WrapperError, "approval_mismatch"):
                self.authorize()
            self.approval[field] = original

    def test_merged_internal_source_pr_is_required(self):
        for field, value in (("merged", False), ("state", "open"), ("merge_commit_sha", REVIEWED)):
            original = self.pull[field]
            self.pull[field] = value
            with self.subTest(field=field), self.assertRaisesRegex(wrapper.WrapperError, "source_pr_mismatch"):
                self.authorize()
            self.pull[field] = original
        self.pull["head"]["repo"]["full_name"] = "external/fork"
        with self.assertRaises(wrapper.WrapperError):
            self.authorize()

    def test_tree_mismatch_and_main_race_are_rejected(self):
        original = self.get
        def changed(path):
            result = original(path)
            if path.endswith("/git/commits/" + REVIEWED):
                result["tree"]["sha"] = "f" * 40
            return result
        with self.assertRaisesRegex(wrapper.WrapperError, "tree_mismatch"):
            wrapper.authorize_old_test_source(TASK_ID, "515", PAYLOAD, CONTROL, get=changed)
        calls = []
        def moved(path):
            result = original(path)
            if path.endswith("/branches/main"):
                calls.append(path)
                if len(calls) > 1:
                    result["commit"]["sha"] = "f" * 40
            return result
        with self.assertRaisesRegex(wrapper.WrapperError, "control_moved"):
            wrapper.authorize_old_test_source(TASK_ID, "515", PAYLOAD, CONTROL, get=moved)

    def test_failed_or_untrusted_required_checks_are_rejected(self):
        self.check_conclusion = "failure"
        with self.assertRaisesRegex(wrapper.WrapperError, "required_checks"):
            self.authorize()
        self.check_conclusion = "success"
        self.check_app = "untrusted"
        with self.assertRaisesRegex(wrapper.WrapperError, "required_checks"):
            self.authorize()

    def test_dispatch_uses_only_validated_main_and_existing_authenticated_transport(self):
        transport = mock.Mock(return_value=(204, None))
        with mock.patch.object(wrapper, "secure_policy", return_value={"lane": "Strict Change", "activation": "deferred"}), \
             mock.patch.object(wrapper, "api_get", side_effect=self.get), \
             mock.patch.object(wrapper.runpy, "run_path", return_value={"request": transport}):
            result = wrapper.dispatch_old_test_artifact(TASK_ID, "515", PAYLOAD)
        self.assertEqual(result["status"], "dispatched")
        transport.assert_called_once_with("POST", "/repos/ideal-sol/oripa/actions/workflows/old-test-main-artifact.yml/dispatches",
                                          {"ref": "main", "inputs": {"task_id": TASK_ID, "pr_number": "515", "source_sha": PAYLOAD}})
        self.assertNotIn("token", json.dumps(result).lower())

    def test_dispatch_never_writes_when_approval_or_policy_fails(self):
        with mock.patch.object(wrapper, "secure_policy", return_value={"lane": "Lite Maintenance", "activation": "none"}), \
             mock.patch.object(wrapper.runpy, "run_path") as transport:
            with self.assertRaises(wrapper.WrapperError):
                wrapper.dispatch_old_test_artifact(TASK_ID, "515", PAYLOAD)
            transport.assert_not_called()


class OldTestArtifactBoundaryTest(unittest.TestCase):
    def test_download_binds_outer_digest_provenance_control_and_inner_verifier(self):
        authority = {"task_id": TASK_ID, "source_sha": PAYLOAD, "source_pr": 515,
                     "control_sha": CONTROL, "target": "old-test", "platform": "linux/amd64"}
        run = {"event": "workflow_dispatch", "status": "completed", "conclusion": "success",
               "path": wrapper.OLD_WORKFLOW_PATH, "head_branch": "main", "head_sha": CONTROL,
               "run_attempt": 1}
        for mutation in ("none", "source", "control", "attempt", "outer"):
            with self.subTest(mutation=mutation), tempfile.TemporaryDirectory() as temporary:
                directory = Path(temporary)
                archive = directory / "test.zip"
                provenance = {**authority, "run_id": 123, "run_attempt": 1}
                if mutation in {"source", "control"}:
                    provenance[mutation + "_sha"] = "f" * 40
                if mutation == "attempt":
                    provenance["run_attempt"] = 2
                with zipfile.ZipFile(archive, "w") as bundle:
                    for name in ("manifest.json", "SHA256SUMS", "oripa-v2-api-linux-amd64.docker.tar.zst"):
                        bundle.writestr(name, "fixture")
                    bundle.writestr("source-authority.json", json.dumps(provenance))
                digest = hashlib.sha256(archive.read_bytes()).hexdigest()
                metadata = {"name": wrapper.artifact_name(TASK_ID, PAYLOAD, True), "expired": False,
                            "workflow_run": {"id": 123}, "digest": "sha256:" + digest}
                def get(path):
                    if path.endswith("/branches/main"):
                        return {"commit": {"sha": CONTROL}}
                    if path.endswith("/artifacts/456"):
                        return metadata
                    if path.endswith("/runs/123"):
                        return run
                    raise AssertionError(path)
                def download(path, target):
                    shutil.copyfile(archive, target)
                    return "0" * 64 if mutation == "outer" else digest
                destination = directory / "result"
                with mock.patch.object(wrapper, "old_test_policy"), \
                     mock.patch.object(wrapper, "authorize_old_test_source", return_value=authority), \
                     mock.patch.object(wrapper, "api_get", side_effect=get), \
                     mock.patch.object(wrapper, "validate_destination", return_value=destination), \
                     mock.patch.object(wrapper, "download_to", side_effect=download), \
                     mock.patch.object(wrapper.subprocess, "run", return_value=subprocess.CompletedProcess([], 0, b"control helper")) as runner:
                    if mutation == "none":
                        result = wrapper.download_preview_artifact(TASK_ID, "515", PAYLOAD, "456", str(destination), old_test=True)
                        self.assertEqual(result["status"], "verified")
                        self.assertEqual(result["control_sha"], CONTROL)
                        self.assertIn(f"{CONTROL}:scripts/ops/preview_image_artifact.py", runner.call_args_list[0].args[0])
                        self.assertEqual(runner.call_args_list[1].args[0][-4:], ["--artifact-kind", "old-test", "--architecture", "amd64"])
                    else:
                        with self.assertRaises(wrapper.WrapperError):
                            wrapper.download_preview_artifact(TASK_ID, "515", PAYLOAD, "456", str(destination), old_test=True)
                        runner.assert_not_called()
                        self.assertFalse(destination.exists())

    def test_old_test_api_archive_verifies_without_accepting_preview_or_production(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            create_artifact(directory, ("api",), artifact_kind="old-test")
            result = artifact.verify_artifact(directory, task_id=TASK, pr_number=PR,
                                              source_sha=HEAD, artifact_kind="old-test")
            self.assertEqual(result["artifact_kind"], "old-test")
            for kind in ("preview", "production-candidate"):
                with self.subTest(kind=kind), self.assertRaises(artifact.ArtifactError):
                    artifact.verify_artifact(directory, task_id=TASK, pr_number=PR,
                                             source_sha=HEAD, artifact_kind=kind)

    def test_old_test_rejects_arm64_and_non_api_inventory(self):
        with self.assertRaisesRegex(artifact.ArtifactError, "architecture_invalid"):
            artifact.validate_target("old-test", "arm64")
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            create_artifact(directory, artifact_kind="old-test")
            with self.assertRaisesRegex(artifact.ArtifactError, "api_only"):
                artifact.verify_artifact(directory, task_id=TASK, pr_number=PR,
                                         source_sha=HEAD, artifact_kind="old-test")

    def test_preview_run_cannot_supply_old_test_artifact_and_vice_versa(self):
        run = {"event": "workflow_dispatch", "status": "completed", "conclusion": "success",
               "path": ".github/workflows/preview-image-build.yml", "head_branch": "main"}
        with mock.patch.object(wrapper, "api_get", return_value=run):
            wrapper.validated_run(123)
            with self.assertRaises(wrapper.WrapperError):
                wrapper.validated_run(123, True)
            run["path"] = wrapper.OLD_WORKFLOW_PATH
            wrapper.validated_run(123, True)
            with self.assertRaises(wrapper.WrapperError):
                wrapper.validated_run(123)
            run["head_branch"] = "task-branch"
            with self.assertRaises(wrapper.WrapperError):
                wrapper.validated_run(123, True)

    def test_old_test_zip_requires_provenance_and_forbids_extra_members(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            archive = directory / "test.zip"
            names = {"manifest.json", "SHA256SUMS", "oripa-v2-api-linux-amd64.docker.tar.zst"}
            with zipfile.ZipFile(archive, "w") as bundle:
                for name in names:
                    bundle.writestr(name, "fixture")
            with self.assertRaises(wrapper.WrapperError):
                wrapper.safe_extract(archive, directory, True)
            with zipfile.ZipFile(archive, "a") as bundle:
                bundle.writestr("source-authority.json", "{}")
            wrapper.safe_extract(archive, directory, True)
            with self.assertRaises(wrapper.WrapperError):
                wrapper.safe_extract(archive, directory)
            with zipfile.ZipFile(archive, "a") as bundle:
                bundle.writestr("../escape", "fixture")
            with self.assertRaises(wrapper.WrapperError):
                wrapper.safe_extract(archive, directory, True)

    def test_workflow_separates_control_and_payload_and_has_no_runtime_authority(self):
        workflow = (ROOT / wrapper.OLD_WORKFLOW_PATH).read_text()
        for required in ("runs-on: ubuntu-24.04", "path: control", "path: payload",
                         "ref: ${{ github.sha }}", "ref: ${{ inputs.source_sha }}",
                         "control/scripts/ops/old_test_source_authority.py",
                         "--platform linux/amd64 --target preview", "--artifact-kind old-test",
                         'OCI_REVISION=${INPUT_SOURCE_SHA}', "persist-credentials: false",
                         "source-authority.json"):
            self.assertIn(required, workflow)
        for forbidden in ("continue-on-error", "secrets.", "docker run", "docker compose",
                          "--platform linux/arm64", "environment: production", "actions: write"):
            self.assertNotIn(forbidden, workflow)
        preview = (ROOT / ".github/workflows/preview-image-build.yml").read_text()
        self.assertIn('if head.get("sha") != head_sha:', preview)
        self.assertIn('raise SystemExit("pull request head mismatch")', preview)
        self.assertIn("tests.ops.test_old_test_source_authority", (ROOT / ".github/workflows/platform-ci.yml").read_text())


if __name__ == "__main__":
    unittest.main()
