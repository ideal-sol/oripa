# MIG-060M Admin Gacha Unpublish／Public Deactivation 提出用レポート

## 基本情報

- Task ID: `MIG-060M`
- Risk: `R3`
- Issue: `#149`
- Branch: `feat/MIG-060M-gacha-unpublish`
- Base: `a3bf2789b1cc7cbad22d7b6185d18863a6ea40db`
- 対象: Pause済み公開GachaのPublic Catalog／新規Drawからの原子的解除
- 対象外: Version／Snapshot／Inventory／履歴削除、Schedule取消、再Publish別経路、
  Draw Algorithm、Public Schema、Storefront UI、Deployment

## MIG-060L Closeout

- Issue `#147` Closed、PR `#148` Squash Merged。
- Final Head: `61d7268df003e420380538ef641be357001e2499`
- Squash Commit: `a3bf2789b1cc7cbad22d7b6185d18863a6ea40db`
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Check成功。
- Fresh Self-review一致、SEV-0／SEV-1 0件。
- Branch／Worktree／Task Resource Cleanup、Local main同期、V1非変更を確認。

## Characterization／設計

- UnpublishはArchive／Deleteではなく、Gacha MasterのPublic PointerとActive Draw
  Pointerを同時に解除する運用遷移とした。
- 安全側の確定としてSales Pause済みだけを対象とする。
- Published Version、Published Probability Snapshot、Draw State、Inventory、
  User Point、Draw Request／Result／Historyは変更または削除しない。
- Scheduled／Processing PublishはBlockerとし、予約を暗黙に取消・削除しない。
- 成功済みDraw ReplayはPointer確認より先にCanonical Resultを返し、新規Drawは
  Point減算、Inventory更新、履歴作成前に拒否する。
- 再Publishは既存Immediate／Scheduled Publish Flowだけを使用する。

## Contract／Security

- Admin APIへUnpublish状態取得、Preflight、MutationをContract-firstで追加した。
- `catalog.publish`、Admin Realm、MFA Enrollment、Fresh MFA 5分、CSRF、
  Exact Origin、JSON、Idempotency-Key、Revision OCC、Critical Rate Limitを強制。
- Owner／Adminは実行可能、OperatorはRead-only／Mutation 403。
- Canonical Replay、同一Key異内容409、Stale Revision、Limiter Fail Closedを維持。
- Public／Webhook OpenAPIとStorefront ClientへのAdmin型非公開境界は変更していない。

## Transaction／DB Guard

- Idempotency Record、Gacha Master、Published Version、Active Draw State、
  Probability、Active Scheduleを固定順でLockする。
- Server Preflight後にPublic／Draw Pointer、DB時刻、Revision、Audit、
  `catalog.change` Outboxを単一Transactionで確定する。
- Forward-safe Migration
  `2026_08_15_000028_add_v2_gacha_public_deactivation.php`を追加し、
  既存Migrationは編集していない。
- 型付きDeactivated At、Actor Public ID、Request ID、Canonical CHECK、
  Restrict FK、Transition／History Triggerを追加した。
- Partial Deactivation、Revision bypass、未Pause、Active Schedule、
  Cross-Gacha／不整合Pointer、Metadata改変、履歴Delete、直接ResumeをFail Closedにした。
- Task DB `oripa_v2_mig060m`でTarget Guard、Migration 1 Step
  Rollback／Reapply、Schema Dumpを確認した。

## Public／Draw／Admin UI

- Commit前は旧Pointer、Commit後はPublic List／Detailと新規Drawの双方で非Activeとなる。
- Publicだけ解除／Drawだけ有効という中間状態をDB Deferred Guardと追加Triggerで拒否する。
- 解除後も既存成功Draw Replay、Point、Inventory、Draw履歴Relationは不変。
- Adminへ現在Public／Sales／Schedule状態、Preflight Blocker、Fresh MFA、
  影響確認Dialog、Conflict／429、Canonical解除状態、JST解除日時を追加した。
- Operator操作非表示、Backend 403、Mobile／Keyboard／Focusを既存基盤で維持した。

## Test／Evidence

- Backend対象全回帰: `26 Test／443 Assertion` PASS。
- 追加Unpublish HTTP／Atomicity: `4 Test／96 Assertion` PASS。
- Process Concurrency: `1 Test／11 Assertion` PASS。
- Admin OpenAPI Lint／Bundle／Contract: PASS。
- Admin Typecheck／Lint／Build: PASS。
- Admin Unit／Component: `55 Test` PASS。
- Admin Browser E2E: `13 Test` PASS。
- Persistent／Ephemeral `migrate:fresh`各2回、最新Migration
  Rollback／Reapply、全V2 Suite、Backup／Restore、API／Admin Health: PASS。
- Migration数28、Migration Set SHA-256:
  `aebf7c4b850dd5bfcecd971e9472632488d679e86dee86cadafb81b047c8b7bf`
- Backup SHA-256:
  `1c6a07e1b27528e33ffe367dfa0155602f08cd0d06eff677ab84472dcc315061`
- Source／Restore Schema SHA-256:
  `795a55b80fba5cc5757a5ea7620374e9a11cdea24ea19fe1a9a3a32a31933f14`
- 100回 p95 `208.224 ms`、Query最大56、Response 667 byte。
- 1000回 p95 `694.111 ms`、Query最大58、Response 18,546 byte。
- Site Schema 10、Storefront Client 14、Storefront Testkit 22 Test:
  依存順SerialでPASS。
- Policy Unit 89、Quality Unit 5、Security Unit 4、DB Guard Unit 29、
  OpenAPI Unit 4、Release Unit 10: PASS。
- Root Audit 0、Legacy Audit 11、Composer既存Baseline 10、
  新規Critical／High 0、Secret／PII Candidate 0。
- V1 Migration 40件Checksum:
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
- V1 Backend隔離回帰はV1 Path／Checksum不変のためMIG-060Lの
  `334 Test／1,820 Assertion` Evidenceを再利用し、GitHub Integrationで再実行する。
- Local Schema SHA-256:
  `b42a794b78f9bfddaaaba999219d442fb77a8046022c82d11942592ae93ba88d`
- Repository外Evidence:
  `/var/lib/oripa-v2-evidence/MIG-060M/`

## 時間を要した作業

| 作業 | 所要 | 原因／効率改善 | 結果 |
| --- | ---: | --- | --- |
| API Image Build | 約50秒 | Existing Classic BuilderでSource固定 | PASS |
| Backend対象回帰 | 約42秒 | Publish／Schedule／Pause回帰を同時確認 | 26 Test PASS |
| Admin Unit | 約34秒 | 全Component Suiteを一度だけ実行 | 55 Test PASS |
| Admin Browser Smoke | 約42秒／回 | 追加Buttonで旧部分一致Locatorが衝突 | 厳密Roleへ統一しPASS |
| Frozen Install | 約7秒 | Worktree symlinkではPackage binaryを解決不能 | Lockfile固定InstallでPASS |
| Persistent Guard | 約3分 | Fresh 2回、全V2、Rollback／Reapply | PASS |
| Ephemeral Guard | 約6分 | 全V2、Load、Backup／Restore、Cleanup | PASS |

- 開始時Root 3.6GB、`/tmp` 3.1GBをRead-only確認した。Rootが安全閾値を
  下回ったため、未使用Docker Build Cacheだけを削除し4.3GBを回収した。
  稼働Container、Named Volume、V1 Resource、Imageは削除していない。
- Task PostgreSQL起動直後のTarget ProbeはHealth到達前に接続拒否となった。
  Resourceを作り直さずHealth確認後に再試行し、Marker／DB名／Migration集合を確認した。
- Unpublish Fixture初回は既存Dummy Snapshot HashによりPreflightで安全に拒否された。
  Production Ruleを緩和せず、Test内で正式Publish済みSnapshotを作成して再実行した。
- Browser Smokeは追加した`Unpublish Preflight`が既存の部分一致Locatorへ重なった。
  Accessible Nameの完全一致へ補正し、3回目で完了した。実装・Security境界は変更していない。
- Local PolicyはUnpublish OperationとMigration 28番を旧Allowlistで拒否した。
  Public Mutation禁止を維持したままTask固有Operation／Migration集合だけを追加し、
  Policy Unit 89件と実Gateを再実行してPASSした。
- First-party Packageは依存順Serial、Host Toolchainは非変更、Gate／Assertion／
  Security／Timeout／Memoryは緩和していない。

## 非変更

- Published Version、Probability Snapshot、Inventory、Point、Draw History、
  Draw Algorithm、CSPRNG、100／1000回Transaction、Public OpenAPI、
  Storefront、Payment Provider、Domain／Nginx／TLS、Deployment。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tag。

## Gate／Closeout

- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- Final Head、Squash Commit、GitHub 8 Check、Fresh Self-review、Issue／PR状態、
  CleanupはPR Closeout時に確定する。
- 次Task候補: `MIG-060N Admin QA Draw Management`
- MIG-060Nは本Task内で開始しない。
