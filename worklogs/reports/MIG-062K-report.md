# MIG-062K ユーザー状態変更

## Task

- Issue: #251
- PR: #252
- Base: `main@3f4fe2167513310e4fc34296cfe2ffc18ccf5fc8`
- Branch: `feat/MIG-062K-admin-user-state-management`
- Risk: R4
- Task Policy SHA-256: `66854b822074617873333ee7ae0a423df2751b1c59fe9e70c606a6b976f8eb19`
- Final Head／Squash Commit: Closeout時に確定

## State／Auth

- 既存`V2UserState`を維持し、手動遷移を`active -> suspended／closed`、`suspended -> active／closed`へ限定した。`closed`は終端とし、`pending_verification／restricted／anonymized`は手動変更しない。
- `suspended／closed`への変更時、既存User SessionとRemember Deviceを同一Transactionで失効する。新規Loginは既存Auth Serviceの`active／restricted`境界で拒否される。
- Point、Prize、Draw、Wallet履歴は変更しない。

## API／Admin／Audit

- `PUT /admin/api/v2/users/{user_id}/state`を追加し、`user.state.manage`、Fresh Authentication、Critical Mutation Rate Limit、Idempotency-Key、Revision OCC、RFC 9457、`private, no-store`を適用した。
- Owner／Adminへ更新権限を付与し、OperatorはRead-onlyとした。User詳細へ現在状態、許可遷移、理由必須の変更Dialogを追加し、成功後はCanonical User詳細を再取得する。
- AuditはActor／Target Public ID、理由、変更前後状態・Revision、アクセス失効件数、Idempotency fingerprintを記録し、Password、Cookie、Session識別子、内部DB IDを保存しない。
- Admin OpenAPI／Generated Clientを同期した。Public Contractは変更していない。

## Migration／Verification／Preview

- Migration `000047`で既存Userを保持する`state_revision DEFAULT 1`と正数Constraintを追加した。Task DB fresh／rollback／reapplyはPASSした。
- Task DB Backend対象19 tests／305 assertions、Admin対象Unit 4 files／35 tests、Typecheck、Lint、OpenAPI bundle/check、Admin generated check、Policy Unit 122 tests、Policy Gate、Quality Gate、git diff checkはPASSした。対象Browser／Preview結果はCloseout時に確定する。
- PreviewはGitHub-hosted amd64 Buildの検証済みAPI／Admin ImageとMigration `000047`だけを適用する。Host Build、Nginx、V1、Storefront、Point／Payment／Draw変更は行わない。

## Remaining

- Preview反映、Required Checks、Fresh Self-review、Squash Merge、CleanupをCloseoutで実施する。
