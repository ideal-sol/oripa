# MIG-061W Report

## Issue／PR／Commit

- Issue: #214
- PR: #215
- Base: `b85d3123943a5c61af2406b28a3b71d73a7ad145`
- Application Head: `0c844f825bbd683fc9cf76b27fa190610fd9adf0`
- Final Head／Squash Commit: GitHub Closeout時に確定
- Task Policy SHA-256: `5cee0f14039cf15371893c4000302a5cf87faa83ff5ad1fc9392abdad9b90537`

## V1移植／既存境界

- V1の`/admin/settings/line`、`LineFriendSetting`、Admin ControllerをRead-only Characterizationした。友だち追加URL、友だち追加ポイントの有効化／付与数／無償ポイント期限、自動応答メッセージ、友だち数、ブロック数をV2へ移植する。
- MIG-058BのLINE Login、External Identity、署名済みMessaging Webhook、Follow／Unfollow、Reply Message、Pending Followを再利用する。Channel Secret／Access Token／Login SecretはRepository外Secretのままとし、Admin Contract、UI、Auditへ返さない。
- MIG-058CのReward Singleton、Wallet／Lot／Operation／Ledger、Friendship単位の冪等付与、Revision OCC、Idempotency、Audit／Outboxを再利用する。既存Webhook、Point付与、署名検証の処理は変更しない。
- V1の単一自動応答文は、MIG-058Bで確定済みのログイン済み／ログイン前の2文へ維持する。V2の`unfollowed`をV1画面上のブロック数として集計する。

## API／権限／Secret

- 既存`GET／PUT /admin/api/v2/identity/line-messaging`とPreview APIを補完し、`friend_add_url`、`friends_count`、`blocked_count`を追加する。新しいPublic APIは追加しない。
- `identity.line.read`はOwner／Admin／Operator、`identity.line.manage`はOwner／Adminだけへ付与する。Operator UIは全項目Read-onlyで、API更新は403となる。
- 友だち追加URLはnullable、最大2,048文字、HTTP／HTTPS URL。秘密値は受理・保存・再表示せず、AuditはURLとMessageをSHA-256で記録する。
- Migration `2026_08_26_000039_add_v2_line_settings_management.php`は既存Singletonへnullable URL列だけを追加し、rollback／reapply可能。Friendship、Webhook、Point履歴は変更しない。

## Verification／Preview

- Task DBでMigration 39件fresh、最新Migration rollback／reapply、LINE／Permission対象16 Test・223 AssertionがPASSした。
- Admin Component／Navigation 2 files・11 Test、対象Browser 1 Test、Typecheck、Lint、Production Build、OpenAPI Bundle／4 Unit、Generated Client、Policy Unit 110件、`git diff --check`がPASSした。
- Secret候補検査で新規Credential 0。LINE資格情報名は既存正本の参照だけで、値は記録していない。
- PR Policy GateはGovernance必須見出し不足を検出した。PR本文を`Task`、`Summary`、`Specification sources`、`Scope`、`Verification performed`、`Verification not performed`へ補正し、再Policy GateはPASSした。Gate条件とApplication差分は変更していない。
- Application HeadからAPI `sha256:dd80b68de22c83c6016fd3adafe70f91edaaddff42ae1d924872d12186a49ef6`、Admin `sha256:6ab94d4c3925df6e4a942b2f2051ae9926c363e3721582e0942530b96c231f73`をBuildし、Previewへ反映した。Migration 000039だけを適用し、DB Guardは38件のBase集合と39件の適用後集合でPASSした。
- Synthetic Ownerで友だち追加URLの一時保存、Canonical再取得、元値復元、友だち／ブロック統計、Secret field非表示を確認した。Mobile横溢れ、Console／Page Error、HTTP 500／502／504は0である。Operator Read-onlyと更新403は対象API／Component Testを正本とする。
- API／Adminはhealthy、`admin.luxe-pack.biz/login`と`luxe-pack.biz`はHTTP 200。固定IP／Network／Restart Policy／Environment Key集合、Nginx checksum、V1、PostgreSQL／Redis設定は維持し、旧Imageへ専用Rollback tagを追加して保持した。
- 残課題: LINE一斉送信、Storefront LINE UI、実LINE Provider資格情報による外部E2Eは対象外。
- 所要時間: 約2時間（Characterization、Policy、Migration／権限／UI、対象検証、Image Build、Preview Smoke、Closeoutを含む）。
- Gate G4／G5: `NOT COMPLETE`。
