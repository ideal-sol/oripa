#!/usr/bin/env python3
"""Validate OLD Test payload authority using the artifact retrieval validator."""

from __future__ import annotations

import json
import os
from pathlib import Path
import runpy
import subprocess
import urllib.request


ROOT = Path(__file__).resolve().parents[2]
WRAPPER = runpy.run_path(str(ROOT / "infrastructure/github-app/oripa-github-app-api"))


def api_get(path: str) -> dict:
    if not path.startswith("/repos/ideal-sol/oripa/"):
        raise ValueError("repository mismatch")
    request = urllib.request.Request(
        "https://api.github.com" + path,
        headers={
            "Accept": "application/vnd.github+json",
            "Authorization": "Bearer " + os.environ["GH_TOKEN"],
            "X-GitHub-Api-Version": "2022-11-28",
        },
    )
    with urllib.request.urlopen(request, timeout=30) as response:
        return json.load(response)


def main() -> None:
    if (
        os.environ.get("GITHUB_REPOSITORY") != "ideal-sol/oripa"
        or os.environ.get("GITHUB_REF") != "refs/heads/main"
        or os.environ.get("GITHUB_EVENT_NAME") != "workflow_dispatch"
    ):
        raise ValueError("protected-main dispatch required")
    control_sha = os.environ["GITHUB_SHA"]
    checked_out = subprocess.check_output(
        ["git", "-C", str(ROOT), "rev-parse", "HEAD"], text=True,
    ).strip()
    if checked_out != control_sha:
        raise ValueError("control checkout mismatch")
    authority = WRAPPER["authorize_old_test_source"](
        os.environ["INPUT_TASK_ID"], os.environ["INPUT_PR_NUMBER"],
        os.environ["INPUT_SOURCE_SHA"], control_sha, get=api_get,
    )
    subprocess.run(
        ["git", "-C", str(ROOT), "merge-base", "--is-ancestor",
         authority["source_sha"], control_sha], check=True,
    )
    authority.update(run_id=int(os.environ["GITHUB_RUN_ID"]),
                     run_attempt=int(os.environ["GITHUB_RUN_ATTEMPT"]))
    destination = Path(os.environ["RUNNER_TEMP"]) / "source-authority.json"
    destination.write_text(json.dumps(authority, sort_keys=True, indent=2) + "\n")


if __name__ == "__main__":
    try:
        main()
    except Exception:
        raise SystemExit("old-test source authority validation failed") from None
