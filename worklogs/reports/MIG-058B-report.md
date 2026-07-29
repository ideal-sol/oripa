# MIG-058B LINE Login v2.1 Identity Linking Vertical Slice 提出用レポート

## 基本情報

- Task ID: `MIG-058B`
- Risk: `R3`
- Issue: `#131`
- Branch: `feat/MIG-058B-line-login`
- Base: `e10748d2d7b8d8a435b1bcf9e2e94ddf7c834a4e`
- 対象: External Identity共通基盤のProvider化、LINE Login／Link／
  Reauthentication／Unlink、Public Contract／Client／Testkit
- 対象外: LIFF、LINE MINI App、Official Account、Storefront UI、
  Admin Social Login、実LINE Account E2E、Provider Token保持、
  Payment Provider、Domain／TLS、Deployment

## MIG-060E Closeout

- Issue `#129` Closed、PR `#130` Squash Merged。
- Final Head: `cf81ab2519adf8b5ecea623a8091e771b8d7ecc9`
- Squash Commit: `e10748d2d7b8d8a435b1bcf9e2e94ddf7c834a4e`
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Check成功。
- Fresh Self-review一致、SEV-0／SEV-1 0件。
- Remote／Local Branch、Worktree Cleanup済み。
- Local `main = origin/main`、Working Tree clean、V1非変更。

## 実装結果

### 共通External Identity基盤

- MIG-058AのAccount、Transaction、History、Session／CSRF Rotation、
  Rate Limit、Audit／Outboxを再利用。
- Google固有処理をProvider Interface／Registryへ分離し、GoogleとLINEを
  同じLifecycle Serviceで処理。
- LINE専用Identity Table、別Session、別Rate Limiterは未作成。

### LINE Protocol

- 固定Authorization／Token／Verify Endpointだけを使用。
- CSPRNG State／Nonce、PKCE S256、Exact Redirect URI、Relative Return Path、
  Browser／User Session Binding、10分、1回限りを実装。
- Issuer、Audience、`exp`、`iat`、Nonce、Subject、必須型を検証。
- Timeout、429、5xx、Verify拒否はFail Closed。任意URL／Discovery／SSRFなし。
- Raw Subject、Code、Access／Refresh Token、ID Tokenを永続化しない。

### Account／Email

- Identity Keyは`provider + issuer + HMAC(subject)`。
- 既存LINE Identity LoginとAuthenticated LinkはEmail Claim不要。
- Email ScopeはRepository外設定が明示的に有効な場合だけ追加。
- 新規UserはEmail Claimがある場合だけ作成し、Password Login無効。
- EmailなしではActive User／架空Emailを作らず
  `EXTERNAL_IDENTITY_EMAIL_COMPLETION_REQUIRED`を返す。
- Email衝突で自動Linkせず、既存Credential Login後の明示Linkを要求。
- Link／Reauthentication／UnlinkでSession／CSRF Rotation、最後のCredential保護、
  Remember Device失効を維持。

### Contract／Client

- Public OpenAPIへLINE Operationを5件追加。Public 47／Admin 93／Webhook 0。
- Storefront ClientへLINE Login／Link／Reauthentication／Unlink Facadeを追加。
- TestkitへLINE FixtureとContract Operationを追加。
- Admin／Webhook型、Subject、Token、SecretをPublicへ非公開。

### Migration／Audit

- Forward-safe Migration:
  `2026_08_07_000018_add_line_external_identity_provider.php`
- Provider／Issuer CHECKをGoogle／LINEへ拡張。既存Migration編集なし。
- LINE Record存在時のRollbackは履歴保持のためFail Closed。
- Start、Callback、Protocol拒否、Email不足／衝突、Provider障害、Link／Unlink、
  Reauthentication、Rate LimitをAppend-only Auditへ接続。
- Raw Protocol値、Full Email、Cookie、Session ID、SecretはAudit／Logへ非保存。

## Test結果

- LINE専用: 12 Test PASS
- LINE同一Callback Process Concurrency: PASS
- 全V2 Suite: PASS
- OpenAPI Unit／Bundle: PASS、Public 47／Admin 93／Webhook 0
- Storefront Client: 生成差分0、Typecheck／Lint／Build、14 Test PASS
- Site Schema: 生成差分0、Typecheck／Lint／Build、10 Test PASS
- Storefront Testkit: 生成差分0、Typecheck／Lint／Build、22 Test PASS
- Admin: 生成差分0、Typecheck／Lint、35 Test、Production Build PASS
- Policy Unit 88／Quality Unit 5／Security Unit 4／Release Unit 10／
  DB Guard Unit 25: PASS
- `policy-gate`／`quality-gate`／`security-gate`／OpenAPI Contract Gate: PASS
- Root／Legacy Frozen Install: PASS
- Root Audit: 0 Finding
- Legacy Audit: 既存11 Finding
- Composer Audit: 既存期限付き10 Finding、Baseline拡張なし
- Legacy Lint: 既存8 Error／1 Warningと一致
- Secret／PII Candidate: 0
- 新規Critical／High: 0

## V2 DB回帰

- Persistent `migrate:fresh`: 2回 PASS
- Ephemeral `migrate:fresh`: 2回 PASS
- 最新Migration Rollback／Reapply: PASS
- 全V2 Suite／Draw／QA／Reporting／Content負荷回帰: PASS
- API／Admin／PostgreSQL／Redis Health: PASS
- Backup／Restore、Schema／Migration Row Checksum一致: PASS
- Task Resource Cleanup: PASS
- Migration数: 18
- Migration Set SHA-256:
  `4f8323fde23415f4a38daccae1d175d6d25a9962b8290523e24fd2ece219a40e`
- Schema SHA-256:
  `638f04f706db84f3f5cbd1c97ff77099dd68d1d0dce6265a03e151a9f2dd7b02`
- Migration Row SHA-256:
  `2057fae3cf8684b8e4bf327b049b586df05ef6608291d3e145ce4ce8b106fab3`
- Backup SHA-256:
  `c535512b233623e49011692395d3377f27b633eddd955d3b92c01cbb17940f76`
- Persistent Evidence:
  `/var/lib/oripa-v2-evidence/MIG-058B/persistent-final/persistent-result.json`
- Ephemeral Evidence:
  `/var/lib/oripa-v2-evidence/MIG-058B/ephemeral-final/`

## 時間を要した作業

| 作業 | 所要 | 原因／再試行 | 結果 |
| --- | ---: | --- | --- |
| API Image Build | 約30秒 | Buildxの`--allow`非対応で初回停止 | Classic BuilderでPASS |
| Package検証 | 初回並行失敗後に依存順再実行 | Testkitが依存Package Build前に型解決不可 | 全Package PASS |
| Persistent Guard | 40.06秒停止、137.99秒停止、108.89秒成功、Self-review後142.60秒 | PHPUnit Helper名、Audit Event Allowlist、HTTP Fakeを修正。LINE固有Protocol Test追加後に再固定 | Migration 2回、Rollback／Reapply、全Suite PASS |
| Ephemeral Smoke | 340.49秒停止、303.69秒成功、Self-review後275.16秒 | Restore時にCHECK式が等価な別表現へ正規化。最終Test集合で再固定 | 決定的OR Constraint、Backup／Restore／Cleanup PASS |

## 非変更

- V1 Migration 40件Checksum:
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagは非変更。
- Google OIDC挙動、Password Reset／SMS、Admin Contract／App、
  Payment Provider、Domain／TLS、Staging／Production Deploymentは非変更。

## Gate／Closeout

- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- Final Head、GitHub 8 Check、Fresh Self-review、Squash Commit、
  Issue Close、Branch／Worktree CleanupはPR完了時に確定する。
- 次Task候補: `MIG-060F Admin Gacha Version Management`
- MIG-060Fは開始しない。
