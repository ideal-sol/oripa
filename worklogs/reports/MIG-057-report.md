# MIG-057 Password Reset／SMS Verification Vertical Slice 提出用レポート

## 基本情報

- Task ID: `MIG-057`
- Risk: `R3`
- Issue: `#117`
- Branch: `feat/MIG-057-password-reset-sms-verification`
- Base: `e0b4640bf12456d47c6cbf22ca99c681a67ad05b`
- 対象: V2 Password Reset、SMS Verification、Public Contract、
  Storefront Client、Testkit、Audit／Outbox
- 対象外: SMS実送信、Google OIDC、LINE、UI、Domain／TLS、Production Deployment

## MIG-056 Closeout

- Issue `#115` Closed、PR `#116` Squash Merged。
- Final Head:
  `1b82684a8dc6f5ecef533a6c9b0c2901920181b4`
- Squash Commit:
  `e0b4640bf12456d47c6cbf22ca99c681a67ad05b`
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Check成功。
- Fresh Self-reviewはFinal Headと一致し、SEV-0／SEV-1は0件。
- Remote／Local Branch、WorktreeはCleanup済み。
- Local `main = origin/main`、Working Tree clean。

## 実装結果

### Password Reset

- GenericなRequest Responseを実装。
- Verified Userだけを対象とし、重複未認証Emailと分離。
- 30分有効、1回限り、5回失敗で失効するCSPRNG Tokenを実装。
- DB保存はToken Hashのみ。Relative Redirectだけを許可。
- 既存V2 Password Policyを適用。
- 成功時に全User Session／Remember Deviceを失効し、新Session／CSRFをRotation。
- Password変更通知をOutboxへ、成功／失敗／Rate Limit／Session失効をAuditへ記録。
- Account 3回／1時間、IP 10回／1時間、Confirm Token／Account各5回を実装。

### SMS Verification

- 状態取得、送信、再送、Code検証を実装。
- PhoneはApplication-level Encryption、検索／重複／Rate LimitはHMAC相関値。
- 数字CodeはCSPRNG生成し、DBにはHashだけを保存。
- 5分有効、1回限り、5回失敗で失効。再送時は旧Challengeを失効。
- 未認証Phone重複を許可し、Active／Suspended UserのVerified Phoneだけを一意化。
- Closed／Anonymized UserのPhoneは再利用可能。
- Phone変更時に旧認証状態を解除し、Verify成功時にSession／CSRFをRotation。
- Phone 3回／1時間・10回／日、IP 5回／1時間、Verify 5回を実装。
- SMS Provider実送信は行わず、暗号化Delivery Envelopeを持つOutboxまで実装。

### Contract／Security

- Public OpenAPIへ6 Operationを追加し、Public 35／Admin 67／Webhook 0 Operation。
- Storefront Client Identity FacadeとTestkit Recovery Fixtureを追加。
- CSRF、Exact Origin、JSON Content-Type、User Realm、Generic Errorを維持。
- 同時Reset Confirm／SMS VerifyはRow Lockにより1件だけ成功。
- Suspicious Recoveryは独自Heuristicを追加せず、明示Signal用の接続境界だけを追加。
- Password、Token、Code、Full Email、Full Phone、Cookie、Raw Session IDを
  Log／Audit／Errorへ記録しない。

## Schema／Migration

- 新規Table:
  - `password_reset_tokens`
  - `user_phone_numbers`
  - `sms_verification_challenges`
- `user_sessions.reauthenticated_at`をForward-safeに追加。
- V2 Migration数: 14
- Migration Set SHA-256:
  `a92551fc56e8c9202201fcb384964ac819c80be289348113410aef1ce969af60`
- Source／Restore Schema SHA-256:
  `ef2a759f15827d55ed322d30ac49522569b82d6866666b3e47ad81607601d10d`
- Source／Restore Migration Row SHA-256:
  `82c81f26517ed15802a6a8e7c12dad30eafdf49dc6e3ac34dce9bc4118772808`
- Backup SHA-256:
  `9ae8fd8eba94e27e9966aef2fd5ed836de389b092b02ac9aed317d182348c439`

## 検証結果

- Persistent／Ephemeral V2 DBで`migrate:fresh`各2回: PASS
- 最新Migration Rollback／Reapply、Migration Status、Schema Inventory: PASS
- Backup／Restore、Schema／Migration Checksum一致、Task Resource Cleanup: PASS
- Password Reset／SMS Test: 12 Test／81 Assertion PASS
- Process Concurrency Test: 2 Test／17 Assertion PASS
- 全V2 SuiteとDraw／QA／Point／Payment／Shipping／Reporting／Content回帰: PASS
- Storefront Client: 生成差分0、Typecheck、Lint、Build、13 Test PASS
- Site Schema: 生成差分0、Typecheck、Lint、Build、10 Test PASS
- Storefront Testkit: 生成差分0、Typecheck、Lint、Build、21 Test、
  Export Surface、実Network禁止 PASS
- Admin: Typecheck、Lint、Build PASS
- OpenAPI Unit 4、Policy Unit 80、DB Guard Unit 25、Release Unit 10: PASS
- Root／Legacy Frozen Install: PASS
- Root Audit: 0 Finding
- Legacy Audit: 既存11 Finding
- Composer Audit: 既存期限付き10 Finding、Baseline拡張なし
- V1 Migration 40件Checksum:
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`

## 非変更／未実行

- V1 Runtime Commit:
  `bfca8efa0b85c00a88fb0fd439a123b722577b68`
- V1 Runtime clean、Public 200、Admin 307。
- V1本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagは非変更。
- SMS Provider実送信、Google OIDC、LINE、Referral、UI、Domain／TLS、
  Staging E2E、Production Deploymentは未実行。

## Gate／完了処理

- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- PR: `#118`
- 初回Final候補Head:
  `c96f33c2af943f9000ac722be99add849912a18f`
- 初回Final候補Headの最新RunではRequired 5 Check、CodeQL 2件、
  Dependency Reviewがすべて成功。
- PR本文のTask Policy Metadata不足による過去の失敗Runは、本文修正だけで解消。
- Application／Migration／Contractを変更せず、本提出記録を確定した次Headを
  Final Headとして全CheckとFresh Self-reviewを再実行する。
- Final Head、Fresh Self-review、Squash Commit、Issue Close、
  Branch／Worktree CleanupはPR完了時に確定する。
- 次Task候補:
  `MIG-058 Google OIDC／LINE Identity Linking Vertical Slice`
- MIG-058は本Task内で開始しない。
