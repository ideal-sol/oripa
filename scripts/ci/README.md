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

The `ci-gate` depends on policy, quality, security, and integration jobs and
rejects failed, cancelled, or skipped dependency results.

## Quality, security, and integration

- `quality_gate.py` validates tracked source and structured file quality.
- `openapi_contract_gate.py` lints and bundles the Public／Admin／Webhook
  OpenAPI 3.1.1 Contract、Commit済みBundle差分、Breaking Changeを検査する。
- `lint_baseline.py` requires an exact, unexpired ESLint fingerprint set.
- `backend_test_baseline.py` requires an exact, unexpired PHPUnit failure set.
- `security_gate.py` performs repository security checks and requires exact,
  unexpired Composer and pnpm advisory baselines.

Operational commands and baseline policy are documented in
`docs/operations/ci/README.md`.

OpenAPI ContractのLocal再現:

```text
pnpm openapi:test
pnpm openapi:check
```

Site Schema AlphaのLocal再現:

```text
pnpm install --frozen-lockfile
pnpm site-schema:check
```

`site-schema:check`はJSON SchemaからのType再生成差分、Typecheck、Lint、Build、
Positive／Negative Fixture、SemVer、Compatibility Family、Required Capability、
Unknown Field、Secret風Field混入を検査する。

## Dependency policy

The gate does not install packages or use repository secrets. Workflow actions
are official actions pinned to immutable full commit SHAs. Basic YAML and TOML
checks are deliberately dependency-free; GitHub also parses the workflow before
it can schedule the jobs.
