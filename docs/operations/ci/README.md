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
the Root V2 Workspace and independent Legacy lock installations, V2 Admin
typecheck/lint/build, Legacy typecheck and exact ESLint findings, JSON, XML,
YAML, TOML, available OpenAPI/JSON Schema files, generated tracked output, and
whitespace.

The V1 ESLint baseline is exact and expires on 2026-08-31. It contains eight
errors and one warning. A new, changed, missing, or expired fingerprint fails.
The baseline does not apply to future V2 application paths.

The V1 backend test baseline is exact and expires on 2026-08-15. It contains
two `AdminPaymentApiTest` fixture failures. The current refund and chargeback
behavior requires payment-origin point lots and a wallet, while these two
legacy fixtures create only a succeeded payment. A new, changed, missing, or
expired failure fails. The baseline is removed when `QUALITY-002` updates the
fixtures without weakening the approved payment behavior.

## Security gate

The security job performs high-confidence secret and dangerous-path scans,
workflow permission and action-pin checks, credential-bearing remote detection,
dangerous workflow command checks, `.codex` safety checks, and Composer/pnpm
audits.

The Legacy dependency baseline is exact and expires on 2026-07-30. It is tracked by
`SEC-001`; new, removed, changed, worsened, or expired findings fail until the
baseline and locked dependencies are reviewed together.

The V2 Root Workspace uses exact patched overrides for transitive `postcss` and
`sharp` versions identified by a Fresh Audit. Its audit must remain at zero
findings and cannot inherit or extend the V1 baseline.

## Integration gate

The integration job uses only ephemeral GitHub Actions PostgreSQL and Redis
services with non-production test credentials. It installs both Root and Legacy
locked dependencies, applies migrations to the empty test database, runs backend
tests, builds and typechecks the Legacy Frontend and V2 Admin, parses available
contracts/manifests, validates V1/V2 Docker Compose configuration, starts the V2
Skeleton, verifies API/Admin Health, destroys its Compose project and volumes,
and rejects generated tracked changes.

It does not use production secrets, production data, or a production database.
Known backend failures are evaluated only through the exact, expiring baseline;
the complete backend suite still runs on every integration job.

## Local reproduction

```text
python3 -m unittest discover -s tests/ci/quality -p 'test_*.py'
python3 -m unittest discover -s tests/ci/security -p 'test_*.py'
python3 scripts/ci/quality_gate.py --repository .
pnpm install --frozen-lockfile
pnpm admin:typecheck
pnpm admin:lint
pnpm admin:build
pnpm --dir legacy/v1-frontend --ignore-workspace install --frozen-lockfile
pnpm --dir legacy/v1-frontend --ignore-workspace typecheck
cd legacy/v1-frontend && pnpm --ignore-workspace exec eslint . --format json
cd apps/api && composer validate --strict --no-check-publish
docker compose config --quiet
docker compose -f docker-compose.v2.yml config --quiet
git diff --check
```

Full backend migration/test reproduction requires PHP 8.4 and an isolated
PostgreSQL test database. It must not run against production or an existing
unknown database.
