# STORE-MIG-062U Current User Point Balance／History Read Contract

## Task

- Task ID: `MIG-062U`
- Issue: `#273` (`https://github.com/ideal-sol/oripa/issues/273`)
- PR: Draft PR作成後に記録する。
- Risk: `R3`
- Base: `5be4488dad7aafb6e306d356a75598bc0279065c`
- Branch: `feat/MIG-062U-current-user-point-read-contract`
- Worktree: `/var/www/oripa-worktrees/MIG-062U`
- Task Policy SHA-256: `347ba2ff32ea40d43d61d523ecb11110bed99f8c1187922b7437eeccb5ad2e48`
- Final Head: Fresh CI前に固定し、PR／Self-reviewへ記録する。
- Squash Commit: Gate-compliant merge後にIssue Closeoutへ記録する。

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
- OpenAPI write bundle: PASS。
- Storefront Client: generate／typecheck／lint／build／26 tests PASS。
- Testkit: Version確定後のfull checkを再実行して記録する。
- Site Schema、Admin generated、Policy／Quality／Security／Integration／CI、Fresh Self-reviewは未完了。

## Phase B

- Remote mainはPhase B開始時もBase `5be4488dad7aafb6e306d356a75598bc0279065c`から移動せず、Generated Contract／Artifact／Preview lock競合は検出しなかった。
- Artifact manifest、SHA-256、Preview、Required Checks、Fresh exact-head Self-review、Squash Mergeは後続Gateで記録する。

## Preview

- 未実施。正式Artifactを検証後、安全なPreview QA Userで残高／履歴のReadだけを非破壊確認する。Point購入、付与、減算、DB直接更新、Cache削除は行わない。

## Cleanup

- Issue Close、Remote／local task branch、専用Worktree、synthetic test resourcesのcleanup、local／Remote main equalityはSquash Merge後に記録する。

## Storefront Adoption Pending

- 別SITE TaskでHeader、`/points`、`/mypage/points`の残高とPoint履歴へClientを接続する。Storefront Repositoryは本Taskで変更しない。

## Remaining

- Package full checks、Required CI、Artifact／Preview、Fresh Self-review、Squash Merge、Issue Close／Cleanup。
