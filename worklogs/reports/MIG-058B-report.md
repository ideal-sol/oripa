# MIG-058B LINE Login v2.1／Messaging Follow Vertical Slice 提出用レポート

## 基本情報

- Task ID: `MIG-058B`
- Risk: `R3`
- Issue: `#131`
- PR: `#132`
- Branch: `feat/MIG-058B-line-login`
- Base: `e10748d2d7b8d8a435b1bcf9e2e94ddf7c834a4e`
- 対象: External Identity共通基盤のLINE Provider拡張、LINE Login／Link／
  Reauthentication／Unlink、Messaging Webhook `follow`／`unfollow`、
  Reply Message、Pending Follow、友だち追加Point、Admin Message設定
- 対象外: Push／Broadcast／Multicast／Narrowcast、Message本文処理、
  LINE連携Code、Messaging Channel ID設定、LIFF、LINE MINI App、
  Storefront UI、実LINE Account E2E、Payment Provider、Domain／TLS、Deployment

## MIG-060E Closeout

- Issue `#129` Closed、PR `#130` Squash Merged。
- Final Head: `cf81ab2519adf8b5ecea623a8091e771b8d7ecc9`
- Squash Commit: `e10748d2d7b8d8a435b1bcf9e2e94ddf7c834a4e`
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Check成功。
- Fresh Self-review一致、SEV-0／SEV-1 0件。
- Remote／Local Branch、Worktree Cleanup済み。
- Local `main = origin/main`、Working Tree clean、V1非変更。

## 最新仕様変更

- PR作業中の人間決定により、旧「Messaging API送信なし」「Channel Access Token不要」
  「Admin設定画面なし」を廃止した。Issue／Branch／Worktree／PRは作り直さず、
  既存LINE Login実装とCommit履歴を維持した。
- 最新正本に従い、Messaging APIは署名済みWebhookとFollowイベントのReply Message
  だけを実装した。Push系APIへのFallbackやEndpointは追加していない。
- Repository外設定は`LINE_LOGIN_CHANNEL_ID`、
  `LINE_LOGIN_CHANNEL_SECRET`、`LINE_MESSAGING_CHANNEL_SECRET`、
  `LINE_MESSAGING_CHANNEL_ACCESS_TOKEN`である。Messaging Channel IDは使用しない。
- LINE公式仕様どおり、生のRequest BodyへHMAC-SHA256署名検証を行い、
  Reply Tokenを1回だけ使用する。資格情報、Reply Token、Raw Subjectは永続化しない。

## 再利用した基盤

- MIG-058Aの`external_identity_accounts`、`external_identity_transactions`、
  Identity History、Provider Registry、Login／Link／Reauthentication／Unlink、
  Session／CSRF Rotation、HMAC Subject、Rate Limit、Audit／Outboxを再利用した。
- MIG-043のPoint Service／Wallet／Operation／Lot／Ledgerを再利用し、
  `line.friend_reward:<friendship_public_id>`を一意Business Keyとした。
- MIG-042のAppend-only Audit／Transactional Outbox、MIG-053AのFresh MFA、
  MIG-060A～EのAdmin Shell／Permission／API Client／Conflict境界を再利用した。
- Google OIDCを複製せず、LINE専用Identity Table、別Session、別Token Storageを
  作成していない。

## LINE Login／Account

- 固定Authorization／Token／Verify Endpoint、CSPRNG State／Nonce、PKCE S256、
  Exact Redirect URI、Relative Return Path、10分、1回限りを実装した。
- Issuer、Audience、`exp`、`iat`、Nonce、Subject、必須型を検証し、
  Timeout／429／5xx／Verify拒否をFail Closedとした。
- Identity Keyは`provider + issuer + HMAC(subject)`。既存LINE Identity Loginと
  Authenticated LinkはEmail Claim不要である。
- 新規UserはVerified Email Claimがある場合だけ作成し、Password Loginを無効化する。
  EmailなしではActive User／架空Emailを作成せず
  `EXTERNAL_IDENTITY_EMAIL_COMPLETION_REQUIRED`を返す。
- Email衝突で自動Linkせず、明示Link、Session／CSRF Rotation、
  Recent Reauthentication、最後のCredential保護を維持する。

## Messaging／Point

- Webhook Contractへ`POST /webhooks/v2/line`を追加した。生Body署名検証後にだけ
  `follow`／`unfollow`を処理し、その他Eventは保存しない。
- `webhookEventId`はHMAC化してUnique保存し、Redeliveryと並行Followを重複排除する。
  Raw User IDはExternal Identityと同じHMAC方式で照合する。
- Login済みFollowはFriendshipを有効化し、設定Pointをfree Pointとして冪等付与する。
  Point、Friendship、Webhook Event、Audit、Outboxは同一Transactionで確定する。
- Login前Followは`line_pending_follows`へHMAC Subject単位で保存する。
  後続LINE Login／Link成功Transactionで照合し、FriendshipとPointを一度だけ確定する。
  Login後のPush Messageは送信しない。
- Point付与または設定読込が失敗した場合はFollow処理をRollbackし、完了Messageを
  送らない。Reply失敗は確定済みPoint／FriendshipをRollbackしない。
- Replyは固定`/v2/bot/message/reply`、Bearer Access Token、1 Text Messageだけを使用。
  Reply Tokenを保存せず、Event状態`pending → processing → sent|failed`で一回性を
  DB Guardする。429／5xx／TimeoutをRedaction済みFailure Codeで記録し、
  PushへFallbackしない。

## Admin設定

- Admin OpenAPIへ設定取得、Preview、更新を追加した。
- `identity.line.manage`はOwnerだけに付与し、更新はFresh MFA 5分、CSRF、
  Exact Origin、JSON、Admin Realm、Critical Mutation Rate Limitを必須とした。
- 2種類の本文をDB Singleton設定とし、Unicode NFC、1～1,000文字、空文字／
  HTML／Script／制御文字を拒否する。許可Placeholderは`{login_url}`だけである。
- Login URLはServer固定Relative Path `/login`から展開し、Browser入力や外部URLを
  使用しない。資格情報、内部情報、Reward設定はPublic Contractへ公開しない。
- Revision OCC、Idempotency-Key、Canonical Replay、Append-only Audit、
  Transactional Outboxを実装した。
- Admin UIへ編集、Preview、Conflict再読込、Dirty State、二重送信防止、
  Fresh MFA Dialog、Canonical Response再取得を追加した。
- Migration Default Messageを明示した。Reward Pointは未承認値を推測せず初期値0とし、
  Domainは正の設定値がある場合だけ冪等付与する。

## Schema／Contract

- Forward-safe Migration:
  `2026_08_07_000018_add_line_external_identity_provider.php`
- Forward-safe Migration:
  `2026_08_07_000019_create_line_messaging_follow_foundation.php`
- 新規Table:
  `line_messaging_settings`、`line_friendships`、`line_pending_follows`、
  `line_webhook_events`
- Provider／Issuer、Subject HMAC、Friendship／Pending状態、Reward一回性、
  Webhook Event／Reply遷移をUnique Constraint、CHECK、Triggerで保証する。
- OpenAPI Operation数: Public 47、Admin 96、Webhook 1。
- Public Storefront ClientへAdmin／Webhook型、Message設定、Subject、Token、
  SecretをExportしない。

## Local Test結果

- LINE Login専用: 13 Test PASS。
- LINE Messaging専用: 8 Test PASS。
- LINE Login／Link Process Concurrency: PASS。
- Webhook Redelivery／Concurrent Follow 2 Process: PASS。
- Persistent全V2 Suite、`migrate:fresh` 2回、Migration Rollback／Reapply、
  Schema Inventory: PASS。
- Admin Unit／Component: 38 Test PASS。
- Admin Browser E2E: 11 Test PASS。
- Admin Typecheck／Lint／Production Build／生成差分0: PASS。
- OpenAPI Unit 4件、Bundle／生成差分: PASS。
- Storefront Client 14 Test、Testkit 22 Test、Site Schema 10 Testと
  各Typecheck／Lint／Build／生成差分: PASS。
- Policy／DB Guard Unit: 114 Test PASS。Quality Unit 5、Security Unit 4 PASS。
- Root／Legacy Frozen Install: PASS。
- Root Audit: 0 Finding。
- Legacy Audit: 既存11 Finding。Composer Audit: 既存Baseline対象のみ。
- 新規Critical／High、Baseline追加／拡張: 0。
- Browser E2E以外の実LINE Account／Credential通信: 未実行（対象外）。

## V2 DB回帰

- Persistent Evidence:
  `/var/lib/oripa-v2-evidence/MIG-058B/persistent-final-head/persistent-result.json`
- Ephemeral Evidence:
  `/var/lib/oripa-v2-evidence/MIG-058B/ephemeral-final-messaging-stable/ephemeral-result.json`
- Migration数: 19。
- Migration Set SHA-256:
  `96a6b7501d1291f5103f1b23438c4670f8ae04449e851e2120622f1f8f520836`
- Persistentは`migrate:fresh` 2回、最新Migration Rollback／Reapply、
  全V2 Suite、Schema Inventory、PostgreSQL／Redis HealthがPASSした。
- Ephemeralは`migrate:fresh` 2回、全V2 Suite、Draw／QA／Reporting／Content
  Load、API／Admin Health、Backup／Restore、Task Resource CleanupがPASSした。
- Source／Restore Schema SHA-256:
  `3d59ccfb05abd0c1954c6d6ff8eef435ccd913fa7aef72972ea75087edd472a2`
- Source／Restore Migration Row SHA-256:
  `77cbd2fc2bce85ea8830e6ff3a8d7b50086fac778c29672e6e10cdff242cd623`
- Backup SHA-256:
  `21b173ef40c209bbea8cea48df2fe4d2c318693465fed6c54b36b56dace64fd3`

## 時間を要した作業

| 作業 | 所要 | 原因／再試行 | 結果 |
| --- | ---: | --- | --- |
| API Image Build | 各約30秒、複数回 | Buildxの`--allow`非対応後、Classic Builderで候補更新を反映 | PASS |
| Persistent Guard | 約150秒失敗、約143秒失敗、約143秒失敗、約143秒成功 | Webhook署名Test Header、Audit Actor正本、Pending日時、HTTP Fake、Schema Inventory順を段階修正 | 全V2 Suite／Migration PASS |
| Admin Browser E2E | 約52秒 | LINE設定のFresh MFA再送を含む11 Scenario | PASS |
| First-party Package | 約90秒 | Contract／Client／Testkit／Site Schemaを依存順で検証 | PASS |
| Ephemeral Smoke初回 | 245.49秒 | Host root filesystemが100%となり、QA Load Testが`/tmp`へ結果を書けず停止。稼働Resourceに触れず未使用Docker Build Cache 12.34GBだけを削除 | Infrastructure容量不足を解消 |
| Ephemeral Smoke再試行 | 374.94秒 | 新規CHECK式がDump／Restoreで等価な別表現へ正規化されSchema Checksum不一致 | CHECKを明示比較／`text[]`へ安定化 |
| Persistent Guard最終 | 144.41秒 | 安定化後Migrationと全V2回帰を再実行 | PASS |
| Ephemeral Smoke最終 | 278.80秒 | Source／Restore双方の全Suite、Load、Backup／Restore、Cleanup | PASS |
| Fresh Self-review後Persistent | 144.66秒 | Environment名統一とMalformed Placeholder拒否を含む全V2回帰 | PASS |

Fresh Self-reviewでは、Login Channel資格情報の旧`V2_LINE_*`Fallbackと、
空／閉じていない波括弧を未知Placeholderとして拒否できない境界を検出した。
資格情報の出所を`LINE_LOGIN_CHANNEL_ID`／`LINE_LOGIN_CHANNEL_SECRET`へ一本化し、
`{login_url}`除去後に残る波括弧をFail Closedとした。回帰Testと全V2 Suiteで
修正を確認した。

## 非変更

- V1 Migration 40件Checksum:
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagは非変更。
- Google OIDCの公開挙動、Draw、Payment、Production Resource、
  Domain／TLS、Deploymentは非変更。

## Gate／Closeout

- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- Final Head、GitHub 8 Check、Fresh Self-review、Squash Commit、
  Issue Close、Branch／Worktree CleanupはPR完了時に確定する。
- 次Task候補: `MIG-060F Admin Gacha Version Management`
- MIG-060Fは開始しない。
