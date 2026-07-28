# V2 Identity Recovery／SMS Verification Boundary

## Responsibility

MIG-057はUser Password Reset、User Phone登録・変更、SMS Verificationの
Public Contract、V2 DB、Domain Service、Audit／Outbox境界を所有する。
SMS Provider Transport、UI、Production Deployment、AdminによるPassword設定は所有しない。

## Password Reset

- Request ResponseはAccountの存在・状態にかかわらず同じAccepted Responseとする。
- Verified Emailを持つ対象Userだけに、CSPRNG Tokenを30分、1回限りで発行する。
- DBにはSHA-256 Token Hashだけを保存する。通知に必要なTokenと宛先は
  Application-level EncryptionしたOutbox Payloadにだけ格納する。
- Accountは1時間3回、IPは1時間10回、ConfirmはToken／Account単位で5回に制限する。
  Email、IP、TokenはRate Limit Keyへ平文保存せず、HMAC相関値を使用する。
- Redirectは設定済みRelative Path Allowlistだけを許可する。
- Confirmは既存`V2PasswordPolicy`を使用し、5回失敗、期限切れ、使用済みTokenを拒否する。
- 成功時は全User SessionとRemember Deviceを失効し、新しいSession／CSRFを発行する。
  Password変更通知OutboxとAuditを同一Transactionで確定する。
- Suspicious Recoveryの独自Heuristicは実装しない。正本で確定したSignalを将来接続する
  `V2SuspiciousRecoveryBoundary`だけを提供し、未接続時はSecurity Holdを推測しない。

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
Password、Reset Token、SMS Code、Full Email、Full Phone、Cookie、Raw Session IDを
Log、Error、Auditへ保存しない。AuditにはPublic IDまたは安全な相関値、Action、
Outcome、Reason、Request IDだけを記録する。

## Operations

V2 Migrationは`apps/api/database/migrations-v2`だけへ追加し、
`scripts/db/v2_database.py`のGuard経由で実行する。V1 Migration、V1 Runtime、
V1本番DB、Nginx、Domain、TLSは変更しない。Production DeploymentはUI、
通知Transport、Staging E2Eが完了するまで行わない。
