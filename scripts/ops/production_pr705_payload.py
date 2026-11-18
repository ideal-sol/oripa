"""Exact PR705-only Production composition; no arbitrary source inputs."""
import hashlib
import os
from pathlib import Path
import json
import subprocess
import tempfile
import runpy

PREVIOUS = runpy.run_path(str(Path(__file__).resolve().with_name("production_pr692_payload.py")))

def git(repository, *args, **kwargs):
    return subprocess.check_output(["git", "-C", str(repository), *args], **kwargs)

MANIFEST = "manifests/production-pr705-payload.json"
MANIFEST_DIGEST = "3f4fbd4ef3b98a72fb7ea700ef2a1a1489997d3f340a5c7f9442d4af02da46e8"
SOURCE = "42cac7fd8e176b8686fedffc7673734036bf670e"
CHANGE = "NEWPROD-DRAWTZ-20261118"
def specification(control):
    raw = git(control, "show", "HEAD:" + MANIFEST)
    if hashlib.sha256(raw).hexdigest() != MANIFEST_DIGEST:
        raise ValueError("production pr705 specification mismatch")
    return json.loads(raw)


def materialize(control: Path, payload: Path, authority: dict) -> None:
    spec = specification(control)
    if (authority.get("composition") != spec or authority.get("source_sha") != SOURCE
            or authority.get("change_id") != CHANGE or authority.get("pr_number") != 705
            or authority.get("source_tree") != spec["source_tree"]):
        raise ValueError("production pr705 authority mismatch")
    if git(payload, "rev-parse", "HEAD").decode().strip() != spec["base_sha"]:
        raise ValueError("pr705 base mismatch")
    if git(payload, "status", "--porcelain", "--untracked-files=all", "--ignored").strip():
        raise ValueError("pr705 payload must be pristine")
    previous = PREVIOUS["specification"](control)
    if (spec["existing_manifest_sha256"] != PREVIOUS["MANIFEST_DIGEST"]
            or spec["existing_source"] != previous["source_sha"]
            or spec["existing_tree"] != previous["source_tree"]
            or spec["wallet_files"] != previous["files"]):
        raise ValueError("pr705 previous composition mismatch")
    PREVIOUS["materialize"](control, payload, {"composition": previous,
        "source_sha": previous["source_sha"], "source_tree": previous["source_tree"],
        "change_id": PREVIOUS["CHANGE"], "pr_number": 692})
    parent = git(control, "rev-parse", spec["fix_sha"] + "^").decode().strip()
    for kind in ("base", "wallet", "fix"):
        if git(control, "rev-parse", spec[kind + "_sha"] + "^{tree}").decode().strip() != spec[kind + "_tree"]:
            raise ValueError("pr705 merged tree mismatch")

    with tempfile.TemporaryDirectory(prefix="pr705-index-") as temporary:
        env = dict(os.environ, GIT_INDEX_FILE=temporary + "/index")
        git(payload, "read-tree", spec["existing_source"], env=env)
        for path, blobs in spec["files"].items():
            for repository, ref, expected in ((payload, spec["existing_source"], blobs["before"]),
                    (control, parent, blobs["before"]), (control, spec["fix_sha"], blobs["after"])):
                actual = git(repository, "ls-tree", ref, "--", path).decode().strip()
                if actual != f"100644 blob {expected}\t{path}":
                    raise ValueError("pr705 blob mismatch")
            content = git(control, "cat-file", "blob", blobs["after"])
            if git(payload, "hash-object", "-w", "--stdin", input=content).decode().strip() != blobs["after"]:
                raise ValueError("pr705 content mismatch")
            git(payload, "update-index", "--cacheinfo", "100644", blobs["after"], path, env=env)
        tree = git(payload, "write-tree", env=env).decode().strip()
    inventory = git(payload, "ls-tree", "-r", "-z", tree)
    changed = git(payload, "diff", "--name-only", spec["existing_source"], tree).decode().splitlines()
    if (tree != spec["source_tree"] or hashlib.sha256(inventory).hexdigest() != spec["inventory_sha256"]
            or sorted(changed) != sorted(spec["files"])):
        raise ValueError("pr705 derived tree mismatch")
    migrations = git(payload, "ls-tree", "-r", "--name-only", tree, "apps/api/database/migrations-v2").decode().splitlines()
    if {Path(p).name.split("_")[3] for p in migrations if p.endswith(".php")} != {str(n).zfill(6) for n in range(1, 108)}:
        raise ValueError("pr705 Ledger107 source boundary mismatch")
    if git(payload, "diff", "--numstat", spec["existing_source"], tree).decode().strip() != "3\t1\t" + next(iter(spec["files"])):
        raise ValueError("pr705 single hunk boundary mismatch")
    # Reject even a same-size alternative hunk; preserve the PR692 fix exactly.
    for wallet_path, blobs in spec["wallet_files"].items():
        if git(payload, "rev-parse", tree + ":" + wallet_path).decode().strip() != blobs["after"]:
            raise ValueError("pr705 wallet fix lost")
    for file_path, blobs in spec["files"].items():
        original = git(payload, "cat-file", "blob", blobs["before"])
        approved = git(payload, "cat-file", "blob", blobs["after"])
        old_line = b"        $registeredAt = CarbonImmutable::parse((string) $user->created_at);"
        new_line = b"        $registeredAt = $user->created_at instanceof \\DateTimeInterface\n            ? CarbonImmutable::instance($user->created_at)\n            : CarbonImmutable::parse((string) $user->created_at);"
        if original.count(old_line) != 1 or original.replace(old_line, new_line) != approved:
            raise ValueError("pr705 exact hunk mismatch")
    revision = git(payload, "hash-object", "-t", "commit", "-w", "--stdin",
                   input=spec["commit_content"].encode()).decode().strip()
    if revision != spec["source_sha"]:
        raise ValueError("pr705 derived commit mismatch")
    # Only this disposable, initially clean build checkout is changed. No shared ref.
    git(payload, "checkout", "--detach", revision)
    if git(payload, "status", "--porcelain", "--untracked-files=all", "--ignored").strip():
        raise ValueError("pr705 materialized checkout mismatch")
