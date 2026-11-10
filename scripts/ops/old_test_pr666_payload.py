"""Reproduce one explicitly derived OLD Test payload, never a merged-source claim."""
import hashlib
import os
from pathlib import Path
import runpy
import subprocess
import tempfile


def git(repository, *args, **kwargs):
    return subprocess.check_output(["git", "-C", str(repository), *args], **kwargs)


def materialize(control: Path, payload: Path, authority: dict) -> None:
    wrapper = runpy.run_path(str(control / "infrastructure/github-app/oripa-github-app-api"))
    spec = wrapper["pr666_manifest"]((control / wrapper["PR666_MANIFEST"]).read_bytes())
    if (authority.get("composition") != spec or authority.get("source_sha") != spec["source_sha"]
            or authority.get("task_id") != spec["task_id"] or authority.get("source_pr") != 666
            or authority.get("payload_kind") != spec["kind"] or authority.get("image_mode") != "api-only"
            or authority.get("source_tree") != spec["source_tree"]):
        raise ValueError("pr666 authority mismatch")
    if git(payload, "rev-parse", "HEAD").decode().strip() != spec["base_sha"]:
        raise ValueError("pr666 base mismatch")
    if git(payload, "status", "--porcelain", "--untracked-files=all", "--ignored").strip():
        raise ValueError("pr666 payload must be pristine")
    parent = git(control, "rev-parse", spec["fix_sha"] + "^").decode().strip()
    with tempfile.TemporaryDirectory(prefix="pr666-index-") as temporary:
        env = dict(os.environ, GIT_INDEX_FILE=temporary + "/index")
        git(payload, "read-tree", spec["base_sha"], env=env)
        for path, blobs in spec["files"].items():
            for repository, ref, expected in ((payload, spec["base_sha"], blobs["before"]),
                    (control, parent, blobs["before"]), (control, spec["fix_sha"], blobs["after"])):
                actual = git(repository, "ls-tree", ref, "--", path).decode().strip()
                if actual != f"100644 blob {expected}\t{path}":
                    raise ValueError("pr666 blob mismatch")
            content = git(control, "cat-file", "blob", blobs["after"])
            if git(payload, "hash-object", "-w", "--stdin", input=content).decode().strip() != blobs["after"]:
                raise ValueError("pr666 content mismatch")
            git(payload, "update-index", "--cacheinfo", "100644", blobs["after"], path, env=env)
        tree = git(payload, "write-tree", env=env).decode().strip()
    inventory = git(payload, "ls-tree", "-r", "-z", tree)
    changed = git(payload, "diff", "--name-only", spec["base_sha"], tree).decode().splitlines()
    if (tree != spec["source_tree"] or hashlib.sha256(inventory).hexdigest() != spec["inventory_sha256"]
            or sorted(changed) != sorted(spec["files"])):
        raise ValueError("pr666 derived tree mismatch")
    revision = git(payload, "hash-object", "-t", "commit", "-w", "--stdin",
                   input=spec["commit_content"].encode()).decode().strip()
    if revision != spec["source_sha"]:
        raise ValueError("pr666 derived commit mismatch")
    # Only this disposable, initially clean build checkout is changed. No shared ref.
    git(payload, "checkout", "--detach", revision)
    if git(payload, "status", "--porcelain", "--untracked-files=all", "--ignored").strip():
        raise ValueError("pr666 materialized checkout mismatch")
