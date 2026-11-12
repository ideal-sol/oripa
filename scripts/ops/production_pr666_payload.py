"""Reproduce the single approved Production PR666 payload, never a merged-source claim."""
import hashlib
import os
from pathlib import Path
import json
import subprocess
import tempfile


def git(repository, *args, **kwargs):
    return subprocess.check_output(["git", "-C", str(repository), *args], **kwargs)


MANIFEST = "manifests/old-test-pr666-payload.json"
MANIFEST_DIGEST = "d6d8bb82da2659db225f267f5562ca9348c1ed59c3bee673bb89c95b0832cfc8"
SOURCE = "cad8be03cbab8b7fba18825a4a9b2a276f402010"
CHANGE = "NEWPROD-PR666-20261105"
ADMIN_MANIFEST = "manifests/platform-production-admin-node-source.json"
ADMIN_MANIFEST_DIGEST = "ede7bd8c3dae821b1d513ee2af2db3d0c025b047b9583636e86133aec7a05509"
ADMIN_SOURCE = "2e9329da44a38101403ab721da1d4b90c353dbe3"
ADMIN_CHANGE = "NEWPROD-ADMIN-NODE-20261112"


def admin_specification(control):
    raw = git(control, "show", "HEAD:" + ADMIN_MANIFEST)
    if hashlib.sha256(raw).hexdigest() != ADMIN_MANIFEST_DIGEST:
        raise ValueError("production admin specification mismatch")
    return json.loads(raw)


def materialize_admin(control, payload, authority):
    spec = admin_specification(control)
    baseline = specification(control)
    if (authority.get("composition") != spec or authority.get("source_sha") != ADMIN_SOURCE
            or authority.get("change_id") != ADMIN_CHANGE or authority.get("pr_number") != 685
            or authority.get("source_tree") != spec["source_tree"]
            or authority.get("image_mode") != "admin-only"
            or authority.get("architecture") != "arm64"):
        raise ValueError("production admin authority mismatch")
    materialize(control, payload, {"composition": baseline, "source_sha": SOURCE,
                "source_tree": baseline["source_tree"], "change_id": CHANGE, "pr_number": 666})
    with tempfile.TemporaryDirectory(prefix="admin-node-index-") as temporary:
        env = dict(os.environ, GIT_INDEX_FILE=temporary + "/index")
        git(payload, "read-tree", SOURCE, env=env)
        for path, blobs in spec["files"].items():
            for repository, revision, expected in ((payload, SOURCE, blobs["before"]),
                    (control, spec["node_sha"], blobs["after"])):
                if git(repository, "ls-tree", revision, "--", path).decode().strip() != f"100644 blob {expected}\t{path}":
                    raise ValueError("admin node blob mismatch")
            before = git(payload, "show", SOURCE + ":" + path)
            after = git(control, "cat-file", "blob", blobs["after"])
            if path == "apps/admin/Dockerfile":
                expected = before.replace(
                    b"node:22.22.3-alpine@sha256:e58326d0d441090181ac150dc2078d3e2cf6a0d42e809aebba3ef5880935ffdd",
                    b"node:22.23.5-alpine@sha256:1314fb30ec37592d5845e5a160d522c0e680972aa534549940b8d73bde88481a")
            else:
                expected = before.replace(b'"node": "22.22.3"', b'"node": "22.23.5"')
            if after != expected or after == before:
                raise ValueError("non-node admin input change")
            git(payload, "hash-object", "-w", "--stdin", input=after)
            git(payload, "update-index", "--cacheinfo", "100644", blobs["after"], path, env=env)
        tree = git(payload, "write-tree", env=env).decode().strip()
    changed = git(payload, "diff", "--name-only", SOURCE, tree).decode().splitlines()
    if (tree != spec["source_tree"] or sorted(changed) != sorted(spec["files"])
            or hashlib.sha256(git(payload, "ls-tree", "-r", "-z", tree)).hexdigest() != spec["inventory_sha256"]):
        raise ValueError("admin node derived tree mismatch")
    revision = git(payload, "hash-object", "-t", "commit", "-w", "--stdin",
                   input=spec["commit_content"].encode()).decode().strip()
    if revision != ADMIN_SOURCE:
        raise ValueError("admin node derived commit mismatch")
    git(payload, "checkout", "--detach", revision)
    if git(payload, "status", "--porcelain", "--untracked-files=all", "--ignored").strip():
        raise ValueError("admin node materialized checkout mismatch")


def specification(control):
    raw = git(control, "show", "HEAD:" + MANIFEST)
    if hashlib.sha256(raw).hexdigest() != MANIFEST_DIGEST:
        raise ValueError("production pr666 specification mismatch")
    return json.loads(raw)


def materialize(control: Path, payload: Path, authority: dict) -> None:
    spec = specification(control)
    if (authority.get("composition") != spec or authority.get("source_sha") != SOURCE
            or authority.get("change_id") != CHANGE or authority.get("pr_number") != 666
            or authority.get("source_tree") != spec["source_tree"]):
        raise ValueError("production pr666 authority mismatch")
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
