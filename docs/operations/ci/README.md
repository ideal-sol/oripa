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
- Strict uses the canonical Readiness change model described below. Gate,
  Security, Dependency and Application changes retain full validation. Only a
  proven Authority-only PR uses focused validation; its Lane remains Strict.

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
and legacy `pnpm audit`, plus `--prod` audits for both pnpm scopes. The general
Composer and legacy pnpm baseline arrays are empty. A dependency-changing PR
with an unapproved finding is blocked. When exact base/head dependency bytes
match, fresh unapproved findings instead produce
`PASS_NO_PR_INTRODUCED_DEPENDENCY_REGRESSION` for Development and
`HOLD_UNAPPROVED_FINDING` for the current security posture. Findings remain
visible and require security maintenance. Missing identity never means NONE.
Lock consistency and Dependency Review remain enforced.
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

### Human-approved exact dev-tool exception

The dedicated `approved_dev_tool_advisories` field in schema 1.1 records Human
approval for `GHSA-vfj7-8cjw-p6xm`, `braces@3.0.3`, High, with approved
`patched_versions` equal to `<0.0.0`. The generic Composer/pnpm arrays and
Dependency Review broad allowlist remain unchanged. Only these paths qualify:

- Workspace: `apps__admin>eslint-config-next>@next/eslint-plugin-next>fast-glob>micromatch>braces`.
- Legacy: `.>eslint-config-next>@next/eslint-plugin-next>fast-glob>micromatch>braces`.

The validator checks `eslint-config-next` in `devDependencies`, absent from
runtime/optional/peer dependencies, in `apps/admin/package.json` and
`legacy/v1-frontend/package.json`. A runtime finding never receives this
exception. All audit exit statuses must agree with parsed findings, including
unchanged-dependency findings. Do not rely on the audit
service's `dev` flag alone to prove dependency scope.

The explicit `security_fingerprint` must exactly match: advisory numeric ID,
GitHub advisory ID, package, severity, vulnerable/patched versions, CVE, CWE,
and CVSS score/vector. Source, audit record ID, locked version and both paths
are also exact-bound; dev-only proof and zero production-only findings remain
mandatory. A newly available fix, changed risk fingerprint, package/version/path,
additional exposure or advisory fails closed.

`informational_metadata` is evidence only. References, overview, title,
created/updated timestamps, attribution, access labels, recommendation text
and other descriptive fields do not participate in the blocking fingerprint.
Description-only changes do not invalidate an otherwise exact approved exception.
Missing or malformed security-relevant fields still fail closed.
No runtime finding receives an exception. Production security readiness requires
zero unapproved findings; a Development merge result cannot satisfy that proof.
The V1 baseline cannot authorize unapproved V2 findings.

Advisory disappearance is resolved/non-blocking even if the exception metadata
remains; no renewal is required. Security summaries expose actual findings,
approved exact exceptions and unapproved findings separately for both scopes,
plus the distinct advisory count. An excepted finding is never reported as zero.

## Canonical change and evidence model (CI-20261007)

`scripts/release/readiness/change.py` owns the source classifier consumed by
Platform CI and the existing Readiness evaluator. This is not another Gate or
another Governance Lane. `NONE`, `PRESENT`, and `UNKNOWN` deltas cover Application,
Dependency, Migration, Contract, Authority, Security Policy, Runtime Config,
API/write path, Tests, Docs and CI Governance. Unknown changed paths, unsupported
file modes, missing dependency inputs and invalid bindings fail closed.

The exact base/head trees enumerate every dependency manifest, Composer lock,
workspace and legacy pnpm lock/importers, workspace config, npm config and patch.
SHA-256 over deterministic path/mode/blob/byte-digest inventories proves byte
equality, including overrides/resolutions inside manifests. Renames, deletion,
new manifests and mode changes cannot disappear from the comparison.

`policy-gate` publishes `canonical-change`; `security-gate` recomputes it at the
exact checked-out PR head and publishes `current-security-posture`, including
all findings, package/version/path, severity, approved status, runtime scope,
audit input digest and observation time. Unavailable/malformed audits and any
baseline modification fail closed. Audit commands, severity thresholds, exact
dev-tool approval, event-driven baseline management and Rulesets are unchanged.

AUTHORITY_ONLY requires only the two existing approved-source manifests plus
optional tests/docs, and NONE for every runtime/security/governance component.
Record fields and source ancestry are checked locally. The existing read-only
GitHub transport and canonical check-run validator verify the merged source PR,
reviewed tree, source checks and protected-main continuity. Arbitrary manifest
fields or provenance mismatch block. Human authority remains PR review; this
path never authorizes Runtime Activation.

Authority-focused quality runs source-authority/Readiness/policy tests and
structure validation. Fresh audits and the complete secret scan still run.
Application regression, builds, contract regeneration and migration integration
are proven non-applicable by current-head evidence. All five Required contexts
remain required and return explicit results. The final aggregator rejects a
missing/failed context or an unexpected integration/ARM64 disposition. All other
Strict PRs, including this CI/Security change, use NORMAL_STRICT_CI.

Default finding classification is UNCHANGED_DEPENDENCY_SECURITY_POSTURE.
ADVISORY_DB_DRIFT_CONFIRMED additionally requires an exact historical dependency
fingerprint, unchanged historical Security policy, an authenticated successful
Security check and its matching GitHub job/audit log preceding the observation.
`adapters.confirm_advisory_drift` uses the supplied existing read transport;
there is no new credential transport or automatic cross-head PASS reuse.
Without this proof the result remains NOT_PROVEN, never an invented prior PASS.

`compare_evidence` reports component equality and invalidation after resync.
It separately compares PR-specific before/after file inventories: unchanged PR
bytes can be proven even when an upstream addition invalidates the whole
Application component's previous regression evidence.
Authority continuity, Advisory posture and current check inventory always need
refresh. Component equality alone never authenticates earlier regression
evidence: execution reuse remains NOT_PROVEN and falls back to fresh validation.
Readiness consumes the same canonical classification and Security record as
bound handoff facts. Missing Security evidence is UNKNOWN; unapproved findings
are HOLD even when Development checks pass. Readiness remains SHADOW ONLY,
blocking_authority=false, Production Fast Lane disabled, Phase 2 not started,
Promotion NOT_STARTED, with no automatic Human GO.

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
