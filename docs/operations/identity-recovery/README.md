# V2 Account Security／SMS Verification Boundary

## Responsibility

MIG-057とACCT-001はUser Password Reset、Email Address Change、Password Change、
User Phone登録・変更、SMS VerificationのPublic Contract、V2 DB、Domain Service、
Audit／Transactional Outbox境界を所有する。Storefront UI、SMS Provider Transport、
Production Deployment、AdminによるPassword設定は所有しない。

## Password Reset

- Request ResponseはAccountの存在・状態にかかわらず同じAccepted Responseとする。
- Verified Emailを持つActive／Restricted Userだけに、CSPRNG Tokenを60分、1回限りで
  発行する。Suspended／Closed／Anonymized／未認証UserにはTokenもMailも発行しない。
- DBにはSHA-256 Token Hashだけを保存する。通知に必要なTokenと宛先は
  Application-level EncryptionしたOutbox Payloadにだけ格納する。
- Accountは1時間3回、IPは1時間10回、ConfirmはToken／Account単位で5回に制限する。
  Email、IP、TokenはRate Limit Keyへ平文保存せず、HMAC相関値を使用する。
- Redirectは設定済みRelative Path Allowlistだけを許可する。
- Resendは旧Tokenを失効する。ConfirmはToken Hashに一致する行をLockし、別Tokenの
  Attemptへ波及させず、5回失敗、期限切れ、使用済み、User状態変化を共通Problemで拒否する。
- Confirmは既存`V2PasswordPolicy`を使用する。成功時は全User SessionとRemember
  Deviceを失効し、新しいSession／CSRFは発行しない。Password変更通知OutboxとAuditを
  同一Transactionで確定し、ClientはLoginへ戻す。
- Suspicious Recoveryの独自Heuristicは実装しない。正本で確定したSignalを将来接続する
  `V2SuspiciousRecoveryBoundary`だけを提供し、未接続時はSecurity Holdを推測しない。

## Email Address Change

- StartはAuthenticated User Session、CSRF、Exact Originを必須とするが、Fresh
  AuthenticationとCurrent Password再入力は要求しない。
- Canonical EmailはStart時に変更しない。User単位で旧Pending Requestを失効し、
  Token Hash、Display／Normalized Email、Initiating Session Hash、Redirect、Attempt、
  60分期限を含む必要最小限の状態だけを専用Tableへ保存する。
- Verification Mailは新Emailだけへ送る。同じPending Emailを複数Userが保持できるが、
  Complete時の再確認、Normalized Email Advisory Lock、Users DB Unique Constraintを
  最終Authorityとする。
- CompleteはToken Hash行をLockし、1回限り、5回Attempt、期限、失効、User状態を検査する。
  別Browser／未ログインBrowserから完了できるが、そのBrowserへSessionを発行しない。
- Initiating Sessionが有効なら保持する。同じBrowserで完了した場合だけSession／CSRFを
  Rotationするが、既存Fresh Authentication時刻は更新しない。Other SessionsとRemember
  Deviceを失効し、完了通知は新Emailだけへ送る。

## Password Change

- Authenticated User Session、CSRF、Exact Origin、操作内Current Password照合を必須とする。
  別Fresh Authentication画面、Verification Mail、Pending Password Tableは使用しない。
- UserとCurrent SessionをLockし、Current PasswordをTransaction内で再検証する。
  New PasswordはCurrent Passwordと異なり、既存`V2PasswordPolicy`を満たす必要がある。
- 成功時は即時Hash更新、Current Session／CSRF Rotation、Other SessionsとRemember
  Device失効、`password_changed`通知Outbox、Auditを同一Transactionで確定する。
  Current BrowserのLogin状態は維持し、ClientはAccount画面へ戻す。

## Security Mail

- `password_reset`、`email_change_verification`、`email_change_completed`、
  `password_changed`は既存固定Mail Template、Sanitize／Render、Transactional Outboxを使う。
- Security Mail Workerは対象TopicだけをClaimする。Provider失敗はRetry／Audit／既存可視化へ
  送り、既に確定したAccount mutationを巻き戻さない。Outbox／必須Audit永続化失敗は
  Account mutation TransactionをFail ClosedでRollbackする。
- ComposeのSecurity Mail Workerは`identity-mail` Profileで明示起動する。Database smokeや
  通常のCore Service起動では自動起動せず、Migration後の承認済みActivationだけが起動する。
- Raw TokenはToken Table、Audit、Error、Logへ保存しない。通知に必要なRaw Tokenは
  Application-level EncryptionしたOutbox Payloadにだけ保持する。

## SMS Verification

- PhoneはE.164へ正規化し、Application-level Encryptionで保存する。
  重複判定、検索、Rate LimitにはRepository外KeyによるHMACだけを使用する。
- 6桁CodeはCSPRNGで生成し、DBにはSHA-256 Hashだけを保存する。
  Challengeは5分、1回限り、5回失敗で失効する。再送は旧Challengeを失効する。
- Phoneは1時間3回、1日10回、IPは1時間5回、VerifyはChallenge単位5回に制限する。
  Limiter障害時はFail Closedとする。
- 未認証Phoneは複数Userで共有できる。Active／Restricted／Suspended Userの
  Verified PhoneはPartial Unique Indexで一意にする。Closed／Anonymized Userの
  認証済みPhoneは、状態をLockして失効した後に再利用できる。
- Phone登録・変更・VerifyはServer DBの`user_sessions.reauthenticated_at`を正本とする
  10分Fresh Authenticationを必須とする。Browser時刻やClient Headerは使用しない。
- Verify成功時はUser SessionとCSRFをRotationし、Absolute Session期限を延長しない。
  Phone変更開始時は旧Phoneの認証状態を解除する。
- SMS通知に必要なCodeと宛先は暗号化Outbox Payloadにだけ格納する。
  実SMS Provider、Provider Secret、API Keyは実装・保存しない。

## Security

全Mutationは既存V2 Browser境界でJSON Content-Type、CSRF、Exact Originを検査する。
Password、Reset Token、Email Change Token、SMS Code、Full Email、Full Phone、Cookie、Raw Session IDを
Log、Error、Auditへ保存しない。AuditにはPublic IDまたは安全な相関値、Action、
Outcome、Reason、Request IDだけを記録する。

## Operations

V2 Migrationは`apps/api/database/migrations-v2`だけへ追加し、
`scripts/db/v2_database.py`のGuard経由で実行する。V1 Migration、V1 Runtime、
V1本番DBを変更しない。Production DeploymentはHuman checkpointとRelease Gateを
満たすまで行わない。
