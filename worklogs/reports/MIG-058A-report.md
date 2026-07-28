# MIG-058A External Identity Foundation／Google OIDC 提出用レポート

## 基本情報

- Task ID: `MIG-058A`
- Risk: `R3`
- Issue: `#119`
- Branch: `feat/MIG-058A-google-oidc`
- Base: `84da69e78d9ed877699427448a29b78e83fabd12`
- 対象: External Identity共通基盤、Google OIDC、Public Contract、
  Storefront Client、Testkit、Audit／Outbox
- 対象外: LINE、UI、実Google Account E2E、Mail実送信、Payment Provider、
  Domain／TLS、Staging／Production Deployment

## MIG-057 Closeout

- Issue `#117` Closed、PR `#118` Squash Merged。
- Final Head:
  `2f2f28d29bab4ce59b1458f6aacd49994e7b3970`
- Squash Commit:
  `84da69e78d9ed877699427448a29b78e83fabd12`
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Check成功。
- Fresh Self-reviewはFinal Headと一致し、SEV-0／SEV-1は0件。
- Remote／Local Branch、WorktreeはCleanup済み。
- Local `main = origin/main`、Working Tree clean。

## V1 Characterization

- V1はCache StateとAuthorization Code交換後のGoogle UserInfoを使用する。
- Nonce、PKCE、ID Token署名検証はなく、Raw Subject、Email、Name、
  Avatar、Raw Profileを保存する。
- 既存SubjectはLogin、Verified Email新規Userは登録、既存Verified Email衝突は拒否。
- V1のEmail識別、Raw Identity／Token／Profile保存、脆弱なProtocol処理はCopyしていない。

## 実装結果

### Schema

- `external_identity_accounts`
- `external_identity_transactions`
- `external_identity_account_histories`
- `users.password_login_enabled`
- Providerは本Taskでは`google`だけ。
- `(provider, issuer, subject_hash)`、User＋ProviderをUnique化。
- Raw SubjectはHMAC相関値、State／Nonce／BindingはHash、
  Code Verifierは暗号化保存。
- Transactionは10分・1回限り。Account Identity、Transaction遷移、
  History Append-onlyをDB Triggerで保護。

### Google OIDC

- Server-side Authorization Code Flow。
- CSPRNG State／Nonce／PKCE Verifier、PKCE S256。
- Exact Redirect URI、Relative Return Path。
- Google Issuer、Token Endpoint、JWKS Endpointを固定しSSRFを拒否。
- RS256、`kid`、署名、Issuer、Audience、`azp`、`exp`、`iat`、Nonce、
  `sub`、`email_verified`を検証。
- Clock Skewは60秒。JWKSはUnknown Key時に1回Refreshし、それでも不明なら拒否。
- Authorization Code、Access／Refresh Token、ID Tokenは永続化しない。
- `firebase/php-jwt`はManifest `^7.1`、Frozen Lock／Policyで解決Versionを
  Exact `7.1.0`に固定した。

### Account／Session

- Existing IdentityはSubjectで識別し、Provider Email変更でUserを変更しない。
- New UserはVerified Email必須、`active`、Password Login無効。
- Email一致だけの自動Linkは禁止。
- Link／Google ReauthenticationはUser SessionとBrowser TransactionへBinding。
- Link、Reauthentication、UnlinkでSession／CSRFをRotationし、
  Absolute期限を延長しない。
- Unlinkは5分以内のServer-side Reauthenticationを必須にし、
  最後のCredentialは削除しない。
- Password Reset成功時にPassword Loginを有効化。

### Contract／Audit

- Public OpenAPIへ7 Operation追加。Public 42／Admin 67／Webhook 0 Operation。
- Storefront Client Identity FacadeとPublic-safe Testkit Fixtureを追加。
- Admin／Webhook型、Provider Subject、Token、Transaction内部IDを公開しない。
- Start／Callback／Protocol拒否／Provider Failure／User作成／Login／Link／
  Unlink／Reauthentication／Rate LimitをAppend-only Auditへ接続。
- New User／Link／Unlink通知をTransactional Outboxへ接続。
- Token、Code、Raw Subject、State、Nonce、Verifier、Full Email、
  Cookie、Raw Session ID、Client SecretをAudit／Log／Errorへ保存しない。

## Migration／Backup Evidence

- V2 Migration数: 15
- Migration Set SHA-256:
  `53cbd05cae2fa794d39a3fd5c71ad87cefcb398e69eafc066a29ec9356e4f27a`
- Source／Restore Schema SHA-256:
  `7ae754f3fcbf1cff5cdf48961f0e03293e0e4e432124e92b0bbb399dcec60090`
- Source／Restore Migration Row SHA-256:
  `3e9d7878e58a77810819042186ef4ac43acb4926d74a7e619296657e382fd4ea`
- Backup SHA-256:
  `21a549606f240faafe86703a359dbbf3916878d7ab9f09eaf3e9a3ac8f14f773`
- Persistent／Ephemeralの`migrate:fresh`各2回、最新Migration
  Rollback／Reapply、Schema Inventory、Backup／Restore、Cleanup: PASS

## Test結果

- 全V2 Suite: PASS
- OIDC Protocol／Account／Session／Concurrency: PASS
- 同一Callback並行実行: 1件だけ成功、二重User／Identity／Sessionなし
- Draw／QA Load、Reporting、Content／Contact Performance回帰: PASS
- Storefront Client: 生成差分0、Typecheck、Lint、Build、14 Test PASS
- Storefront Testkit: 生成差分0、Typecheck、Lint、Build、22 Test、
  Export Surface、実Network禁止 PASS
- Policy Unit Test: 80件 PASS
- Site Schema: 生成差分0、Typecheck、Lint、Build、10 Test PASS
- Admin: Typecheck、Lint、Build PASS
- OpenAPI Unit 4、Quality Unit 5、Security Unit 4、Site Template Unit 6、
  DB Guard Unit 25、Release Unit 10: PASS
- Root／Legacy Frozen Install: PASS
- Root Audit: 0 Finding
- Legacy Audit: 既存11 Finding
- Composer Audit: 既存期限付き10 Finding、Baseline拡張なし
- `policy-gate`／`quality-gate`／`security-gate`／OpenAPI Contract Gate: PASS
- Secret／PII Candidate、新規Critical／High: 0件
- Legacy Lint: 既存8 Error／1 Warning、増加なし

## 検出・修正した不具合

1. 並行Callbackで後着Replayが先着の`processing` Transactionを`failed`へ変更できた。
   Claim成功Workerだけが自身のTransactionを失敗化する所有境界へ修正した。
2. Public Auth ControllerがRepositoryに存在しない基底Controllerを継承し、
   HTTP Routeで500になった。Standalone V2 Controllerへ修正した。
3. OIDC CHECK式がBackup／Restore後に等価なCast表現へ変わった。
   PostgreSQL Canonical `text[]`式でMigrationを固定した。
4. 期限切れCallbackは拒否できていたが、Transaction状態が`pending`のままだった。
   Server時刻で期限切れを再確認して`expired`へ遷移し、同状態をTestで固定した。
5. GitHub Quality GateのComposer Strict ValidateがManifestのExact制約を
   SemVer警告としてFailureにした。Gateを弱めず、Manifestを`^7.1`、
   Frozen Lock／PolicyをExact `7.1.0`として再現性を維持した。

## 時間を要した作業

| 作業 | 所要時間 | 原因／再試行 | 解決 |
| --- | ---: | --- | --- |
| Composer Dependency生成 | 0.49秒＋6.27秒＋約29.3秒 | Host PHP 8.3とProject PHP 8.4差、Post-autoload Parse失敗 | HostではLock生成だけ行い、PHP 8.4 Container Buildで確定 |
| Persistent Guard初回 | 約122.8秒 | Test Harnessの時刻移動／OpenSSL参照渡し2件 | Harness修正 |
| Persistent Guard再実行 | 126.4秒 | Harness修正後の正規検証 | PASS |
| Persistent HTTP検証 | 134.9秒 | 存在しない基底ControllerによるRoute 500 | Standalone Controller化 |
| Persistent最終候補 | 131.0秒 | HTTP修正後 | PASS |
| Persistent並行修正後 | 156.4秒 | Callback所有競合修正後の全Suite再検証 | PASS |
| Persistent Canonical Migration最終 | 99.5秒 | Migration最終Sourceで再検証 | PASS |
| Persistent Expiry補完後 | 130.6秒 | Fresh self-reviewで期限切れ状態の明示遷移を補完 | PASS |
| Ephemeral Build | 21.4秒で停止 | Host Storage不足 `ENOSPC` | 未使用Build Cache 2.17GB、Dangling Image 10.62GBを限定Cleanup |
| Ephemeral並行検証 | 172.0秒で停止 | 後着Replayが先着Transactionを失敗化 | Claim所有境界を修正 |
| Ephemeral Backup比較 | 260.2秒で停止 | PostgreSQLがCHECK式を等価なCanonical表現へ変換 | Canonical CHECK式へ固定 |
| Ephemeral最終 | 292.8秒 | 全Suite／Load／Backup／Restore | PASS |

今後の短縮策:

- Smoke前にDisk空き容量とDocker Build CacheをRead-only Preflightする。
- DB Guardへ対象Testだけを正規DBで実行するModeを追加候補とする。
- Migration CHECK式はCanonical Templateを使用する。
- Service Testより前段でHTTP Route Smokeを実行する。

## 非変更／未実行

- V1 Migration 40件Checksum:
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagは非変更。
- Google Client ID／SecretはRepositoryへ保存せず、Production Credentialは未使用。
- LINE、UI、実Google Browser E2E、Mail実送信、Payment Provider、
  Domain／TLS、Staging／Production Deploymentは未実行。

## Gate／完了処理

- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- Final Head、GitHub Check、Fresh Self-review、Squash Commit、
  Branch／Worktree CleanupはPR完了時に確定する。
- 次Task候補:
  `MIG-058B LINE Login v2.1 Identity Linking Vertical Slice`
- MIG-058Bは本Task内で開始しない。
