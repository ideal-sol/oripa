# STORE-MIG-062V Current User Gacha History Read Contract

## Task

- Task ID: `MIG-062V`
- Issue: `#275` (`https://github.com/ideal-sol/oripa/issues/275`)
- PR: Draft PR作成後に記録する。
- Risk: `R3`
- Base: `642ab8f6bfe7ef2ca0a7e3fb9d0ecd05b600d803`
- Branch: `feat/MIG-062V-current-user-draw-history-read-contract`
- Worktree: `/var/www/oripa-worktrees/MIG-062V`
- Task Policy SHA-256: `eaa0c324cc06f1d64ece2a6660f8cb421dd7c51c5e2af0d78318066a6cf9c91e`
- Final Head: Fresh CI前に固定し、PR／Self-reviewへ記録する。
- Squash Commit: Gate-compliant merge後にIssue Closeoutへ記録する。

## Phase A

- Latest Platform `main@642ab8f6bfe7ef2ca0a7e3fb9d0ecd05b600d803`、GitHub Issue／PR全履歴、Task Policy、Remote refsを照合し、既存最大`MIG-062U`の次で未使用な`MIG-062V`を採番した。Active Platform Taskは0件である。
- Open項目はDependabotだけであり、Package manifest／lockfileとのPath overlapはfirst-party Artifact Version同期に限定する。Gacha／Draw Domainの同時変更はなくScope Gate／Conflict GateはPASSした。
- Canonical History Sourceは`draw_requests`である。Current User owner、public ID、requested／executed count、completed status、transaction-fixed occurrence timeを再利用し、新しいBusiness Rule／Enum／state transitionを追加しない。
- Historical Gacha PresentationはDraw Requestが固定参照するimmutable published `catalog_gacha_versions`のtitle／presentation assetを使用する。Current Gacha Presentationを遡及適用しない。
- 順序は`draw_requests.created_at DESC, draw_requests.id DESC`、内部IDはserver-only tie-breakerとする。CursorはCurrent User所有のDraw Request public IDをopaque token化して継続位置を解決する。
- Migration created: 0。Task／Preview Migration applied: 0。Local synthetic test DBだけに既存V2 Migration 53件を適用した。Draw Mutation、Gacha lifecycle、Point、Inventory／Prize、Payment、Storefront Repositoryは変更していない。

## Public Contract

- `GET /api/v2/me/draws`／`listDrawHistory`。`limit` 1..100、Opaque Cursor、`items`／`next_cursor`を返す。
- ItemはDraw public ID、historical Gacha public ID／title／public presentation asset、UTC occurrence、requested／executed count、Backend-authoritative `completed`／`完了`を返す。
- `AUTHENTICATION_REQUIRED`、`SESSION_EXPIRED`、`INVALID_CURSOR`、`INVALID_PAGINATION`、`RATE_LIMITED`と未知Error fallbackをRFC 9457 Problem Detailsへ同期した。
- Success／Problemとも`Cache-Control: private, no-store`、`Vary: Cookie`、User Session必須である。Read-only GETへCSRFを追加しない。
- User／Gacha Version／Draw State／Probability／IdempotencyのDB ID、QA内部情報、event code、response snapshotを公開しない。

## Packages

- Public OpenAPI／bundle／Generated Typesと薄いStorefront Draw Clientを同期した。ClientはEndpoint、query encoding、response typeを内部化し、status／label解釈を持たない。
- Site Schema shapeは変更せず、既存`required_capabilities`で`user-draw-history.read.v2`を宣言できる。
- Testkitは複数／空履歴、partial execution presentation、同時刻stable order、Cursor continuation、認証／Cursor／Rate Limit Problem fixtureを提供する。
- Artifact Versionは最新mainを正本として`2.0.0-alpha.19`へ更新した。Public 53／Admin 212／Webhook 1 operations。

## Checks

- Backend targeted: 3 tests／55 assertions PASS。既存Draw 20 tests、QA Draw 15 tests／150 assertions、V1 Draw characterization 1 test／14 assertions PASS。
- Changed PHP syntax、OpenAPI bundle/checkと7 tests: PASS。
- Storefront Client: generate／typecheck／lint／build／27 tests PASS。
- Site Schema: generate／typecheck／lint／build／10 tests PASS。Schema shapeは不変。
- Storefront Testkit: generate／typecheck／lint／build／33 tests、exports、network boundary PASS。
- Admin generated check／typecheck／lint／tests／production build: PASS。
- Policy Unit 125 tests、Local policy gate、Quality Unit 5 tests／Local quality gate: PASS。
- Security Unit 10 tests、Composer／Root pnpm／Legacy pnpm Fresh Audit各0件、Secret candidate 0、Local security gate: PASS。
- Release Unit 10 tests／`release:validate`: PASS。Required CI／Artifact／Preview／Fresh Self-reviewは後続Gateで記録する。

## Phase B

- Remote mainはPhase B開始前に再確認する。Artifact manifest、SHA-256、Preview、Required Checks、Fresh exact-head Self-review、Squash Mergeは後続Gateで記録する。

## Preview

- 未実施。正式Artifactを検証後、安全な既存Preview QA UserでGacha履歴のReadだけを非破壊確認する。Draw、Point／Prize／Inventory／Payment Mutation、DB直接更新、Migration、Cache削除は行わない。

## Cleanup

- Issue Close、Remote／local task branch、専用Worktree、synthetic test resourcesのcleanup、local／Remote main equalityはSquash Merge後に記録する。

## Storefront Adoption Pending

- 別SITE Taskで`/mypage/draws`へClientを接続する。Storefront Repositoryは本Taskで変更しない。

## Remaining

- Application Head commit／Draft PR、Required CI、Artifact／Preview、Fresh Self-review、Squash Merge、Issue Close／Cleanup。
