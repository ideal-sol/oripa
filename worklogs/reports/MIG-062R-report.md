# MIG-062R Point Product Read／Eligibility Contract

## Task

- Issue: #267
- Base: `main@25fa06d40a169b43ee677d1fe84d0cf8f3ae7715`
- Branch: `feat/MIG-062R-point-product-read-eligibility`
- Worktree: `/var/www/oripa-worktrees/MIG-062R`
- Risk: R3
- Task Policy SHA-256: `116e2d5d9f5a0c8bf2b2ae603b3e6dce2ff000498ffbd141f82a1fd674fec6a1`

## Existing Domain Audit

- Point商品は既存`point_purchase_plans`を正本とする。Adminの`V2PointPurchasePlanService`が名称、JPY価格、paid／free Point、表示順、販売期間、公開状態、Audience、任意User Tagを管理する。
- 初回購入判定は既存`V2PointPurchaseEligibilityService`と`payments`を再利用する。Current Userに`status = succeeded`のPaymentが1件でもあれば初回対象外となり、failed／canceled等は対象外判定に使用しない。
- Public readは最新Versionかつ`published`のPlanだけを対象とする。販売期間はBackendで`coming_soon`／`available`／`ended`へ評価し、`sort_order`、次いで内部安定順で配列を返す。内部順序値は公開しない。
- Payment Provider、Payment Session、Point付与、Ledger、Webhook、Refund、Purchase Lifecycleは変更しない。
- 既存Schemaで実装可能なためMigrationは作成しない。

## Public Contract

- `GET /api/v2/point-products`を追加し、Opaque Public ID、Title、JPY価格、paid／bonus／total Point、Audience code／label、販売状態、Current User eligibility、ineligible reason、CTAを返す。
- Audienceは`all_users`（すべてのユーザー）と`first_purchase_users`（初回ユーザー）をCanonicalに返す。
- Anonymousは`authentication_required`と`login` CTAを返し、User固有Dataを含めずPublic 60秒Cacheと`Vary: Cookie`を使用する。
- AuthenticatedはCurrent UserをServer側で評価し、`private, no-store`と`Vary: Cookie`を使用する。購入履歴、User Tag、内部Plan ID、Provider情報は公開しない。
- CTAは既存Gacha Presentation patternへ揃え、`enabled`／`disabled`、`login`／`purchase`、Canonical reasonを返す。Purchase Mutationは追加しない。
- Errorは既存RFC 9457汎用Problem Detailsを維持し、実在しないMutation Error Codeを追加しない。

## Contract／Packages

- Public OpenAPI sourceへ`listPointProducts`とPoint Product schemasを追加し、Repository-local bundleはPublic 50 operationsへ同期した。
- Generated Storefront typesと薄い`createStorefrontPointProductClient` facadeを追加した。
- Storefront TestkitへAnonymous複数件／0件、全User eligible、初回eligible、成功済み購入後ineligible、販売不可、Login CTA、Backend ordering fixtureを追加した。
- Site SchemaのManifest構造変更は不要。Production package version、Runtime capability確定、Artifact releaseはPhase Bへ留保する。

## Phase A Verification

- Backend Targeted: 4 tests／54 assertions PASS。Base SHA相当のMIG-062P API imageとTask専用PostgreSQLを使用し、試験後にContainer／Networkを削除した。
- PHP syntax: 6対象file PASS。
- Public OpenAPI lint／bundle／generated comparison: PASS。Public 50／Admin 212／Webhook 1 operations。
- Storefront Client: generated check、typecheck、lint、build、25 tests PASS。
- Site Schema: generated check、typecheck、lint、build、10 tests PASS。Schema差分なし。
- Storefront Testkit: generated check、typecheck、lint、build、31 tests、export、network boundary PASS。
- `git diff --check`: PASS。
- Local Policy Gate: FAIL。Testkit package compatibility metadataと`policy_gate.py`がPublic 49 operationsを固定している。両PathはMIG-062QのArtifact／Gate差分と競合するためPhase Aでは変更せず、最新main基準のPhase B最終同期対象とする。
- Composer installは実行Policyで拒否されたためHost直接のPHPUnitは未実行。上記隔離Containerで同一Targeted Testを実行した。

## Integration Wait

- Phase A差分は専用Worktreeでstaged済みだが、Local `git commit`は実行環境のCommand Policyにより拒否された。Commit／push／Draft PR作成は未実施であり、許可された実行経路が必要となる。
- MIG-062QはPublic OpenAPI、bundled Public contract、Generated Storefront types、Testkit generated contractと競合する。
- MIG-062Qのmerge／cleanup、最新main追従、Conflict再確認、Platform Integration Lock、Artifact Release Lock、Preview Deployment Lockの人間確認までPhase Bを開始しない。
- Production Artifact version確定、最終Generated同期、Artifact release、Preview、Runtime activation、Squash merge、Issue close、cleanupは未実施。
- plat-main移管が必要なMutation／Payment／Point Ledger／Purchase Lifecycle差分は本Phase Aでは発生していない。
