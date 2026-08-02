# MIG-061B Configurable Admin Authentication Policy Report

## Task

- Task ID: `MIG-061B`
- Risk: `R4`
- Base: `main@b067e125b3dc96c7c9fa98c8485a1edb51e77450`
- Branch: `feat/MIG-061B-configurable-admin-authentication`
- Issue: `#165`
- PR: 作成前
- Task Policy SHA-256:
  `1b78e921714d54b5a921b0339e794b5ae93e47b857cf845b9592695f31e379e9`
- Evidence: `/var/lib/oripa-v2-evidence/MIG-061B/`

## Scope／Non-change

- Installation SingletonのAdmin認証Policyとして、MFA必須と招待Token必須を
  独立して設定できるBackend、Admin Contract、Admin UIを追加した。
- 通常Loginから招待Token入力を分離し、Policy OFF時はPassword-only、ON時は既存
  TOTP／WebAuthn ChallengeまたはEnrollmentを利用する。
- Ownerだけが現在Password、Fresh Authentication、Revision OCC、Idempotency-Keyを
  使用してPolicyを更新できる。MFA OFF時のFresh AuthenticationはPassword再確認を
  使用する。
- Invitation OFF時はOwnerが一時Password付きActive Adminを作成し、ON時は既存の
  Hash／期限／使用済み／失効境界を持つInvitationを発行する。既存Adminの通常Loginへ
  Invitationを遡及適用しない。
- V1、Storefront、Public／Webhook Contract、Point／Payment／Draw、Nginx／TLS／DNS、
  Production Paymentは変更していない。

## Contract／Authorization／Transaction

- Admin OpenAPIへPassword-onlyを含む型付きLogin結果、Invitation Acceptance、
  Authentication Policy取得／更新、Admin作成を追加し、Public／Webhook Schemaは
  変更していない。
- Admin Realm、Session、CSRF、Exact Origin、JSON Content-Type、Owner Permission、
  Critical Rate Limit、`private, no-store`、RFC 9457境界を維持した。
- Policy更新はOwner Row、Idempotency Record、Singleton PolicyをLockし、Revision、
  Append-only Audit、Transactional Outboxを同一Transactionで確定する。
- Current Password、Invitation Token、Temporary Password、TOTP Secret、WebAuthn
  CredentialはAudit、Outbox、Responseの不要な領域、Client Storageへ保存しない。

## Migration／DB Guard

- Forward-safe Migration
  `2026_08_17_000030_create_v2_admin_authentication_policy.php`を追加した。
- `admin_authentication_policy`は`id = 1`、Revision 1以上、UUIDv7 Public ID、UTC
  Timestamp、更新者／Request IDを型付きColumnで保持する。
- Triggerは物理Delete、Identity変更、No-op更新、Revision飛越し、Mutation Metadata
  欠落を拒否する。`migrate:fresh`再実行に耐える`CREATE OR REPLACE FUNCTION`を使用する。
- Task専用DB `oripa_v2_mig061b`、Marker `mig061b`、Purpose
  `v2-task-ephemeral`でDB Target Safety Guardを実行し、V1／Preview DBから分離した。
- Migration fresh、対象Rollback／Table・Function消失確認／Reapply、Migration集合再照合は
  `PASS`。既存Admin、Invitation、MFA Credentialは変更していない。

## Local Verification

- PHP Syntax: 15 changed／new PHP files `PASS`
- Authentication Policy／Login／Fresh対象: `25 tests / 158 assertions` `PASS`
- Process Concurrency: `1 test / 7 assertions` `PASS`
- 全V2 Suite: `297 tests / 2701 assertions / 4 existing skips` `PASS`
- DB Guard Unit: `29 tests` `PASS`
- Admin OpenAPI Lint／Bundle／Breaking Check: `PASS`
- Admin Generated Contract差分: `0`
- Admin Typecheck／Lint／Production Build: `PASS`
- Admin Unit／Component: `13 files / 63 tests` `PASS`
- Admin Browser E2E: `16 tests` `PASS`
- `git diff --check`: `PASS`
- Policy／Quality／Security／Secret・PII／GitHub CheckはFinal Headで確定する。

## Preview Deployment

- 対象は既存MIG-061A PreviewのV2 Admin／APIとV2 DB
  `oripa_v2_mig061a`だけである。
- Migration `000030`、Policy `MFA OFF／Invitation OFF`、Synthetic Ownerの一時Password、
  Admin／API Candidate Imageを反映する予定である。
- Credential値はRepository、Report、通常Logへ記録しない。保存先は
  `/root/mig061a-preview-login.txt`、Owner `root:root`、Mode `0600`だけを正本へ記録する。
- Nginx、固定Network／IP、V1 Runtime、`luxe-pack.biz`は変更しない。
- Deployment結果、Image Digest、Migration Count、Browser Smokeは反映後に確定する。

## 時間を要した作業

- 全V2 Suiteは約`3分28秒`、Admin Browser E2E最終実行は約`1分21秒`を要した。
- 初回Concurrency TestはPostgreSQL Functionが`migrate:fresh`後に残ることを検出した。
  既存Migration規則の`CREATE OR REPLACE FUNCTION`へ修正し、対象Testを先に再実行して
  Full Suiteの無駄な再試行を避けた。
- Admin Browser E2E初回は新しい`mfa_required`を既存Fixtureが省略して3件失敗した。
  MFA ON／OFFをFixtureへ明示し、失敗3件だけを先行再試験後、全16件を1回実行した。
- Root空き容量とDocker使用量を重い検証前にRead-only確認した。空き`4.7GB`で安全範囲の
  ため追加Cleanupは行わず、稼働Container、Image、Named Volumeを維持した。

## Final／Gate

- Final Head、Preview反映、GitHub Check、Fresh Self-review、Squash Commit、Cleanupは
  Closeoutで確定する。
- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- MIG-061C以降は開始しない。
