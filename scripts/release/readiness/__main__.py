"""Local JSON consumer. Evaluation outcomes never authorize or block operations."""

import argparse
import json
from pathlib import Path

from .evaluator import evaluate, unknown_record
from .records import RecordError, canonical, load


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    arguments = parser.parse_args()
    try:
        payload = load(arguments.input)
        record = evaluate(payload["candidate"], payload["snapshot"], payload["continuity"])
    except (RecordError, ValueError, TypeError, KeyError, OSError):
        record = unknown_record()
    with arguments.output.open("xb") as stream:
        stream.write(canonical(record))
    print(json.dumps({"shadow_final_status": record["shadow_final_status"], "production_impact": "NONE"}))


if __name__ == "__main__":
    main()
