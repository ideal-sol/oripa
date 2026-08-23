"""Lane-aware fixed-head self-review validation policy."""

from __future__ import annotations

import datetime


SELF_REVIEW_SCHEMA_VERSION = "2.0"
SELF_REVIEW_GOVERNANCE_KEYS = {"lane", "activation"}
STRICT_MAX_AGE_SECONDS = 1800


class SelfReviewPolicyFailure(ValueError):
    pass


def parse_time(value: str) -> datetime.datetime:
    try:
        parsed = datetime.datetime.fromisoformat(value.replace("Z", "+00:00"))
    except (AttributeError, ValueError):
        raise SelfReviewPolicyFailure("evidence_time_invalid")
    if parsed.tzinfo is None:
        raise SelfReviewPolicyFailure("evidence_time_invalid")
    return parsed


def validate_governance_metadata(policy: dict, evidence: dict) -> None:
    if evidence.get("schema_version") != SELF_REVIEW_SCHEMA_VERSION:
        raise SelfReviewPolicyFailure("evidence_version_invalid")
    if evidence.get("lane") != policy.get("lane"):
        raise SelfReviewPolicyFailure("evidence_lane_invalid")
    if evidence.get("activation") != policy.get("activation"):
        raise SelfReviewPolicyFailure("evidence_activation_invalid")


def validate_head(expected_head: str, current_head: str, evidence: dict) -> None:
    if evidence.get("head_sha") != expected_head or current_head != expected_head:
        raise SelfReviewPolicyFailure("evidence_sha_invalid")


def validate_freshness(
    lane: str,
    created_at: str,
    *,
    now: datetime.datetime | None = None,
    strict_max_age_seconds: int = STRICT_MAX_AGE_SECONDS,
) -> None:
    current = now or datetime.datetime.now(datetime.timezone.utc)
    created = parse_time(created_at)
    age = current - created
    if age.total_seconds() < -60:
        raise SelfReviewPolicyFailure("evidence_time_invalid")
    if lane == "Strict Change" and age.total_seconds() > strict_max_age_seconds:
        raise SelfReviewPolicyFailure("evidence_stale")
    if lane not in {"Lite Maintenance", "Standard Change", "Strict Change"}:
        raise SelfReviewPolicyFailure("evidence_lane_invalid")
