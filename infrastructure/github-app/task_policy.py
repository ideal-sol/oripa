"""Canonical governance metadata for root-owned GitHub Task Policies."""

from __future__ import annotations


LANES = ("Lite Maintenance", "Standard Change", "Strict Change")
LANE_RANK = {lane: rank for rank, lane in enumerate(LANES)}
ACTIVATION_MODES = ("none", "deferred", "immediate")
GOVERNANCE_POLICY_KEYS = {"lane", "activation"}


class TaskPolicyFailure(ValueError):
    pass


def validate_governance_metadata(policy: dict) -> dict:
    if policy.get("lane") not in LANE_RANK:
        raise TaskPolicyFailure("policy_lane_invalid")
    if policy.get("activation") not in ACTIVATION_MODES:
        raise TaskPolicyFailure("policy_activation_invalid")
    return policy


def validate_lane_transition(previous_lane: str, next_lane: str) -> str:
    if previous_lane not in LANE_RANK or next_lane not in LANE_RANK:
        raise TaskPolicyFailure("policy_lane_invalid")
    if LANE_RANK[next_lane] < LANE_RANK[previous_lane]:
        raise TaskPolicyFailure("policy_lane_downgrade_rejected")
    return next_lane
