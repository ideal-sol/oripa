# QUALITY-002 V1 AdminPaymentApiTest Baseline Expiry Remediation

## Task

- Task ID: `QUALITY-002`
- Issue: `#279` (`https://github.com/ideal-sol/oripa/issues/279`)
- Risk: `R3`
- Lane: `plat-main`
- Base: `1118703eb704f901d25d946074e3707e9c557c6f`
- Branch: `fix/QUALITY-002-admin-payment-baseline-removal`
- Worktree: `/var/www/oripa-worktrees/QUALITY-002`
- Migration reserved／created／applied: `0 / 0 / 0`
- PR／Application Head／Final Head／Squash SHA: Phase Bで確定する。

## Coordination

- Start時にlatest `origin/main`、Active Task Ledger、Platform Integration Lock、Migration Allocation Lock、Artifact Release Lock、Preview Deployment Lockを再確認した。
- Shared Lockは全て`none`で、QUALITY-002はMigration、Artifact、Previewを使用しない。
- `MIG-062W`はIssue #277／PR #278、Head `cbae33cf629daa1729bc9cd626f6bb8fa872d8f6`のplat-contract Blocked Taskである。そのBranch、Worktree、Source、LINE Friend State Contract、Generated Contractは変更していない。

## Baseline History

- GOV-009はApplication／fixture変更をScope外として、既知2 failureをClass、Method、Exception Typeの完全一致baselineで2026-08-15まで暫定管理した。
- Ownerは`platform-maintainers`、Tracking Taskは`QUALITY-002`、Exit条件は完全なpayment-origin fixtureへ更新して両TestがPASSした後のbaseline削除だった。
- Expiry時の正式処理はFail Closedであり、期限だけを延長せず、追跡failureを再現・診断してExit条件を実行することである。

## Root Cause

- Refund fixtureはSucceeded Paymentだけを作成し、現在の`PaymentRefundService`が必須とするPayment-origin paid／free Point Lotを作成していなかった。実Responseは422 `Payment-origin point lots were not found.`だった。
- Chargeback fixtureもSucceeded Paymentだけを作成し、`ChargebackReversalService`／`PointReversalService`がLockするWalletを作成していなかった。実Responseは422 `No query results for model [App\\Models\\Wallet].`だった。
- V1 Runtime、PostgreSQL、Redis、Environment、Application Serviceの障害ではない。GOV-009後のCanonical reversal contractへ旧fixtureと旧response expectationが追従していないことが原因である。

## Resolution

- Refund／Chargeback fixtureへWalletとPayment-origin paid／free Point Lotを追加した。
- Assertionを現在の`PaymentReversalResource`へ同期し、reversal type／completed status／reason、Payment final status、reversed amount、shortfall 0、Wallet／Lot 0、Ledger、Audit、Chargeback user suspensionを検証する。Assertionの削除、skip、緩和は行っていない。
- Application Sourceは変更していない。
- `.ci/baselines/backend-tests.json`と専用validatorを削除し、Integration Workflowを通常の`php artisan test`へ変更した。全failureを許容しないためGateは弱化ではなく強化される。
- Baseline renewal: `なし`。新しい期限、Owner、Failure allowanceは作成していない。

## Verification

- Before fix targeted reproduction: 2 tests／2 assertions、2 FAIL。Refund／Chargebackとも422を確認した。
- After fix `AdminPaymentApiTest`: 6 tests／50 assertions、PASS。
- Full backend suite: 342 tests／1854 assertions、failure 0、exit 0。局所Image実行の`.env`未Materialize WarningはCI fixture環境と分離して記録する。
- Quality Unit: 4 tests、PASS。
- Policy Unit: 125 tests、PASS。
- Security Unit: 10 tests、PASS。
- Local Policy Gate: PASS、tracked 1433 files。
- Local Quality Gate: PASS、PHP 802／JSON 102／YAML 19／XML 2／TOML 1／Contract 4。
- Fresh Composer／Root pnpm／Legacy pnpm Audit: 各0 findings。
- Local Security Gate: PASS、secret candidate 0。
- `git diff --check`: PASS。
- GitHub Required Checks、Fresh Self-review、Merge／Cleanup: Phase B pending。

## Impact

- Test fix: `あり`。FixtureとCanonical response assertionsのみ。
- Gate change: `あり`。Expired failure allowanceを撤去し、backend suiteの通常exit statusを必須化。
- Application／API／Database／Auth／Point／Payment Runtime behavior: 変更なし。
- Migration／Generated Contract／Artifact／Preview／Storefront／Infrastructure／Production: 変更なし。
- Rollback: Squash Commitをrevertすると期限切れbaselineと2 fixture failureが復活しRequired Integration GateがFail Closedするため、通常運用上はrevertしない。
