# MIG-060G Admin Draft Probability Editor／Validation 提出用レポート

## 基本情報

- Task ID: `MIG-060G`
- Risk: `R3`
- Issue: `#137`
- Branch: `feat/MIG-060G-admin-probability-editor`
- Base: `eada85c353f0f6380d00632c05e81eef1243cb17`
- 対象: Draft Probability Version／Stage／Entry／Minimum Guaranteeの
  Admin Contract、Domain、DB Guard、Editor、Server Validation
- 対象外: Probability Publish、Gacha Version Publish／Schedule／Unpublish、
  Public Contract、Draw Logic、Storefront UI、Deployment

## MIG-060F Closeout

- Issue `#135` Closed、PR `#136` Squash Merged。
- Final Head: `1563c2abaa3dab6f241ddb059804ab49a35a80ac`
- Squash Commit: `eada85c353f0f6380d00632c05e81eef1243cb17`
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Check成功。
- Fresh Self-review一致、SEV-0／SEV-1 0件。
- Remote／Local Branch、Worktree Cleanup、Local main同期、V1非変更を確認。

## Characterization／設計

- MIG-050のProbability Version、Stage、Entry、Minimum Guaranteeと、
  MIG-060D～FのCatalog Mutation、Gacha Draft、Permission基盤を再利用した。
- Probabilityは整数ppmだけを使用し、1 Stageの公開可能合計は
  `1,000,000 ppm`とした。保存途中のDraftは合計未達／超過を保持できるが、
  Server Validationで公開不可理由を返す。
- Entryは`prize`または`point_back`、`no_prize`は追加していない。
- 通常Entry内のPrize重複を拒否し、既存Minimum Guaranteeの意味を変更していない。
- Stageは`sold_count`、先頭1、隣接する連続範囲、安定したSort Orderを維持する。
- Entry件数上限等、正本にない仕様は追加していない。

## Schema／DB Guard

- Forward-safe Migration:
  `2026_08_09_000022_add_v2_probability_draft_management.php`
- Probability VersionへRevision、Archive日時、Clone元Version参照を追加した。
- CHECKはRevision正数とArchive状態を明示Cast付きCanonical式で保証する。
- 通常Entryの同一Stage／Prize重複をPartial Unique Indexで拒否する。
- DB TriggerはVersion物理Delete、Published／Archived変更、Identity変更、
  Revision bypass、Published／Archived配下のStage／Entry／Minimum Guarantee変更を
  拒否する。
- Fresh Self-reviewで、Child FKをPublishedからDraftへ付け替えると旧親検査を
  迂回できる不足を検出した。UPDATEでは旧親と新親の両方を検査するよう補強し、
  Entry／Stage双方の回帰Testを追加した。
- 既存Migrationは編集していない。

## Contract／Domain

- Admin OpenAPIへProbability Version一覧／詳細、Draft作成、Clone、
  Entry一括置換、Validation、Discardの7 Operationを追加した。
- Admin Operation数は114、Public 47、Webhook 1である。
- UUIDv7 Public ID、Opaque Cursor、Stable Sort、RFC 9457、Request ID、
  `private, no-store`を維持した。
- Public／Webhook Contractは非変更で、Storefront ClientへAdmin型を公開していない。
- `catalog.read`はOwner／Admin／Operator、`catalog.manage`はOwner／Adminだけに
  許可し、Role名によるUI独自判定は追加していない。
- Draft Mutationは既存Catalog Mutation Executorを再利用し、Admin Realm、
  MFA Enrollment、CSRF、Exact Origin、JSON、Idempotency-Key、Revision OCC、
  Rate Limit、Auditを適用した。
- Create／Clone／Replace／Discardは`catalog.change` Outboxと同一Transactionで
  確定する。Validationは状態を変更しないためOutboxを生成せず、Auditと
  Canonical Idempotency結果を保存する。
- Probability Version Rowと親Gacha／Gacha VersionをLockし、Concurrent Entry更新は
  1件だけ成功、Stale Revisionは409、同一Key異内容は409とした。

## Admin UI

- Gacha Version画面からProbability Version一覧／詳細へ遷移できるようにした。
- Draft作成、Published／Draft Clone、Entry追加／削除、整数ppm入力、
  Prize／Rank表示、Point Back、Minimum Guarantee、Current／Required Total、
  Remaining／Excess、Server Validation、保存、Discardを実装した。
- Published／Archived VersionはRead-onlyで、Publish Buttonは実装していない。
- 既存Admin Shell、PermissionProvider、ProtectedAdminRoute、Catalog共通Component、
  Dirty State、Conflict、Confirmationを再利用した。
- Idempotency-Keyは同じ未確定操作で再利用し、確定後の新操作では新規生成する。
- Canonical再取得、二重送信防止、401／403／429、Mobile、Keyboard、Focusを確認した。
- TailAdmin無料版を視覚基準とし、App全体置換、Dependency更新、CSP緩和は行っていない。

## Test／検証

- Probability対象Clean DB: `6 Test／80 Assertion` PASS。
- 全V2 Suite: `246 Test／2038 Assertion／4 Skip` PASS。
  Skipは既存の明示的Load Test境界であり、PASSとして数えていない。
- Draw回帰: 1000回p95 `678.193 ms`、最大58 Query。
- Admin: OpenAPI Bundle／Breaking Check、生成差分0、Typecheck、Lint、
  Production Build、Unit／Component `46 Test`、Browser E2E `13 Test` PASS。
- Browserでは整数ppm、Canonical Response、CSRF／Idempotency Header、
  Owner／Operator境界、Mobile横溢れなしを確認した。
- Persistent／Ephemeral V2 `migrate:fresh`各2回、最新Migration
  Rollback／Reapply、API／DB／Redis／Storage HealthがPASSした。
- Migration数: 22
- Migration Set SHA-256:
  `5e7fe1193ce445c91c0feada4cfe446fbcfd926b0922d7455c322347a6e55974`
- Backup SHA-256:
  `b15f5bba10939acb516f497871dceff64c7b739c179dedf9de8f94b484449477`
- Source／Restore正規化Schema SHA-256:
  `b1872a79f4619b4ced8c4c6ba55c6e715fb109f9b428b5bb97dd35750f3b4874`
- Source／Restore Migration Row SHA-256:
  `1cc47acfa6529df6ffe5a49d1cacc6c0fcc7dcf39e25c43538667223d42fee1e`
- Evidence: `/var/lib/oripa-v2-evidence/MIG-060G/`、Directory mode 700、
  File mode 600。
- Site Schema 10、Storefront Client 14、Storefront Testkit 22 Testを
  依存順にSerial実行し、生成差分／Typecheck／Lint／Buildを含めPASSした。
- Policy Unit 88、Quality Unit 5、Security Unit 4、DB Guard Unit 26、
  OpenAPI Unit 4、Release Unit 10と各Local GateがPASSした。
- Root／Legacy Frozen Install、Legacy Typecheck／Build、
  Lint既存8 Error／1 Warning FingerprintがPASSした。
- Root Audit 0、Legacy Audit既存11、Composer既存Baseline 10、
  新規Critical／High 0、Secret／PII Candidate 0。
- V1 Migration 40件の正本Checksum:
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`

## 時間を要した作業

| 作業 | 所要 | 原因／効率改善 | 結果 |
| --- | ---: | --- | --- |
| Admin Browser E2E | 約1分6秒 | 既存12 ScenarioにProbability Editorを追加 | 13 Test PASS |
| 全V2 Suite | 約1分55秒／回 | Draw／Shipping性能を含む246 Test | Final 246 Test PASS |
| 全V2 Suite再試行 | 約3分50秒 | 既存Google OIDC 4分59秒境界の一時Failureと、Fresh review後DB Guard補強 | Clean DBで境界Test成功、Final全Suite PASS |
| API Image Build | 約30秒 | Migration／DB Guardを含むFinal Image再生成 | Host更新せずClassic BuilderでPASS |
| Backup／Restore | 約2分 | Custom Dump、Inventory、Restore、Schema／Migration Row比較 | 正規化Checksum一致 |
| First-party Package | 約1分 | 依存Build前の並列実行を避けSerial実行 | 全PASS |
| Policy Unit補正 | 約3分 | 新MigrationのFixture集合とAdmin ppm管理面を旧Read-only禁止が誤検知 | Public漏えい禁止を維持し88 Test PASS |

- 開始時Root空き8.7GB、`/tmp`空き3.3GB、重い検証後はRoot 7.9GB、
  `/tmp` 3.2GBをRead-only確認した。安全閾値内のためDocker Cache、
  Image、Named VolumeのCleanupは実施していない。
- Compose／Buildxの組合せを変更せず、既存Classic Builderを使用した。
- Syntax／OpenAPI／対象Unit／HTTP／Admin Smokeを全回帰より先に実行した。
- First-party Packageは依存順にSerial実行した。
- Google OIDC境界Testの初回FailureはClean DB単独再実行で成功を確認し、
  残存DBでの再試行結果は正本に採用しなかった。
- Fresh Self-review後はBackend Migration／Testだけを変更したため、
  Admin／Browser／Package Evidenceは再利用し、Persistent／Ephemeral Migration、
  Probability対象、全V2 Suite、Backup／Restoreを再実行した。
- Gate、Baseline、Assertion、Timeout、Memory設定は縮小・緩和していない。

## 非変更

- Probability Publish、Gacha Version Publish／Schedule／Unpublish、
  Public Catalog Contract、Draw Logic、Storefront UI、Payment Provider、
  Domain／Nginx／TLS、Staging／Production Deployment。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tag。

## Gate／Closeout

- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- Local R3検証は成功。
- Final Head、GitHub 8 Check、Fresh Self-review、Squash Commit、Issue Close、
  Branch／Worktree／Task Resource CleanupはPR Closeoutで確定する。
- 次Task候補: `MIG-060H Admin Probability Publish／Gacha Version Publish・Schedule`
- MIG-060Hは開始しない。
