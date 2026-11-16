"""Exact PR692-only Production composition; no arbitrary source inputs."""
import hashlib
import os
from pathlib import Path
import json
import subprocess
import tempfile

def git(repository, *args, **kwargs):
    return subprocess.check_output(["git", "-C", str(repository), *args], **kwargs)

MANIFEST = "manifests/production-pr692-payload.json"
MANIFEST_DIGEST = "9b7fe6debf645b33e112d8cef92f2f0a3b074dcdad56fbb9d89e3cf1e3949c1d"
SOURCE = "516fe7a946683103ed68ee4875d5ea3d94327a0d"
CHANGE = "NEWPROD-PR692-AUTHORITY"
def specification(control):
    raw = git(control, "show", "HEAD:" + MANIFEST)
    if hashlib.sha256(raw).hexdigest() != MANIFEST_DIGEST:
        raise ValueError("production pr692 specification mismatch")
    return json.loads(raw)


def materialize(control: Path, payload: Path, authority: dict) -> None:
    spec = specification(control)
    if (authority.get("composition") != spec or authority.get("source_sha") != SOURCE
            or authority.get("change_id") != CHANGE or authority.get("pr_number") != 692
            or authority.get("source_tree") != spec["source_tree"]):
        raise ValueError("production pr692 authority mismatch")
    if git(payload, "rev-parse", "HEAD").decode().strip() != spec["base_sha"]:
        raise ValueError("pr692 base mismatch")
    if git(payload, "status", "--porcelain", "--untracked-files=all", "--ignored").strip():
        raise ValueError("pr692 payload must be pristine")
    parent = git(control, "rev-parse", spec["fix_sha"] + "^").decode().strip()
    for kind in ("base", "fix"):
        if git(control, "rev-parse", spec[kind + "_sha"] + "^{tree}").decode().strip() != spec[kind + "_tree"]:
            raise ValueError("pr692 merged tree mismatch")

    with tempfile.TemporaryDirectory(prefix="pr692-index-") as temporary:
        env = dict(os.environ, GIT_INDEX_FILE=temporary + "/index")
        git(payload, "read-tree", spec["base_sha"], env=env)
        for path, blobs in spec["files"].items():
            for repository, ref, expected in ((payload, spec["base_sha"], blobs["before"]),
                    (control, parent, blobs["before"]), (control, spec["fix_sha"], blobs["after"])):
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
    if git(payload, "diff", "--numstat", spec["base_sha"], tree).decode().strip() != "5\t1\t" + next(iter(spec["files"])):
        raise ValueError("pr692 single hunk boundary mismatch")
    revision = git(payload, "hash-object", "-t", "commit", "-w", "--stdin",
                   input=spec["commit_content"].encode()).decode().strip()
    if revision != spec["source_sha"]:
        raise ValueError("pr692 derived commit mismatch")
    # Only this disposable, initially clean build checkout is changed. No shared ref.
    git(payload, "checkout", "--detach", revision)
    if git(payload, "status", "--porcelain", "--untracked-files=all", "--ignored").strip():
        raise ValueError("pr692 materialized checkout mismatch")
