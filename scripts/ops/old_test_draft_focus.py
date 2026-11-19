#!/usr/bin/env python3
"""Execute only main-approved focused commands, without the authorization token."""
import json
import os
from pathlib import Path
import subprocess

authority = json.loads((Path(os.environ["RUNNER_TEMP"]) / "source-authority.json").read_text())
environment = {key: value for key, value in os.environ.items()
               if key not in {"GH_TOKEN", "GITHUB_TOKEN"} and not key.startswith("INPUT_")}
for command in authority["plan"]["focused_commands"]:
    subprocess.run(command, check=True, env=environment)
