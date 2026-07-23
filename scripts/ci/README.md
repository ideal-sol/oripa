# Platform CI scripts

## Policy gate

`policy_gate.py` uses only the Python standard library. It checks repository
governance on pull requests, pushes to `main`, and manual workflow runs.

Local reproduction:

```text
python3 -m unittest discover -s tests/ci/policy -p 'test_*.py'
python3 scripts/ci/policy_gate.py --repository .
git diff --check
```

For pull request metadata validation, GitHub Actions supplies
`POLICY_EVENT_NAME=pull_request` and `POLICY_EVENT_PATH`. The script reads the
event JSON directly. It does not interpolate pull request text into a shell.

The bootstrap `ci-gate` depends on `policy-gate` and rejects failed, cancelled,
or skipped dependency results. GOV-009 replaces this bootstrap aggregation with
the complete policy, quality, security, and integration gate set.

## Dependency policy

The gate does not install packages or use repository secrets. Workflow actions
are official actions pinned to immutable full commit SHAs. Basic YAML and TOML
checks are deliberately dependency-free; GitHub also parses the workflow before
it can schedule the jobs.
