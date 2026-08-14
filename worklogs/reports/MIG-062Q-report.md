# MIG-062Q Gacha Lifecycle／公開後編集／Public State整理

## Task

- Issue: #265
- Base: `main@25fa06d40a169b43ee677d1fe84d0cf8f3ae7715`
- Branch: `feat/MIG-062Q-gacha-lifecycle-presentation`
- Risk: R4
- Task Policy SHA-256: `67f9a102ea59f9806258573029bcacbd0e6d088500cd186de4080ed170086d76`（既存Preview Image Build WorkflowのContract Artifact生成拡張だけをAtomic追加許可）
- Final Head／PR／Squash Commit: Closeout時に確定
- MIG-062P Cleanup: Worktree、Task DB、Container、Network、Volume、Local Branch削除済み。開始時に`main = origin/main`、Working Tree cleanを確認した。

## Lifecycle／初回公開

- `catalog_gachas.management_status`をLifecycleの正本とし、`first_published_at`を一度でも初回公開処理が成立した不可逆な事実として追加した。Pointerがnullでも再び未公開とは判定しない。
- 初回だけ`draft -> published`または`draft -> scheduled`を許可する。予約はImmutable Versionと最初のDraw Stateを一度だけ確定し、開始前は`coming_soon`、到達後は時刻判定だけで`on_sale`になる。Schedulerによる状態書換えは使用しない。
- 初回予約の開始前は`scheduled_start_at`の変更と取消を許可する。取消時は予約済みDraw Stateを履歴として閉じてDraftへ戻し、開始時刻到達後の取消は拒否する。
- 公開後は`published -> sales_paused -> published`と`published／sales_paused -> unpublished`だけを許可する。`unpublished`は終端であり、再公開・再予約を拒否する。

## 公開後編集／Snapshot

- Gacha Masterに最小Current Presentation Overlayとして、現在タイトル、サムネイル、タグ、説明、注意事項、現在の販売開始／終了日時を保持する。初回予約の取消・変更にだけ使う`scheduled_start_at`と、開始後も保持する`current_publish_start_at`を分離した。公開後変更はこのOverlayだけを更新し、Version Publish、Draw State作成、Inventory再作成を行わない。
- 公開後Gachaはタイトル、サムネイル、タグ、終了日時、状態、説明、注意事項だけをBackend Whitelistで許可する。消費Point、開始日時、Audience、初回User期間、Allowed Draw Counts、Daily Limit、総口数等は409で拒否する。
- Prizeは同一Gachaの安定したPrize Masterに現在タイトル／サムネイルを保持し、公開後更新をこの2項目だけへ限定する。Prize追加／削除、数量、交換Point、Probability等は拒否する。
- Published／Historical Version relationのSnapshot、過去Draw Result、User Prize Snapshotは直接変更しない。Storefrontの現在表示だけがCurrent Presentationを利用する。
- 終端の非公開後もAdmin詳細は最後のPublished SnapshotとCurrent Presentationから基本情報を表示できる。Current Published Pointerと公開中景品表示はnullのままとし、再公開は許可しない。

## Draw State／sold_count／Public

- 初回公開につき原則1 Draw Stateとし、販売停止、再開、公開後表示編集で追加しない。既存Stateは削除せず、`closed`、`closed_at`、`close_reason`で履歴化し、Partial Unique Indexで1 Gachaあたり`selling／paused`のOpen Stateを最大1件にする。
- 実Draw成功数の正本は`gacha_draw_states.sold_count`である。Pause／Resume、Presentation編集、Point交換では変更せず、`catalog_gachas.sold_count`を判定正本にしない。
- Public Sale Stateへ`paused`を追加した。停止中もPublic一覧／詳細へ実残数と通常表示情報を返し、CTA disabled、reason `sales_paused`、`allowed_draw_counts=[]`とする。非公開だけをPublic対象外とする。
- Public OpenAPI、Generated Types、Storefront Client、Site Schema、Testkitを`2.0.0-alpha.16`へ同期する。旧Artifactは上書きしない。Final Head確定前に生成した未採用`alpha.15`候補も差し替えず保持し、配布対象にしない。Strict Client採用前のShared Storefrontで`paused`を生成しないよう、Previewの停止Smokeは直接APIで行い元状態へ戻す。
- Contract ArtifactはProduction HostでBuildせず、Exact PR HeadとRequired ChecksをFail Closedで検証する既存GitHub-hosted `ubuntu-24.04` Preview Image Build Workflowから3 PackageとPublic OpenAPIも生成し、別の1日保持GitHub Artifactとして搬出する。新Secret、Registry、Cloud Resourceは使用しない。

## Migration／Characterization

- Migration `2026_09_07_000052_add_v2_gacha_lifecycle_presentation.php`を追加した。Current Presentation、初回公開時刻、Draw State閉鎖情報、単一Open State制約、Lifecycle整合ConstraintをForward-safeに追加する。既存の非Active Open Stateまたは複数Open Stateは推測変換せずMigrationを停止する。
- Preview事前調査はGacha 6件（published 4、draft 2、scheduled／paused／unpublished 0）、一度公開済み4件、未公開2件、Draw Request 4件、Draw Result 16件だった。
- 全GachaのDraw Stateは各1件以下で、複数Selling State、Active Pointer不整合、旧State参照の移行障害は0件だった。通常Dataの意味を失う自動変換は不要と判定した。
- Task DBでfresh、rollback、reapplyを確認した。現在Pointerあり／なし双方の既存公開Gacha BackfillとLifecycle Constraintを対象Migration Testで検証した。
- Required Integration GateでPostgreSQL 17.11のschema dump／restore表現差を検出した。単一Open State Partial Unique Indexのpredicateを同義のCanonical SQLへ限定修正し、17.11でfresh、rollback、reapplyおよびnonce以外のschema round-trip一致を再確認した。DB保証とApplication挙動は変更していない。

## Verification／Preview／Closeout

- Backend targeted: Lifecycle 4 tests／106 assertions、関連Catalog 37 tests／478 assertions、MIG-062J／MIG-062M回帰 27 tests／270 assertions、公開競合 1 test／12 assertions、Migration Backfill 2 tests／4 assertionsがPASSした。既知のFixture path warning以外の失敗はない。
- Admin targeted: Lifecycle UI Unitを含む17 tests、Typecheck、LintがPASSした。Desktop／Mobile BrowserとProduction BuildはGitHub-hosted Required ChecksおよびPreview Smokeを正本とする。
- Contract: Admin／Public OpenAPI lint／bundle、Generated Client、Storefront Client 24 tests、Site Schema 10 tests、Testkit 30 tests、Policy Gate／Unit 125 tests、`git diff --check`がPASSした。Version更新後のPackage buildはProduction Host Build禁止に従いローカルでは行わず、CI／Artifact生成で確認する。
- Preview Image Build Workflow拡張後にPipeline／Policy対象139 tests、Workflow YAML、Policy Gate、`git diff --check`がPASSした。PostgreSQL 17.11のMigration／Backup Restoreを含むRequired Integration Gateも補正後HeadでPASSした。
- 全Suite、Production Host Build、Storefront Repository、V1、Nginx、Point／Payment、Operational Inventory、Persistent QA制約は対象外である。
- Artifact、Required Checks、Preview Image／Migration／Smoke、Fresh Self-review、Squash Merge、CleanupはCloseout時に確定する。
- G4／G5はNOT COMPLETEを維持する。
