# オリパ・パッケージ V2
# 認証・権限・セキュリティ基準 最終確定版

- 文書ID: `V2-IDENTITY-AUTHORIZATION-SECURITY-BASELINE-001`
- 状態: **FINAL / Architecture Baseline 1.0 / Revision 1**
- 確定日: 2026-07-22
- 改訂内容: Password最小長を8文字へ変更。Ownerによるpaid Point手動調整の自己承認を許可。
- 適用対象: オリパ・プラットフォーム V2を導入する各サイトの完全独立環境
- 保存推奨先: `docs/architecture/V2_IDENTITY_AUTHORIZATION_SECURITY_BASELINE_FINAL_2026-07-22.md`

## 優先関係

1. 人間による最新の明示決定
2. 本書
3. `V2_DATA_POINT_PAYMENT_BASELINE_FINAL_2026-07-22.md`
4. `API_V2_AND_STOREFRONT_CLIENT_CONTRACT_FINAL_2026-07-21.md`
5. V2全体設計
6. V1の確定仕様・実装記録

本書は、API契約で意図的に保留されていた次の項目を確定する。

- User / Adminの認証Realm
- Session有効期間
- Remember me
- Password基準
- MFA
- OAuth / OIDC
- Account Linking
- Owner / Admin / Operator
- 再認証
- Rate Limit
- 監査
- Secret管理
- Security Header
- Account Recovery
- Chargeback等に伴うAccount Restriction

---

# 1. 設計根拠と到達目標

## 1.1 参照基準

本設計は、次を技術的な基準として利用する。

- OWASP Application Security Verification Standard 5.0.0
- OWASP Authentication / Authorization / Session Management / MFA / OAuth / Logging / Secrets Management Cheat Sheets
- NIST SP 800-63B-4
- Laravel 13 Authentication / Sanctum / Session / Hashing
- 既に確定したV2 API契約
- 既に確定したV2 DB・ポイント・決済基準
- 現在のプロジェクト仕様と実装状況

本書は、OWASPやNISTへの認証取得を意味しない。

## 1.2 Security Verification目標

全体:

```text
OWASP ASVS 5.0.0 Level 2相当を実装・検証目標
```

次の高リスク領域:

```text
管理者認証
決済
有償・無償ポイント
返金
チャージバック
確率公開
QA抽選
個人情報Export
Provider Secret
```

については、該当するASVS Level 3要件も追加検証する。

---

# 2. 既存仕様として維持する内容

現在のプロジェクトでは、次の基盤が実装済みまたは設計済みである。

- 通常会員登録
- Password Login
- Email Verification
- 未認証Emailの重複許可と、認証成立時の占有
- SMS Verification Backend基盤
- Verified Phoneの重複防止
- Google Login Laravel API基盤
- LINE Account Linking
- SMS Verification後の紹介Point付与
- `admin.<site-domain>`による管理Subdomain
- Owner限定QA Test User / QA Draw Plan
- Admin / OperatorはQA操作に403
- 返金・チャージバック管理API
- Mailgun
- Discord通知

V2ではこれらを捨てず、認証Realm、MFA、権限、監査、Recoveryを強化する。

---

# 3. 脅威モデル

## 3.1 保護する資産

- User Account
- Admin Account
- paid / free Point
- Paymentと返金・チャージバック
- 景品・配送先
- 抽選履歴と確率Version
- QA設定
- 個人情報
- Provider Secret
- OAuth Token
- Session
- Audit Log
- Backup
- Release Pipeline

## 3.2 主な脅威

- Credential Stuffing
- Password Spraying
- Brute Force
- Session Fixation / Session Hijacking
- CSRF
- XSS
- IDOR / Object Level Authorization不備
- Admin権限昇格
- Account LinkingによるAccount Takeover
- SMS SIM Swap
- OAuth `state` / `nonce` / Redirect URI不備
- Webhook偽造・Replay
- Refund / Point Adjustmentの内部不正
- QA機能の不正利用
- Secret漏えい
- Site AからSite Bへの誤接続
- CodexやCIへのProduction Secret混入
- Dependency / Container Supply Chain
- Audit Log改ざん
- Backup漏えい

---

# 4. 最終決定一覧

| 項目 | V2確定内容 |
|---|---|
| Site間の認証 | 完全分離 |
| Site間のUser共有 | 行わない |
| Site間のAdmin共有 | 行わない |
| UserとAdmin | 別Table・別Provider・別Guard |
| UserとAdmin Session | 別Cookie・別Session Store |
| Public / Admin / Webhook | 別HTTP Surface |
| Default Laravel配置 | 同一Source / Imageから3 Runtime |
| Browser認証 | Laravel Sanctum Session Cookie |
| Browser Token保存 | Local Storage禁止 |
| User Session | Idle 60分、Absolute 24時間 |
| User Remember me | 任意、30日、Device別・Rotation |
| Admin Session | Idle 15分、Absolute 8時間 |
| Admin Remember me | 禁止 |
| User Step-up | 10分間有効 |
| Admin Step-up | 5分間有効 |
| Password | 8～128文字 |
| Password規則 | 文字種強制なし、Blocklistあり |
| Password Hash | Argon2id |
| 定期Password変更 | 要求しない |
| User MFA | 任意 |
| Admin MFA | 全員必須 |
| Owner MFA | 2つ以上、うち1つはWebAuthn |
| Admin SMS / Email MFA | 禁止 |
| Admin Role | Owner / Admin / Operator固定 |
| Custom Role | V2.0では作らない |
| QA管理 | Owner限定＋直前再認証 |
| Authorization | Deny by default、毎RequestでPolicy |
| API CORS | 原則無効、Same-Origin Proxy |
| CSRF | Sanctum＋Origin検証 |
| Public / Admin Cookie | Host-only |
| Session ID | Login・MFA・権限変更時に再生成 |
| Audit | Append-only＋Tamper Evidence |
| Secret | Site・Environmentごとに別 |
| Codex | Production Secret / PIIへアクセス禁止 |
| Security Test | CI＋Staging DAST＋公開前Pentest |

---

# 5. Siteごとの完全独立

各Siteは次を個別に持つ。

```text
Site A
├── User Account
├── Admin Account
├── User Session
├── Admin Session
├── OAuth Client
├── MFA Credential
├── Provider Secret
├── Audit Log
├── Database
├── Redis
├── Storage
└── Backup
```

Site Bはこれらを共有しない。

## 禁止

- Cross-site SSO
- 共通User Database
- 共通Admin Account
- 共通Session Cookie
- 共通OAuth Client Secret
- 共通APP_KEY
- 共通Webhook Secret
- Site AのStorefrontからSite B APIへの接続
- Site間のAccount Linking

共通化するのはSource Code、Migration、Container Image、API Contract、Storefront Clientだけとする。

---

# 6. HTTP Surfaceの分離

## 6.1 Default Runtime

1 Siteあたり、同一Laravel Source / Container Imageから次を起動する。

```text
api-public
api-admin
api-webhook
```

### `api-public`

- `/api/v2/*`
- User Guard
- User Session Cookie
- Sanctum / CSRF
- Storefront Hostからだけ到達可能

### `api-admin`

- `/admin/api/v2/*`
- Admin Guard
- Admin Session Cookie
- Sanctum / CSRF
- Admin Hostからだけ到達可能

### `api-webhook`

- `/webhooks/v2/*`
- Browser Session Middlewareなし
- CSRF対象外
- Provider Signature必須
- Webhook Hostからだけ到達可能

小規模環境でRuntimeを統合する場合も、Route、Middleware、Cookie、Guard、Session Table、Nginx到達経路が同等に分離されていることをIntegration Testで証明し、別ADRを作成する。

## 6.2 Nginx制約

```text
www.example.jp/api/v2/*
    → api-public

admin.example.jp/admin/api/v2/*
    → api-admin

hooks.example.jp/webhooks/v2/*
    → api-webhook
```

次は404または接続拒否とする。

```text
www.example.jp/admin/api/v2/*
admin.example.jp/api/v2/*
www.example.jp/webhooks/v2/*
未知のHost Header
```

---

# 7. Identity Realm

## 7.1 UserとAdminを分離

### User

```text
users
```

### Admin

```text
admins
```

別のLaravel Auth Providerを使用する。

```text
user guard
admin guard
```

禁止:

- `users.role = owner`
- User SessionをAdminへ昇格
- 同じSession内でUser / Adminを切替
- User APIがAdmin Guardを受理
- Admin APIがUser Guardを受理

同じ人物が顧客UserとAdminを兼ねる場合も、別Account・別Credential・別Sessionとして扱う。

---

# 8. User Account Lifecycle

## 8.1 状態

```text
pending_verification
active
restricted
suspended
closed
anonymized
```

### `pending_verification`

- Email Verification前
- Login完了扱いにしない
- Point購入、抽選、交換、配送を不可
- Verificationと再送だけを許可

### `active`

通常利用可能。

### `restricted`

一部機能を制限する。

### `suspended`

- Login不可
- 全Session失効
- Remember Device失効
- 管理者理由必須

### `closed`

退会済み。Login不可。

### `anonymized`

保持が必要な金融・Point・Draw Relationを残し、個人情報を匿名化した状態。

---

# 9. Email Identity

## 9.1 正規化

Emailは次を行う。

- 前後空白を除去
- Unicode NFC
- Domainを小文字化
- Application上の占有判定は正規化済み値で実施
- Original表示値とNormalized値を分離可能

## 9.2 未認証Email重複ルール

既存ルールを維持する。

```text
同じEmailのpending User
→ 複数存在可能

Verified Email
→ 1 Site内で1 Userだけ
```

DBでは、Verified EmailだけにPartial Unique Constraintを設定する。

Verification時:

```text
1. Normalized Email単位でLock
2. Tokenを検証
3. Verified Emailの既存占有を確認
4. 未占有なら当該UserをVerified化
5. 同じEmailの他のPending Verificationを失効
6. 競合Accountをemail_conflict状態へ
7. Sessionを再生成
8. Audit / Notification
```

同時VerificationはDB Constraintで1件だけ成功させる。

他のPending Userには、他Accountの存在を詳しく開示せず、Verification不能を通知する。

## 9.3 Verification Link

- CSPRNG Token
- DBにはHashだけを保存
- 1回限り
- 有効期限60分
- 再送で以前のTokenを失効
- ResendはRate Limit対象
- Redirect先はRelative Path allowlist

Pending Registrationは既定7日で期限切れとし、再登録可能にする。保持期間はPrivacy方針に従う。

## 9.4 Email変更

- Fresh Reauthentication必須
- 新Emailを先にVerification
- Verification完了まで旧EmailをLogin Identityとして維持
- 旧Emailと新Emailの両方へ通知
- 完了時に全Remember Deviceを失効
- 他Sessionを失効
- Audit必須

---

# 10. Phone / SMS Verification

## 10.1 用途

V2.0のSMSは次に使用する。

- Phone Ownership Verification
- 紹介Point付与条件
- Risk Signal
- User Notification

使用しないもの:

- Admin MFA
- Admin Recovery
- User Password Resetの唯一の要素
- User Loginの唯一のCredential

## 10.2 Phone

- E.164形式へ正規化
- Verified Phoneは1 Site内で1 Userだけ
- 未認証ChallengeはPhoneを占有しない
- Phone変更はFresh Reauthentication必須
- 旧Emailへ通知

## 10.3 SMS OTP

- 6桁
- CSPRNG
- 有効期限5分
- 1回限り
- 最大5回試行
- 再送間隔60秒
- DBにはOTP Hashだけを保存
- 新しいOTP発行で失敗回数を無条件にResetしない
- SIM Swap等のRisk Signalを利用可能にする

---

# 11. Password基準

## 11.1 User / Admin共通

```text
Minimum: 8文字
Maximum: 128文字
```

- Spaceを許可
- Unicodeを許可
- Silent Truncation禁止
- 大文字・小文字・数字・記号の組合せ強制なし
- Password ManagerとPasteを許可
- Password Strength Meterを表示可能
- Common / Compromised Password Blocklistを適用
- Password HintとSecurity Questionを使用しない
- 定期変更を要求しない
- 漏えい・侵害の根拠がある場合だけ強制変更

8文字はユーザビリティを優先した製品基準とする。短いPasswordによるRiskは、Common / Compromised Password Blocklist、Argon2id、Rate Limit、Credential Stuffing検知、User MFAの提供、Admin MFA必須で補完する。UIでは12文字以上またはPassphraseを推奨表示するが、必須条件にはしない。

Errorは、認証前にはAccount存在の有無が分からないGeneric Messageを使用する。

## 11.2 Hash

```text
Argon2id
```

Default:

```text
memory_cost = 64 MiB
time_cost = 3
threads = 1
```

各環境でLogin 1回あたりおおむね250～500msを目安にBenchmarkする。

最低でも次を下回らない。

```text
19 MiB
2 iterations
1 thread
```

Hash Parameterを更新した場合は、成功Login時に`needsRehash`で段階更新する。

Passwordを暗号化保存、平文保存、独自SHA-256保存してはいけない。

---

# 12. User Login方式

V2.0で許可する。

```text
Email + Password
Google OpenID Connect
Remember Device
Optional User MFA
```

LINEはV2.0ではAccount Linking / Reward用途とし、Primary Loginには使用しない。

Apple Loginは別ADRで追加する。

## 12.1 Login前提

Password Login:

- Verified Email
- Accountがactiveまたは許可されたrestricted状態
- Rate Limit通過

Google Login:

- OIDC検証成功
- Identity Link済み、または安全な新規Account作成が成立

---

# 13. OAuth / OpenID Connect

## 13.1 Flow

```text
Authorization Code Flow
＋
PKCE S256
```

禁止:

- Implicit Flow
- Resource Owner Password Grant
- Wildcard Redirect URI
- Client側だけのID Token検証
- `email`だけによるAccount自動Link

## 13.2 必須検証

- `state`
- PKCE `code_verifier`
- OIDC `nonce`
- Issuer
- Audience
- Signature
- Expiration
- Authorized Partyがある場合の検証
- Exact Redirect URI
- ProviderのVerified Email
- One-time Authorization Code

`state`、`nonce`、PKCE TransactionはCSPRNGで生成し、Browser SessionへBindingし、10分で失効させる。

## 13.3 Identity Key

External Identityは次で一意にする。

```text
provider_issuer
provider_subject
```

EmailをProvider IdentityのPrimary Keyにしない。

## 13.4 Account Linking

既存Verified EmailとGoogle Emailが一致しても、自動Linkしない。

既存AccountへLinkするには:

```text
1. 既存AccountへLogin
2. Fresh Reauthentication
3. OAuth Flow完了
4. Provider IdentityをLink
5. 全Session / Notification / Audit
```

Userが未Loginで、同じVerified Emailの既存Accountがある場合は、既存LoginまたはRecoveryへ誘導する。Account存在の詳細は最小限にする。

## 13.5 Token保存

- Access Tokenを原則保存しない
- Refresh Tokenは機能上必要な場合だけ保存
- 保存するTokenはApplication-level Encryption
- Scopeを最小化
- Log / Discord / Sentryへ出さない
- Unlink時にProvider側Revocationが可能なら実行

---

# 14. MFA

## 14.1 User MFA

User MFAは任意とする。

V2の対応Method:

- TOTP
- WebAuthn / Passkey

Commercial Releaseにおける必須条件:

- User MFAを有効化・無効化できる
- Recovery Codeを発行できる
- MFA変更時にSession失効・通知・Auditが行われる

UserにMFAを強制するかは、Site設定またはRisk Policyで決める。

SMSとEmailはUser MFAの標準Methodにしない。

## 14.2 Admin MFA

すべてのOwner / Admin / Operatorに必須。

許可:

- WebAuthn / Passkey
- TOTP
- One-time Recovery Code

禁止:

- SMS
- Email OTP
- Security Question

### Owner

Ownerは次を必須とする。

```text
2つ以上のAuthenticator
かつ
少なくとも1つはWebAuthn / Passkey
```

Recovery Codeは2つ目のAuthenticatorに数えない。

### Admin / Operator

少なくとも1つのMFA Methodを登録する。

WebAuthnを優先し、TOTPをFallbackとして許可する。

## 14.3 TOTP

- 標準TOTP
- 6桁
- 30秒Step
- 許容Windowは前後1 Step
- 同じTime StepのCode再利用禁止
- SecretはApplication-level Encryption
- QR表示はEnrollment時だけ
- AuditにSecretを含めない

## 14.4 WebAuthn

- RP IDをSiteのAdmin Domainへ固定
- Allowed OriginをExact Match
- `userVerification = required`
- ChallengeはCSPRNG、1回限り、5分以内
- Public Keyだけを保存
- Credential IDを一意保存
- Sign Count等の異常を記録
- Attestationは原則`none`
- Credential名、登録日時、最終利用日時を表示
- Credential削除はFresh MFA必須

## 14.5 Recovery Code

- 10個発行
- 各Codeは128bit以上のEntropy
- DBにはHashだけを保存
- 1回限り
- 表示は発行時だけ
- 再生成すると旧Codeを全失効
- 使用時にUser / Adminへ通知
- Admin Recovery Code使用後は、新Authenticator登録を要求

---

# 15. Admin Account Lifecycle

## 15.1 Self Registration禁止

Admin Accountは公開画面から作成しない。

作成方法:

```text
初回Owner
→ CLI Bootstrap

追加Admin
→ OwnerがInvitation作成
```

## 15.2 Initial Owner

CLIは次を行う。

- Server Consoleからのみ実行
- 一時Invitationを作成
- Invitation TokenはHash保存
- 有効期限30分
- 初回LoginでPasswordとMFAを設定
- 完了後にToken失効
- Audit Log作成

Productionは次のいずれかを満たす。

```text
A. Active Ownerが2名以上
B. Active Owner 1名＋Test済みOffline Break-glass
```

共有Owner Accountは禁止する。

## 15.3 Admin Invitation

- Ownerだけが発行
- 有効期限24時間
- TokenはHash保存
- 1回限り
- Email Verified必須
- MFA Enrollment完了までAdmin画面へ入れない
- Invitation Resendで旧Token失効

## 15.4 状態

```text
invited
active
suspended
disabled
```

Role変更、Suspend、MFA Reset時:

- Fresh Owner MFA
- 対象Adminの全Session失効
- TargetとOwnerへ通知
- Audit必須

最後の有効Ownerを無効化・降格できない。

---

# 16. RoleとPermission

V2.0のSystem Roleは固定する。

```text
Owner
Admin
Operator
```

Custom Role Editorは作らない。

Permission判定はRole名の直接比較を各Controllerへ散在させず、Permission CodeとLaravel Policy / Gateで行う。

## 16.1 Permission Matrix

| 操作 | Owner | Admin | Operator | 追加条件 |
|---|---:|---:|---:|---|
| Admin追加・Role変更・無効化 | 可 | 不可 | 不可 | Fresh MFA |
| Admin MFA Reset | 可 | 不可 | 不可 | Fresh WebAuthn推奨 |
| Security設定 | 可 | 不可 | 不可 | Fresh MFA |
| Provider Secret設定 | 可 | 不可 | 不可 | Write-only＋Fresh MFA |
| Security Audit閲覧 | 可 | 制限付き | 不可 | |
| Operational Audit閲覧 | 可 | 可 | 制限付き | |
| Gacha / Prize閲覧 | 可 | 可 | 可 | |
| Gacha Draft作成・編集 | 可 | 可 | 可 | |
| Gacha公開・停止 | 可 | 可 | 不可 | Fresh MFA |
| Probability Draft編集 | 可 | 可 | 不可 | |
| Probability Version公開 | 可 | 可 | 不可 | Fresh MFA |
| Content / Banner / Notice | 可 | 可 | 可 | |
| User検索・Masked情報 | 可 | 可 | 可 | |
| User Full PII | 可 | 可 | Shipping範囲のみ | Access Audit |
| User Suspend / Restriction | 可 | 可 | 不可 | 理由必須 |
| QA Test Mode / Plan | 可 | 不可 | 不可 | Fresh MFA |
| Point残高・Ledger閲覧 | 可 | 可 | Support範囲のみ | |
| free Point手動調整 | 可 | 可 | 不可 | Fresh MFA＋理由 |
| paid Point調整Request | 可 | 可 | 不可 | Fresh MFA |
| paid Point調整Approve / Execute | 可 | 不可 | 不可 | Fresh MFA＋理由＋Audit（Owner自己承認可） |
| Payment / Sales閲覧 | 可 | 可 | User個別Statusのみ | |
| Financial CSV Export | 可 | 可 | 不可 | Fresh MFA |
| 通常返金実行 | 可 | 可 | 不可 | Fresh MFA＋再Eligibility |
| Chargeback閲覧 | 可 | 可 | 制限付き | |
| CB Manual Restoration | 可 | 不可 | 不可 | Fresh MFA＋理由 |
| CB Hold解除 | 可 | 可 | 不可 | Fresh MFA |
| Shipping処理 | 可 | 可 | 可 | Address Access Audit |
| 問い合わせ対応 | 可 | 可 | 可 | |
| Branding / 一般設定 | 可 | 可 | Content範囲のみ | |
| Backup / Restore | Infraのみ | Infraのみ | 不可 | App UIから実行不可 |

## 16.2 paid Point手動調整の承認統制

paid Pointの手動調整は、Ownerだけが承認・実行できる。

```text
Requester = Admin
→ Ownerが承認・実行

Requester = Owner
→ 同じOwnerが自己承認・実行可能
```

Ownerの自己承認を許可する。別Ownerによる承認は必須としない。

ただし、自己承認を含むすべてのpaid Point手動調整で、次を必須とする。

- Fresh MFA
- 調整理由とReason Code
- 対象User、paid Point量、変更前後残高の確認画面
- 明示的な最終確認
- Idempotency-Key
- Point Operation・Ledger・Audit Logへの記録
- 実行後の全Ownerへの通知
- 日次Reconciliation対象への追加

単独OwnerのProduction環境でも、通常の管理画面から申請・自己承認・実行できる。Break-glass手順は、通常認証やMFAを利用できない緊急復旧時だけに使用する。

## 16.3 OperatorのPII

OperatorはUser一覧で次をMasked表示する。

```text
Email
Phone
Address
```

Shipping作業中の対象Requestだけ、必要な配送情報を閲覧できる。

閲覧自体をAuditする。

---

# 17. Authorization実装規則

- Deny by default
- 毎RequestでBackend Authorization
- UI非表示だけに依存しない
- Resource Ownershipを毎回確認
- Public IDが推測困難でもAuthorizationを省略しない
- Admin Permission＋Resource State＋Contextを確認
- Mass Assignment Allowlist
- Dedicated Request DTO
- Dedicated API Resource
- Query ScopeでPIIと非公開情報を制限
- 存在を秘匿すべきResourceは404
- Permission不足は403
- Invalid Workflow Transitionは409または422
- PolicyなしのAdmin MutationをCIで検出

Site専用Frontendは権限の正本にならない。

---

# 18. Fresh Reauthentication / Step-up

## 18.1 User

Fresh Reauthenticationの有効時間:

```text
10分
```

対象:

- Password変更
- Email変更
- Phone変更
- OAuth Link / Unlink
- MFA登録・削除
- Recovery Code再生成
- Remember Device管理
- 他Session失効
- 新しいShipping Address登録・変更
- Security Riskで指定された操作

方法:

- Password
- WebAuthn
- Fresh OIDC Login
- MFA有効UserはMFAも要求可能

Remember Deviceだけで復元されたSessionは、上記操作前に必ずFresh Authenticationを要求する。

## 18.2 Admin

Fresh MFAの有効時間:

```text
5分
```

対象:

- Permission MatrixでFresh MFA指定の全操作
- Admin管理
- Provider Secret
- Refund
- paid Point
- Probability公開
- QA
- Financial Export
- Security設定
- CB Restoration / Hold解除

Password再入力だけではFresh MFAを満たさない。

---

# 19. Session設計

## 19.1 Storage

DefaultはDatabase Session Driverとする。

```text
user_sessions
admin_sessions
```

別Tableを使用する。

Session Dataは最小限にし、Payment CredentialやPIIを保存しない。

Session IDはLogへ出さず、必要な相関にはKeyed Hashを使用する。

## 19.2 User Session

```text
Idle Timeout:     60分
Absolute Timeout: 24時間
```

Cookie:

```text
Name:     __Host-oripa_user_session
Secure:   true
HttpOnly: true
SameSite: Lax
Path:     /
Domain:   未指定
```

CSRF Cookie:

```text
Name:     __Host-oripa_user_xsrf
Secure:   true
HttpOnly: false
SameSite: Lax
Path:     /
Domain:   未指定
```

## 19.3 Admin Session

```text
Idle Timeout:     15分
Absolute Timeout: 8時間
```

Cookie:

```text
Name:     __Host-oripa_admin_session
Secure:   true
HttpOnly: true
SameSite: Strict
Path:     /
Domain:   未指定
```

CSRF Cookie:

```text
Name:     __Host-oripa_admin_xsrf
Secure:   true
HttpOnly: false
SameSite: Strict
Path:     /
Domain:   未指定
```

AdminにRemember meを提供しない。

## 19.4 Session ID再生成

必須:

- Password Login成功
- OAuth Login成功
- MFA成功
- Fresh Reauthentication成功
- Password Reset
- Email / Phone変更
- Role / Permission変更
- MFA変更
- Account Recovery
- AnonymousからAuthenticatedへの遷移

Logout:

```text
Auth Logout
→ Server Session invalidate
→ CSRF Token regenerate
→ Cookie expire
```

## 19.5 Session管理画面

UserとAdminは、自身のActive Sessionを確認・失効できる。

表示:

- Device Label
- Browser
- Rough Location
- Created At
- Last Used At
- Current Session
- Remember Deviceか

Raw Session IDや完全IPは表示しない。

---

# 20. User Remember me

Laravel標準の単一`remember_token`だけに依存せず、Device別Persistent Loginを実装する。

```text
user_remember_devices
```

主要項目:

- user ID
- selector
- token hash
- device label
- created at
- last used at
- expires at
- revoked at
- rotation counter

規則:

- Userが明示選択した場合だけ
- 最大30日
- 使用ごとにToken Rotation
- TokenはDBへHash保存
- Device単位で失効可能
- Password / Email / MFA / Recovery変更時に全失効
- Replay検出時にToken Family全失効
- Remember Loginは低いAssuranceとしてSessionへ記録
- Sensitive Action前にFresh Authentication

---

# 21. CSRF / Origin / CORS

## 21.1 CSRF

Browser Sessionを使うUnsafe Method:

```text
POST
PUT
PATCH
DELETE
```

にCSRFを必須とする。

Storefront Client / Admin ClientがSanctum CSRF Cookieを取得し、Headerを送る。

追加防御:

- `Origin` Exact Match
- `Referer`のFallback確認
- `Sec-Fetch-Site`が`cross-site`のUnsafe Requestを拒否
- JSON Content-Typeを要求
- GETで状態変更しない

## 21.2 例外

次はBrowser CSRF対象外。

- Provider Webhook
- OAuth Callback
- Payment Provider Callback

代わりに、Signature、`state`、`nonce`、PKCE、One-time Tokenを使用する。

## 21.3 CORS

Same-Origin Reverse Proxyを前提とし、CORSは原則無効。

有効化が必要な場合:

- Exact Origin allowlist
- `*`禁止
- Credential時のWildcard禁止
- `Vary: Origin`
- Allowed Method / Headerを最小化
- 別ADRとSecurity Test

---

# 22. Rate Limit

以下はV2 Defaultである。

Siteが緩和する場合は、理由・負荷試験・Security Reviewを記録する。

| Endpoint / Operation | Default |
|---|---|
| User Login失敗 | Account＋IPで5回 / 15分 |
| User Login全体 | IPで30回 / 1時間 |
| Admin Login失敗 | Account＋IPで5回 / 15分 |
| Admin Login全体 | IPで20回 / 1時間 |
| MFA Verify | Sessionで5回 / 5分 |
| Register | IPで5回 / 1時間、Emailで3回 / 1時間 |
| Password Reset Request | Accountで3回 / 1時間、IPで10回 / 1時間 |
| Password Reset Confirm | Token / Accountで5回 |
| Email Verification Resend | Accountで3回 / 1時間、10回 / 日 |
| SMS Send | Phoneで3回 / 1時間、10回 / 日、IPで5回 / 1時間 |
| SMS Verify | Challengeで5回 |
| Anonymous Public API | IPで60回 / 分 |
| Authenticated User API | Userで120回 / 分 |
| Draw Mutation | Userで20回 / 分 |
| Payment Create | Userで10回 / 10分 |
| Prize Exchange / Shipping | Userで10回 / 分 |
| Contact Request | IPで5回 / 1時間 |
| Admin Read | Adminで300回 / 分 |
| Admin Mutation | Adminで60回 / 分 |
| Critical Admin Mutation | Adminで10回 / 10分 |
| Financial Export | Adminで5回 / 1時間 |
| Webhook | Provider Endpointで600回 / 分 |

追加規則:

- AccountとIPの両方をKeyにする
- Email / PhoneはHMACしてRate Limit Keyに使用
- IPだけの永久Lock禁止
- Exponential Backoff
- Generic Error
- `429`＋`Retry-After`
- CAPTCHAは失敗回数増加後のDefense-in-depth
- Critical Rate Limiterが利用不能な場合はFail Closed
- Credential Stuffingを検知してAlert

---

# 23. Account Recovery

## 23.1 User Password Reset

- Request Responseは常にGeneric
- TokenはCSPRNG
- DBにはHash保存
- 有効期限30分
- 1回限り
- 5回失敗でToken失効
- Open Redirect禁止
- Reset成功時に全SessionとRemember Deviceを失効
- Password変更通知
- Suspicious RecoveryはSecurity Hold

Support OperatorはUser Passwordを直接設定できない。

## 23.2 User MFA Recovery

優先順:

1. Recovery Code
2. 別の登録Authenticator
3. Fresh OIDC Identity
4. Manual Recovery Process

Manual Recoveryでは、SupportだけでEmail・Phone・MFAを変更しない。

Owner承認、本人確認記録、Session全失効、通知、Auditを必須とする。

## 23.3 Admin MFA Recovery

- 別Authenticator
- Recovery Code
- 別OwnerによるMFA Reset
- Offline Break-glass

Owner / Admin / Operator本人が自分のMFAを無確認で解除できない。

別OwnerによるReset:

- Reset実行OwnerのFresh WebAuthn
- 対象Admin全Session失効
- 一時Recovery Session
- 新MFA登録必須
- 全Ownerへ通知
- Security Audit

## 23.4 Break-glass

- Web Endpointを作らない
- Server Consoleだけ
- Offline SecretまたはInfrastructure Access
- 一時Owner Invitationだけを発行
- 使用後にSecret Rotation
- Incident Ticket
- 全Ownerへ通知
- Audit Digestへ記録
- 定期的にRecovery Test

---

# 24. Account Restriction

## 24.1 `user_restrictions`

例:

```text
security_hold
financial_hold
purchase_blocked
draw_blocked
exchange_blocked
shipping_blocked
login_blocked
```

主要項目:

- user ID
- restriction type
- reason code
- source type / ID
- imposed by
- imposed at
- expires at
- released by
- release reason
- released at

## 24.2 Chargeback

成功したChargebackまたは未解決Shortfallでは、自動的に`financial_hold`を付与する。

Defaultで禁止:

- Point購入
- 抽選
- 景品Point交換
- 新規配送依頼

許可:

- Login
- Chargeback / 返送案内の閲覧
- 問い合わせ
- 返送手続き
- 自身の履歴閲覧

Hold解除:

- OwnerまたはAdmin
- Fresh MFA
- 理由必須
- Shortfall / Prize Action再確認
- Audit

## 24.3 Security Hold

Account Takeover疑いでは:

- 新規Payment
- Draw
- Exchange
- Shipping
- Credential変更

を停止し、Recovery Flowへ誘導する。

## 24.4 QA

- restricted / suspended UserへQA Modeを設定しない
- QA Modeは認証・権限・制限をBypassしない
- Owner＋Fresh MFA
- 最大24時間
- Audit必須

---

# 25. Audit Log

## 25.1 `audit_logs`

Append-onlyとする。

主要項目:

- public ID
- occurred at
- business date
- request ID
- actor type
- actor ID
- actor role
- auth realm
- session correlation hash
- action code
- target type
- target public ID
- outcome
- reason code
- reason text
- IP encrypted / correlation hash
- user agent hash
- before redacted
- after redacted
- metadata redacted
- previous hash
- record hash

禁止:

- Password
- Session ID
- CSRF Token
- Recovery Code
- TOTP Secret
- OAuth Token
- Provider Secret
- PAN / CVV
- Raw Authorization Header
- 不要なFull Address

## 25.2 必須Event

### Authentication

- Register
- Email Verification
- Login Success / Failure
- Logout
- Password Reset
- Password Change
- MFA Enrollment / Removal / Failure
- Recovery Code Use
- OAuth Link / Unlink
- Session Revoke
- Remember Device Add / Revoke
- Account Suspend / Restrict

### Admin

- Invitation
- Role Change
- MFA Reset
- Security Setting
- Provider Secret Update
- PII View
- Export
- Refund
- Point Adjustment
- Probability Publish
- Gacha Publish
- QA Mode / Plan
- Chargeback Hold / Restoration
- Shipping Address Access

### System

- Webhook Signature Failure
- Rate Limit Trigger
- Reconciliation Difference
- Secret Rotation
- Backup / Restore
- Migration
- Break-glass

## 25.3 Tamper Evidence

- DB UPDATE / DELETEをApplication Roleへ許可しない
- Record Hash Chain
- Audit HMAC KeyをDBと分離
- Daily DigestをObject Storageへ保存
- Object Lock / WORMが利用可能なら有効化
- UIから削除不可
- ClockをNTP同期
- AlertでAudit書込失敗を検知

## 25.4 Retention Default

これはPackage Defaultであり、法務要件を代替しない。

```text
金融・Point・Privileged Admin Audit: 7年
Authentication / Security Event:     2年
Access Log:                           1年
Debug Log:                            30日
Security Incident関連:               7年
```

Siteの法務・Privacy方針がより長い場合はそちらを優先する。

---

# 26. PIIとData Protection

- PIIは必要最小限
- Full PIIをList APIへ含めない
- UIでMask
- PII AccessをAudit
- Shipping AddressはApplication-level Encryptionを標準
- OAuth / TOTP / Provider TokenはApplication-level Encryption
- Database Volumeを暗号化
- Backupを暗号化
- Backup KeyをBackupと別管理
- Discord / Mail / AnalyticsへFull PIIを送らない
- Production DB DumpをDeveloper PCやCodexへ渡さない
- Test FixtureはFake Data
- User削除は金融履歴をCascade削除せず匿名化

検索に必要なEmail / Phone Normalized値は、厳格なDB Access Control下で保持する。LogへはHMACまたはMask値だけを出す。

---

# 27. Secret / Key管理

## 27.1 SiteとEnvironment

次をすべて別にする。

```text
Site A Production
Site A Staging
Site B Production
Site B Staging
```

Secret再利用禁止。

## 27.2 Secret例

- Laravel APP_KEY
- Audit HMAC Key
- Authentication Encryption Key
- Database Credential
- Redis Credential
- Payment API Key
- Webhook Secret
- Mailgun Key
- SMS Key
- OAuth Client Secret
- LINE Secret
- Storage Key
- Backup Encryption Key
- GitHub Deployment Credential

## 27.3 保存場所

優先:

1. Cloud Secret Manager / Vault
2. Docker SecretまたはRoot-only Mounted File
3. やむを得ない場合のみServer `.env`、権限600

禁止:

- Git
- Container Image
- Frontend Bundle
- `NEXT_PUBLIC_*`
- Codex Prompt
- Issue / PR本文
- Chat
- Log
- Screenshot
- DBの一般設定Table

## 27.4 Application UI

Secret設定画面:

- 現在値を再表示しない
- `設定済み`と末尾識別だけ表示
- UpdateはWrite-only
- Fresh Owner MFA
- Audit
- Connection TestでもSecretをResponseへ返さない

## 27.5 Rotation

- Key Versionを保持
- Current / Previousの重複期間を設ける
- Rotation手順をRunbook化
- Incident時は即時Rotation
- 定期RotationはProvider仕様とRiskに応じる
- APP_KEY等のData Encryption Keyは、復号Key Ringと再暗号化計画なしに直接変更しない

---

# 28. HTTP / Browser Security

## 28.1 TLS

- ProductionはHTTPSのみ
- TLS 1.2以上
- TLS 1.3推奨
- HTTPはHTTPSへRedirect
- Mixed Content禁止
- Certificate自動更新
- Unknown Host拒否

## 28.2 Header

Production Default:

```text
Strict-Transport-Security:
  max-age=31536000; includeSubDomains

X-Content-Type-Options:
  nosniff

Referrer-Policy:
  strict-origin-when-cross-origin

X-Frame-Options:
  DENY

Permissions-Policy:
  camera=(), microphone=(), geolocation=()

Cross-Origin-Resource-Policy:
  same-site
```

Storefront:

```text
Cross-Origin-Opener-Policy:
  same-origin-allow-popups
```

Admin:

```text
Cross-Origin-Opener-Policy:
  same-origin
```

HSTS `preload`は、全SubdomainのHTTPS運用を確認した後に別途有効化する。

## 28.3 CSP

Nonce-based CSPを採用する。

最低限:

```text
default-src 'self'
script-src 'nonce-<random>' 'strict-dynamic'
object-src 'none'
base-uri 'none'
frame-ancestors 'none'
form-action 'self' <approved-payment-provider>
connect-src 'self' <approved-provider>
img-src 'self' https: data:
style-src 'self' 'nonce-<random>'
```

- Productionで`unsafe-eval`禁止
- Scriptの`unsafe-inline`禁止
- Provider DomainはSiteごとにAllowlist
- StagingでReport-Only検証後、ProductionでEnforce
- CSP Violationを監視
- AdminはStorefrontより厳格にする

## 28.4 Cache

Authenticated User / Admin Response:

```text
Cache-Control: private, no-store
```

Admin HTML:

```text
Cache-Control: no-store
X-Robots-Tag: noindex, nofollow
```

---

# 29. XSS / Content / Upload

- Reactの通常Escapeを維持
- `dangerouslySetInnerHTML`を原則禁止
- Rich TextはServer側Allowlist Sanitization
- `javascript:` URL禁止
- Open Redirect禁止
- User入力をHTMLとして保存しない
- SQLはParameterized Query / Eloquent
- Mass Assignment Guard
- ValidationはServer側正本

## Upload

- ExtensionだけでなくMagic Byte確認
- MIME Allowlist
- Size Limit
- Random Object Key
- User File NameをPathへ使わない
- Webroot外またはObject Storage
- 実行権限なし
- Malware Scan
- Image Re-encodeを推奨
- Admin UploadのSVGは禁止
- SVGはRepositoryへReview済みAssetとしてのみ追加
- Downloadは適切なContent-Type / Content-Disposition
- Video処理はQueue Worker
- Upload失敗時に内部Pathを返さない

---

# 30. Webhook Security

- HTTPS
- Raw Body Size Limit
- Provider Signature検証
- Timestamp ToleranceをProvider仕様に従って設定
- Event ID Unique
- Replay拒否
- Secret Rotation対応
- Session Cookieを使用しない
- CSRF対象外
- IP Allowlistは補助
- Signatureが正本
- Provider Eventを処理前に保存
- Error Responseへ内部情報を出さない
- Queueへ渡す前にSignature検証
- ProviderごとにRate Limit
- ProductionとStaging Secretを分離

---

# 31. Infrastructure Identity

## 31.1 Database Role

分離する。

```text
app_runtime
migration
readonly_reporting
backup
```

`app_runtime`には次を許可しない。

- CREATE DATABASE
- DROP DATABASE
- ALTER Schema
- Role管理
- Audit Log DELETE / UPDATE

Migration CredentialはDeploy時だけ使用する。

## 31.2 Redis

- Public Internetへ公開しない
- Password / ACL
- TLSまたはPrivate Network
- Siteごとに分離
- Queue PayloadへSecretを入れない
- Sessionを使用する場合もUser / Admin Namespaceを分離

## 31.3 Object Storage

- Private Default
- Public Asset PrefixとPrivate Export Prefixを分離
- Exportは短期Signed URL
- Bucket PolicyはSiteごと
- Backup BucketはApplicationから直接削除不可
- Versioning / Object Lockを利用可能なら有効化

## 31.4 Scheduler / Queue

- Browser Sessionを持たない
- Service Identityを使用
- 最小権限
- Job PayloadへFull PII / Secretを入れない
- Job ID / Request IDで相関
- Failed Jobの閲覧もAdmin Permission対象

---

# 32. Codex / Git / CI Security

## 32.1 Codex

Platform Codex:

- Auth / Security Code変更可能
- Production Secretへアクセス不可
- Production DBへアクセス不可
- Real PIIをPromptへ含めない
- Secretが必要なTestはDummy値

Site専用Codex:

- Auth / SDK内部 / Admin / Laravel変更禁止
- `/api/v2`直接Fetch禁止
- Cookie名参照禁止
- Security Header緩和禁止
- CSP Allowlist変更はPlatform Change Request
- External Script追加はReview対象

## 32.2 Git

- `main`直接Push禁止
- Branch Protection
- PR必須
- Security Sensitive PathにCODEOWNERS
- Signed CommitまたはVerified CI Identity推奨
- Secret Scanning
- `.env`、Key、DB Dump禁止
- HistoryからのSecret削除Runbook

Security Sensitive Path例:

```text
apps/api/app/Domain/Identity/**
apps/api/app/Http/Middleware/**
apps/api/config/auth.php
apps/api/config/session.php
apps/api/config/sanctum.php
apps/api/routes/admin_v2.php
apps/api/routes/webhooks_v2.php
apps/admin/src/lib/auth/**
infrastructure/nginx/**
openapi/**
```

## 32.3 CI

必須:

- Secret Scan
- Dependency Scan
- Composer Audit
- pnpm Audit
- SAST
- PHPStan / Type Analysis
- ESLint Security Rule
- Container Scan
- IaC Scan
- SBOM生成
- Lockfile固定
- `--frozen-lockfile`
- OpenAPI Contract Test
- Authorization Matrix Test
- Session / Cookie Test
- Security Header Test
- Production Config Validation
- Mock Payment Production拒否
- Debug Mode Production拒否

Build ArtifactへSecretを埋め込まない。

ContainerはDigestでDeployし、可能なら署名・Provenanceを検証する。

---

# 33. Monitoring / Alert

即時Alert対象:

- Admin Login失敗急増
- Owner Login
- 新しいAdmin Device
- MFA Reset
- Role変更
- Provider Secret変更
- Break-glass
- Refund急増
- paid Point調整
- QA Mode有効化
- Probability公開
- Financial Export
- Webhook Signature Failure
- Repeated Idempotency Conflict
- Audit書込失敗
- Reconciliation不一致
- CSP Violation急増
- Secret Scan検出
- Unknown Host / CORS攻撃
- Account Takeover Signal

AlertにFull PII / Secretを含めない。

---

# 34. Incident Response

各Siteに次を用意する。

- Security Contact
- Incident Severity
- Owner Escalation
- Session一括失効
- User / Admin単位失効
- OAuth Token Revocation
- Secret Rotation
- Webhook Secret Rotation
- Maintenance Mode
- Account Security Hold
- Evidence保全
- Notification Template
- Backup Restore
- Postmortem

`/.well-known/security.txt`を用意し、脆弱性報告窓口を明示する。

Security Incident中も、Audit Logを停止・削除しない。

---

# 35. 主要DB Table

```text
users
user_email_verifications
user_phone_numbers
sms_verification_challenges
user_oauth_identities
user_webauthn_credentials
user_totp_methods
user_recovery_codes
user_sessions
user_remember_devices
user_restrictions

admins
admin_invitations
admin_webauthn_credentials
admin_totp_methods
admin_recovery_codes
admin_sessions

reauthentication_grants
password_reset_tokens
oauth_authorization_transactions
auth_events
audit_logs
audit_daily_digests
security_incidents
```

Token / OTP / Recovery CodeはHash保存。

TOTP Secret、OAuth Refresh Token、PIIは必要に応じて暗号化保存する。

---

# 36. API契約への反映

既存API v2契約へ次を反映する。

## 36.1 Auth Error

```text
401 AUTHENTICATION_REQUIRED
401 SESSION_EXPIRED
403 AUTHORIZATION_DENIED
403 CSRF_TOKEN_MISMATCH
403 FRESH_AUTHENTICATION_REQUIRED
403 MFA_REQUIRED
403 ACCOUNT_RESTRICTED
409 EMAIL_ALREADY_CLAIMED
409 OAUTH_IDENTITY_ALREADY_LINKED
422 MFA_CODE_INVALID
429 RATE_LIMITED
```

Account Enumerationを防ぐEndpointでは、外部MessageをGenericにする。

## 36.2 User Auth Endpoint追加・明確化

```text
POST   /api/v2/auth/login
POST   /api/v2/auth/logout
POST   /api/v2/auth/register
POST   /api/v2/auth/password/forgot
POST   /api/v2/auth/password/reset
POST   /api/v2/auth/email/verification-notification
GET    /api/v2/auth/email/verify/{user_id}/{hash}

GET    /api/v2/auth/oauth/{provider}/redirect
GET    /api/v2/auth/oauth/{provider}/callback
POST   /api/v2/me/oauth-identities/{provider}
DELETE /api/v2/me/oauth-identities/{identity_id}

GET    /api/v2/me/sessions
DELETE /api/v2/me/sessions/{session_id}
DELETE /api/v2/me/sessions/others

GET    /api/v2/me/mfa
POST   /api/v2/me/mfa/totp
POST   /api/v2/me/mfa/totp/confirm
POST   /api/v2/me/mfa/webauthn/options
POST   /api/v2/me/mfa/webauthn
DELETE /api/v2/me/mfa/{method_id}
POST   /api/v2/me/recovery-codes/regenerate

POST   /api/v2/me/reauthenticate
```

実際のOpenAPI Operation IDはAPI契約更新時に固定する。

## 36.3 Admin Auth

Admin API Contractへ次を別定義する。

```text
POST /admin/api/v2/auth/login
POST /admin/api/v2/auth/mfa/verify
POST /admin/api/v2/auth/logout
POST /admin/api/v2/auth/reauthenticate
GET  /admin/api/v2/auth/session
GET  /admin/api/v2/auth/sessions
DELETE /admin/api/v2/auth/sessions/{session_id}
```

Admin型をStorefront Clientへ含めない。

---

# 37. Public Release前の必須Test

## 37.1 Realm Separation

- User CookieでAdmin API拒否
- Admin CookieでPublic User API拒否
- Storefront HostからAdmin Route拒否
- Admin HostからPublic Route拒否
- Webhook RuntimeにSession Middlewareなし
- Site A CookieがSite Bへ送信されない
- Cookie `Domain`未指定

## 37.2 Password / Login

- 8文字未満拒否
- 128文字許可
- Unicode / Space許可
- Composition Ruleなし
- Blocklist
- Argon2id
- Generic Login Error
- Credential Stuffing Rate Limit
- Session Fixation防止
- Hash Rehash

## 37.3 Email / Phone

- Pending Email重複
- Concurrent Verificationで1件だけ成功
- Verified Email Unique
- Verification Token Replay拒否
- Phone E.164
- Verified Phone Unique
- SMS OTP Expiry / Attempt / Replay
- Email / Phone変更時のStep-up

## 37.4 OAuth

- State
- PKCE
- Nonce
- Issuer
- Audience
- Signature
- Expiry
- Redirect URI Exact
- Open Redirect拒否
- Email CollisionでAuto-linkしない
- Provider Subject Unique
- Token非Log

## 37.5 MFA

- Admin MFAなしでAccess不可
- TOTP Replay拒否
- WebAuthn Challenge Replay拒否
- Origin / RP ID拒否
- Recovery Code 1回限り
- OwnerにWebAuthn必須
- MFA Resetで全Session失効
- SMS / Email Admin MFAなし

## 37.6 Authorization

- Matrixの全Permission
- Deny by default
- IDOR
- PII Mask
- Operator Shipping Scope
- Owner-only QA
- Ownerによるpaid Point自己承認成功
- 自己承認時のFresh MFA・理由・Audit・全Owner通知
- Last Owner保護
- Permission変更後Session失効
- UIを迂回したDirect API拒否

## 37.7 Session / CSRF

- User 60分Idle
- User 24時間Absolute
- Admin 15分Idle
- Admin 8時間Absolute
- Admin Remember meなし
- Cookie属性
- CSRF
- Origin検証
- Logout Server Invalidate
- Password Resetで全Session失効
- Remember Token Rotation / Replay

## 37.8 Audit / Secret

- Critical Event全記録
- Secret / Password / Session ID非記録
- Audit UPDATE / DELETE拒否
- Hash Chain検証
- Daily Digest
- Secret Scan
- `NEXT_PUBLIC`へSecretなし
- Production Debug false
- Unknown Host拒否

## 37.9 Browser / API

- CSP
- XSS
- File Upload
- Security Header
- CORS無効
- Cache-Control
- Admin noindex
- Rate Limit
- ErrorでStack Trace非表示

---

# 38. Security Release Gate

Commercial Production公開前に必須:

- ASVS 5.0.0 Level 2 Checklist
- Admin / Financial Critical Flowの追加Review
- Automated Security Test
- Staging DAST
- Authorization Matrix Test
- OAuth / MFA E2E
- Webhook Signature / Replay Test
- Secret Scan
- Dependency / Container Scan
- Backup / Restore
- Incident Runbook
- Break-glass Test
- 外部または独立担当によるPentest
- High / Critical Finding 0件
- Medium FindingのOwner受容記録
- Security Contact設定
- 2 OwnerまたはTest済みBreak-glass

---

# 39. V1からの主要変更

| V1 / 現状 | V2確定 |
|---|---|
| User / Adminが同じFrontend内 | 別Next.js App・別Host |
| Laravelの単一HTTP Surface中心 | Public / Admin / Webhook Runtime分離 |
| Admin Role詳細が未確定 | Owner / Admin / Operator固定 |
| QA Owner限定 | 維持＋Fresh MFA |
| Admin MFA未確定 | 全Admin必須 |
| User MFA未確定 | 任意TOTP / WebAuthn |
| Cookie詳細未確定 | Host-only `__Host-` Cookie |
| Session期間未確定 | User 60m/24h、Admin 15m/8h |
| Remember me未確定 | User Device別30日、Admin禁止 |
| Google基盤のみ | OIDC Code＋PKCE＋State＋Nonce |
| Email一致Auto-link余地 | Auto-link禁止 |
| SMS基盤 | Phone Verification用途、Admin MFA禁止 |
| Password詳細未確定 | 8～128、Argon2id、Blocklist |
| Controller Role比較の余地 | Permission Code＋Policy |
| UI非表示の権限表現 | Backend毎Request判定 |
| Auditの統一基準不足 | Append-only＋Hash Chain |
| `.env`中心 | Secret Manager / Mounted Secret優先 |
| CodexのSecret境界未明確 | Production Secret / PII禁止 |
| Chargeback制限未確定 | `financial_hold`を自動付与 |

---

# 40. 意図的に別工程へ残す事項

本書で認証・権限・Security基準は確定する。

次は別設計とする。

- 本人確認 / KYCを導入するか
- 年齢確認
- Anti-fraud Risk Scoring詳細
- Device Fingerprinting Provider
- Admin Enterprise SSO
- Apple Login
- LINE Login
- Custom Admin Role
- IP Allowlist強制
- Customer SupportによるManual Account Recoveryの本人確認手順
- 法的なData Retentionと削除期間
- CSPに追加する本番Payment Provider Domain
- PCI DSSの最終Scope
- Security Monitoring製品

これらを追加する場合、本書の次の不変条件を破ってはならない。

- Site完全分離
- User / Admin Realm分離
- Admin MFA
- Deny by default
- Backend Authorization
- Host-only Session
- Secret非公開
- Audit Append-only
- Account Linkingの明示同意
- Critical ActionのFresh MFA

---

# 41. 最終確定要旨

V2の認証構造:

```text
Storefront Browser
→ User Host-only Session
→ api-public
→ User Guard / Policy

Admin Browser
→ Admin Host-only Session
→ api-admin
→ Admin Guard / Permission / Policy
→ Mandatory MFA / Fresh MFA

Provider
→ api-webhook
→ Signature / Replay Protection
```

権限構造:

```text
Owner
├── Security / Admin / QA / Provider
├── paid Point申請・自己承認・実行
└── 全業務

Admin
├── 運用管理
├── 公開
├── Refund
└── Financial Export

Operator
├── Content
├── 問い合わせ
├── Shipping
└── 制限されたSupport閲覧
```

重要な原則:

```text
Frontendは権限の正本ではない
SessionはUserとAdminで共有しない
MFA ResetはMFAを迂回する抜け道にしない
OAuth Email一致だけでAccountをLinkしない
SMSをAdmin MFAに使わない
SecretをGit・Codex・Frontendへ渡さない
Critical ActionはFresh MFAとAuditを必須にする
```

本書を、V2の認証・権限・セキュリティ実装の正式な基準とする。
