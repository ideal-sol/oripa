# V2 Authentication Flow Alpha

## Purpose

MIG-041のUser／Admin Realm分離を使い、V2の登録、Email Verification、
Password Login、Admin MFA EnrollmentとSession発行をContract-firstで実装する。
本AlphaはProduction Deployment不可である。MIG-042でAudit／Outbox永続化境界へ
接続したが、実外部Transport、運用監視、Point／Payment基盤などのRelease Gate残項目を
満たすまでProduction Deploymentを許可しない。

## Contract

Public Contractは`openapi/public/openapi.yaml`の6 Operation、Admin Contractは
`openapi/admin/openapi.yaml`の9 Operationを正本とする。Public Bundleから生成する
`@oripa/storefront-client`へAdmin／Webhook型をExportしない。

## User Flow

- 登録時は`pending_verification`とし、同じ未検証Emailの複数登録を許可する。
- Verification TokenはCSPRNGで生成し、DBにはSHA-256 Hashだけを60分保存する。
- 再送は旧Tokenを失効し、Relative Path allowlist外へのRedirectを拒否する。
- Normalized Email単位のDB LockとPartial Unique Indexにより、検証成功は1 Accountに
  限定する。
- LoginはVerified Emailと`active`／`restricted`だけを許可し、Account存在を
  Generic Errorで秘匿する。
- User Sessionは`user_sessions`だけにHash保存し、
  `__Host-oripa_user_session`をSecure、HttpOnly、Host-only、SameSite `Lax`で発行する。

## Admin Flow

- Password成功は5分のPre-auth Transactionだけを発行し、Admin Sessionへ昇格しない。
- MFA成功後だけ`admin_sessions`へSessionを発行する。
- Admin Cookieは`__Host-oripa_admin_session`、Secure、HttpOnly、Host-only、
  SameSite `Strict`で、Remember meを提供しない。
- Initial Ownerは`php artisan v2:identity:create-owner-invitation <email>`からだけ作成する。
  Web Endpointはなく、TokenはHash保存し、30分、1回限り、実行時だけ表示する。
- MFA Enrollmentは初回InvitationまたはRecovery Code使用後に発行する専用Transactionへ
  限定する。既存Active AdminのPassword成功だけではAuthenticatorを追加できない。
- OwnerはAuthenticator 2つ以上かつWebAuthn 1つ以上、Admin／Operatorは
  WebAuthnまたはTOTP 1つ以上を必要とする。

## MFA

- TOTPは6桁、30秒、前後1 Stepを許容し、使用済みTime Stepを再利用できない。
  SecretはLaravel Application Encryptionで暗号化して保存する。
- WebAuthnはAdmin DomainのRP ID、Exact Origin、`userVerification=required`、
  Attestation `none`、5分・1回限りChallengeを使用する。LibraryによるCeremony検証を
  通過したPublic Keyだけを保存する。
- Recovery Codeは128bit以上を10件生成し、Hashだけを保存する。再生成は旧Codeを
  全失効し、使用後SessionはMFA再登録必須として通常Admin Accessを拒否する。
  使用時だけ5分のRecovery Enrollment Transactionを発行し、通常Pre-authと分離する。
- SMS、Email OTP、Security QuestionはAdmin MFAとして使用しない。

## Browser Security

Public／AdminはSession Cookie、CSRF Cookie、SameSite、Originを共有しない。Unsafe
MethodはJSON、CSRF Double Submit、Exact OriginまたはReferer fallbackを要求し、
`Sec-Fetch-Site: cross-site`を拒否する。CORSを認証手段として使用しない。

Rate Limit KeyへEmail平文を使用せず、Application KeyによるHMACを使用する。Critical
Rate LimiterまたはTransaction Storeが利用不能な場合はFail Closedとする。

## Security Events

Register、Verification、Login、Logout、Admin Invitation、MFA、Recovery Code、
Rate LimitのEventは`V2SecurityEventSink`へ送る。MIG-042では
`V2PersistentSecurityEventSink`を`audit_logs`へ接続する。Password、Token、
Raw Session ID、MFA Secret、Recovery Code、Full EmailをEventへ含めず、Session、
IP、User Agentは相関Hashだけを保存する。

Email Verification通知はUser作成／Token更新と同じTransactionで
`outbox_messages`へ保存する。通知内容はApplication-level Encryption済みの
CiphertextだけをPayloadへ持たせ、実Mail Transportは本Taskでは実装しない。

## Verification

Task専用V2 PostgreSQL／Redisだけを使用し、V2 Migration Pathで`migrate:fresh`を
2回実行する。PHPUnit、OpenAPI Bundle、Generated Client、Policy Gate、
Backup／Restoreを継続検証する。V1 Runtime、V1本番DB、V1 Migrationは対象外である。
