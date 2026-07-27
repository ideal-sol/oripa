# V1 Early Release Worklog

## MIG-045 Closeout

- Issue `#89`はClosed、PR `#90`はMergedをGitHub APIで再確認した。
- Final Headは`c68ff796fe1edf4c05ba7644950a9865c20a294f`、Squash Commitは`07d9da4c8a482a806c092ec8ef19ac62d901dbec`。
- Required 5 Check、CodeQL、Dependency Reviewを含む8 Checkはすべて成功した。
- Final Head固定後のFresh Self-reviewは同一SHAを参照し、SEV-0／SEV-1は0件。
- `platform-v2.0.0-alpha.1`はPre-releaseとして作成済みで、Gate G3は`COMPLETE`。
- MIG-045のRemote／Local Task BranchとWorktreeはCleanup済み。
- Local `main = origin/main`、Main Working Tree cleanを確認した。
- V1 Runtime、V1本番Resource、`archive/v1-current`、Annotated Tagは変更されていない。

## V1-NOTICE-001 お知らせ／LP拡張

### Task

- Task ID: `V1-NOTICE-001`
- Risk: `R3`
- Issue: `#91`
- Base／PR Base: `v1/early-release`
- Base SHA: `bfca8efa0b85c00a88fb0fd439a123b722577b68`
- Branch: `feature/V1-NOTICE-001-content-extension`
- V1 Runtime Worktreeは固定Commitかつcleanのまま維持した。

### 実装

- `announcements.category`へ`notice`／`lp`の固定値とDB Constraintを追加した。既存Recordは`notice`をDefaultとし、NULL／未定義値を拒否する。
- `published_until`をNULL可で追加し、JST基準の公開開始／終了条件をModel Scopeへ一元化した。
- Public一覧は公開期間内の`notice`だけを返し、公開期間内の`lp`は既存詳細URLからだけ取得可能とした。
- LP詳細へ`noindex`、`nofollow`、`noarchive`を設定した。現行RepositoryにSitemap、RSS、関連記事、Public検索の実装はなく、既存の唯一の一覧SurfaceからLPを除外した。
- `ezyang/htmlpurifier` `4.19.0`をExact Versionで追加し、Server-side Sanitizationを実装した。
- `script`、Event属性、`javascript:` URL、`iframe`、`form`、`style`を除去し、段落、見出し、強調、List、Link、Tableを許可した。
- 既存Plain Textは保存値を変更せず、改行とHTML Escapeを適用して互換表示する。
- 管理画面へ必須カテゴリ選択、公開終了日時、同一Sanitizerを使うPreviewを追加した。
- LPはトップスライダー対象へ設定できない。
- 新規V1 Migrationだけを追加し、既存Migrationは変更していない。

### 検証

- 対象Feature Test: 13 Test、47 Assertion。カテゴリ、公開期間、LP直接表示、404、無期限、Admin権限、Preview一致、Stored XSS防止、DB Constraintを確認した。
- Full Backend Regression: 実装前は332 Warning／2既知Failure、実装後は344 Warning／同一2既知Failure。新規Failureなし。
- 既知Failureは`AdminPaymentApiTest`の既存Refund／Chargeback Fixture不整合であり、本Taskの変更範囲外。
- 非Production Migration: `fresh`、Rollback、既存Record作成、Apply、Rollback、Reapplyを実行した。
- Migration前後のRecord数は`1／1／1`、既存本文SHA-256は全段階で一致し、既存Recordの`category=notice`を確認した。
- Frontend Typecheck／Buildは成功した。
- Frontend Lintは実装前後とも既存`8 Error／1 Warning`で、新規Findingなし。
- 非Production Smoke Test: Public Top 200、LP詳細200、Public一覧からLP除外、LP robots 3指定を確認した。
- Composer Auditは既存10 Finding、Frontend Auditは既存18 Finding（High 12／Moderate 6）。追加したHTMLPurifier由来の新規Findingは0件で、Frontend Lockfileは変更していない。
- `git diff --check`、PHP Syntax、JSON Parse、Secret／PII Candidate、Scopeを確認した。
- Production Deployment、V1本番DB Migration／Rollback／Seed、Nginx変更は未実行。

### 保護対象

- `/var/www/oripa-v1-runtime`、`luxe-pack.biz`、`admin.luxe-pack.biz`は変更していない。
- V1本番DB／Redis／Storage、Nginx、Production Containerは変更していない。
- `archive/v1-current`と`v1-before-productization-2026-07-22`は固定Commitのまま。
- V2 `main`、V2実装、MIG-045後続Taskは変更・開始していない。

### 次Task候補

- `V1-DRAW-1000A Bulk Draw 1000 Backend・Transaction・性能基盤`
- 主目的は1000回ガチャとし、100回対応は同じBulk基盤を利用する副次要件とする。

## V1-NOTICE-002 お知らせ一覧のカテゴリ・URL表示

### Task

- Task ID: `V1-NOTICE-002`
- Risk: `R3`
- Issue: `#93`
- PR: `#94`
- Base／PR Base: `v1/early-release`
- Base SHA: `71c010010eb8d35d688ea5e3aca30d2b47987950`
- Branch: `feature/V1-NOTICE-002-announcement-list`

### 実装

- 管理画面のお知らせ一覧へ`notice`／`lp`のカテゴリFilterを追加した。
- Backendの管理一覧APIへ`AnnouncementCategory` Enumで検証するカテゴリ絞り込みを追加し、未定義カテゴリは`422`で拒否する。
- 一覧のカテゴリ表示を`お知らせ`／`LP`へ統一した。
- 各RecordのPublic詳細URLを一覧へ表示し、別Tabで確認できる導線を追加した。
- Publicのお知らせ一覧は引き続き公開期間内の`notice`だけを表示し、`lp`は既存詳細URLからのみ参照可能とする既存仕様を維持した。

### Scope

- 変更対象は管理一覧API、対象Feature Test、管理画面一覧、Worklogだけに限定した。
- Migration、DB Schema、Sanitization、認証、Point、Payment、Draw、V2実装は変更していない。
- 稼働中V1 Runtime、V1本番DB／Redis／Storage、Nginx、`archive/v1-current`、Annotated Tagは変更していない。
- Production Build／Deployおよび本番DB操作は実行していない。

### 検証

- Backend対象Feature Testは`14 Test／58 Assertion`で成功した。既存HTMLPurifier cache書込Warningは発生したが、Test Failureは0件。
- Frontend TypecheckとProduction Buildは成功した。
- Frontend Lintは既存Baselineと同一の`8 Error／1 Warning`で、新規Findingは0件。
- Composer Manifest Validationと変更PHP FileのSyntax Checkは成功した。
- Composer Auditは既存`10 Finding`、Frontend Auditは既存`18 Finding`（High `12`／Moderate `6`）で、Dependency Fileを変更しておらず新規Findingは追加していない。
- `git diff --check`、変更Path、Secret／PII Candidate、Binary／Submodule、V1 RuntimeおよびArchive Refの不変性を確認した。

## V1-DRAW-1000A 1000回Bulk Draw Backend・Transaction・性能基盤

### Task／Preflight

- Task IDは`V1-DRAW-1000A`、Riskは`R3`、Issueは`#95`。
- PreflightでLocal／Remote `v1/early-release`が`3e3d532ac9c4986315087b6836779ff574b3b5fe`で一致し、Working Tree clean、ahead／behind `0／0`を確認した。この実測SHAをTask Base／PR Baseに採用した。
- Nginxと稼働ContainerをRead-only確認し、Production Backendは`127.0.0.1:8120`、Frontendは`127.0.0.1:3120`、Rollback Runtimeは`8110／3110`、Original Runtimeは`8100／3100`だった。
- Publicは`200`、Adminは正常な`307` Redirect、Production Migration Pendingは`0`。V1-NOTICE-001D／002Dの承認済みCloseoutと一致した。
- V2 `main`は`07d9da4c8a482a806c092ec8ef19ac62d901dbec`、`archive/v1-current`と`v1-before-productization-2026-07-22`は`bfca8efa0b85c00a88fb0fd439a123b722577b68`で不変だった。

### Characterization／設計

- 既存1／5／10回は、1つの`draw_requests`、連続する`draw_sequence_number`、個別`draw_results`、Bulk単位のPoint消費、既存`DrawRequestResource`を使用する挙動をCharacterization Testで固定した。
- 確率抽選は各DrawでCSPRNGを1回実行し、`sold_count + draw index`から該当するPublished Probability Stageを毎回選択する。閾値を跨ぐBulkでもStageを省略せず、当選数の一括近似は行わない。
- 100／1000回だけをBulk Pathとし、1／5／10回のQuery／保存／Response Pathは維持した。1000回は単一HTTP Request、単一DB Transaction、1000件の個別履歴として処理する。
- Lock順は`gacha`、`wallet`、Point Lot、対象Prizeを各集合内`id ASC`で固定した。Bulk開始前にPoint残高と残口数を検査し、途中FailureはPoint、在庫、履歴、景品、集計を全Rollbackする。
- GachaとPrizeはBulk中にMemory上で逐次更新し、DB保存をBulk末尾へ集約した。Probability Rangeは各Drawで現在の在庫を反映して再構築し、売切Prizeのppmを既存Minimum Guaranteeへ吸収する。
- 同一Userの異なるKey、別Userの同一Gacha、同一Keyの並行実行を実DB Processで検証し、残高、在庫、通し番号、User Prizeの重複や超過がないことを確認した。
- Deadlock／Serialization FailureはLaravel TransactionのBulk限定3回Retryとし、既存1／5／10回は従来どおり1回のままにした。外部通信はTransactionへ追加していない。

### Idempotency／Response

- 100／1000回では`Idempotency-Key` Headerを必須とし、同一Key＋同一Requestは保存済みBulk結果を返す。同一Key＋異なるRequest、処理中Record、24時間のReplay期限切れは`409`で拒否する。
- Request HashはUser、Gacha、Draw Countだけから1回決定し、時刻依存値を含めない。期限切れRecordは監査可能性のため自動削除せず、Cleanupは後続の明示的Retention Taskで扱う。
- Bulk Responseは`bulk_request_id`、`requested_count`、`executed_count`、消費Point、景品別／Rank別集計、高Rank結果、Status、Replay、処理時間を返す。内部DB ID、Idempotency Key、1000件の全結果は公開しない。
- 個別結果はDBへ全件保存し、API集計の当選数と個別履歴数が一致することをTestで確認した。既存1／5／10回Response Contractは変更していない。

### Migration／Test

- 新規Migrationだけで`draw_requests.public_id`、Request Hash、Replay期限、処理時間を追加した。既存Migrationは編集していない。
- Task専用PostgreSQLで`migrate:fresh`、最新MigrationのRollback、再Applyを実行し、全MigrationがAppliedであることを確認した。Production DBではMigration／Rollback／Seed／`migrate:fresh`を実行していない。
- Bulk／QA対象Testは最終変更前後を通して成功し、最新対象実行は`39 Warning／271 Assertion`、追加契約Testは`2 Warning／27 Assertion`だった。Warningは既存の`.env`／HTMLPurifier cache読込に由来する。
- Full Backend Regressionは`368 Warning／2092 Assertion`。既知`AdminPaymentApiTest`のRefund／Chargeback 2 Failureは既存Fingerprintと完全一致した。
- Full Regression時のTest Containerに`APP_KEY`が未指定だったためPassword Reset 2件が環境起因で失敗したが、非秘密のTest用`APP_KEY`を明示した対象再実行は`2 Warning／8 Assertion`で成功した。新規Code Failureは0件。
- Point、Payment、Refund、Chargeback、QA、Probability、Inventory、User PrizeのRegressionは上記Full Backend Regression内で実行した。
- Frontend Frozen Install、Typecheck、Buildは成功。Lintは既存Baselineと同一の`8 Error／1 Warning`で、新規Findingなし。
- Composer Manifestは有効、Composer Auditは既存10 Finding。Frontend Auditは既存18 Finding（High 12／Moderate 6）で、Dependency Fileを変更しておらず増加なし。
- Task専用BackendをLoopback `8135`で起動し、非Production `/api/health`はApplication／PostgreSQL／Redis／Storageすべて`ok`で`200`だった。

### 性能

- Task専用PostgreSQL／Redisと4 Rank、Prize合計40％、Minimum Guarantee 60％の代表Fixtureを使用し、100／1000回を各5回実行した。
- 100回はp50 `339.734 ms`、p95 `370.136 ms`、Transaction `315-362 ms`、Query `289-297`、Response `2,968-4,889 bytes`。
- 1000回はp50 `3,174.876 ms`、p95 `3,514.548 ms`、Transaction `3,124-3,504 ms`、Query `2,613-2,656`、Response `6,445-6,744 bytes`、観測Peak Memory増分最大`2,097,152 bytes`。
- 実効API Timeout `60,000 ms`の50％である`30,000 ms`を基準とし、1000回p95は基準内。隔離性能測定で観測Lock Waitは`0 ms`、競合時の整合性は別の並行Testで確認した。
- 各Sampleで作成した`draw_results`、User Prize＋Point Lot、Point差分、残口数差分が要求Draw Countと一致した。

### Scope／保護対象

- 変更はBulk Draw Domain、必要最小限のPoint付与Batch、Request／Resource／Model／Migration、対象Test、Domain README、Worklogに限定した。
- Frontend UI、100／1000回Button、Animation、結果画面、確率仕様、Payment仕様、V2実装は変更していない。
- Production Deployment、Nginx変更、Runtime設定変更、V1本番DB／Redis／Storage操作は未実行。
- 稼働中／Rollback／Original Runtime、V2 `main`、`archive/v1-current`、Annotated Tagは変更していない。

### 次Task候補

- `V1-DRAW-1000B Bulk Draw 1000 Frontend・結果集計・本番反映`
