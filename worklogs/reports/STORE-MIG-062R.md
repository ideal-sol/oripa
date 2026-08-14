# STORE-MIG-062R Point Product Read／Eligibility Contract

## Task

- Task ID: `MIG-062R`
- Issue: `#267`
- Phase A Base: `25fa06d40a169b43ee677d1fe84d0cf8f3ae7715`
- Phase B Base: `e305a76a9a2dbd88e019ceecb7153514906a38d0`
- Branch: `feat/MIG-062R-point-product-read-eligibility`
- Worktree: `/var/www/oripa-worktrees/MIG-062R`
- Risk: `R3`
- Task Policy SHA-256: initial `116e2d5d9f5a0c8bf2b2ae603b3e6dce2ff000498ffbd141f82a1fd674fec6a1`; Phase B package sync `1f7e8a12bc51f8a07619186e8ff694876396eb3093d5fc4088c3f4e885644751`; push boundary `a57c90ebc74707b11dcfd56672fe40e39cdc3b968bcf7f9f54c50d5d6bd44c5f`

## Existing Domain Audit

- Point商品は既存`point_purchase_plans`を正本とする。Adminの`V2PointPurchasePlanService`が名称、JPY価格、paid／free Point、表示順、販売期間、公開状態、Audience、任意User Tagを管理する。
- 初回購入判定は既存`V2PointPurchaseEligibilityService`と`payments`を再利用する。Current Userに`status = succeeded`のPaymentが1件でもあれば初回対象外となり、created／processing／failed／canceled／expiredは対象外判定に使用しない。
- Public readは最新Versionかつ`published`のPlanだけを対象とする。販売期間はBackendで`coming_soon`／`available`／`ended`へ評価し、`sort_order`、次いで内部安定順で配列を返す。内部順序値は公開しない。
- Payment Provider、Payment Session、Point付与、Ledger、Webhook、Refund、Purchase Lifecycleは変更しない。
- 既存Schemaで実装可能なためMigrationは作成も適用もしない。

## Public Contract

- `GET /api/v2/point-products`を追加し、Opaque Public ID、Title、JPY価格、paid／bonus／total Point、Audience code／label、販売状態、Current User eligibility、ineligible reason、CTAを返す。
- Audienceは`all_users`（すべてのユーザー）と`first_purchase_users`（初回ユーザー）をCanonicalに返す。
- Anonymousは`authentication_required`と`login` CTAを返し、User固有Dataを含めずPublic 60秒Cacheと`Vary: Cookie`を使用する。
- AuthenticatedはCurrent UserをServer側で評価し、`private, no-store`と`Vary: Cookie`を使用する。購入履歴、User Tag、内部Plan ID、Provider情報は公開しない。
- CTAは既存Gacha Presentation patternへ揃え、`enabled`／`disabled`、`login`／`purchase`、Canonical reasonを返す。Purchase Mutationは追加しない。
- Errorは既存RFC 9457汎用Problem Detailsを維持し、実在しないMutation Error Codeを追加しない。

## Phase A

- Public OpenAPI sourceへ`listPointProducts`とPoint Product schemasを追加し、Repository-local bundleをPublic 50 operationsへ同期した。
- Generated Storefront typesと薄い`createStorefrontPointProductClient` facadeを追加した。
- Storefront TestkitへAnonymous複数件／0件、全User eligible、初回eligible、成功済み購入後ineligible、販売不可、Login CTA、Backend ordering fixtureを追加した。
- Site Manifest schema構造変更は不要と判定した。
- Backend Targetedは4 tests／54 assertions PASS。PHP syntax、Public OpenAPI、Storefront Client 25 tests、Site Schema 10 tests、Testkit 31 testsもPASSした。
- Phase A staged patch SHA-256は`76845ba6a585ceb3e665b13a6b5ed4a1a5a4c4a94155d6d56e248d06207bbb9a`。
- MIG-062QとのPublic OpenAPI／Generated Contract競合によりPhase Aでintegration-waitへ移行した。

## Phase B Integration

- MIG-062QとMIG-062S merge後の`origin/main@e305a76a9a2dbd88e019ceecb7153514906a38d0`へrebaseした。
- Generated Public Contract競合は、最新mainのPublic OpenAPIを正本にbundle、Storefront types、Testkit contractを再生成して解消した。MIG-062Q生成物をPhase A生成物で上書きしていない。
- Point Domain、`point_purchase_plans`、`payments`、Migration setにMIG-062Q／MIG-062Sとの直接Conflictはない。
- GitHub App wrapperはPhase A Baseからのcumulative diffを検証するため、Task Policyへmerge済みMIG-062Q／MIG-062Sの継承Pathだけをexact追加した。MIG-062R commitは当該Pathを変更せず、最新mainの祖先として取り込むだけである。
- Package／3 API Contract／release metadataを既存Versionを上書きしない`2.0.0-alpha.17`へ同期した。
- Public 50／Admin 212／Webhook 1 operations。Migration setは既存53件のままで、新規Migrationはない。

## Verification

- Latest-main Backend Targeted: MIG-062S exact Preview API imageとTask専用PostgreSQLを使用し、4 tests／54 assertions PASS。既知のfixture path warning 4件のみ。Task container／networkは削除済み。
- OpenAPI bundle checkと7 tests: PASS。
- Storefront Client generated check／typecheck／lint／build／25 tests: PASS。
- Site Schema generated check／typecheck／lint／build／10 tests: PASS。Schema shape差分なし。
- Storefront Testkit generated check／typecheck／lint／build／31 tests／exports／network boundary: PASS。
- Policy Gate unit 125 testsとLocal Policy Gate: PASS。
- Release foundation 10 testsと`release:validate`: PASS。
- Release validationは`2.0.0-alpha.17`、Public／Admin／Webhook contract version一致、Migration 53件を確認した。
- Required GitHub Checks、CodeQL、Dependency Review、exact-head Fresh Self-review、PreviewはPR final head確定後に記録する。

## Artifact

- Artifact Version: `2.0.0-alpha.17`
- Source Commit: PR final head確定後に記録する。
- Manifest SHA-256: Artifact readback後に記録する。
- Client SHA-256: Artifact readback後に記録する。
- Testkit SHA-256: Artifact readback後に記録する。
- Site Schema SHA-256: Artifact readback後に記録する。
- Public OpenAPI SHA-256: `ab9515f9abfb0040722991f7624faf287011f2d2852f90142a0e691ddbc643e6`
- Registry publishは行わず、exact-head GitHub-hosted workflowが生成するimmutable tarball／manifestを正本とする。

## Preview／Closeout

- PreviewはPoint購入MutationやPaymentを実行せず、Synthetic Point PlanのRead-only Presentationだけを検証する。
- Anonymous／Authenticated、eligible／ineligible、0件／複数件、ordering、cache boundary、既存V1／Public API非影響を確認する。
- PR、Required Checks、Artifact readback、Preview、Fresh Self-review、Squash Merge、Issue Close、branch／worktree cleanupは完了時に追記する。
- G4／G5は`NOT COMPLETE`を維持する。

## Out of Scope

- Point購入Mutation、Payment Provider／Session、Point付与／Ledger Mutation、Webhook／Callback、Refund、Purchase Lifecycle、二重付与防止Transactionは変更していない。
- Storefront Repository、V1、Nginx、Production DB、Production Runtimeは変更していない。
- plat-mainへ移管が必要な新規State Transition／Payment／Point Mutation差分は発生していない。
