# MIG-060O Admin QA Draw Execution／Result Review 提出用レポート

## 基本情報

- Task ID: `MIG-060O`
- Risk: `R3`
- Issue: `#153`
- PR: `#154`
- Branch: `feat/MIG-060O-admin-qa-draw-execution-review`
- Base: `f4e6187f46ee7cb4d120e1a2015be8577ec5e3da`
- 対象: Admin QA Draw実行、Server Preflight、Execution一覧／詳細、
  Canonical Replay、結果Review UI
- 対象外: 通常User向けDraw UI、Public／Webhook Contract、Storefront、
  LINE、Provider、Domain／TLS、Deployment、V1

## MIG-060N Closeout

- Issue `#151` Closed、PR `#152` Squash Merged。
- Final Head: `889daef8175afdcde845019fde42b4f5600e7768`
- Squash Commit／本Task Base:
  `f4e6187f46ee7cb4d120e1a2015be8577ec5e3da`
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Check成功。
- Fresh Self-review一致、SEV-0／SEV-1 0件、Branch／Worktree／Task Resource
  Cleanup、Local main同期、V1非変更を確認した。

## 実装／Contract

- 既存`V2DrawService`、`V2QaDrawResolver`、QA Plan Assignment、
  QA Execution、Point、Inventory、User Prizeを再利用した。
- Admin OpenAPIへQA Draw実行前Preflight、実行Mutation、Execution一覧／詳細の
  Result Review Fieldを追加した。Public／Webhook BundleはBaseと同一である。
- 実行単位は既存正本の`1`、`5`、`10`、`100`、`1000`だけを使用する。
- PreflightはPlan／Assignment Revision、Plan残数、Point、販売残数、Plan Item順の
  Prize在庫を確認する。実行時はTransaction内のLock後に全条件を再検証する。
- Execution ReviewはExecution、Plan、Test User、Assignment、Gacha／Version、
  Draw Request、回数、Point、Prize／Rank集計、販売／在庫差分、実行日時を返す。
  内部ID、Credential、不要なPII、個別ppmは返さない。
- 既存Execution一覧の`user_id`、`gacha_id`、`draw_request_id`、`from`、`to`を
  維持し、`plan_id`を後方互換なOptional Filterとして追加した。

## Permission／Transaction

- `qa.draw.manage`、Owner-only、Admin Realm、MFA Enrollment、Fresh MFA 5分、
  CSRF、Exact Origin、JSON Content-Type、Critical Mutation Rate Limitを既存共通
  FoundationでFail Closedにする。
- Admin Serviceから通常Drawへ型付きCommandを渡し、Plan／Assignment Public IDと
  RevisionをQA ResolverのLock Queryへ固定する。Controllerだけの検査に依存しない。
- Point、Inventory、販売口数、Draw Request／Result、User Prize、QA Execution、
  Audit、Outbox、Idempotencyは既存Draw単一Transactionで確定する。
- Draw Algorithm、CSPRNG、抽選順、100／1000回Set-based Persistenceは変更していない。

## Idempotency／Concurrency

- Admin Public IDと受領したIdempotency-KeyからDomain内部Keyを導出する。
- 同じKey／同じRequestは既存Canonical Resultを返し、Point、Inventory、
  販売口数、Plan Item、User Prize、Executionを二重作成しない。
- Request HashへPlan／Assignment Revisionを含め、同じKeyの異内容利用を拒否する。
- Concurrent実行、Retry時のPlan Item選択固定、Point不足、在庫不足、販売残不足、
  Chunk途中FailureのRollbackは既存QA Draw／Draw Concurrency基盤を再利用した。

## Admin UI

- `/qa`のActive Plan詳細へ実Data影響の警告、Assignment選択、実行回数、
  Server Preflight、最終確認、二重送信防止、結果Reviewを追加した。
- Execution一覧／詳細、Canonical Replay表示、Fresh MFA Dialog、Conflict／429、
  Loading／Empty／Errorを既存Admin Shellで扱う。
- UTC保存、`Asia/Tokyo`表示、Mobile、Keyboard、Focus、Accessible Role／Nameを
  維持した。架空結果や通常User向けDraw UIは追加していない。

## Test／性能

- Backend対象: `2 Test／27 Assertion` PASS。
- Admin Unit／Component: `59 Test` PASS。
- Admin Browser E2E: `14 Test` PASS。
- Admin OpenAPI Lint／Bundle、生成差分0、Typecheck、Lint、Production Build: PASS。
- Persistent／Ephemeral双方で`migrate:fresh` 2回、Migration 29件、
  rollback／reapply、全V2 Suite、Schema Inventory、Health: PASS。
- Backup／Restore一致、Backup SHA-256:
  `17d4989cf849d39df7b66745f446a4ef84c66ee254efd5ea096686930bcf356b`
- Source／Restore Schema SHA-256:
  `339970b0c5baead71d527b6e86934f12bdf1ff12ef2c311c80ba373c71f6372d`
- Migration Set SHA-256:
  `49e6df42f64c7a3b4124fb9800bc13ea59ed523d155bb7e4a833dfb9ee8a4b29`
- 通常100回p95 `172.990 ms`／Query 56、通常1000回p95
  `750.815 ms`／Query 58。
- QA 100回p95 `152.092 ms`／Query 65、QA 1000回p95
  `654.059 ms`／Query 76。
- QA同一Gacha 5／10 User最終 `4.059 s`／`8.016 s`、
  通常同一Gacha 20 User最終 `16.131 s`。
- 500／502／504、未解決Deadlock、負Wallet、Inventory overflow、
  Point／Inventory／History不一致は0。
- Site Schema 10、Storefront Client 14、Storefront Testkit 22、
  Release 10、Policy 89、Quality 5、DB Guard 29、Site Template 6 Test: PASS。
- Root Audit 0、Legacy Audit 11、Composer Audit 10で件数増加なし。
- Security Unit 4 TestはPASSしたが、Repository正本Baseline
  `.ci/baselines/dependency-advisories.json`の期限が`2026-07-30`であり、
  `2026-07-31`のLocal Security Gateは`dependency advisory baseline has expired`
  でFail Closedした。Baseline／Lockfileは本Taskで変更していない。
- Repository外Evidence:
  `/var/lib/oripa-v2-evidence/MIG-060O/`

## Security／Audit

- Admin実行成功、Canonical Replay、失敗、Execution参照をAppend-only Auditへ記録する。
- Draw Outboxと既存AuditをTransaction内で維持する。
- Raw Session ID、Cookie、Token、Secret、Full Email、CSPRNG生値、内部DB IDを
  Response／Auditへ追加していない。
- Secret／PII Candidate、新規Critical／High、Baseline追加は0件である。

## V1非変更

- V1／共通Infra Path差分は0。
- V1 Migration 40件の内容SHA-256 Set:
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagは非変更である。
- V1全回帰は重複実行せず、Frozen Legacy Typecheck／BuildとLint Baselineを確認した。

## 時間を要した作業

| 作業 | 所要 | 原因／効率改善 | 結果 |
| --- | ---: | --- | --- |
| API Image Build | 約1分 | PHP 8.4固定Classic Builderを使用 | PASS |
| Admin Unit | 約1.2分 | 対象指定が全Component Suiteへ転送 | 59 Test PASS |
| Admin Browser E2E | 約1.1分 | Accessible Nameを完全一致へ補正 | 14 Test PASS |
| Persistent Guard | 約4分 | Fresh 2回、全V2、Rollback／Reapply | PASS |
| Ephemeral Guard | 約9分 | 全V2、Load、Backup／Restore、Health | PASS |
| Frozen Legacy | 約1.3分 | Lockfile固定Install／Build／Lint Baseline | PASS |

- 開始時Root空き`2.6GB`、使用率95%だったため、稼働Container／Named Volume／
  V1 Resourceへ触れずDangling Imageだけを削除し`6.327GB`回収した。
- OpenAPI Gate初回は必須`--repository`引数不足で起動前停止した。正規Commandへ
  修正し、Bundle生成と生成差分検査を一度だけ完了した。
- GitHub初回`policy-gate`は新規`qa-execution-panel.tsx`のAdmin Skeleton
  Allowlist登録漏れを検出した。Wildcardで緩和せず当該Fileだけを正本へ追加し、
  Policy Unit 89件とLocal Gateを再実行してPASSした。
- Browser対象Smokeは部分一致Locatorと警告文期待の2点だけを補正した。
  Security／Permission／実装境界は変更せず、失敗した1シナリオだけ再実行した。
- 通常Draw性能の初回採取は直前QA負荷Fixtureと衝突しTest本体前に停止した。
  Task専用DBをFresh化してFixtureを分離し、通常Draw負荷だけ再実行した。
- First-party Packageは依存順Serial、通過済みSuiteはEvidenceを維持した。
  Host Toolchain、Assertion、Timeout、Memory、Security Gateを緩和していない。

## Final／Gate

- Local実装・機能・DB・性能検証は成功した。
- Security Baseline期限切れのため、Final Head固定、Required Check成功、
  Fresh Self-review、Squash Merge、Issue Close、Cleanupは未確定である。
- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- 次Task候補: `MIG-060P`以降は本Taskで開始しない。

## SEC-005解消後Closeout再検証

### SEC-005取込み

- SEC-005 Issue `#155`はClosed、PR `#156`はSquash Mergedである。
- SEC-005 Final Headは`4488fe98658ca33af7240427052628bc5ba4a9d9`、
  Squash Commit／最新mainは
  `df94c24239c95a5e0d68fc95314eec06dfa45796`である。
- SEC-005のRequired 5 Check、CodeQL 2件、Dependency Reviewはすべて成功し、
  Fresh Self-reviewはSEV-0／SEV-1 0件である。
- 最新mainとMIG-060Oの競合を`git merge-tree`で確認した結果、Application、
  Contract、Migration、DBに競合はなく、追記位置が重なった
  `worklogs/new_ver_main.md`だけを意味確認して両Taskの記録を保持した。
- SEC-005のLegacy Lockfile、Dependency Advisory Baseline、Dependency Review
  Workflow、Security UnitはPRの最新main側から保持する。MIG-060OのApplication／
  Contract差分を欠落させていない。
- Task Policyの固定Baseとfast-forward／Exact Scopeを維持するため、SEC-005の
  CommitをTask Branchへ重複Commitせず、最新mainをPR BaseおよびGitHub Merge
  Resultの正本とした。空Commit、force push、History rewriteは行っていない。

### Security再確認

- Legacy pnpm Audit 0、Root Workspace pnpm Audit 0。
- Composerは既存Baseline 10件（Medium 9／Unknown 1）で、新規Critical／Highは0件。
- Baseline期限は`2026-08-07`。期限延長、Advisory追加、Gate緩和は行っていない。
- Security Unit `6 Test`、Local Security Gate、Secret／PII ScanはPASSした。
- Security Gate結果はComposer 10、Legacy pnpm 0、Workspace pnpm 0、
  Secret Candidate 0である。

### Closeout対象再検証

- MIG-060O Backend対象は`2 Test／27 Assertion`でPASSした。
- Admin OpenAPI Lint／Bundle／Breaking Check、生成差分0、Typecheck、Lint、
  Production BuildはPASSした。
- Admin Unit／Componentは`59 Test`、対象Browser E2Eは`1 Test`でPASSした。
- Policy Gate、Quality Gate、DB Guard Unit `29 Test`、`git diff --check`は
  PASSした。
- DB Target Safety GuardはTask ID `MIG-060O`、DB
  `oripa_v2_mig060o`、Purpose `v2-task-ephemeral`、Schema `public`、
  Migration集合`repository`でPASSした。
- SEC-005はApplication、Migration、DB、Drawを変更していないため、
  Persistent／Ephemeral Guard、Backup／Restore、通常／QA 100／1000回性能、
  同一Gacha負荷、V1 Backend全回帰は再実行していない。旧Headの成功Evidenceを
  保持し、GitHub Integrationで最新mainとの統合を再確認する。

### 時間を要した作業

- Admin OpenAPI／生成／Typecheck／Lint／Build／UnitのSerial検証は約2分、
  対象Browser E2Eは約40秒、Quality Gateは約30秒を要した。
- Backend対象Testの初回起動はComposeの`api:latest`が旧Buildを参照して0 Testで
  停止した。DB／Test Failureではないことを確認し、MIG-060O固定済み
  `api:target` Imageと明示Test Pathへ切り替え、不要なImage再Buildを避けた。
- Root空き8.7GB、`/tmp`空き2.9GBで安全閾値内だったため、Docker Cache、
  稼働Container、Named Volume、V1 Resourceは削除していない。
- 成功済みPersistent／Ephemeral／Backup／Load Evidenceは重複実行せず、
  Assertion、Timeout、Memory、Security Gateを変更していない。

### Final Closeout

- Repository外Evidenceは`/var/lib/oripa-v2-evidence/MIG-060O/`へ保持する。
- Final Head、GitHub 8 Check、Fresh Self-review、Squash Commit、Issue Close、
  Branch／Worktree／Task DB CleanupはPR Closeoutで確定する。
- Gate G4／G5は`NOT COMPLETE`を維持する。
- MIG-060P以降は本Taskで開始しない。
