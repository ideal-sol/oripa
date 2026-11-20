"""Reproduce a draft Source identity; grants no Build or Activation authority."""
import hashlib
import json
import os
from pathlib import Path
import runpy
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]
MANIFEST = "manifests/production-php8428-payload-design.json"
DIGEST = "5ed92be79c7046ffdd9baf213efa6e3524fabc517c0de5587110146b9d8512c6"


def reproduce(control=ROOT):
    raw = (control / MANIFEST).read_bytes()
    if hashlib.sha256(raw).hexdigest() != DIGEST:
        raise ValueError("draft specification digest mismatch")
    spec = json.loads(raw)
    previous = runpy.run_path(str(control / "scripts/ops/production_pr705_payload.py"))
    prior = previous["specification"](control)
    if (prior["source_sha"] != spec["existing_source"]
            or prior["source_tree"] != spec["existing_tree"]
            or previous["MANIFEST_DIGEST"] != spec["previous_manifest_sha256"]
            or spec["activation_authorized"] or spec["build_authorized"]):
        raise ValueError("previous Source or authorization mismatch")
    with tempfile.TemporaryDirectory(prefix="php8428-design-") as directory:
        payload = Path(directory) / "payload"
        subprocess.run(["git", "clone", "-q", "--shared", "--no-checkout", str(control), str(payload)], check=True)
        git = lambda *args, **kw: subprocess.check_output(["git", "-C", str(payload), *args], **kw)
        git("checkout", "-q", "--detach", prior["base_sha"])
        previous["materialize"](control, payload, {
            "composition": prior, "source_sha": prior["source_sha"],
            "source_tree": prior["source_tree"], "change_id": previous["CHANGE"], "pr_number": 705})
        env = dict(os.environ, GIT_INDEX_FILE=str(Path(directory) / "index"))
        git("read-tree", spec["existing_source"], env=env)
        for path, identity in spec["files"].items():
            if git("rev-parse", spec["existing_source"] + ":" + path).decode().strip() != identity["before"]:
                raise ValueError("input blob mismatch")
            content = git("cat-file", "blob", identity["before"]).decode()
            for old, new in spec["replacements"].items():
                if content.count(old) != identity["replacement_counts"][old]:
                    raise ValueError("replacement count mismatch")
                content = content.replace(old, new)
            blob = git("hash-object", "-w", "--stdin", input=content.encode()).decode().strip()
            if blob != identity["after"]:
                raise ValueError("output blob mismatch")
            git("update-index", "--cacheinfo", "100644", blob, path, env=env)
        tree = git("write-tree", env=env).decode().strip()
        if (tree != spec["source_tree"]
                or hashlib.sha256(git("ls-tree", "-r", "-z", tree)).hexdigest() != spec["inventory_sha256"]
                or set(git("diff", "--name-only", spec["existing_source"], tree).decode().splitlines()) != set(spec["files"])):
            raise ValueError("limited tree or inventory mismatch")
        source = git("hash-object", "-t", "commit", "-w", "--stdin", input=spec["commit_content"].encode()).decode().strip()
        if source != spec["source_sha"]:
            raise ValueError("derived commit mismatch")
        return {"source_sha": source, "source_tree": tree, "inventory_sha256": spec["inventory_sha256"],
                "changed_paths": sorted(spec["files"]), "build_authorized": False, "activation_authorized": False}


if __name__ == "__main__":
    print(json.dumps(reproduce(), indent=2))
