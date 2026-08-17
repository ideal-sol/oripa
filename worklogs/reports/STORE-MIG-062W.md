# STORE-MIG-062W LINE Friend State Read／Presentation Contract

## Task

- Task ID: `MIG-062W`
- Issue: `#277` (`https://github.com/ideal-sol/oripa/issues/277`)
- PR: `#278` (`https://github.com/ideal-sol/oripa/pull/278`)
- Risk: `R3`
- Original Base: `1118703eb704f901d25d946074e3707e9c557c6f`
- Latest Main: `c2960e4c73aaeab8d840c09a8ec714266962d823`
- Branch: `feat/MIG-062W-line-friend-state-read-contract`
- Worktree: `/var/www/oripa-worktrees/MIG-062W`
- Task Policy SHA-256: `efe786ff3c10eaeaf6bb8285fce63829ffeea81e6dc39fe38d0e199c45ff4353`
- CI Evidence Head: `58f7bf9212941572a30360d1881b63712c6bf4a6`
- Original Implementation Head: `904de9f2867ae6e8f5becc74d4e7b1d3b1013ee0`
- Resume Head: `cbae33cf629daa1729bc9cd626f6bb8fa872d8f6`
- Latest-main Sync Head: `776c368beabe3d7f51b4ef3ecf812d6cc5f4126a`
- Phase B Application Head: 本再開記録commitで確定する。
- Preview Head／Artifact: Fresh Required Checks PASS後に確定する。
- Final Head／Squash Commit: Preview／Closeout後に確定する。

## Phase A

- Latest Platform `main@1118703eb704f901d25d946074e3707e9c557c6f`、Remote main、GitHub全Issue／PR履歴を照合し、既存最大`MIG-062V`の次で未使用な`MIG-062W`を採番した。Active Platform Taskは0件で、Open項目はDependabot PRだけである。
- Canonical Friend Stateは既存`external_identity_accounts`の有効なLINE Identityと、同一User／subjectの`line_friendships.status = friend`かつ`unfollowed_at IS NULL`のJoinである。新しいWebhook、Provider check、state transition、Migration、Auth／Security判断を必要としないため停止条件には該当しない。
- 既存Draw eligibility内の同一Canonical queryを`V2LineFriendStateService`へ抽出し、DrawとCurrent User Readの二重実装を除いた。人間確定仕様の「LINE連携済み かつ 友だち追加確認済み」を`is_line_user`としてBackendだけで確定する。
- Migration created: 0。Task／Preview Migration applied: 0。Local isolated synthetic PostgreSQLだけに既存V2 Migration 53件を適用した。

## Public Contract

- `GET /api/v2/me/line-friend-state`／`getLineFriendState`。Current User Session必須のRead-only GETで、CSRFを要求しない。
- `linked`、`friend_confirmed`、`is_line_user`、Backend-authoritativeなstatus code／label、Primary Action code／label／hrefを返す。
- 未連携は既存`startLineIdentityLink`へ接続するAction、連携済み未確認は既存LINE Messaging設定の`friend_add_url`が安全に存在する場合だけURL Action、確認済みはActionなしとする。
- `AUTHENTICATION_REQUIRED`、`SESSION_EXPIRED`、`RATE_LIMITED`と未知Error fallbackをRFC 9457 Typed Problem Detailsへ同期した。
- Success／Problemとも`Cache-Control: private, no-store`、`Vary: Cookie`。Provider subject／issuer／secret／token、内部ID、Webhook event、reward設定を公開しない。

## Packages

- Public OpenAPI、Repository-local bundle、Generated Types、薄いStorefront Identity Clientの`getLineFriendState`を同期した。ClientはEndpointとresponse typeだけを持ち、LINEユーザー判定やlabel／CTA変換を行わない。
- Site Schema shapeは変更せず、既存`required_capabilities`で`user-line-friend-state.read.v2`を宣言できる。
- Testkitは未連携、連携済み友だち未確認、確認済みLINEユーザー、認証／Session／Rate Limit Problem fixtureを提供する。
- Artifact Versionは最新mainを正本として`2.0.0-alpha.20`へ更新した。Public 54／Admin 212／Webhook 1 operations。

## Checks

- Backend targeted: 6 tests／46 assertions PASS。既存LINE audience回帰: 2 tests／9 assertions PASS。
- Changed PHP syntax、OpenAPI bundle/check、OpenAPI Unit 7 tests: PASS。
- Storefront Client: generate／typecheck／lint／build／27 tests PASS。
- Site Schema: generate／typecheck／lint／build／10 tests PASS。Schema shapeは不変。
- Storefront Testkit: generate／typecheck／lint／build／34 tests、exports、network boundary PASS。
- Admin generated check／typecheck／lint／159 tests／Production build: PASS。
- Policy Unit 125 tests、Local Policy Gate、Quality Unit 5 tests／Local Quality Gate: PASS。
- Security Unit 10 tests、Composer／Workspace pnpm／Legacy pnpm Fresh Audit各0件、Secret candidate 0、Local Security Gate: PASS。
- Release Unit 10 tests／`release:validate`: PASS。`2.0.0-alpha.20`、Migration 53件、Public／Admin／Webhook checksum一致を確認した。
- Application exact headのRequired CI、Artifact、Preview、Fresh Self-reviewはPhase Bで実施する。
- 初回PR event Policy GateはPR本文の必須見出しを`#`で記載したため、parserが要求する`##`／`###`として認識せずFAILした。同Application headのmanual dispatch Policy GateはPASSした。本文をCanonical見出しとexact `Changed files`／`Allowed paths`へ補正し、本記録を含むdocs-only fresh headで全Checksを再実行する。

## Scope Impact

- API impact: Current User Read 1 operation追加。既存Draw LINE audience判定のqueryを同値の共通Serviceへ移動した。
- Database impact: Schema／Migration／data mutationなし。
- Auth impact: 既存User Session Realmを再利用。Recent Authentication、unlink、OAuth／Callback変更なし。
- Point／Payment／Draw impact: Point／Payment／Draw Transaction変更なし。Drawは既存LINE audience判定結果を共通Serviceから取得するだけである。
- Infrastructure impact: Phase Aはなし。PreviewはGitHub-hosted exact-head imageを使用しHost buildを行わない。
- Storefront Repository impact: なし。別SITE Taskで`/mypage/line`へ採用する。

## Phase B Blocked

- Fresh head `58f7bf9212941572a30360d1881b63712c6bf4a6`では`policy-gate`、`quality-gate`、`security-gate`、CodeQL、CodeQL JavaScript／TypeScript、Dependency ReviewがPASSした。
- `integration-gate`はTask非変更の既存V1 `Tests\\Feature\\AdminPaymentApiTest` 2件と、`.ci/baselines/backend-tests.json`の期限切れを検出してFAILした。`ci-gate`もその必須Gate失敗を受けてFAILした。
- Backend baselineは最新mainと同一で、tracking taskは`QUALITY-002`、期限は`2026-08-15`である。現在日は`2026-08-17`であり、GitHub履歴にActiveな`QUALITY-002` Taskは存在しない。
- 既存V1 fixture変更、baseline延長、Gate緩和は本TaskのAllowed Paths外かつ無関係な品質問題であるため、MIG-062Wへ取り込まない。Task固有のLocal checksとFresh CIのPolicy／Quality／Securityには失敗がない。
- Required 5 Checksを満たせないため、immutable `2.0.0-alpha.20` Artifact、Preview、Fresh Self-review、Squash Merge、Issue close、Remote／local branch削除、worktree削除は未実施である。PR #278、Task branch、専用worktreeを保持してPrerequisite修正後の再開を待つ。
- Previewは未実施であり、LINE link／unlink、OAuth／Callback、follow／unfollow、Webhook、Provider操作、DB直接更新、Migration、Cache削除を実行していない。

## Phase B Resumed

- Human Operatorの「決済審査用V2環境 READY」とMIG-062W再開承認を受け、既存Issue #277、PR #278、Branch、Worktree、Task Policyを継続した。新Task／Issue／PR／Branch／Worktreeは作成していない。
- Preflightではlocal／origin／Remote mainが`c2960e4c73aaeab8d840c09a8ec714266962d823`で一致し、Issue／PRはOpen、Task worktreeはclean、Remote task headは`cbae33cf629daa1729bc9cd626f6bb8fa872d8f6`だった。Open first-party TaskはMIG-062Wだけである。
- Coordination Ledgerは完了済みOPS-007をstale active／Preview Lock heldとしていた。Issue #283 close、mainへのsquash、worktree削除、最新人間READYを照合してstale lockを解放し、Migration／Artifact／Preview Lockがnoneの状態からMIG-062WがPlatform Integration Lockを取得した。
- QUALITY-002、OPS-006、GOV-014、GOV-015、OPS-007のlatest-main変更とLINE sourceのPath overlapは0で、automatic merge conflictは`worklogs/new_ver_main.md`だけだった。両Task記録を保持した二親merge candidateをGOV-015 wrapperで検証し、Remote head`776c368beabe3d7f51b4ef3ecf812d6cc5f4126a`へno-force syncした。
- Base sync時だけTask Policyへ`sync-task-branch-base`を許可し、完了後はAPI lifecycle wrapperのstrict operation schemaへ戻した。Policy `base_sha`は実際に取り込んだlatest mainへ更新し、Allowed Pathsは変更していない。
- latest mainのProduction Artifact Versionは`2.0.0-alpha.19`であり、MIG-062Wが次Version`2.0.0-alpha.20`を使用することを正本から再確認した。OpenAPI／Generated Types／Client／Site Schema／Testkit／Admin生成を再実行し、差分0、Public 54／Admin 212／Webhook 1 operationsである。
- Fresh LocalはOpenAPI 7、Storefront Client 27、Site Schema 10、Testkit 34、Admin 159、Policy 125、Quality 4、Security 10、Release 10、Ops 34 tests、各generate／typecheck／lint／build、Local Policy／Quality／Security／Release Gate、dependency audit 0、secret candidate 0がPASSした。
- PHP 8.4一時test imageと完全分離PostgreSQLで、MIG-062W 6 tests／46 assertions、既存LINE audience 2 tests／9 assertions、QUALITY-002後の`AdminPaymentApiTest` 6 tests／50 assertionsがPASSした。初回はphpunit固定DB名`oripa_test`とsynthetic DB名不一致で0 assertion FAILし、Runtime／Sourceを変更せず正しいCI DB名へ合わせて再実行した。
- Migration createdは0、Task／Preview／Production appliedは0。Local synthetic V2 DBだけに既存53 migrations、別synthetic V1 DBだけに既存V1 migrationsを適用した。
- Exact Application headのFresh Required Checks、Artifact、Runtime boundary preflight、Read-only Preview、Fresh Self-review、Merge／Cleanupは後続Phase Bで実行する。
