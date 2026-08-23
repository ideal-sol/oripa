import datetime
import importlib.util
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "infrastructure/github-app/self_review_policy.py"
SPEC = importlib.util.spec_from_file_location("self_review_policy", SCRIPT)
self_review = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(self_review)


NOW = datetime.datetime(2026, 8, 23, 12, 0, tzinfo=datetime.timezone.utc)


class SelfReviewPolicyTest(unittest.TestCase):
    def test_lite_and_standard_are_head_bound_not_time_expiring(self):
        old = "2026-07-01T00:00:00Z"
        self_review.validate_freshness("Lite Maintenance", old, now=NOW)
        self_review.validate_freshness("Standard Change", old, now=NOW)

    def test_strict_retains_current_thirty_minute_freshness(self):
        self_review.validate_freshness(
            "Strict Change", "2026-08-23T11:31:00Z", now=NOW
        )
        with self.assertRaises(self_review.SelfReviewPolicyFailure):
            self_review.validate_freshness(
                "Strict Change", "2026-08-23T11:29:59Z", now=NOW
            )

    def test_future_evidence_and_invalid_lane_fail_closed(self):
        with self.assertRaises(self_review.SelfReviewPolicyFailure):
            self_review.validate_freshness(
                "Lite Maintenance", "2026-08-23T12:01:01Z", now=NOW
            )
        with self.assertRaises(self_review.SelfReviewPolicyFailure):
            self_review.validate_freshness("Unknown", "2026-08-23T12:00:00Z", now=NOW)

    def test_evidence_metadata_must_match_task_policy(self):
        policy = {"lane": "Standard Change", "activation": "deferred"}
        evidence = {
            "schema_version": "2.0",
            "lane": "Standard Change",
            "activation": "deferred",
        }
        self_review.validate_governance_metadata(policy, evidence)
        evidence["lane"] = "Lite Maintenance"
        with self.assertRaises(self_review.SelfReviewPolicyFailure):
            self_review.validate_governance_metadata(policy, evidence)

    def test_every_lane_is_bound_to_the_unchanged_final_head(self):
        expected = "a" * 40
        for lane in ("Lite Maintenance", "Standard Change", "Strict Change"):
            with self.subTest(lane=lane):
                self_review.validate_head(
                    expected,
                    expected,
                    {"lane": lane, "head_sha": expected},
                )
        with self.assertRaises(self_review.SelfReviewPolicyFailure):
            self_review.validate_head(expected, "b" * 40, {"head_sha": expected})
