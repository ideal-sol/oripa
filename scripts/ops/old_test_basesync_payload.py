"""Exact OLD Test PR688/692 composition; no arbitrary source inputs."""
import hashlib
import os
from pathlib import Path
import json
import subprocess
import tempfile

def git(repository, *args, **kwargs):
    return subprocess.check_output(["git", "-C", str(repository), *args], **kwargs)

MANIFEST = "manifests/old-test-basesync-payload.json"
MANIFEST_DIGEST = "241ccc26a6d1a7c168170c549dda83525fee50d7393a1f1ff5bbc5ca1fe1ac98"
SOURCE = "c87d956804fbb1f31b9959535122e047155786fd"
CHANGE = "OLDTEST-BASESYNC-20261116"
def specification(control):
    raw = (control / MANIFEST).read_bytes()
    if hashlib.sha256(raw).hexdigest() != MANIFEST_DIGEST:
        raise ValueError("old-test basesync specification mismatch")
    return json.loads(raw)


def materialize(control: Path, payload: Path, authority: dict) -> None:
    spec = specification(control)
    if (authority.get("composition") != spec or authority.get("source_sha") != SOURCE
            or authority.get("task_id") != CHANGE or authority.get("source_pr") != 692
            or authority.get("target") != "old-test" or authority.get("platform") != "linux/amd64"
            or authority.get("image_mode") != "api-only" or authority.get("payload_kind") != spec["kind"]
            or authority.get("source_tree") != spec["source_tree"]):
        raise ValueError("production pr692 authority mismatch")
    if git(payload, "rev-parse", "HEAD").decode().strip() != spec["base_sha"]:
        raise ValueError("pr692 base mismatch")
    if git(payload, "status", "--porcelain", "--untracked-files=all", "--ignored").strip():
        raise ValueError("pr692 payload must be pristine")
    for kind in ("base", "line", "fix"):
        if git(control, "rev-parse", spec[kind + "_sha"] + "^{tree}").decode().strip() != spec[kind + "_tree"]:
            raise ValueError("pr692 merged tree mismatch")

    with tempfile.TemporaryDirectory(prefix="pr692-index-") as temporary:
        env = dict(os.environ, GIT_INDEX_FILE=temporary + "/index")
        git(payload, "read-tree", spec["base_sha"], env=env)
        for path, blobs in spec["files"].items():
            fix = spec[blobs["fix"] + "_sha"]
            parent = git(control, "rev-parse", fix + "^").decode().strip()
            for repository, ref, expected in ((payload, spec["base_sha"], blobs["before"]),
                    (control, parent, blobs["before"]), (control, fix, blobs["after"])):
                actual = git(repository, "ls-tree", ref, "--", path).decode().strip()
                if actual != f"100644 blob {expected}\t{path}":
                    raise ValueError("pr692 blob mismatch")
            content = git(control, "cat-file", "blob", blobs["after"])
            if git(payload, "hash-object", "-w", "--stdin", input=content).decode().strip() != blobs["after"]:
                raise ValueError("pr692 content mismatch")
            git(payload, "update-index", "--cacheinfo", "100644", blobs["after"], path, env=env)
        tree = git(payload, "write-tree", env=env).decode().strip()
    inventory = git(payload, "ls-tree", "-r", "-z", tree)
    changed = git(payload, "diff", "--name-only", spec["base_sha"], tree).decode().splitlines()
    if (tree != spec["source_tree"] or hashlib.sha256(inventory).hexdigest() != spec["inventory_sha256"]
            or sorted(changed) != sorted(spec["files"])):
        raise ValueError("pr692 derived tree mismatch")
    migrations = git(payload, "ls-tree", "-r", "--name-only", tree, "apps/api/database/migrations-v2").decode().splitlines()
    if {Path(p).name.split("_")[3] for p in migrations if p.endswith(".php")} != {str(n).zfill(6) for n in range(1, 108)}:
        raise ValueError("pr692 Ledger107 source boundary mismatch")
    expected = ["2\t1\t" + list(spec["files"])[0], "5\t1\t" + list(spec["files"])[1]]
    if git(payload, "diff", "--numstat", spec["base_sha"], tree).decode().splitlines() != expected:
        raise ValueError("basesync two hunk boundary mismatch")
    revision = git(payload, "hash-object", "-t", "commit", "-w", "--stdin",
                   input=spec["commit_content"].encode()).decode().strip()
    if revision != spec["source_sha"]:
        raise ValueError("pr692 derived commit mismatch")
    # Only this disposable, initially clean build checkout is changed. No shared ref.
    git(payload, "checkout", "--detach", revision)
    if git(payload, "status", "--porcelain", "--untracked-files=all", "--ignored").strip():
        raise ValueError("pr692 materialized checkout mismatch")
