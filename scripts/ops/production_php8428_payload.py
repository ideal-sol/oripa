"""Canonical materialization of the unchanged PR715 limited payload design."""
import hashlib
import json
import os
from pathlib import Path
import runpy
import tempfile

DESIGN = runpy.run_path(str(Path(__file__).with_name("production_php8428_design.py")))
PREVIOUS = runpy.run_path(str(Path(__file__).with_name("production_pr705_payload.py")))
git = PREVIOUS["git"]
MANIFEST = DESIGN["MANIFEST"]
MANIFEST_DIGEST = DESIGN["DIGEST"]
SOURCE = "0254792e8dc0baafbef45c6f2ca861ee91cfa191"
CHANGE = "NEWPROD-PHP8428"


def specification(control):
    raw = git(control, "show", "HEAD:" + MANIFEST)
    if hashlib.sha256(raw).hexdigest() != MANIFEST_DIGEST:
        raise ValueError("PHP8428 specification digest mismatch")
    spec = json.loads(raw)
    previous = PREVIOUS["specification"](control)
    if (spec["existing_source"] != previous["source_sha"]
            or spec["existing_tree"] != previous["source_tree"]
            or spec["previous_manifest_sha256"] != PREVIOUS["MANIFEST_DIGEST"]
            or spec["source_sha"] != SOURCE or spec["build_authorized"]
            or spec["activation_authorized"]):
        raise ValueError("PHP8428 previous composition or boundary mismatch")
    for kind in ("base", "wallet", "fix"):
        for suffix in ("sha", "tree", "pr", "reviewed_sha"):
            spec[kind + "_" + suffix] = previous[kind + "_" + suffix]
    spec.update(php_sha="4fb1d80a5fca4d8f12829a26972aa2c9402a92c3", php_pr=715,
                php_reviewed_sha="3083d1278162ed5fc529c393600e3996b02fc120",
                php_tree="00abe579aade9353b90e86f8ba44c9edeccd71db")
    return spec


def materialize(control, payload, authority):
    spec = specification(control)
    if (authority.get("composition") != spec or authority.get("source_sha") != SOURCE
            or authority.get("source_tree") != spec["source_tree"]
            or authority.get("change_id") != CHANGE or authority.get("pr_number") != 715):
        raise ValueError("PHP8428 authority mismatch")
    # Existing design independently checks the exact immutable tree and inventory.
    if DESIGN["reproduce"](control)["source_sha"] != SOURCE:
        raise ValueError("PHP8428 design mismatch")
    previous = PREVIOUS["specification"](control)
    PREVIOUS["materialize"](control, payload, {"composition": previous,
        "source_sha": previous["source_sha"], "source_tree": previous["source_tree"],
        "change_id": PREVIOUS["CHANGE"], "pr_number": 705})
    with tempfile.TemporaryDirectory(prefix="php8428-index-") as temporary:
        env = dict(os.environ, GIT_INDEX_FILE=temporary + "/index")
        git(payload, "read-tree", spec["existing_source"], env=env)
        for path, identity in spec["files"].items():
            if git(payload, "rev-parse", spec["existing_source"] + ":" + path).decode().strip() != identity["before"]:
                raise ValueError("PHP8428 input blob mismatch")
            content = git(payload, "cat-file", "blob", identity["before"]).decode()
            for old, new in spec["replacements"].items():
                if content.count(old) != identity["replacement_counts"][old]:
                    raise ValueError("PHP8428 replacement count mismatch")
                content = content.replace(old, new)
            blob = git(payload, "hash-object", "-w", "--stdin", input=content.encode()).decode().strip()
            if blob != identity["after"]:
                raise ValueError("PHP8428 output blob mismatch")
            git(payload, "update-index", "--cacheinfo", "100644", blob, path, env=env)
        tree = git(payload, "write-tree", env=env).decode().strip()
    if (tree != spec["source_tree"]
            or hashlib.sha256(git(payload, "ls-tree", "-r", "-z", tree)).hexdigest() != spec["inventory_sha256"]
            or set(git(payload, "diff", "--name-only", spec["existing_source"], tree).decode().splitlines()) != set(spec["files"])):
        raise ValueError("PHP8428 tree or inventory mismatch")
    revision = git(payload, "hash-object", "-t", "commit", "-w", "--stdin", input=spec["commit_content"].encode()).decode().strip()
    if revision != SOURCE:
        raise ValueError("PHP8428 commit mismatch")
    git(payload, "checkout", "--detach", revision)
    if git(payload, "status", "--porcelain", "--untracked-files=all", "--ignored").strip():
        raise ValueError("PHP8428 materialized checkout mismatch")
