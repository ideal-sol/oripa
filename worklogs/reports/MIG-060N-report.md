# MIG-060N Admin QA Plan／Test User Management 提出用レポート

## 基本情報

- Task ID: `MIG-060N`
- Risk: `R3`
- Issue: `#151`
- Branch: `feat/MIG-060N-admin-qa-plan-management`
- Base: `373f51d9605da2881482899b4f7f59b6132ab410`
- 対象: QA Plan／QA Test Userの参照、作成、更新、割当、無効化、Preflight
- 対象外: QA Draw実行／再実行／結果確認、通常Draw仕様変更、Public Contract、
  Storefront UI、Provider、Domain／TLS、Deployment

## MIG-060M Closeout

- Issue `#149` Closed、PR `#150` Squash Merged。
- Final Head: `d0a77dd9149a5389d8c743fefcc9d2830bd931ff`
- Squash Commit: `373f51d9605da2881482899b4f7f59b6132ab410`
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Check成功。
- Fresh Self-review一致、SEV-0／SEV-1 0件。
- Branch／Worktree／Task Resource Cleanup、Local main同期、V1非変更を確認。

## Characterization／Permission

- 既存QA Test Mode、QA Plan、Plan Item、QA Execution、QA Resolverを再利用した。
- Plan状態は`active`、`paused`、`completed`、`disabled`で、Plan Itemは対象Gachaの
  Published Versionに属するPrize Relation、数量、消費数、任意Assetを保持する。
- QA DrawはMockではなく通常Drawと同じPoint、Inventory、販売口数、User Prizeへ
  影響する。管理Preflightはこれらを変更しない。
- 確定済み`qa.draw.manage`はOwner-only＋Fresh MFA 5分である。推奨値より既存正本を
  優先し、Admin／OperatorへQA権限を追加していない。

## Contract／Domain

- Admin OpenAPIへPlan一覧／詳細／作成／更新／Enable／Disable／Archive／Preflight、
  Test User一覧／Candidate検索／Mode保存／無効化、割当／解除を追加した。
- UUIDv7 Public ID、Opaque Cursor、Stable Sort、RFC 9457、`private, no-store`、
  Idempotency-Key、Revision OCC、Canonical Replayを共通基盤で実装した。
- Admin Realm、MFA Enrollment、Fresh MFA、CSRF、Exact Origin、JSON、
  Critical Rate LimitをService境界でもFail Closedにする。
- Code／User／Gachaはimmutable、Unknown Field、期間逆転、Terminal／Archived、
  実行済みPlanの破壊的変更、無効User、重複／競合Assignmentを拒否する。
- PreflightはPublished Version／Probability Snapshot、Stage合計、Prize Relation、
  残Draw数、Active Test User、有効期間をServerで検証する。
- QA Draw実行Endpoint、Public／Webhook変更、Storefront ClientへのAdmin型公開はない。

## Migration／Concurrency

- Forward-safe Migration
  `2026_08_16_000029_add_v2_qa_plan_management.php`を追加した。
- QA Mode／Plan Revision、Plan Code／Archive、履歴保持型
  `qa_draw_plan_assignments`を追加し、既存Plan所有UserをUUIDv7 Assignmentへ移行した。
- CHECK、Restrict FK、TriggerによりRevision bypass、Identity付替え、
  Terminal／Archive後変更、Assignment物理DeleteをDBでも拒否する。
- Plan／User／Mode／Assignment Lock、Unique制約、Revision OCCによりConcurrent
  Update／Assignmentは1件だけ成功する。
- Mutation、Audit、Transactional Outbox、Idempotencyを単一Transactionで確定する。

## Admin UI

- `/qa`へOverview、Plan一覧／詳細／Form、Enable／Disable／Archive、Preflight、
  Test User一覧／Candidate検索／Mode設定／割当／解除を実装した。
- Conflict再読込、Dirty State、Confirmation、二重送信防止、Canonical再取得、
  Fresh MFA、Loading／Empty／Error、Mobile／Keyboard／Focusを既存基盤で統一した。
- Admin表示は`Asia/Tokyo`、保存はUTCである。
- QA Draw実行／再実行Buttonと架空結果は追加していない。

## Test／Evidence

- Backend QA管理／QA Draw／HTTP／DB Guard: `20 Test／183 Assertion` PASS。
- Process Concurrency: `2 Test／14 Assertion` PASS。
- Admin OpenAPI／生成／Typecheck／Lint／Build: PASS。
- Admin Unit／Component: `58 Test` PASS。
- Admin Browser E2E: `14 Test` PASS。
- Persistent／Ephemeral `migrate:fresh`各2回、最新Migration
  Rollback／Reapply、全V2 Suite、Backup／Restore、Health: PASS。
- Migration数29、Migration Set SHA-256:
  `49e6df42f64c7a3b4124fb9800bc13ea59ed523d155bb7e4a833dfb9ee8a4b29`
- Backup SHA-256:
  `6b817a304ac8a039337e51278f09573820a5d74b0408fd6d7c1379f8c6b5dbc9`
- Source／Restore Schema SHA-256:
  `339970b0c5baead71d527b6e86934f12bdf1ff12ef2c311c80ba373c71f6372d`
- 通常100／1000回p95: `157.390 ms`／`621.172 ms`、Query 56／58。
- QA 100／1000回p95: `191.098 ms`／`647.095 ms`、Query 65／76。
- QA同一Gacha 5／10 User最終: `4.039 s`／`8.279 s`。
- 通常同一Gacha 20 User最終: `16.032 s`。
- 500／502／504、未解決Deadlock、Point／Inventory／History不一致: 0。
- Site Schema 10、Storefront Client 14、Storefront Testkit 22 Test:
  依存順SerialでPASS。
- Policy Unit 89、Quality Unit 5、Security Unit 4、DB Guard Unit 29、
  OpenAPI Unit 4、Release Unit 10: PASS。
- Root Audit 0、Legacy Audit 11、Composer既存Baseline 10、
  新規Critical／High 0、Secret／PII Candidate 0。
- V1 Migration 40件Checksum:
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
- Repository外Evidence:
  `/var/lib/oripa-v2-evidence/MIG-060N/`

## 時間を要した作業

| 作業 | 所要 | 原因／効率改善 | 結果 |
| --- | ---: | --- | --- |
| API Image Build | 約1分 | Existing Classic BuilderでSource固定 | PASS |
| Backend対象回帰 | 約1分 | QA管理と既存QA DrawをClean DBで先行 | 20 Test PASS |
| Admin Browser E2E | 約1.1分 | Accessible Role／Name完全一致を使用 | 14 Test PASS |
| Persistent Guard初回 | 約3分 | 新TableのSchema Inventory不足をFail Closed検出 | Guard正本を補正 |
| Persistent Guard Final | 約3分 | Fresh 2回、全V2、Rollback／Reapply | PASS |
| Ephemeral Guard | 約6分 | 全V2、Load、Backup／Restore、Cleanup | PASS |

- Root空き2.1GBが安全閾値を下回ったため、未使用Docker Build Cacheだけを削除して
  約1.9GBを回収した。稼働Container、Image、Named Volume、V1 Resourceは
  削除していない。
- 対象Unit／HTTP／Admin SmokeをFull Guard前に実行した。既に完了した全回帰は
  中断または重複実行せずEvidenceを維持した。
- Persistent Guard初回の約3分を無駄に再試行しないよう、Schema Inventoryと
  DB Guard Unit 29件を先に修正・検証してからFinal Guardを実行した。
- First-party Packageは依存順Serial、Root／LegacyはFrozen Installを使用した。
  Host Compose／Buildx、Security／Quality Gate、Assertion、Timeout、Memoryは
  更新または緩和していない。

## 非変更

- QA DrawのPoint、Inventory、販売口数、User Prize、CSPRNG、抽選順、
  100／1000回Transaction／Set-based Persistence。
- Public／Webhook OpenAPI、Storefront、Payment Provider、Domain／Nginx／TLS、
  Deployment。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tag。

## Gate／Closeout

- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- Final Head、Squash Commit、GitHub 8 Check、Fresh Self-review、Issue／PR状態、
  CleanupはPR Closeout時に確定する。
- 次Task候補: `MIG-060O Admin QA Draw Execution／Result Review`
- MIG-060Oは本Task内で開始しない。
