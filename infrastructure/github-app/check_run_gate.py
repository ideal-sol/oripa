"""Canonical fixed-head Required Check evaluation."""

from __future__ import annotations

from datetime import datetime


EXPECTED_SOURCE = {
    "id": 15368,
    "slug": "github-actions",
    "owner": "github",
}
MAX_CHECK_RUN_PAGES = 10


class CheckRunGateError(ValueError):
    pass


def _source_matches(check: dict) -> bool:
    app = check.get("app")
    owner = app.get("owner") if isinstance(app, dict) else None
    return (
        isinstance(app, dict)
        and app.get("id") == EXPECTED_SOURCE["id"]
        and app.get("slug") == EXPECTED_SOURCE["slug"]
        and isinstance(owner, dict)
        and owner.get("login") == EXPECTED_SOURCE["owner"]
    )


def _run_order(check: dict) -> tuple[datetime, int]:
    started_at = check.get("started_at")
    identifier = check.get("id")
    if not isinstance(started_at, str) or not isinstance(identifier, int) or isinstance(identifier, bool):
        raise CheckRunGateError("check_run_order_invalid")
    try:
        started = datetime.fromisoformat(started_at.replace("Z", "+00:00"))
    except ValueError as error:
        raise CheckRunGateError("check_run_order_invalid") from error
    if started.tzinfo is None or identifier <= 0:
        raise CheckRunGateError("check_run_order_invalid")
    return started, identifier


def evaluate_required_check_runs(
    check_runs: list[object], *, head_sha: str, required_checks: set[str]
) -> dict:
    """Evaluate latest canonical Required Check runs for one exact commit."""

    latest: dict[str, dict] = {}
    failures: set[str] = set()
    for check in check_runs:
        if not isinstance(check, dict):
            continue
        name = check.get("name")
        if name not in required_checks:
            continue
        if check.get("head_sha") != head_sha:
            failures.add(f"{name}:stale_head")
            continue
        if not _source_matches(check):
            failures.add(f"{name}:source_mismatch")
            continue
        try:
            current_order = _run_order(check)
        except CheckRunGateError:
            failures.add(f"{name}:invalid_order")
            continue
        existing = latest.get(name)
        if existing is None or current_order > _run_order(existing):
            latest[name] = check

    selected = []
    for name in sorted(required_checks):
        check = latest.get(name)
        if check is None:
            failures.add(f"{name}:missing")
            continue
        selected.append(check)
        if check.get("status") != "completed":
            failures.add(f"{name}:pending")
        elif check.get("conclusion") != "success":
            failures.add(f"{name}:not_success")

    return {
        "head_sha": head_sha,
        "required": sorted(required_checks),
        "runs": [
            {
                "id": check["id"],
                "name": check["name"],
                "status": check.get("status"),
                "conclusion": check.get("conclusion"),
                "started_at": check.get("started_at"),
                "completed_at": check.get("completed_at"),
                "source": EXPECTED_SOURCE.copy(),
            }
            for check in selected
        ],
        "failures": sorted(failures),
        "passed": not failures,
    }


def list_required_check_runs(
    api_get, *, repository: str, head_sha: str
) -> list[object]:
    """Read every Check Run GitHub exposes for the exact head, fail closed on truncation."""

    check_runs = []
    total_count = None
    for page in range(1, MAX_CHECK_RUN_PAGES + 1):
        result = api_get(
            f"/repos/{repository}/commits/{head_sha}/check-runs?filter=all&per_page=100&page={page}"
        )
        if not isinstance(result, dict) or not isinstance(result.get("check_runs"), list):
            raise CheckRunGateError("required_checks_response_invalid")
        if total_count is None:
            total_count = result.get("total_count")
            if not isinstance(total_count, int) or total_count < 0:
                raise CheckRunGateError("required_checks_response_invalid")
        check_runs.extend(result["check_runs"])
        if len(result["check_runs"]) < 100:
            if len(check_runs) < total_count:
                raise CheckRunGateError("required_checks_response_truncated")
            return check_runs
    raise CheckRunGateError("required_checks_response_truncated")
