# STORE-MIG-062V Current User Gacha History Read Contract

## Task

- Task ID: `MIG-062V`
- Issue: `#275` (`https://github.com/ideal-sol/oripa/issues/275`)
- PR: `#276` (`https://github.com/ideal-sol/oripa/pull/276`)
- Risk: `R3`
- Base: `642ab8f6bfe7ef2ca0a7e3fb9d0ecd05b600d803`
- Branch: `feat/MIG-062V-current-user-draw-history-read-contract`
- Worktree: `/var/www/oripa-worktrees/MIG-062V`
- Task Policy SHA-256: `eaa0c324cc06f1d64ece2a6660f8cb421dd7c51c5e2af0d78318066a6cf9c91e`
- Application／Preview Head: `2b58e308693fa6e642023e2778274e789da75c09`
- Final Head: このReportを含むdocs-only headをFresh CI前に固定し、exact SHAをPR／Self-review／Issue Closeoutへ記録する。
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
- Release Unit 10 tests／`release:validate`: PASS。`2.0.0-alpha.19`、Migration 53件、Public／Admin／Webhook checksum一致を確認した。
- Application Head `2b58e308693fa6e642023e2778274e789da75c09`の`policy-gate`／`quality-gate`／`security-gate`／`integration-gate`／`ci-gate`、CodeQL、CodeQL JavaScript、Dependency Review: PASS。
- Final docs-only headのFresh Required Checksとexact-head Fresh Self-reviewはReport commit後に実行し、exact値をPR／Issue Closeoutへ記録する。

## Phase B

- Phase B開始時もRemote mainはBase `642ab8f6bfe7ef2ca0a7e3fb9d0ecd05b600d803`から移動せず、Generated Contract／Artifact／Preview lock競合は検出しなかった。
- Draft PR #276を作成し、Application HeadのRequired 5 Checks、CodeQL、CodeQL JavaScript、Dependency Reviewを失敗履歴0でPASSした。
- GitHub-hosted Workflow Run `31886804304`で同一exact headの`linux/amd64` Preview Imageとimmutable Storefront Contract Artifactを生成し、outer digest／manifest／package identity／OCI revision／architectureをreadback検証した。
- Host Buildは行わず、Preview APIだけをTask imageへ`--no-build --no-deps`で更新した。Admin、PostgreSQL、Redis、Nginx、Storefront Runtimeは変更していない。

## Artifact

- Artifact Version: `2.0.0-alpha.19`。Source Commit: `2b58e308693fa6e642023e2778274e789da75c09`。Registry publishは未実施。
- Storefront Contract Artifact: ID `9247519110`、name `oripa-storefront-contract-MIG-062V-2b58e308693fa6e642023e2778274e789da75c09`、outer／GitHub digest `e221dca904d85499b4413c27aff96a49ecb705bf5b6f80f6a8f146cdc652b880`。
- Contract manifest SHA-256: `7014f84ec38c742d2586a148700489bbdad069c78a02173ae4cf80bb11fd3448`。Client `25cffd3e571eaa3df8e6cc19b50d591605c8dbb8783cca03ee83b0d82c79fe95`、Site Schema `963b8f65f5ed296bf7e5eba9901e282f8400e40643ef73b36fb0b160d753ad43`、Testkit `cb7f19df5802f56fdc692ad8bead5c8f5e2638961b00fe2c85250b08f4978a36`、Public OpenAPI `2b6883e8e51eebe6414f401553e866112b56d6e400b34ca17436433666fa0211`。
- Artifact readbackは6ファイル限定、outer digest、SHA256SUMS、manifest Task／source、3 package name／version、Public 53 operationsをFail Closed検証した。Evidenceは`/var/lib/oripa-v2-evidence/MIG-062V/storefront-contract-artifact/2b58e308693fa6e642023e2778274e789da75c09`。
- Preview Image Artifact: ID `9247515776`、outer／GitHub digest `21fde98b5598d73994e587725524cadc51bab2b5f0e96d01a053ea732373bcbb`、manifest SHA-256 `c5973c59ca4558e4bafa5cc68bd3f3bafc2dc5e9477cb92c2556f5a6a511c14f`、OCI revision／architecture一致。

## Preview

- 直前Taskで使用した旧QA credentialはTask Image／直前Imageの双方で401 `INVALID_CREDENTIALS`となり、Task回帰ではなくcredential失効と切り分けた。以降の再試行を止め、より新しい安全な既存Preview QA User credentialへ切り替えた。Secret、Cookie、Token、User identifierはEvidence／Reportへ記録していない。
- Current User Readは200、4件／2ページ、partial execution、public presentation asset 4件、Backend `completed`／`完了`、UTC新しい順、repeat同順、Cursor continuation、重複なしを確認した。
- Success／匿名401 Problem Detailsとも`private, no-store`、`Vary: Cookie`を確認した。ResponseにUser／Gacha Version／Draw State／Probability／IdempotencyのDB ID、QA内部情報、event code、response snapshotがないことを再帰検証した。
- Runtime Error 0、Mutation 0。Draw、Point、Prize／Inventory、Payment、Gacha lifecycle、DB直接更新、Migration、Cache削除は実行していない。
- Evidence: `/var/lib/oripa-v2-evidence/MIG-062V/preview-result.json`。認証情報、Cookie、Token、PII、public Draw identifier、Cursorは保存していない。

## Cleanup

- Preview APIはTask exact image `oripa-v2-api:preview-MIG-062V-2b58e308693f` (`sha256:f19b4bf332f11cc8a24d9c09c7aa9b3e00019b79c0551489e301d8e974a9ef5d`)、固定IP `192.168.61.10`でhealthy。Rollbackは直前の検証済み`oripa-v2-api:preview-MIG-062U-83f2732ce9a7` (`sha256:6925b0db5cb12593029b9c7167509d5a17211891e75182dfdcfb8d8919921324`)へ戻す。DB／Migration rollbackは不要。
- Synthetic test PostgreSQL／Redis Containerとnetworkは削除済み。実行PolicyがDocker volume削除を拒否したため、isolated dependency volume `mig062v_backend_vendor`だけは未接続のまま残る。
- Issue Close、Remote／local task branch、専用Worktree、local／Remote `main` equalityはSquash Merge後にPR／Issue Closeoutへexact値を記録する。

## Storefront Adoption Pending

- 別SITE Taskで`/mypage/draws`へClientを接続する。Storefront Repositoryは本Taskで変更しない。

## Remaining

- Final docs-only headのFresh Required Checks／Self-review、Squash Merge、Issue Close／branch／worktree cleanup。Local dependency volume削除は実行Policy上の残件である。
