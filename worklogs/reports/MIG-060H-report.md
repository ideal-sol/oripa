# MIG-060H Admin Probability Publish Foundation 提出用レポート

## 基本情報

- Task ID: `MIG-060H`
- Risk: `R3`
- Issue: `#139`
- Branch: `feat/MIG-060H-probability-publish`
- Base: `515f30cd38e3cefc83e50d9067c5a3b0252e4c7d`
- 対象: 検証済みDraft Probability VersionのServer再検証と、
  不変なPublished Probability Snapshotへの確定
- 対象外: Gacha Version Publish／Schedule／Unpublish、Public Catalog切替、
  Draw Logic、Storefront UI、Deployment

## MIG-060G Closeout

- Issue `#137` Closed、PR `#138` Squash Merged。
- Final Head: `f5ea26f72c4f3b857283c194aac6d1003ab96379`
- Squash Commit: `515f30cd38e3cefc83e50d9067c5a3b0252e4c7d`
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Check成功。
- Fresh Self-review一致、SEV-0／SEV-1 0件。
- Remote／Local Branch、Worktree Cleanup、Local main同期、V1非変更を確認。

## Characterization／設計

- MIG-050、MIG-060F、MIG-060GのSchema、Gacha Draft、
  Probability Draft Editor／Validation、Catalog Mutation基盤を再利用した。
- 同一Gacha Versionには複数のPublished Probability Snapshotを候補として
  保持できる。選択PointerとGacha Version PublishはMIG-060Iへ延期した。
- PublishはProbability Versionだけを確定し、Gacha VersionのStatus／Pointer、
  Gacha MasterのState／Published Version、Public Catalog、Draw参照を変更しない。
- Float、丸め、自動補正、Client算出Hash、新しい確率仕様は追加していない。

## Contract／Permission

- Admin OpenAPIへPublish PreflightとDraft Probability Publishを追加した。
  既存Published Probability詳細取得Contractを再利用した。
- Admin Operation数116、Public 47、Webhook 1。Public／Webhook Contractは非変更。
- 中央Permission Matrixへ`catalog.publish`を追加した。
  Owner／Adminは許可、Operatorは拒否する。
- Admin Realm、MFA Enrollment、Fresh MFA 5分、CSRF、Exact Origin、JSON、
  Idempotency-Key、Revision OCC、RFC 9457、Request ID、
  `private, no-store`を適用した。
- Critical Mutationは既存共通Limiterの10回／10分を使用し、
  Limiter障害時はFail Closedとする。

## Publish Domain／Snapshot

- Idempotency Record、親Gacha、Gacha Version、Probability VersionをLockし、
  Serverが保存済みStage／Entry／Minimum Guaranteeを毎回再検証する。
- 全Stageで整数ppm合計`1,000,000`、連続した`sold_count`範囲、
  ActiveなPrize Relation、Minimum Guaranteeの正本制約を必須とする。
- 正規化Stage構造からServer側でSnapshot SHA-256を決定的に再計算する。
- Published状態、Published At、Revision、Snapshot SHA、Append-only Audit、
  `catalog.change` Outboxを同一Transactionで確定する。
- Canonical Replay、同一Key異内容409、Stale Revision、Concurrent後着、
  再Publish、Archived／不完全DraftのFail Closedを確認した。

## Schema／DB Guard

- Forward-safe Migration:
  `2026_08_10_000023_protect_v2_published_probability_relations.php`
- 既存Migrationは編集していない。
- MIG-060GのGuardでPublished Version、Stage、Entry、Minimum Guarantee、
  Revision、Snapshot Hashの更新／削除を拒否する。
- 新GuardでPublished Snapshotが参照するGacha Version／Prize Relationの
  付替えと削除を拒否し、Draft親経由のFK付替え迂回を防止する。
- Migration Apply／Rollback／Reapply、Dump／Restore後のTrigger一致を確認した。

## Admin UI

- 既存Probability EditorへPublish Preflight、Validation結果、
  Fresh MFA、最終確認、Publish、Conflict／Rate Limit、Published At、
  Snapshot Hash短縮表示、Canonical再取得を追加した。
- Fresh MFA再認証後も同じ未確定操作のIdempotency-Keyを再利用する。
- Published後はRead-onlyで、OperatorにPublish操作を表示しない。
- Gacha Version Publish／Schedule Buttonは追加していない。
- Dirty State、二重送信防止、Mobile、Keyboard、Focusは既存基盤を再利用した。

## Test／検証

- Probability Publish対象: `11 Test／125 Assertion` PASS。
- 専用Concurrency／DB Guard Test: PASS。
- Persistent／Ephemeral Guardの双方で全V2 Suiteと
  Probability／Catalog／Draw／QA回帰: PASS。
  GuardがSuite件数を出力しないため、件数は推測していない。
- Admin OpenAPI Lint／Bundle／Breaking Check、生成差分0、
  Typecheck、Lint、Production Build: PASS。
- Admin Unit／Component: `47 Test` PASS。
- Admin Browser E2E: `13 Test` PASS。
- Persistent／Ephemeral `migrate:fresh`: 各2回 PASS。
- 最新Migration Rollback／Reapply、API／DB／Redis／Storage Health: PASS。
- Migration数: 23
- Migration Set SHA-256:
  `2779a163800e46d06d0dc094fc41b1ee80ea4f027a9e5df63115f1defcf0e4a5`
- Backup SHA-256:
  `120a687894055484887ae94ef7d4c11102ab3b2bdc35549c7222ce1ea47134ed`
- Source／Restore Schema SHA-256:
  `76b283dad2b68bd2a042f1de5e048f645cd9532e6d70be2fe77a582ef36bdfa4`
- Source／Restore Migration Row SHA-256:
  `691a9fc0a40da64e4659e0117c4e9c101b6d1ff73f5263e8044e364cd5838a6c`
- Site Schema 10、Storefront Client 14、Storefront Testkit 22 Testを
  依存順にSerial実行し、生成差分／Typecheck／Lint／Buildを含めPASS。
- Policy Unit 88、Quality Unit 5、Security Unit 4、DB Guard Unit 26、
  OpenAPI Unit 4、Release Unit 10: PASS。
- Root／Legacy Frozen Install、Legacy Typecheck／Build: PASS。
- Legacy Lint既存`8 Error／1 Warning` Fingerprint: 一致、増加なし。
- Root Audit 0、Legacy Audit 11、Composer既存Baseline 10、
  新規Critical／High 0、Secret／PII Candidate 0。
- V1 Migration 40件の正本Checksum:
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
- Evidence: `/var/lib/oripa-v2-evidence/MIG-060H/`

## 時間を要した作業

| 作業 | 所要 | 原因／効率改善 | 結果 |
| --- | ---: | --- | --- |
| Admin Browser E2E | 約59秒 | Publish／Fresh MFA／Mobileを既存Scenarioへ統合 | 13 Test PASS |
| Persistent Guard | 約3分 | migrate:fresh 2回、全Suite、Rollback／Reapply | PASS |
| Ephemeral Guard | 約5分 | 全Suite、性能回帰、Backup／Restore、Cleanup | PASS |
| API Image Build | 約30秒 | Migration／DB Guardを含む固定Target Image | Classic BuilderでPASS |
| First-party Package | 約1分 | 依存Build前の並列実行を避けSerial実行 | 全PASS |
| Browser不具合補正 | 約2分 | Fresh MFA後にIdempotency-Keyが変わる不足を検出 | 同一Key維持後に全E2E PASS |
| Policy Fixture同期 | 約1分 | 新Migrationが既存Fixture集合に未反映 | Gateを弱めず88 Test PASS |

- 開始時Root空き8.2GBからBuild後6.7GBとなったため、稼働Container、
  Named Volume、V1 Resourceへ触れず、未使用Build CacheとDangling Imageだけを
  Cleanupした。重い検証前はRoot空き13GB、完了時は12GBである。
- 旧Task DBへのRollback確認は対象Migrationが存在せずNo-opだった。
  既存DBを変更せずTask専用Clean DBへ切り替え、Apply／Rollback／Reapply、
  Dump／Restoreを実施した。
- OpenAPI初回はWorktreeの依存未展開を検出した。Host Toolchainを更新せず、
  Rootの固定RedoclyとFrozen Offline Installを使用した。
- Syntax／OpenAPI／対象Unit／HTTP／Admin Smokeを先行し、
  First-party Packageを依存順にSerial実行した。
- 同一Headの成功Evidenceは重複実行せず維持した。
- Gate、Baseline、Assertion、Timeout、Memory設定は縮小・緩和していない。

## 非変更

- Gacha Version Publish／Schedule／Unpublish、Public Catalog切替、
  Draw Logic、Public／Webhook Contract、Storefront UI、Payment Provider、
  Domain／Nginx／TLS、Staging／Production Deployment。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tag。

## Gate／Closeout

- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- Local R3検証は成功。
- Final Head、GitHub 8 Check、Fresh Self-review、Squash Commit、Issue Close、
  Branch／Worktree／Task Resource CleanupはPR Closeoutで確定する。
- 次Task候補: `MIG-060I Admin Gacha Version Publish／Schedule`
- MIG-060Iは開始しない。
