import importlib.util
import json
from pathlib import Path
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[3]
SCRIPT = ROOT / "scripts/ci/lane_policy.py"
SPEC = importlib.util.spec_from_file_location("lane_policy", SCRIPT)
lane_policy = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(lane_policy)


def body(lane="Lite Maintenance", activation="deferred", ui_verification="PASS"):
    return (
        f"- Lane: {lane}\n"
        f"- Application Runtime Activation: {activation}\n"
        f"- UI Verification: {ui_verification}\n"
    )


class LanePolicyTest(unittest.TestCase):
    def test_ui_only_lite_candidate_is_lite(self):
        result = lane_policy.validate_pr_lane(
            body(),
            [
                "apps/admin/src/components/catalog/catalog-layout.tsx",
                "apps/admin/test/catalog-layout.test.tsx",
            ],
        )
        self.assertEqual(result["lane"], "Lite Maintenance")
        self.assertEqual(result["minimum_lane"], "Lite Maintenance")
        self.assertEqual(result["activation"], "deferred")

    def test_lite_rejects_every_sensitive_domain_fixture(self):
        paths = [
            ".github/workflows/platform-ci.yml",
            "pnpm-lock.yaml",
            "apps/api/database/migrations-v2/example.php",
            "apps/api/app/Domain/Identity/Services/V2SessionService.php",
            "apps/api/app/Domain/Payment/V2/PaymentService.php",
            "apps/api/app/Domain/Point/V2/CoinService.php",
            "apps/api/app/Domain/Draw/V2/InventoryService.php",
            "docs/operations/deployment/production.md",
            "infrastructure/github-app/task_policy.py",
        ]
        for path in paths:
            with self.subTest(path=path):
                with self.assertRaises(lane_policy.LanePolicyFailure):
                    lane_policy.validate_pr_lane(body(), [path])

    def test_standard_candidate_passes_normal_lane(self):
        result = lane_policy.validate_pr_lane(
            body("Standard Change", "none"),
            ["apps/api/app/Domain/Catalog/Services/V2CatalogReadService.php"],
        )
        self.assertEqual(result["minimum_lane"], "Standard Change")

    def test_strict_path_requires_strict_and_strict_passes(self):
        for path in (
            ".github/workflows/platform-ci.yml",
            ".github/ISSUE_TEMPLATE/task.yml",
            ".github/pull_request_template.md",
        ):
            with self.subTest(path=path):
                with self.assertRaises(lane_policy.LanePolicyFailure):
                    lane_policy.validate_pr_lane(
                        body("Standard Change", "none"), [path]
                    )
                result = lane_policy.validate_pr_lane(
                    body("Strict Change", "none"), [path]
                )
                self.assertEqual(result["minimum_lane"], "Strict Change")

    def test_missing_invalid_lane_and_unknown_path_fail_closed(self):
        with self.assertRaises(lane_policy.LanePolicyFailure):
            lane_policy.validate_pr_lane("- Application Runtime Activation: none\n", ["README.md"])
        with self.assertRaises(lane_policy.LanePolicyFailure):
            lane_policy.validate_pr_lane(body("Fast Change", "none"), ["README.md"])
        with self.assertRaises(lane_policy.LanePolicyFailure):
            lane_policy.validate_pr_lane(body(), ["unknown/new-file.xyz"])

    def test_activation_modes_are_exact(self):
        for activation in lane_policy.ACTIVATION_MODES:
            self.assertEqual(lane_policy.validate_activation(activation), activation)
        for activation in ("", "later", "Immediate"):
            with self.subTest(activation=activation):
                with self.assertRaises(lane_policy.LanePolicyFailure):
                    lane_policy.validate_activation(activation)

    def test_lite_ui_requires_targeted_ui_verification(self):
        with self.assertRaisesRegex(
            lane_policy.LanePolicyFailure, "UI Verification PASS"
        ):
            lane_policy.validate_pr_lane(
                body(ui_verification="NOT_APPLICABLE"),
                ["apps/admin/src/components/catalog/catalog-layout.tsx"],
            )

    def test_non_pr_event_fails_safe_to_strict(self):
        result = lane_policy.event_policy(ROOT, "push", None)
        self.assertEqual(result["lane"], "Strict Change")
        self.assertEqual(result["activation"], "none")

    def test_pr_event_rejects_invalid_activation(self):
        event = {
            "pull_request": {
                "body": body("Lite Maintenance", "later"),
                "base": {"sha": "a" * 40},
                "head": {"sha": "b" * 40},
            }
        }
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "event.json"
            path.write_text(json.dumps(event), encoding="utf-8")
            with self.assertRaises(lane_policy.LanePolicyFailure):
                lane_policy.event_policy(ROOT, "pull_request", path)

    def test_diff_secret_scan_reports_only_candidate_count(self):
        with self.assertRaisesRegex(
            lane_policy.LanePolicyFailure,
            "secret candidates found.*1",
        ) as raised:
            original = lane_policy.run_git
            lane_policy.run_git = lambda *_args: "+github_pat_" + "A" * 30
            try:
                lane_policy.scan_added_diff(
                    ROOT, ["README.md"], "a" * 40, "b" * 40
                )
            finally:
                lane_policy.run_git = original
        self.assertNotIn("github_pat_", str(raised.exception))

    def test_required_check_names_stay_and_strict_full_suite_is_preserved(self):
        workflow = (ROOT / ".github/workflows/platform-ci.yml").read_text(
            encoding="utf-8"
        )
        for name in (
            "policy-gate",
            "quality-gate",
            "security-gate",
            "integration-gate",
            "ci-gate",
        ):
            self.assertIn(f"name: {name}", workflow)
        self.assertIn("if: needs.policy-gate.outputs.lane_key != 'lite'", workflow)
        self.assertIn("working-directory: apps/api\n        run: php artisan test", workflow)
        self.assertIn("INTEGRATION_SUITE_RESULT", workflow)
        self.assertNotIn("continue-on-error: true", workflow)

    def test_committed_main_ruleset_requires_all_five_without_bypass(self):
        ruleset = json.loads(
            (ROOT / "docs/operations/github-rulesets/main-ruleset.json").read_text(
                encoding="utf-8"
            )
        )
        self.assertEqual(ruleset["bypass_actors"], [])
        required = next(
            rule for rule in ruleset["rules"] if rule["type"] == "required_status_checks"
        )
        contexts = {
            item["context"]
            for item in required["parameters"]["required_status_checks"]
        }
        self.assertEqual(
            contexts,
            {
                "policy-gate",
                "quality-gate",
                "security-gate",
                "integration-gate",
                "ci-gate",
            },
        )
