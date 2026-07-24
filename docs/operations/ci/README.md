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
YAML, TOML, Public／Admin／Webhook OpenAPI 3.1.1、deterministic Bundle、
Breaking Change、`@oripa/storefront-client`の生成差分／Typecheck／Lint／Build／
Unit Test、generated tracked output、whitespace。

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
`sharp`、`js-yaml` versions identified by a Fresh Audit. Its audit must remain at zero
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

## OpenAPI contract gate

`openapi_contract_gate.py`はRedocly `2.40.0`で3 SurfaceをLint／Bundleし、
Commit済みBundleとの差分を拒否する。Pull RequestではBase SHAの既存Bundleと
比較し、Path／Operation／Response／Schema削除、`operationId`／認証／冪等性／型の
変更、Required Field追加をBreaking Changeとして拒否する。

MIG-030は共通Primitiveと空の`paths`だけを作成する。業務Endpoint、Laravel Route、
業務Operationは後続のContract-first Taskまで検証済み扱いにしない。

## Storefront Client gate

`@oripa/storefront-client`はPublic OpenAPI Bundleだけから
`src/generated/public.ts`を決定的に生成する。`generate:check`は再生成結果と
Commit済みFileのByte差分を拒否し、Admin／Webhook SurfaceをExportしない。
MIG-031時点のPublic API Operationは0件であり、Fake Endpoint Methodは持たない。

`quality-gate`と`integration-gate`は、生成差分、Typecheck、Lint、Build、
Unit Testを実行する。Unit Testは`credentials: include`、Version Header、
Request ID、Timeout／AbortSignal、Idempotency-Key、RFC 9457 Problem Details、
安全なRequestだけのRetry、設定可能なCSRF初期化境界を検証する。

## Local reproduction

```text
python3 -m unittest discover -s tests/ci/quality -p 'test_*.py'
python3 -m unittest discover -s tests/ci/security -p 'test_*.py'
python3 -m unittest discover -s tests/db -p 'test_*.py'
python3 scripts/ci/quality_gate.py --repository .
pnpm install --frozen-lockfile
pnpm openapi:test
pnpm openapi:check
pnpm storefront:check
pnpm admin:typecheck
pnpm admin:lint
pnpm admin:build
pnpm --dir legacy/v1-frontend --ignore-workspace install --frozen-lockfile
pnpm --dir legacy/v1-frontend --ignore-workspace typecheck
cd legacy/v1-frontend && pnpm --ignore-workspace exec eslint . --format json
cd apps/api && composer validate --strict --no-check-publish
docker compose config --quiet
python3 scripts/db/v2_database.py validate \
  --repository . \
  --env-file /etc/oripa-v2/dev.env \
  --project oripa-v2-dev \
  --migration-path apps/api/database/migrations-v2
git diff --check
```

Full backend migration/test reproduction requires PHP 8.4 and an isolated
PostgreSQL test database. It must not run against production or an existing
unknown database.
