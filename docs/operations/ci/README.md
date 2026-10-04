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

GOV-017 keeps all five exact contexts and resolves Lane in `policy-gate`.
Missing/invalid metadata, an unknown path, or a Lane below the changed-path
minimum fails closed. Push and manual events without PR metadata use Strict.

- Lite runs changed-path validation, focused quality checks, an added-diff
  high-confidence secret scan, final-head review, and targeted UI confirmation
  when applicable. Its `integration-gate` succeeds only after policy proves the
  full suite is not applicable.
- Standard runs normal quality/security CI and the full current integration
  suite, plus Task-focused affected-domain verification.
- Strict runs the same full quality, security, and integration work required
  before GOV-017. The required aggregator cannot pass if that work is skipped.

CodeQL and Dependency Review remain available workflows but are not Required
Check contexts for Lite unless a higher Lane or another policy requires them.

## Quality gate

The quality job validates PHP syntax, Composer manifest/lock consistency,
the Root V2 Workspace and independent Legacy lock installations, V2 Admin
typecheck/lint/build, Legacy typecheck and approved ESLint fingerprints, JSON, XML,
YAML, TOML, Public／Admin／Webhook OpenAPI 3.1.1、deterministic Bundle、
Breaking Change、`@oripa/storefront-client`の生成差分／Typecheck／Lint／Build／
Unit Test、generated tracked output、whitespace。

The V1 ESLint baseline contains eight errors and one warning. Every normal CI
collects fresh ESLint JSON and compares exact fingerprints. New fingerprints,
including changed severity, rule, message, or location, fail. Missing approved
findings are resolved improvements and do not fail; the summary reports current,
known, new, and resolved counts. The baseline does not apply to V2 paths.

`QUALITY-002` removed the expired V1 backend failure baseline after updating the
two `AdminPaymentApiTest` fixtures with the required payment-origin point lots
and wallet state. The integration job now requires the complete backend suite
to exit successfully; no backend test failure is allowed or renewed.

## Security gate

The security job performs high-confidence secret and dangerous-path scans,
workflow permission and action-pin checks, credential-bearing remote detection,
dangerous workflow command checks, `.codex` safety checks, and Composer/pnpm
audits.

Every normal CI runs fresh `composer audit --locked`, workspace `pnpm audit`,
and legacy `pnpm audit`. The approved Composer and legacy pnpm baseline arrays
are empty: any actual advisory is blocking. New package/version/path, severity,
or advisory identity fingerprints fail, including advisory-database changes with
unchanged source/locks. Resolved approved findings are informational, not
regressions. A dependency or lock change alone does not invalidate the baseline
when fresh audits pass; lock consistency and Dependency Review remain enforced.
Malformed, incomplete, unavailable, or lock-inconsistent audit results fail
closed. Raw command statuses must agree with parsed findings; an unexplained
nonzero status cannot pass. Fresh lint likewise requires a valid complete report
and a consistent exit status.

Both advisory and ESLint baselines use schema `1.1`. Required management fields
are `owner`, `reason`, `tracking_task`, and `removal_condition`. Fixed expiry and
seven-day renewal are removed: time passage alone never fails these baselines.
There is no mandatory monthly or scheduled review, nor a replacement date field.
New baseline allowances require separate Human review; resolved entries may be
removed after fresh evidence confirms improvement. Reports never authorize
automatic baseline expansion, suppression, or weakening a severity threshold.

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
The complete backend suite runs on every integration job, and any backend test
failure fails the job.

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
python3 -m unittest tests.ci.policy.test_lane_policy
python3 scripts/ci/lane_policy.py --repository .
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
