import base64
import copy
import json
import hashlib
import io
import shutil
import subprocess
from pathlib import Path
import tempfile
import unittest
import runpy
from unittest import mock
import zipfile

from tests.ops.test_preview_image_pipeline import (
    ROOT, TASK, PR, HEAD, artifact, wrapper, create_artifact, create_docker_archive,
)


PAYLOAD = "c4eaca6cc2a851a7da937bf0b499bd3bfb6af5e7"
CONTROL = "c" * 40
REVIEWED = "d" * 40
TREE = "e" * 40
TASK_ID = "PRIZECSV-20261007"


class Alpha46ApprovalInventoryTest(unittest.TestCase):
    def test_exact_payload_keeps_applied_migrations_and_only_adds_097_098(self):
        approval = json.loads((ROOT / "manifests/platform-old-test-approved-source.json").read_text())
        self.assertEqual(approval["task_id"], "OLDTEST-ALPHA46-20261025")
        self.assertEqual(approval["source_pr"], 617)
        self.assertEqual(approval["source_sha"], "3592fcdc8e43467c3dd67d2677ec52b7d328eb77")
        self.assertEqual(approval["image_mode"], "normal")
        self.assertIs(approval["activation_authorized"], False)
        baseline = "8a656d1a4fb44e15155cbec3ab0ad7b927553587"
        target = approval["source_sha"]
        directory = "apps/api/database/migrations-v2"
        def inventory(ref):
            output = subprocess.check_output(["git", "ls-tree", "-r", ref, "--", directory], cwd=ROOT, text=True)
            return {line.split("\t", 1)[1]: line.split()[2] for line in output.splitlines() if line.endswith(".php")}
        before, after = inventory(baseline), inventory(target)
        self.assertEqual(len(before), 96)
        self.assertEqual({path: after[path] for path in before}, before)
        self.assertEqual(sorted(set(after) - set(before)), [
            directory + "/2026_10_22_000097_add_v2_member_rank_prelaunch_activation.php",
            directory + "/2026_10_23_000098_add_v2_shipping_minimum_points.php",
        ])


class OldTestBuildRecipeTest(unittest.TestCase):
    def setUp(self):
        self.recipe = json.loads((ROOT / "manifests/old-test-build-recipe.json").read_text())
        self.source = self.recipe["source_sha"]
        self.original = b"FROM pinned\nRUN install linux-libc-dev=6.1.187-1\n"
        self.updated = self.original.replace(b"6.1.187-1", b"6.1.190-1")
        self.recipe["source_sha256"] = hashlib.sha256(self.original).hexdigest()
        self.recipe["recipe_sha256"] = hashlib.sha256(self.updated).hexdigest()
        self.pull = {
            "state": "closed", "merged": True, "merge_commit_sha": self.recipe["recipe_sha"],
            "base": {"ref": "main", "repo": {"full_name": wrapper.REPOSITORY}},
            "head": {"sha": REVIEWED, "repo": {"full_name": wrapper.REPOSITORY}},
        }
        self.tree = TREE
        self.ancestor = True

    def get(self, path):
        if "/contents/manifests/" in path:
            return {"encoding": "base64", "content": base64.b64encode(json.dumps(self.recipe).encode()).decode()}
        if "/contents/infra/" in path:
            body = self.original if path.endswith(self.source) else self.updated
            return {"type": "file", "encoding": "base64", "content": base64.encodebytes(body).decode()}
        if "/compare/" in path:
            return {"status": "ahead" if self.ancestor else "diverged", "merge_base_commit": {"sha": self.recipe["recipe_sha"]}, "base_commit": {"sha": self.recipe["recipe_sha"]}}
        if path.endswith("/pulls/583"):
            return self.pull
        if "/git/commits/" in path:
            commit = path.rsplit("/", 1)[1]
            return {"sha": commit, "tree": {"sha": self.tree if commit == REVIEWED else TREE}}
        raise AssertionError(path)

    def authorize(self):
        return wrapper.authorize_old_test_recipe(self.source, CONTROL, self.get)

    def test_recipe_binding_and_checks(self):
        with mock.patch.object(wrapper, "validate_required_checks") as checks:
            result = self.authorize()["build_recipe"]
        checks.assert_called_once_with(REVIEWED, self.get)
        self.assertEqual(result["source_sha"], self.source)
        self.assertEqual(result["recipe_sha256"], hashlib.sha256(self.updated).hexdigest())

    def test_legacy_payload_does_not_enable_recipe(self):
        transport = mock.Mock()
        self.assertEqual(wrapper.authorize_old_test_recipe(PAYLOAD, CONTROL, transport), {})
        transport.assert_not_called()

    def test_invalid_manifest_fields_fail_closed(self):
        original = copy.deepcopy(self.recipe)
        for field, value in (("source_sha", PAYLOAD), ("recipe_sha", CONTROL), ("recipe_pr", 584), ("path", "apps/api/file.php"), ("schema_version", "2.0"), ("unexpected", True)):
            with self.subTest(field=field), self.assertRaises(wrapper.WrapperError):
                self.recipe = {**original, field: value}
                self.authorize()

    def test_unmerged_foreign_recipe_or_tree_rejected(self):
        for failure in ("unmerged", "foreign", "tree", "ancestor"):
            self.setUp()
            if failure == "unmerged":
                self.pull["merged"] = False
            elif failure == "foreign":
                self.pull["head"]["repo"]["full_name"] = "other/repo"
            elif failure == "tree":
                self.tree = "f" * 40
            else:
                self.ancestor = False
            with self.subTest(failure=failure), self.assertRaises(wrapper.WrapperError):
                self.authorize()

    def test_recipe_failed_required_checks_rejected(self):
        with mock.patch.object(wrapper, "validate_required_checks", side_effect=wrapper.WrapperError("checks failed")), self.assertRaises(wrapper.WrapperError):
            self.authorize()

    def test_digest_and_non_pin_changes_rejected(self):
        for valid_digest in (False, True):
            self.setUp()
            self.updated += b"RUN unwanted-command\n"
            if valid_digest:
                self.recipe["recipe_sha256"] = hashlib.sha256(self.updated).hexdigest()
            with mock.patch.object(wrapper, "validate_required_checks"), self.assertRaises(wrapper.WrapperError):
                self.authorize()

    def test_materialization_preserves_payload_and_is_exclusive(self):
        prepare = runpy.run_path(str(ROOT / "scripts/ops/old_test_source_authority.py"))["prepare_recipe"]
        with tempfile.TemporaryDirectory() as temporary:
            payload = Path(temporary)
            dockerfile = payload / "infra/docker/backend/Dockerfile"
            dockerfile.parent.mkdir(parents=True)
            dockerfile.write_bytes(self.original)
            destination = payload / "external.Dockerfile"
            authority = {"source_sha": self.source, "build_recipe": self.recipe}
            with mock.patch("subprocess.check_output", side_effect=[self.source + "\n", self.updated]):
                prepare(authority, payload, payload, destination)
            self.assertEqual(destination.read_bytes(), self.updated)
            self.assertEqual(dockerfile.read_bytes(), self.original)
            with mock.patch("subprocess.check_output", side_effect=[self.source + "\n", self.updated]), self.assertRaises(FileExistsError):
                prepare(authority, payload, payload, destination)
            with mock.patch("subprocess.check_output", return_value=self.source + "\n"), self.assertRaisesRegex(ValueError, "authority required"):
                prepare({"source_sha": self.source}, payload, payload, payload / "missing")

    def test_workflow_builds_only_payload_context_with_authorized_recipe(self):
        workflow = (ROOT / ".github/workflows/old-test-main-artifact.yml").read_text()
        self.assertIn("working-directory: payload", workflow)
        self.assertIn("--prepare-recipe", workflow)
        self.assertIn('--file "$RUNNER_TEMP/approved-api.Dockerfile" --tag "$api_image" .', workflow)
        self.assertIn('OCI_REVISION=${INPUT_SOURCE_SHA}', workflow)
        self.assertIn('source-authority.json', workflow)


class GithubReadTransportTest(unittest.TestCase):
    def test_structured_sha_comparison_reaches_canonical_get_transport(self):
        for source, control in ((PAYLOAD, CONTROL), (PAYLOAD.upper(), CONTROL.upper())):
            with self.subTest(source=source), \
                 mock.patch.object(wrapper, "installation_token", return_value="unit-test-credential"), \
                 mock.patch.object(wrapper.urllib.request, "urlopen", return_value=io.BytesIO(b'{"status":"ahead"}')) as opener:
                self.assertEqual(wrapper.api_compare(wrapper.REPOSITORY, source, control), {"status": "ahead"})
                request = opener.call_args.args[0]
                self.assertEqual(request.full_url, f"https://api.github.com/repos/ideal-sol/oripa/compare/{PAYLOAD}...{CONTROL}?per_page=1")
                self.assertEqual(request.get_method(), "GET")
                self.assertEqual(opener.call_args.kwargs, {"timeout": 60})

    def test_compare_rejects_non_sha_components_before_credentials_or_network(self):
        invalid = (
            "a" * 39, "a" * 41, "g" * 40, "main", "v1.0.0", "", None,
            PAYLOAD + "/extra", PAYLOAD + "?unexpected=value", PAYLOAD + "#fragment",
            PAYLOAD + "%2f", PAYLOAD + "\\extra", "../main", "%2e%2e",
            "%2E%2E", "%252e%252e", "..%2f", "%2e%2e%2f", "%255c..",
            PAYLOAD + "\n", PAYLOAD + "\r", PAYLOAD + "\x00",
        )
        with mock.patch.object(wrapper, "installation_token") as credential, \
             mock.patch.object(wrapper.urllib.request, "urlopen") as opener:
            for value in invalid:
                for source, control in ((value, CONTROL), (PAYLOAD, value)):
                    with self.subTest(source=source, control=control), self.assertRaisesRegex(wrapper.WrapperError, "github_compare_identity_invalid"):
                        wrapper.api_compare(wrapper.REPOSITORY, source, control)
            for repository in ("external/repository", "ideal-sol/other", "ideal-sol/oripa/..", "https://api.github.com/repos/ideal-sol/oripa", "ideal-sol%2foripa", None):
                with self.subTest(repository=repository), self.assertRaisesRegex(wrapper.WrapperError, "github_compare_identity_invalid"):
                    wrapper.api_compare(repository, PAYLOAD, CONTROL)
            credential.assert_not_called()
            opener.assert_not_called()

    def test_generic_paths_still_reject_traversal_and_raw_compare(self):
        invalid = (
            "../", "../../", "/foo/../bar", "/%2e%2e", "/%2E%2E",
            "/%252e%252e", "/..%2f", "/%2e%2e%2f", "/foo\\..\\bar",
            "/foo/%252e%2E%255cbar", "/%255c..", "/foo%0abar", "/foo#fragment",
            "https://api.github.com/repos/ideal-sol/oripa", "//external/host", None,
        )
        comparisons = (
            f"{PAYLOAD}...{CONTROL}", f"{'a' * 39}...{CONTROL}", f"{'a' * 41}...{CONTROL}",
            f"{PAYLOAD}..{CONTROL}", f"{PAYLOAD}....{CONTROL}",
            f"{PAYLOAD}/{CONTROL}", f"{PAYLOAD}%252f{CONTROL}",
            f"{PAYLOAD}...main", f"main...{CONTROL}", f"{PAYLOAD}...{CONTROL}/extra",
            f"{PAYLOAD}%2f...{CONTROL}", f"{PAYLOAD}...{CONTROL}?unexpected=value",
            f"{PAYLOAD}...{CONTROL}#fragment", "../...", "%2e%2e%2f...main",
        )
        with mock.patch.object(wrapper, "installation_token") as credential, \
             mock.patch.object(wrapper.urllib.request, "urlopen") as opener:
            for path in invalid + tuple("/repos/ideal-sol/oripa/compare/" + value for value in comparisons):
                with self.subTest(path=path), self.assertRaisesRegex(wrapper.WrapperError, "github_path_invalid"):
                    wrapper.api_get(path)
            credential.assert_not_called()
            opener.assert_not_called()

    def test_compare_operation_does_not_accept_a_combined_path_or_extra_options(self):
        with mock.patch.object(wrapper, "installation_token") as credential, \
             mock.patch.object(wrapper.urllib.request, "urlopen") as opener:
            for source in (f"{PAYLOAD}/{CONTROL}", f"{PAYLOAD}...{CONTROL}"):
                with self.subTest(source=source), self.assertRaises(wrapper.WrapperError):
                    wrapper.api_compare(wrapper.REPOSITORY, source, CONTROL)
            with self.assertRaises(TypeError):
                wrapper.api_compare(wrapper.REPOSITORY, PAYLOAD, CONTROL, query="unexpected=value")
            credential.assert_not_called()
            opener.assert_not_called()

    def test_existing_generic_read_paths_reach_transport_unchanged(self):
        paths = (
            "/repos/ideal-sol/oripa/branches/main",
            "/repos/ideal-sol/oripa/branches/archive%2Fv1-current",
            "/repos/ideal-sol/oripa/actions/runs?per_page=100&page=2",
        )
        for path in paths:
            with self.subTest(path=path), \
                 mock.patch.object(wrapper, "installation_token", return_value="unit-test-credential"), \
                 mock.patch.object(wrapper.urllib.request, "urlopen", return_value=io.BytesIO(b"{}")) as opener:
                self.assertEqual(wrapper.api_get(path), {})
                self.assertEqual(opener.call_args.args[0].full_url, wrapper.API_ROOT + path)

    def test_compare_transport_errors_remain_sanitized(self):
        failures = (
            (wrapper.urllib.error.HTTPError("https://api.github.com", 404, "private response", {}, io.BytesIO(b"private body")), "github_api_http_404"),
            (wrapper.urllib.error.URLError("private connection detail"), "github_api_connection_failed"),
        )
        for error, expected in failures:
            with self.subTest(expected=expected), \
                 mock.patch.object(wrapper, "installation_token", return_value="unit-test-credential"), \
                 mock.patch.object(wrapper.urllib.request, "urlopen", side_effect=error), \
                 self.assertRaisesRegex(wrapper.WrapperError, "^" + expected + "$"):
                wrapper.api_compare(wrapper.REPOSITORY, PAYLOAD, CONTROL)


class OldTestSourceAuthorityTest(unittest.TestCase):
    def setUp(self):
        self.approval = {
            "schema_version": "1.0",
            "repository": wrapper.REPOSITORY,
            "task_id": TASK_ID,
            "source_pr": 549,
            "source_sha": PAYLOAD,
            "target": "old-test",
            "platform": "linux/amd64",
            "image_mode": "normal",
            "authority": "Human-approved exact OLD Test payload: synthetic unit test",
            "activation_authorized": False,
        }
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
        if path.endswith("/pulls/549"):
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
        return wrapper.authorize_old_test_source(TASK_ID, "549", source, control, get=self.get)

    def test_configured_approval_preserves_distinct_payload_and_control(self):
        self.approval = json.loads((ROOT / wrapper.OLD_AUTHORITY_PATH).read_text())
        source = self.approval["source_sha"]
        source_pr = str(self.approval["source_pr"])
        task = self.approval["task_id"]
        self.pull["merge_commit_sha"] = source
        self.comparison = {
            "status": "ahead", "merge_base_commit": {"sha": source},
            "base_commit": {"sha": source},
        }

        def transport(path):
            return self.get(path.replace(f"/pulls/{source_pr}", "/pulls/549"))

        with mock.patch.object(wrapper, "installation_token") as credential:
            result = wrapper.authorize_old_test_source(task, source_pr, source, CONTROL, get=transport)
            self.assertEqual(result["source_sha"], source)
            self.assertEqual(result["control_sha"], CONTROL)
            self.assertNotEqual(result["source_sha"], result["control_sha"])
            self.assertEqual(result["image_mode"], "normal")
            self.assertIs(self.approval["activation_authorized"], False)
            for field, invalid in (("source_sha", CONTROL), ("activation_authorized", True)):
                original = self.approval[field]
                self.approval[field] = invalid
                with self.subTest(field=field), self.assertRaises(wrapper.WrapperError):
                    wrapper.authorize_old_test_source(task, source_pr, source, CONTROL, get=transport)
                self.approval[field] = original
            credential.assert_not_called()

    def test_ci_injected_read_transport_does_not_use_host_credentials(self):
        transport = mock.Mock(side_effect=self.get)
        with mock.patch.object(wrapper, "installation_token") as credential, \
             mock.patch.object(wrapper.urllib.request, "urlopen") as opener:
            result = wrapper.authorize_old_test_source(TASK_ID, "549", PAYLOAD, CONTROL, get=transport)
        self.assertEqual(result["source_sha"], PAYLOAD)
        transport.assert_any_call(f"/repos/{wrapper.REPOSITORY}/compare/{PAYLOAD}...{CONTROL}?per_page=1")
        credential.assert_not_called()
        opener.assert_not_called()

    def test_full_authority_uses_real_path_guards_and_http_transport(self):
        requests = []
        def respond(request, **options):
            path = request.full_url.removeprefix(wrapper.API_ROOT)
            requests.append(path)
            return io.BytesIO(json.dumps(self.get(path)).encode())
        with mock.patch.object(wrapper, "installation_token", return_value="unit-test-credential"), \
             mock.patch.object(wrapper.urllib.request, "urlopen", side_effect=respond):
            result = wrapper.authorize_old_test_source(TASK_ID, "549", PAYLOAD, CONTROL)
        self.assertEqual(result["source_sha"], PAYLOAD)
        self.assertEqual(requests.count(f"/repos/{wrapper.REPOSITORY}/compare/{PAYLOAD}...{CONTROL}?per_page=1"), 1)

    def recipe_response(self, path):
        if path.endswith("/branches/main") or "/check-runs?" in path:
            return self.get(path)
        if "/contents/" + wrapper.OLD_AUTHORITY_PATH in path:
            approval = {**self.approval, "source_sha": self.build_recipe.source}
            return {"encoding": "base64", "content": base64.b64encode(json.dumps(approval).encode()).decode()}
        if path.endswith("/pulls/549"):
            return {**self.pull, "merge_commit_sha": self.build_recipe.source}
        if f"/compare/{self.build_recipe.source}..." in path:
            return {"status": "ahead", "base_commit": {"sha": self.build_recipe.source},
                    "merge_base_commit": {"sha": self.build_recipe.source}}
        return self.build_recipe.get(path)

    def test_recipe_dispatch_uses_real_default_transport_and_path_guards(self):
        self.build_recipe = OldTestBuildRecipeTest()
        self.build_recipe.setUp()
        requests = []
        def respond(request, **options):
            path = request.full_url.removeprefix(wrapper.API_ROOT)
            requests.append(path)
            return io.BytesIO(json.dumps(self.recipe_response(path)).encode())
        dispatch = mock.Mock(return_value=(204, None))
        with mock.patch.object(wrapper, "installation_token", return_value="unit-test-credential"), \
             mock.patch.object(wrapper.urllib.request, "urlopen", side_effect=respond), \
             mock.patch.object(wrapper, "secure_policy", return_value={"lane": "Strict Change", "activation": "deferred"}), \
             mock.patch.object(wrapper.runpy, "run_path", return_value={"request": dispatch}), \
             mock.patch.object(wrapper, "api_get", wraps=wrapper.api_get) as generic:
            result = wrapper.dispatch_old_test_artifact(TASK_ID, "549", self.build_recipe.source)
        self.assertEqual(result["status"], "dispatched")
        self.assertEqual(result["build_recipe"]["recipe_sha"], self.build_recipe.recipe["recipe_sha"])
        for source in (self.build_recipe.source, self.build_recipe.recipe["recipe_sha"]):
            self.assertEqual(requests.count(f"/repos/{wrapper.REPOSITORY}/compare/{source}...{CONTROL}?per_page=1"), 1)
        self.assertFalse(any("/compare/" in call.args[0] for call in generic.call_args_list))
        dispatch.assert_called_once_with("POST", "/repos/ideal-sol/oripa/actions/workflows/old-test-main-artifact.yml/dispatches",
                                         {"ref": "main", "inputs": {"task_id": TASK_ID, "pr_number": "549", "source_sha": self.build_recipe.source}})

    def test_recipe_authority_injected_transport_remains_offline(self):
        self.build_recipe = OldTestBuildRecipeTest()
        self.build_recipe.setUp()
        with mock.patch.object(wrapper, "installation_token") as credential, \
             mock.patch.object(wrapper.urllib.request, "urlopen") as opener:
            result = wrapper.authorize_old_test_source(TASK_ID, "549", self.build_recipe.source, CONTROL, get=self.recipe_response)
        self.assertEqual(result["build_recipe"]["recipe_sha"], self.build_recipe.recipe["recipe_sha"])
        credential.assert_not_called()
        opener.assert_not_called()

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

    def test_only_explicitly_approved_api_or_api_admin_inventory_is_authority(self):
        for mode in ("api-only", "normal"):
            self.approval["image_mode"] = mode
            with self.subTest(mode=mode):
                result = self.authorize()
                self.assertEqual(result["image_mode"], mode)
                self.assertEqual(result["source_sha"], PAYLOAD)
        for source in (REVIEWED, CONTROL, "0ce41ab473fd5a4fb44773041ae097ffb40b14ce"):
            with self.subTest(source=source), self.assertRaisesRegex(wrapper.WrapperError, "approval_mismatch"):
                self.authorize(source=source)

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
                             ("image_mode", "admin-only"), ("image_mode", ""),
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
            wrapper.authorize_old_test_source(TASK_ID, "549", PAYLOAD, CONTROL, get=changed)
        calls = []
        def moved(path):
            result = original(path)
            if path.endswith("/branches/main"):
                calls.append(path)
                if len(calls) > 1:
                    result["commit"]["sha"] = "f" * 40
            return result
        with self.assertRaisesRegex(wrapper.WrapperError, "control_moved"):
            wrapper.authorize_old_test_source(TASK_ID, "549", PAYLOAD, CONTROL, get=moved)

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
             mock.patch.object(wrapper, "api_compare", return_value=self.comparison), \
             mock.patch.object(wrapper.runpy, "run_path", return_value={"request": transport}):
            result = wrapper.dispatch_old_test_artifact(TASK_ID, "549", PAYLOAD)
        self.assertEqual(result["status"], "dispatched")
        transport.assert_called_once_with("POST", "/repos/ideal-sol/oripa/actions/workflows/old-test-main-artifact.yml/dispatches",
                                          {"ref": "main", "inputs": {"task_id": TASK_ID, "pr_number": "549", "source_sha": PAYLOAD}})
        self.assertNotIn("token", json.dumps(result).lower())

    def test_dispatch_never_writes_when_approval_or_policy_fails(self):
        with mock.patch.object(wrapper, "secure_policy", return_value={"lane": "Lite Maintenance", "activation": "none"}), \
             mock.patch.object(wrapper.runpy, "run_path") as transport:
            with self.assertRaises(wrapper.WrapperError):
                wrapper.dispatch_old_test_artifact(TASK_ID, "549", PAYLOAD)
            transport.assert_not_called()


class OldTestArtifactBoundaryTest(unittest.TestCase):
    def test_builder_packages_api_admin_and_rejects_agency_before_docker(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            fixtures = {name: create_docker_archive(directory, name, artifact_kind="old-test")
                        for name in ("api", "admin")}
            arguments = artifact.parser().parse_args([
                "package", "--output", str(directory / "output"), "--task-id", TASK,
                "--pr-number", str(PR), "--source-sha", HEAD,
                "--created-at", "2026-08-12T00:00:00Z", "--artifact-kind", "old-test",
                "--image-mode", "normal", "--api-image", fixtures["api"]["reference"],
                "--admin-image", fixtures["admin"]["reference"],
            ])
            def inspect(reference):
                metadata = next(item for item in fixtures.values() if item["reference"] == reference)
                return {"Architecture": "amd64", "Os": "linux", "Id": metadata["image_id"],
                        "Config": {"Labels": metadata["labels"]}}
            def run(command, **options):
                if command[:3] == ["docker", "image", "save"]:
                    metadata = next(item for item in fixtures.values() if item["reference"] == command[-1])
                    with Path(command[command.index("--output") + 1]).open("wb") as output:
                        subprocess.run(["zstd", "--decompress", "--stdout", str(directory / metadata["archive"])], stdout=output, check=True)
                else:
                    self.assertEqual(command[0], "zstd")
                    subprocess.run(command, check=True)
                return ""
            with mock.patch.object(artifact, "docker_inspect", side_effect=inspect), \
                 mock.patch.object(artifact, "run", side_effect=run):
                artifact.package_images(arguments)
            result = artifact.verify_artifact(arguments.output, task_id=TASK, pr_number=PR,
                                              source_sha=HEAD, artifact_kind="old-test")
            self.assertEqual([item["name"] for item in result["images"]], ["api", "admin"])
            arguments.output = directory / "rejected"
            arguments.image_mode = "agency"
            with mock.patch.object(artifact, "docker_inspect") as docker, \
                 self.assertRaisesRegex(artifact.ArtifactError, "old_test_image_inventory_invalid"):
                artifact.package_images(arguments)
            docker.assert_not_called()

    def test_download_binds_outer_digest_provenance_control_and_inner_verifier(self):
        authority = {"task_id": TASK_ID, "source_sha": PAYLOAD, "source_pr": 549,
                     "control_sha": CONTROL, "target": "old-test", "platform": "linux/amd64"}
        run = {"event": "workflow_dispatch", "status": "completed", "conclusion": "success",
               "path": wrapper.OLD_WORKFLOW_PATH, "head_branch": "main", "head_sha": CONTROL,
               "run_attempt": 1}
        for image_mode, mutation in ((mode, change) for mode in ("api-only", "normal")
                                     for change in ("none", "source", "control", "attempt", "outer", "inventory", "mode")):
            authority["image_mode"] = image_mode
            with self.subTest(mode=image_mode, mutation=mutation), tempfile.TemporaryDirectory() as temporary:
                directory = Path(temporary)
                archive = directory / "test.zip"
                provenance = {**authority, "run_id": 123, "run_attempt": 1}
                if mutation in {"source", "control"}:
                    provenance[mutation + "_sha"] = "f" * 40
                if mutation == "attempt":
                    provenance["run_attempt"] = 2
                if mutation == "mode":
                    provenance["image_mode"] = "normal" if image_mode == "api-only" else "api-only"
                image_names = ["api", "admin"] if image_mode == "normal" else ["api"]
                manifest_names = image_names if mutation != "inventory" else list(reversed(image_names)) + ["agency"]
                with zipfile.ZipFile(archive, "w") as bundle:
                    for name in ["SHA256SUMS"] + [f"oripa-v2-{image}-linux-amd64.docker.tar.zst" for image in image_names]:
                        bundle.writestr(name, "fixture")
                    bundle.writestr("manifest.json", json.dumps({"images": [{"name": name} for name in manifest_names]}))
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
                        result = wrapper.download_preview_artifact(TASK_ID, "549", PAYLOAD, "456", str(destination), old_test=True)
                        self.assertEqual(result["status"], "verified")
                        self.assertEqual(result["control_sha"], CONTROL)
                        self.assertIn(f"{CONTROL}:scripts/ops/preview_image_artifact.py", runner.call_args_list[0].args[0])
                        self.assertEqual(runner.call_args_list[1].args[0][-4:], ["--artifact-kind", "old-test", "--architecture", "amd64"])
                    else:
                        with self.assertRaises(wrapper.WrapperError):
                            wrapper.download_preview_artifact(TASK_ID, "549", PAYLOAD, "456", str(destination), old_test=True)
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

    def test_old_test_api_admin_archive_verifies_but_not_as_preview_or_production(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            create_artifact(directory, ("api", "admin"), artifact_kind="old-test")
            result = artifact.verify_artifact(directory, task_id=TASK, pr_number=PR,
                                              source_sha=HEAD, artifact_kind="old-test")
            self.assertEqual([item["name"] for item in result["images"]], ["api", "admin"])
            for kind in ("preview", "production-candidate"):
                with self.subTest(kind=kind), self.assertRaises(artifact.ArtifactError):
                    artifact.verify_artifact(directory, task_id=TASK, pr_number=PR,
                                             source_sha=HEAD, artifact_kind=kind)

    def test_old_test_rejects_arm64_agency_admin_only_and_reordered_inventory(self):
        with self.assertRaisesRegex(artifact.ArtifactError, "architecture_invalid"):
            artifact.validate_target("old-test", "arm64")
        for names in (("api", "admin", "agency"), ("admin",), ("admin", "api")):
            with self.subTest(names=names), tempfile.TemporaryDirectory() as temporary:
                directory = Path(temporary)
                create_artifact(directory, names, artifact_kind="old-test")
                with self.assertRaises(artifact.ArtifactError):
                    artifact.verify_artifact(directory, task_id=TASK, pr_number=PR,
                                             source_sha=HEAD, artifact_kind="old-test")

    def test_old_test_api_admin_zip_preserves_exact_inventory_and_provenance(self):
        for extra in (None, "oripa-v2-agency-linux-amd64.docker.tar.zst", "unexpected"):
            with self.subTest(extra=extra), tempfile.TemporaryDirectory() as temporary:
                directory = Path(temporary)
                archive = directory / "test.zip"
                names = {"manifest.json", "SHA256SUMS", "source-authority.json",
                         "oripa-v2-api-linux-amd64.docker.tar.zst", "oripa-v2-admin-linux-amd64.docker.tar.zst"}
                if extra:
                    names.add(extra)
                with zipfile.ZipFile(archive, "w") as bundle:
                    for name in names:
                        bundle.writestr(name, "fixture")
                if extra:
                    with self.assertRaises(wrapper.WrapperError):
                        wrapper.safe_extract(archive, directory, True)
                else:
                    wrapper.safe_extract(archive, directory, True)

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
                         "source-authority.json", 'if [[ "$image_mode" == normal ]]; then',
                         '--file apps/admin/Dockerfile', '--admin-image "$admin_image"',
                         '--image-mode "$image_mode"', 'test "$image_mode" = api-only || test "$image_mode" = normal'):
            self.assertIn(required, workflow)
        for forbidden in ("continue-on-error", "secrets.", "docker run", "docker compose",
                          "--platform linux/arm64", "environment: production", "actions: write",
                          "apps/agency/Dockerfile", "--agency-image"):
            self.assertNotIn(forbidden, workflow)
        preview = (ROOT / ".github/workflows/preview-image-build.yml").read_text()
        self.assertIn('if head.get("sha") != head_sha:', preview)
        self.assertIn('raise SystemExit("pull request head mismatch")', preview)
        self.assertIn("tests.ops.test_old_test_source_authority", (ROOT / ".github/workflows/platform-ci.yml").read_text())


if __name__ == "__main__":
    unittest.main()
