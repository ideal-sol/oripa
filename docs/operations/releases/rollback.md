# Rollback

## Trigger

次のいずれかを確認した場合はPromotionを停止し、Rollbackを評価する。

- SEV-0／SEV-1 Incident
- Auth、Payment、Point、Draw、Inventoryの不変条件違反
- Migration不整合またはData Integrity Risk
- Error Rate、Latency、Availabilityの承認済み閾値超過
- Artifact Digest、Manifest、Environmentの不一致

## Procedure

1. 新規DeploymentとRelease Promotionを停止する。
2. Incident ID、対象Environment、Release ID、Artifact Digestを記録する。
3. Database WriteとMigration状態を確認し、逆Migrationを推測で実行しない。
4. 直前に検証済みのArtifact Digestを特定する。
5. Release／Deployment ManifestとBackup／Restore条件を照合する。
6. Required Gateと承認主体を満たしてRollbackする。
7. Post-rollback Verificationと監視結果を記録する。

## Database

- Destructive MigrationはRollback手段として自動実行しない。
- Forward Fix、Restore、Compensating Operationの選択はData Riskを評価する。
- Production DBへの直接操作は人間が承認したIncident Taskだけで行う。
- Backupが存在するだけでRestore可能とみなさず、定期的なRestore Test Evidenceを要求する。

## Responsibility

- CodexはManifest照合、Digest検証、非Production Rehearsal、Check実行を自動化できる。
- Production Rollbackの実行条件はRelease PolicyとIncident権限に従う。
- 法務、会計、Provider連絡、顧客告知の判断は人間が行う。

## Evidence

Rollback記録には、理由、影響、対象Digest、Migration Revision、承認、実行結果、
残Riskを含める。Secret、Credential、実PIIは含めない。
