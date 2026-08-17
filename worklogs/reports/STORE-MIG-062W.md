# STORE-MIG-062W LINE Friend State Read／Presentation Contract

## Task

- Task ID: `MIG-062W`
- Issue: `#277` (`https://github.com/ideal-sol/oripa/issues/277`)
- PR: `#278` (`https://github.com/ideal-sol/oripa/pull/278`)
- Risk: `R3`
- Base: `1118703eb704f901d25d946074e3707e9c557c6f`
- Branch: `feat/MIG-062W-line-friend-state-read-contract`
- Worktree: `/var/www/oripa-worktrees/MIG-062W`
- Task Policy SHA-256: `e51655a6f2cb4584dcb3b33b88c7436f2a2dec2d76b0ccf0a2579b02713589f0`
- Application Head: `904de9f2867ae6e8f5becc74d4e7b1d3b1013ee0`
- Preview Head: PR本文補正を記録するdocs-only fresh headへ固定する。
- Final Head／Squash Commit: Fresh Closeout後にIssue／PRへ記録する。

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

## Phase B Pending

- Draft PR、exact Application head Required Checks、immutable `2.0.0-alpha.20` Artifact、Read-only Preview、Fresh Self-review、Squash Merge、Issue／branch／worktree cleanup。
- PreviewでLINE link／unlink、OAuth／Callback、follow／unfollow、Webhook、Provider操作、DB直接更新、Migration、Cache削除を実行しない。
