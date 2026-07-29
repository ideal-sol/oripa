# MIG-060F Admin Gacha Master／Draft Version Management 提出用レポート

## 基本情報

- Task ID: `MIG-060F`
- Risk: `R3`
- Issue: `#135`
- Branch: `feat/MIG-060F-admin-gacha-draft-management`
- Base: `236f2842003779aeb9e86b24858a0a5619ae1753`
- 対象: Gacha MasterとDraft VersionのAdmin Contract、Domain、DB Guard、UI
- 対象外: Probability Mutation／Publish、Gacha Version Publish／Schedule、
  Public Contract、Draw Logic、Storefront UI、Deployment

## MIG-058C Closeout

- Issue `#133` Closed、PR `#134` Squash Merged。
- Final Head: `9a918c8b492f7acfc2abe92a4f0c22e102f41053`
- Squash Commit: `236f2842003779aeb9e86b24858a0a5619ae1753`
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Check成功。
- Fresh Self-review一致、SEV-0／SEV-1 0件。
- Branch／Worktree Cleanup、Local main同期、V1非変更を確認。

## Characterization／再利用

- MIG-050のGacha／Version Schemaと、MIG-060A～EのAdmin Auth Shell、
  Permission、Read Contract、Catalog Mutation Executorを正本として再利用した。
- Code／Slug、Version番号、Published Version、Draw履歴、Category／Tag／Prize／Asset
  Relationの既存意味を維持した。
- `catalog.read`／`catalog.manage`、Idempotency、Revision OCC、Rate Limit、
  Append-only Audit、Transactional Outboxを重複実装していない。
- Published／Scheduled相当の状態は編集せず、DraftだけをMutation対象にした。

## Schema／Domain

- Forward-safe Migration:
  `2026_08_08_000021_add_v2_gacha_draft_management.php`
- 追加:
  Gacha／Version Revision、Archive日時、Clone元Version参照。
- DB Guard:
  物理Delete、Code／Slug・Version identity変更、Revision bypass、
  Published Version変更、Published／Draw参照を持つMasterの破壊的変更、
  Archive後の本体／Relation変更を拒否。
- Gacha Master:
  一覧／詳細／Create／Update／Archive。
- Draft Version:
  一覧／詳細／Create／Clone／Update／Discard。
- Version番号はGacha Row Lock下でServer生成し、RelationはTransaction内で一括更新。
- ActiveなPublic IDだけを受理し、重複Relation、期間逆転、負数、
  Unknown Field、HTML／Script／制御文字を拒否。

## Contract／Admin UI

- Admin OpenAPIへ11 Operationを追加。Operation数はAdmin 107、Public 47、
  Webhook 1。
- Opaque Cursor、Stable Sort、UUIDv7 Public ID、RFC 9457、Request ID、
  `private, no-store`を維持。
- Public／Webhook Contract、Storefront Client Admin非公開境界は非変更。
- `/catalog/gachas`へ一覧、検索、Pagination、Master／Version詳細、
  Create／Edit／Archive／Clone／Discardを追加。
- Fresh Self-reviewでVersion一覧のCursor操作がUIへ接続されていない不足を検出し、
  Master詳細へ前後ページ導線とRoute単位の状態分離を追加した。
- Category／Tag／Prize／Asset選択、Dirty State、Confirmation、Conflict再読込、
  二重送信防止、Canonical再取得、Mobile／Keyboard／Focusを実装。
- Published VersionはRead-only。Publish Buttonや架空Dataは追加していない。

## Test／検証

- Clean DB対象: 5 Test／76 Assertion PASS。
- Admin: Typecheck、Lint、Production Build、Unit／Component 43 Test、
  Browser E2E 12 Test PASS。Version一覧の次ページ取得と前ページ復帰を含む。
- Persistent Guard: Migration 21件、`migrate:fresh` 2回、
  Rollback／Reapply、全V2 Suite、Health、Schema Inventory PASS。
- Ephemeral Guard: `migrate:fresh` 2回、全V2／Load／Performance、
  API／Admin Health、Backup／Restore、Resource Cleanup PASS。
- Migration Set SHA-256:
  `649d695a3960257fe1a5c591d6d6516bff0e93d83dcbf92d8d23e8ff3608fef5`
- Backup SHA-256:
  `01dcbbb7ba849d1ce19f4e260953a6896b7c14a6caed90411f83415e7bcfc98b`
- Source／Restore Schema SHA-256:
  `56523b1ffeaf71e48214cd39dc1ca02c73b4b1989ea2d099195b4a0672d31b72`
- Source／Restore Migration Row SHA-256:
  `5ea638643e79ea811a2278d3cf7d5b6e996724a2c0e5795a06bfc8d8283b700c`
- Site Schema 10、Storefront Client 14、Storefront Testkit 22 Testを
  依存順にSerial実行し、生成差分／Typecheck／Lint／Buildを含めPASS。
- Policy Unit 88／Policy Gate、Quality Unit 5／Quality Gate、
  DB Guard Unit 26、OpenAPI Unit 4、Release Unit 10／Source Validation PASS。
- Root Audit 0、Legacy Audit 11、Composer既存Baseline 10、
  新規Critical／High 0、Secret Candidate 0。
- Legacy Typecheck／Build、Lint既存8 Error／1 Warning Fingerprint PASS。
- V1 Migration 40件Checksum:
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`

## 時間を要した作業

| 作業 | 所要 | 原因／効率改善 | 結果 |
| --- | ---: | --- | --- |
| 容量確保 | 約1分 | Root空き4.6GBのため、未使用Build Cache 192MBとDangling Image 5.684GBだけを削除 | Root空き12GB |
| Docker Build | 約30秒／回 | Compose v2.40.1とBuildx v0.12.1の`--allow`不一致 | Host更新せずClassic BuilderでPASS |
| Clean DB対象Test | 約7秒／回 | Trigger JoinとPublish state保護を対象回帰で先行検出 | 5 Test／76 Assertion PASS |
| Admin Browser E2E | 約59秒 | 既存11 Scenario＋Gacha画面1 Scenario | 12 Test PASS |
| First-party Package | 約52秒 | 依存Build前の並列実行を避けSerial実行 | 全PASS |
| Persistent Guard | 約141秒 | migrate 2回、Rollback／Reapply、全V2 Suite | PASS |
| Ephemeral Guard | 約426秒 | Load／Performance、Backup／Restore、Health、Cleanup | PASS |

- 重い検証前にRoot／`/tmp`／Docker CacheをRead-only確認した。
- 容量不足時だけ未使用Build CacheとDangling Imageを削除し、稼働Resource、
  Named Volume、V1 Resourceには触れていない。
- Task専用Env名のGuard拒否、古いMigration定義のTask DB、Draw参照Join、
  Publish state過剰保護を対象Testで短時間に検出し、Full Guard前に修正した。
- Syntax／型、OpenAPI、対象Unit／HTTP、Admin Smoke、Persistent、
  Ephemeral、Package／Gateの順を維持した。
- 成功済みAdmin／Package EvidenceはBackend／Policyだけの修正後に重複実行せず、
  Full Persistent／Ephemeralを最終候補で各1回実行した。
- Fresh Self-review後のCursor UI修正はAdmin範囲だけだったため、Admin Typecheck、
  Lint、Unit 43件、Production Build／Browser E2E 12件を再実行した。
  Backend、Migration、Packageに差分がないことを確認し、約9分半を要した
  Persistent／Ephemeral Guardは重複実行せず既存Evidenceを維持した。
- Gate、Baseline、Assertion、Timeout、Memory設定は弱めていない。

## 非変更

- Probability Editor／Publish、Gacha Publish／Schedule、Public Catalog、
  Draw Logic、Storefront、Payment Provider、Domain／Nginx／TLS、Deployment。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tag。

## Gate／Closeout

- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- Local R3検証は成功。
- Final Head、GitHub Check、Fresh Self-review、Squash Commit、Cleanupは
  PR Closeoutで確定する。
- 次Task候補: `MIG-060G Admin Probability Editor／Publish`
- MIG-060Gは開始しない。
