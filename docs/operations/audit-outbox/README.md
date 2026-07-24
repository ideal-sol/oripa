# V2 Audit／Transactional Outbox Foundation

## Purpose

MIG-042はV2 Authentication Eventを改ざん検知可能なAppend-only Auditへ永続化し、
Domain変更と外部通知要求を同じDB Transactionで確定するTransactional Outbox境界を
提供する。本Foundationは非Production検証用であり、Audit検索／Export API、WORM
Storage、実Mail／SMS／Discord／Payment Transportは含まない。

## Audit Boundary

- `audit_logs`は`public_id`、`occurred_at`、`business_date`、`request_id`、
  Actor／Role／Realm／Session Correlation、Action／Target／Outcome／Reason、
  Redaction済みBefore／After／Metadata、`previous_hash`、`record_hash`を保持する。
- Record追加はPostgreSQL Advisory Transaction Lockで直列化し、直前Record Hashを
  Chainへ含める。
- `audit_logs`と`audit_daily_digests`のUpdate／Delete／TruncateはApplication Modelと
  PostgreSQL Triggerの両方で拒否する。
- HashとDaily DigestはRepository／DB外の`V2_AUDIT_HMAC_KEY`を使う。Key値をDB、
  Repository、Log、Worklog、Issue、PRへ保存しない。
- Chain Verificationは全Recordを再計算し、前Hash、Record Hash、Daily Digestの
  不一致を失敗させる。
- Password、Token、Raw Session ID、Recovery Code、TOTP Secret、Full Email、
  Authorization／Cookie値、不要なPIIを保存しない。必要な相関値はHMAC Hashだけを
  保存する。

`V2PersistentSecurityEventSink`はRegister、Email Verification、Login成功／失敗、
Logout、Admin Invitation、MFA Enrollment／成功／失敗、Recovery Code使用、
Rate Limit発火をAuditへ接続する。未知Eventや禁止Metadataは黙って破棄せず拒否する。

## Outbox Boundary

- `V2OutboxService::enqueue`はActive DB Transactionがない呼出しを拒否する。
- Messageは`topic`、Aggregate、Event Type、Payload、Deduplication Key、Status、
  Available At、Attempts、Lease／Lock、Delivered At、Last Errorを保持する。
- Unique Deduplication Keyで同一Messageの重複を防ぎ、異なる内容の再利用を拒否する。
- Claimは`FOR UPDATE SKIP LOCKED`を使い、期限切れLeaseだけを再取得できる。
- Success、Retry、Failureは現在のWorkerと有効Leaseが一致する場合だけ遷移する。
- Domain TransactionがRollbackされた場合、Outbox Messageも残らない。
- 外部通信はClaim Transaction外で後続Workerが行う。DB Transaction内でProviderへ
  通信しない。

Email Verification通知はUser／Verification Token更新と同じTransactionでenqueueする。
Recipient、Token、RedirectはApplication-level Encryptionした単一Ciphertextとして
保存し、平文FieldをPayloadへ置かない。

## Operations

V2 DB操作は必ず`/etc/oripa-v2/dev.env`と`scripts/db/v2_database.py`のGuardを使う。
Persistent／Ephemeral双方で`apps/api/database/migrations-v2`を明示し、V1 Migration、
V1本番DB、共有PostgreSQL／Redis、Host Port、Production Environmentを拒否する。

Daily Digest生成前にはChain Verificationを実行する。Digestを作成済みの日付へRecordを
遅延追加する運用は後続の締め処理Taskで禁止する必要があるため、本Foundationだけで
Production運用可能とは判断しない。

## Verification

- V2 `migrate:fresh`を2回実行し、Schema／Migration Checksumを比較する。
- Task専用Ephemeral PostgreSQLへBackupし、別ResourceへRestoreしてChecksumを比較する。
- Audit Append-only、Hash Chain、Tamper検知、Daily Digest、並行WriteをTestする。
- Outbox Rollback、Deduplication、並行Claim、Lease、Retry、Success、FailureをTestする。
- Authentication／MFA Security Event永続化とEmail Verification OutboxをRegression
  Testする。
- V1 Migration 40件のChecksum、V1 Runtime、V1本番DB、V1 Archive Refを変更しない。

## Production Boundary

本FoundationはProduction Deploymentを許可しない。運用監視、Key Rotation、Daily
Close、External WORM／Backup Policy、実Transport Worker、Failure Queue対応、
Point／Payment Domain Auditを後続Taskで完成させ、Release Gateを再評価する。
