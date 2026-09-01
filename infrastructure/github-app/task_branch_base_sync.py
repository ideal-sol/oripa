"""Validation rules for clean, no-force, exact-scope task branch base sync."""

from __future__ import annotations

import re


FULL_SHA = re.compile(r"[0-9a-f]{40}")
class TaskBranchBaseSyncError(ValueError):
    pass


def validate_current_refs(
    *, task_head: str, base_sha: str, remote_task_head: str, remote_base_sha: str
) -> None:
    """Require exact optimistic locks before preparing or pushing a sync."""

    if remote_task_head != task_head:
        raise TaskBranchBaseSyncError("sync_task_head_changed")
    if remote_base_sha != base_sha:
        raise TaskBranchBaseSyncError("sync_base_changed")


def path_allowed(path: str, allowed_paths: list[str]) -> bool:
    return any(
        path == allowed
        or (allowed.endswith("/**") and path.startswith(f"{allowed[:-3]}/"))
        for allowed in allowed_paths
    )


def validate_candidate(
    *,
    task_head: str,
    base_sha: str,
    candidate_sha: str,
    parents: list[str],
    task_head_is_ancestor: bool,
    base_is_ancestor: bool,
    candidate_tree_sha: str,
    canonical_tree_sha: str,
    net_changed_paths: list[str],
    conflict_paths: list[str],
    allowed_paths: list[str],
) -> dict:
    """Fail closed unless a clean candidate exactly preserves both parents."""

    for value in (
        task_head,
        base_sha,
        candidate_sha,
        candidate_tree_sha,
        canonical_tree_sha,
    ):
        if not isinstance(value, str) or not FULL_SHA.fullmatch(value):
            raise TaskBranchBaseSyncError("sync_sha_invalid")
    if candidate_sha in {task_head, base_sha}:
        raise TaskBranchBaseSyncError("sync_candidate_invalid")
    if parents != [task_head, base_sha]:
        raise TaskBranchBaseSyncError("sync_parent_mismatch")
    if not isinstance(conflict_paths, list):
        raise TaskBranchBaseSyncError("sync_conflict_metadata_invalid")
    if conflict_paths:
        raise TaskBranchBaseSyncError("sync_conflict_required")
    if task_head_is_ancestor is not True:
        raise TaskBranchBaseSyncError("sync_task_change_lost")
    if base_is_ancestor is not True:
        raise TaskBranchBaseSyncError("sync_base_change_lost")
    if candidate_tree_sha != canonical_tree_sha:
        raise TaskBranchBaseSyncError("sync_tree_mismatch")
    if not net_changed_paths or len(set(net_changed_paths)) != len(net_changed_paths) or any(
        not isinstance(path, str) or not path_allowed(path, allowed_paths)
        for path in net_changed_paths
    ):
        raise TaskBranchBaseSyncError("sync_net_scope_rejected")
    return {
        "task_head": task_head,
        "base_sha": base_sha,
        "candidate_sha": candidate_sha,
        "candidate_tree_sha": candidate_tree_sha,
        "canonical_tree_sha": canonical_tree_sha,
        "net_changed_paths": sorted(net_changed_paths),
        "conflict_paths": [],
        "passed": True,
    }
