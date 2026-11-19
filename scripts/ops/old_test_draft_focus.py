#!/usr/bin/env python3
"""Execute only main-approved focused commands, without the authorization token."""
import json
import os
from pathlib import Path
import subprocess
import runpy
import tempfile


def verify_contracts(authority, payload, control, environment, gate):
    """Use the existing control-plane validator, with an explicit immutable base."""
    with tempfile.TemporaryDirectory(prefix="draft-contract-") as temporary:
        generated = gate["generate_bundles"](payload, Path(temporary))
        # workflow_dispatch has no PR event base; never let that disable comparison.
        gate["validate_generated"].__globals__["event_base_sha"] = lambda: None
        gate["validate_generated"](payload, generated, True, False)
        for surface, filename in generated.items():
            previous = gate["previous_bundle"](control, authority["plan"]["baseline_sha"],
                                                gate["SURFACES"][surface]["bundle"])
            current = json.loads(filename.read_text())
            if previous is None or gate["breaking_changes"](previous, current):
                raise ValueError("draft_contract_incompatible:" + surface)
            if (previous.get("security") != current.get("security")
                    or previous.get("components", {}).get("securitySchemes") != current.get("components", {}).get("securitySchemes")):
                raise ValueError("draft_contract_security_boundary:" + surface)
            old_operations = {(p, m): op for p, m, op in gate["iter_operations"](previous)}
            for path, method, operation in gate["iter_operations"](current):
                old = old_operations.get((path, method))
                if old is not None and old.get("security") != operation.get("security"):
                    raise ValueError("draft_contract_security_boundary:" + surface)
    paths = authority["contract_paths"]
    if any("admin" in path for path in paths):
        subprocess.run(["node", "apps/admin/scripts/generate-admin-contract.mjs", "--check"],
                       cwd=payload, env=environment, check=True)
    if any("public" in path or "storefront-client" in path for path in paths):
        subprocess.run(["pnpm", "--dir", "packages/storefront-client", "generate:check"],
                       cwd=payload, env=environment, check=True)


def main():
    authority = json.loads((Path(os.environ["RUNNER_TEMP"]) / "source-authority.json").read_text())
    environment = {key: value for key, value in os.environ.items()
                   if key not in {"GH_TOKEN", "GITHUB_TOKEN"} and not key.startswith("INPUT_")}
    control = Path(__file__).resolve().parents[2]
    # Load trusted comparison code before executing any feature commands.
    gate = (runpy.run_path(str(control / "scripts/ci/openapi_contract_gate.py"))
            if authority.get("contract_paths") else None)
    for command in authority["plan"]["focused_commands"]:
        subprocess.run(command, check=True, env=environment)
    if gate:
        verify_contracts(authority, Path.cwd(), control, environment, gate)


if __name__ == "__main__":
    main()
