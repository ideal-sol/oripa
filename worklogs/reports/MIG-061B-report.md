# MIG-061B Configurable Admin Authentication Policy Report

## Task

- Task ID: `MIG-061B`
- Risk: `R4`
- Base: `main@b067e125b3dc96c7c9fa98c8485a1edb51e77450`
- Branch: `feat/MIG-061B-configurable-admin-authentication`
- Issue: `#165`
- PR: `#166`（Draft、CloseoutまでOpen）
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
- DB Target Safety GuardでMarker `mig061a`、Purpose `v2-persistent`、Schema
  `public`、V2 Migration集合を再照合してからMigration `000030`だけを適用した。
  Migrationは29件から30件となり、Policyは初期値`MFA OFF／Invitation OFF`、
  Browser往復確認後もRevision 3の`OFF／OFF`である。
- Admin Candidateは
  `sha256:a4decc57554d19a1d20e92220209fe8ba1964f39c864bca45d6ca345d8a26945`、
  API Candidateは
  `sha256:465994e86c64974586b80d8428fc9a653c704efe3bafb4ff545ce6b93a4773e3`
  へ更新した。Source SHAは`72d973b8667a115e122ce5a854d5d7ec79f22a79`である。
- PreviewはCompose Project `mig061a-v2-preview`、Network
  `mig061a-v2-preview_v2_private`、Admin `192.168.61.11:3000`、API
  `192.168.61.10:8000`でhealthyを維持する。Loopback Portはそれぞれ
  `127.0.0.1:3611`、`127.0.0.1:8611`である。
- Synthetic OwnerはActiveへ確定し、一時Passwordを再発行した。TOTP／WebAuthn
  Credentialは各1件を保持し、削除・変更していない。Credential rotationはSecretを
  含まないAppend-only Auditへ記録した。
- Credential値はRepository、Report、通常Logへ記録しない。保存先は
  `/root/mig061a-preview-login.txt`、Owner `root:root`、Mode `0600`だけを正本へ記録する。
- 実DomainでPassword-only Login、通常Login FormにInvitation Tokenがないこと、
  MFA／InvitationのON／ONからOFF／OFFへの保存、Dashboard、Gacha、Prize、QAを確認した。
  Console Critical Error、Page Error、HTTP 500／502／504はいずれも0である。
- `admin.luxe-pack.biz` Login／Health／Session API、`luxe-pack.biz`はすべてHTTP 200。
  Nginx checksumは
  `9832e492f8995db08a45d72f22566d09111d44539524b6509a79b986909f7347`
  で不変であり、Nginx／TLS／DNS、V1 Runtimeは変更していない。

## 時間を要した作業

- 全V2 Suiteは約`3分28秒`、Admin Browser E2E最終実行は約`1分21秒`を要した。
- 初回Concurrency TestはPostgreSQL Functionが`migrate:fresh`後に残ることを検出した。
  既存Migration規則の`CREATE OR REPLACE FUNCTION`へ修正し、対象Testを先に再実行して
  Full Suiteの無駄な再試行を避けた。
- Admin Browser E2E初回は新しい`mfa_required`を既存Fixtureが省略して3件失敗した。
  MFA ON／OFFをFixtureへ明示し、失敗3件だけを先行再試験後、全16件を1回実行した。
- Root空き容量とDocker使用量を重い検証前にRead-only確認した。空き`4.7GB`で安全範囲の
  ため追加Cleanupは行わず、稼働Container、Image、Named Volumeを維持した。
- Previewへの初回Migration用one-shot Containerが既存の固定API IPと競合したため、
  DB Write前に停止した。続く固定OverrideなしのCompose実行でPreview Networkが一時的に
  動的Subnetへ再作成され、Nginxの固定UpstreamがTimeoutした。DB Volume、Image、V1
  Resourceは削除せず、Preview Projectだけを停止して正本の固定Subnet Overrideで再起動し、
  Admin/APIの固定IPと全HTTP 200を復旧した。その後は既存API ContainerからMigrationを
  適用し、同じ失敗経路を再試行していない。
- Preview運用envにHost／Origin／WebAuthn RPの非Secret設定が永続化されておらず、再作成後
  にHost Guardが404を返した。Secret値を表示せず正本値をroot専用envへ追加し、Admin/API
  だけを再作成して解消した。Nginx変更やHost Toolchain更新は行っていない。
- Preview結果を記録した最初のPushは、PR本文更新より先にPull Request Checkが開始され、
  旧本文のPreview未実施記載をPolicy GateがFail Closedした。同一SHAでは後続成功Runが
  旧失敗Checkを置換しない集約仕様のため、空CommitやGate緩和は使わず、この実経緯と
  Preview後Local Policy／Quality／Security GateのPASSを正本へ追記した新Headで再実行した。
- 次のHeadではPR本文の`Changed files`をカテゴリで記載していたため、Policy Gateの
  実Diffとの完全一致検査がFail Closedした。Task Policyの許可範囲は逸脱していないが、
  Governance要件に従って全43 PathをPR本文へ明示し、この検出結果を記録した新Headで
  Checkを再実行した。
- その後、`Changed files`は43件一致したが、`Allowed paths`も概略ではなくTask Policyの
  実Path列挙を必要とする検査でFail Closedした。発行済みPolicyの60 PathをPR本文へ
  完全転記し、実Diffが全てその内側であることを機械照合してから新HeadをPushした。

## Final／Gate

- Preview反映とLocal Evidenceは確定済みである。Final Head、GitHub Check、Fresh
  Self-review、Squash Commit、Task専用Resource CleanupはCloseoutで確定する。
- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- MIG-061C以降は開始しない。
