# Platform CI operations

## Required checks

After GOV-009, `main` and `release/**` require these exact check contexts:

- `policy-gate`
- `quality-gate`
- `security-gate`
- `integration-gate`
- `ci-gate`

`ci-gate` uses `needs` and succeeds only when the other four jobs succeed.
Cancelled and skipped dependency jobs are failures.

## Quality gate

The quality job validates PHP syntax, Composer manifest/lock consistency,
frontend lock installation, typecheck, exact ESLint findings, JSON, XML, YAML,
TOML, available OpenAPI/JSON Schema files, generated tracked output, and
whitespace.

The V1 ESLint baseline is exact and expires on 2026-08-31. It contains eight
errors and one warning. A new, changed, missing, or expired fingerprint fails.
The baseline does not apply to future V2 application paths.

## Security gate

The security job performs high-confidence secret and dangerous-path scans,
workflow permission and action-pin checks, credential-bearing remote detection,
dangerous workflow command checks, `.codex` safety checks, and Composer/pnpm
audits.

The dependency baseline is exact and expires on 2026-07-30. It is tracked by
`SEC-001`; new, removed, changed, worsened, or expired findings fail until the
baseline and locked dependencies are reviewed together.

## Integration gate

The integration job uses only ephemeral GitHub Actions PostgreSQL and Redis
services with non-production test credentials. It installs locked dependencies,
applies migrations to the empty test database, runs backend tests, builds and
typechecks the frontend, parses available contracts/manifests, validates Docker
Compose configuration, and rejects generated tracked changes.

It does not use production secrets, production data, or a production database.

## Local reproduction

```text
python3 -m unittest discover -s tests/ci/quality -p 'test_*.py'
python3 -m unittest discover -s tests/ci/security -p 'test_*.py'
python3 scripts/ci/quality_gate.py --repository .
cd frontend && pnpm typecheck
cd frontend && pnpm exec eslint . --format json
cd backend && composer validate --strict --no-check-publish
docker compose config --quiet
git diff --check
```

Full backend migration/test reproduction requires PHP 8.4 and an isolated
PostgreSQL test database. It must not run against production or an existing
unknown database.
