"""Validation rules for conflict-aware, no-force task branch base sync."""

from __future__ import annotations

import re


FULL_SHA = re.compile(r"[0-9a-f]{40}")
CONFLICT_MARKERS = (b"<<<<<<<", b"=======", b">>>>>>>")


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
    net_changed_paths: list[str],
    conflict_paths: list[str],
    automatic_merge_mismatches: list[str],
    unresolved_conflict_paths: list[str],
    resolved_blobs: dict[str, tuple[str | None, str | None, str | None]],
    allowed_paths: list[str],
) -> dict:
    """Fail closed unless a candidate preserves base changes and task scope."""

    for value in (task_head, base_sha, candidate_sha):
        if not FULL_SHA.fullmatch(value):
            raise TaskBranchBaseSyncError("sync_sha_invalid")
    if candidate_sha in {task_head, base_sha}:
        raise TaskBranchBaseSyncError("sync_candidate_invalid")
    if parents != [task_head, base_sha]:
        raise TaskBranchBaseSyncError("sync_parent_mismatch")
    if len(set(net_changed_paths)) != len(net_changed_paths) or any(
        not isinstance(path, str) or not path_allowed(path, allowed_paths)
        for path in net_changed_paths
    ):
        raise TaskBranchBaseSyncError("sync_net_scope_rejected")
    if len(set(conflict_paths)) != len(conflict_paths) or any(
        not isinstance(path, str)
        or path not in net_changed_paths
        or not path_allowed(path, allowed_paths)
        for path in conflict_paths
    ):
        raise TaskBranchBaseSyncError("sync_conflict_scope_rejected")
    if automatic_merge_mismatches:
        raise TaskBranchBaseSyncError("sync_base_change_lost")
    if unresolved_conflict_paths:
        raise TaskBranchBaseSyncError("sync_unresolved_conflict")
    if set(resolved_blobs) != set(conflict_paths):
        raise TaskBranchBaseSyncError("sync_conflict_metadata_invalid")
    for path in conflict_paths:
        candidate_blob, task_blob, base_blob = resolved_blobs[path]
        if not isinstance(candidate_blob, str) or candidate_blob in {task_blob, base_blob}:
            raise TaskBranchBaseSyncError("sync_conflict_resolution_rejected")
    return {
        "task_head": task_head,
        "base_sha": base_sha,
        "candidate_sha": candidate_sha,
        "net_changed_paths": sorted(net_changed_paths),
        "conflict_paths": sorted(conflict_paths),
        "passed": True,
    }


def contains_unresolved_markers(content: bytes) -> bool:
    return any(marker in content for marker in CONFLICT_MARKERS)
