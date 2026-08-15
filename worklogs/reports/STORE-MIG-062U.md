# STORE-MIG-062U Current User Point Balance／History Read Contract

## Task

- Task ID: `MIG-062U`
- Issue: `#273` (`https://github.com/ideal-sol/oripa/issues/273`)
- PR: `#274` (`https://github.com/ideal-sol/oripa/pull/274`)
- Risk: `R3`
- Base: `5be4488dad7aafb6e306d356a75598bc0279065c`
- Branch: `feat/MIG-062U-current-user-point-read-contract`
- Worktree: `/var/www/oripa-worktrees/MIG-062U`
- Task Policy SHA-256: `347ba2ff32ea40d43d61d523ecb11110bed99f8c1187922b7437eeccb5ad2e48`
- Application／Preview Head: `83f2732ce9a7adac3573e6f3975e43a53467de07`
- Final Head: このReportを含むdocs-only headをFresh CI前に固定し、exact SHAをPR／Self-review／Issue Closeoutへ記録する。
- Squash Commit: Gate-compliant merge後にIssue Closeoutへ記録する。Commit済みReport自身へmerge後SHAを後書きしない。

## Phase A

- GitHub全Issue／PR履歴、Task Policy、Remote refsを照合し、既存最大`MIG-062T`の次で未使用な`MIG-062U`を採番した。Active Platform Taskは0件、Open項目はDependabotだけである。
- Dependabot PRはPackage manifest／lockfileとPath overlapするがDependency-onlyであり、本Taskはfirst-party Artifact Versionだけを変更してDependency値を変更しない。Point Domainの同時変更はなくScope Gate／Conflict GateはPASSした。
- Canonical Balance SourceはV2 `wallets`で、`paid_balance - paid_reserved_balance`と`free_balance - free_reserved_balance`を既存`WalletBalance` Presentationへ返す。GETはWalletを作成・更新しない。
- Canonical History Sourceはimmutable `point_ledger_entries`とappend-only `point_operations`である。Operation単位にdeltaを集約し、`point_operations.public_id`、発生日時、signed delta、Backend生成理由Labelだけを公開する。
- `occurred_at DESC, point_operations.id DESC`を正本順序とし、Current Userに属するOperation public IDをOpaque Cursorとして継続位置へ解決する。同一時刻でも決定的であり、内部IDをResponse／Cursorへ直接公開しない。
- Migration created: 0。Migration applied: 0。Point Mutation、Ledger書込、Payment、Draw、Prize／Shipping、MIG-062R Point Product／Eligibilityは変更していない。

## Public Contract

- `GET /api/v2/me/wallet`／`getWallet`。
- `GET /api/v2/me/point-ledgers`／`listPointLedgerEntries`。`limit` 1..100、Opaque Cursor、`items`／`next_cursor`。
- `AUTHENTICATION_REQUIRED`、`SESSION_EXPIRED`、`INVALID_CURSOR`、`INVALID_PAGINATION`、`RATE_LIMITED`と未知Error fallbackを既存RFC 9457 Problem Detailsへ同期した。Read-only GETへCSRFを追加していない。
- Success／Problemとも`Cache-Control: private, no-store`、`Vary: Cookie`、User Session必須でありPublic Cacheへ混入させない。
- Wallet／Ledger／Lot／OperationのDB ID、business key、source／actor code、metadataを公開しない。

## Packages

- Public OpenAPI、bundle、Generated Types、薄い`createStorefrontCurrentUserPointClient`を追加した。ClientはEndpoint string、response type、Cursor queryを内部化し、Point計算／理由変換を持たない。
- Site Schema shapeは変更せず、既存`required_capabilities`で`user-point.read.v2`を宣言できる。
- Testkitは正数／0残高、複数／空履歴、加算／減算、Stable Ordering、Cursor continuation、認証／Session／Rate Limit Problemを提供する。
- Artifact Versionは最新mainを正本として`2.0.0-alpha.18`へ更新した。Public 52／Admin 212／Webhook 1 operations。

## Checks

- Backend targeted: 5 tests／54 assertions PASS。
- Changed PHP syntax: PASS。OpenAPI bundle/checkと7 tests: PASS。Public 52／Admin 212／Webhook 1 operations。
- Storefront Client: generate／typecheck／lint／build／26 tests PASS。
- Site Schema: generate／typecheck／lint／build／10 tests PASS。Schema shapeは不変。
- Storefront Testkit: generate／typecheck／lint／build／32 tests、exports、network boundary PASS。
- Admin: generated check、typecheck、lint、159 tests、production build PASS。
- Policy: Unit 125 tests、Local `policy-gate` PASS。Quality: Unit 5 tests、Local `quality-gate` PASS。
- Security: Unit 10 tests、Composer／Root pnpm／Legacy pnpm Fresh Audit各0件、Secret candidate 0、Local `security-gate` PASS。
- Release: Unit 10 tests、`release:validate` PASS。`2.0.0-alpha.18`、Migration 53件、Public／Admin／Webhook checksum一致を確認した。
- Application Head `83f2732ce9a7adac3573e6f3975e43a53467de07`の`policy-gate`／`quality-gate`／`security-gate`／`integration-gate`／`ci-gate`、CodeQL、CodeQL JavaScript、Dependency Review: PASS。
- Final docs-only headのFresh Required Checksとexact-head Fresh Self-reviewはReport commit後に実行し、exact値をPR／Issue Closeoutへ記録する。

## Phase B

- Remote mainはPhase B開始時もBase `5be4488dad7aafb6e306d356a75598bc0279065c`から移動せず、Generated Contract／Artifact／Preview lock競合は検出しなかった。
- Draft PR #274を作成し、Application HeadのRequired Checksを全件PASSした。GitHub-hosted Workflow Run `31862183365`で同一exact headの`linux/amd64` Preview Imageとimmutable Storefront Contract Artifactを生成した。
- Host Buildは行わず、Preview APIだけを`oripa-v2-api:preview-MIG-062U-83f2732ce9a7` (`sha256:6925b0db5cb12593029b9c7167509d5a17211891e75182dfdcfb8d8919921324`)へ`--no-build --no-deps`で更新した。Admin、PostgreSQL、Redis、Nginx、Storefront Runtimeは変更していない。
- 初回API再作成時にTask overrideへ既存固定IP指定が欠落し、Nginx経由の匿名Smokeが502でFail Closedした。既存Preview正本どおりAPI `192.168.61.10`／loopback `8611`を復旧し、healthyとDomain経由Readを再確認した。DB、Cache、他Containerは変更していない。

## Artifact

- Artifact Version: `2.0.0-alpha.18`。Source Commit: `83f2732ce9a7adac3573e6f3975e43a53467de07`。Registry publishは未実施。
- Storefront Contract Artifact: ID `9240966261`、name `oripa-storefront-contract-MIG-062U-83f2732ce9a7adac3573e6f3975e43a53467de07`、outer／GitHub digest `3d75b401b9cca25b83b0c8be044c478d7b88d275c0d98f8eb786670b634d8545`。
- Contract manifest SHA-256: `3da2049590468b34bbde41e5de50453db093320446d4299e1c15b9923cfc36f2`。Client `15f1b40b4c49d2949288af1a317c7b8a5a618a992e97a803522f2b374982952f`、Site Schema `7bafc95c53e2b599fc624c5231535fe3b9d741c187f85ff342111995ea7a5b7c`、Testkit `e0763f9604604f8a1beb7ec9400ead63e641d24783e70dd5837714fc94986b9f`、Public OpenAPI `391a8962710612478688a7479daa73f170b8e9093e0cfef380702a4f2d236860`。
- Artifact readbackは6ファイル限定、outer digest、SHA256SUMS、manifest Task／source、3 package name／versionをFail Closed検証した。Evidenceは`/var/lib/oripa-v2-evidence/MIG-062U/storefront-contract-artifact/83f2732ce9a7adac3573e6f3975e43a53467de07`。
- Preview Image Artifact: ID `9240962686`、outer／GitHub digest `d02189410c2a40c2601cd1dc4e569bc2a641285c5bd63ae6681c8d08c019e25c`、manifest SHA-256 `211f56c6e4779f9d15a6d2485517df7f5e8e9953e49622e06baaea491129b471`、OCI revision／architecture一致。

## Preview

- 安全な既存Preview QA Userでログインし、`GET /api/v2/me/wallet`は200、Canonical total 11985、paid／free合計一致、非負値を確認した。Credential、Cookie、Token、User identifierはReportへ記録していない。
- `GET /api/v2/me/point-ledgers?limit=2`を4ページ継続し、7件、加算／減算、理由Label 4種類、新しい順、重複なし、repeat read同順を確認した。最古Entry cursorのContinuationは200／0件／`next_cursor: null`だった。
- Success／匿名401 Problem Detailsとも`private, no-store`、`Vary: Cookie`を確認した。Responseに内部Wallet／Ledger／Operation／User ID、source／operation code、actor、business keyがないことを再帰検証した。
- Runtime Error 0、Mutation 0。Point購入、付与、減算、Draw、Point Exchange、Admin Adjustment、DB直接更新、Migration、Cache削除は実行していない。
- Evidence: `/var/lib/oripa-v2-evidence/MIG-062U/preview-result.json`。認証情報、Cookie、Token、PII、public ledger identifierは保存していない。

## Cleanup

- Preview APIはTask exact image、固定IP `192.168.61.10`、loopback `8611`でhealthy。Rollbackは直前の検証済み`oripa-v2-api:preview-MIG-062R-e7c74fd1c53d` (`sha256:55817dad2136dae9c391afbc74a51ae072f2d38180b170f9a6014b878ffa3122`)へ戻す。DB／Migration rollbackは不要。
- Issue Close、Remote／local task branch、専用Worktree、synthetic test PostgreSQL／network cleanup、local／Remote `main` equalityはSquash Merge後にPR／Issue Closeoutへexact値を記録する。

## Storefront Adoption Pending

- 別SITE TaskでHeader、`/points`、`/mypage/points`の残高とPoint履歴へClientを接続する。Storefront Repositoryは本Taskで変更しない。

## Remaining

- Final docs-only headのFresh Required Checks／Self-review、Squash Merge、Issue Close／branch／worktree／synthetic test resource Cleanup。Storefront Adoptionは別SITE Task。
