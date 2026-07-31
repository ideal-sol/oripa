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

## V1-DRAW-1000B 100／1000回Bulk Draw Frontend・結果集計・本番反映

### Task／Preflight

- Task IDは`V1-DRAW-1000B`、Riskは`R3`、Issueは`#97`。
- Base／PR Baseは`v1/early-release`、Base SHAは`64a8a6481bad4dc4d4d62efbf39eddaf35fac60b`。Local／Remoteは一致し、Working Tree clean、未Push／未Merge Commitなしを確認した。
- V1-DRAW-1000AはIssue `#95`、PR `#96`で完了し、同Squash Commitが今回のBaseである。
- 開始時のProduction Backendは`127.0.0.1:8120`、Frontendは`127.0.0.1:3120`、直前Rollback Runtimeは`8110／3110`。Publicは`200`、Adminは正常な`307`、API Healthは`200`、Production Migration Pendingは`0`だった。
- V2 `main`は`07d9da4c8a482a806c092ec8ef19ac62d901dbec`、`archive/v1-current`とAnnotated Tagは`bfca8efa0b85c00a88fb0fd439a123b722577b68`で不変だった。

### Frontend／Idempotency

- 既存1／5／10回のButton、Request、全画面Animation、個別Result表示を変更せず、同じ選択領域へ100／1000回を追加した。1000回は既存配色内で強調し、Mobileでは全幅表示する。
- 100／1000回だけに確認Dialogを追加し、実行回数、1回Point、合計消費Point、現在残高、予想残高、残り口数を表示する。Point不足、残口数不足、日次上限不足では実行を無効化し、Backendを最終判定と明記した。
- 意図的な新規Bulk操作ごとにBrowser CSPRNGの`crypto.randomUUID()`で`Idempotency-Key`を1回生成する。Response不明または同一Key処理中では同じKeyを保持し、別Keyで自動再実行しない。
- 同一Key処理中、Key期限切れ、Request Conflict、Point不足、残口数不足、認証切れ、結果不明、Server Errorを分離して表示する。
- 100／1000回では個別Animationを再生せず、Fake Progressを持たない短い処理中表示と二重操作防止を使用する。

### Result UI

- Bulk Responseの`requested_count`、`executed_count`、消費Point、景品別／Rank別集計、高Rank結果、Replay、処理時間、公開Bulk Request IDを使用する。
- 更新後Point残高は抽選後に既存`/me/points`から再取得し、残り口数は実行件数との差分を表示する。
- 景品別とRank別の合計を`executed_count`と検証する。Minimum Guaranteeの`point_back`はBackend集計との差分から明示的な集計行として表示し、合計不一致を黙って表示しない。
- 景品は同一景品を数量で集約し、高RankはBackend上限以内だけを表示する。1000件の個別DOMを生成せず、個別結果はBackendの履歴を正本とする。
- Dialog、Status通知、Keyboard操作、Mobile 1 Column、画像Fallbackを追加し、Desktop／Mobileで横溢れがないことをBrowserで確認した。

### 非Production検証

- Task専用PostgreSQL 17、Redis 7、Backend、Frontendを隔離Networkで起動し、固定Test Fixtureだけを使用した。Production DB／Redis／Storage、Nginx、Runtimeは使用・変更していない。
- Browser E2Eで既存1／5／10回Button、100回確認Dialog、100回成功、Response喪失後の同一Key Replay、1000回成功、集計合計、Point残高、残り口数、景品別／Rank別、高Rank上限、履歴再取得、Keyboard操作、Desktop／Mobileを確認した。
- 実HTTP Requestは100回の初回と同一Key Replay、1000回の合計3件。1000件の個別Requestは送信していない。新規1000回操作では100回のKeyと異なるKeyを使用した。
- DB結果は`draw_requests=2`、`draw_results=1100`、`user_prizes=1100`、`sold_count=1100`、Wallet残高`0`で一致した。Migrationは全件Applied。
- Browser Consoleの新規Errorは0件、Mobile横溢れは0px。Browser ScreenshotとSummaryはRepository外Evidenceへ保存し、SHA-256を取得した。
- Frontend Frozen Install、Typecheck、Production Build、変更File単体Lintは成功した。Full Lintは既存Fingerprintと同一の`8 Error／1 Warning`で、新規Findingは0件。
- `frontend/package.json`と`frontend/pnpm-lock.yaml`は変更しておらず、Checksumはそれぞれ`2736b5097f5cdcf3c12dcb11fab531787e7a12e0f16447df6a73e0dc7a8d3ad0`、`55171c1b7dd2f1988b77bdcb8906ce4401cb860a6b6c8c0bfc36dc76f6cb8bfd`でBaseと一致した。
- Full Backend Regressionは`370 Warning／2099 Assertion`で、既知`AdminPaymentApiTest`のRefund／Chargeback 2 Failureだけが既存Fingerprintと一致した。新規Failureは0件だった。
- Full Regressionに含まれるBulk性能Testも再実行され、100回はp50 `382.437 ms`／p95 `470.299 ms`、1000回はp50 `3,366.853 ms`／p95 `3,576.927 ms`だった。実効API Timeout `60,000 ms`の50％である`30,000 ms`以内を維持した。
- Frontend Unit Test基盤は存在せず、新規FrameworkをRepositoryへ追加せずBrowser Smokeを使用した。Productionの成功Drawは安全なFixtureが存在しない限り未実行とする。
- `git diff --check`、変更Scope、Secret／PII Candidate、Binary／Submodule、V1 Migration不変を確認した。

### Merge／Production反映境界

- 変更対象は`frontend/src/app/gachas/[id]/draw-panel.tsx`、`frontend/src/app/globals.css`、本Worklogだけ。Backend、Migration、Dependency、Lockfileは変更していない。
- GitHub Check未設定の場合は成功扱いにせず、Local Validationと固定Head Self-reviewをEvidenceとする。
- Squash Merge後のCommitだけをDeploy Sourceとし、Full／Schema Backup、`pg_restore --list`、Migration Status、現行Runtime／Nginx Upstream、Rollback手順をRepository外Evidenceへ保全してから反映する。
- ProductionではV1-DRAW-1000Aの新規MigrationだけがPendingであることを確認し、承認対象以外のMigrationがあれば停止する。`migrate:fresh`、Reset、Seed、手動Data修正は実行しない。
- Productionの実景品／実Pointを使う成功Drawは行わず、安全なFixtureがない場合はUI表示と非破壊経路だけを確認する。

## V1-DRAW-1000A2 Bulk Draw Set-based Persistence最適化

### Task／Preflight

- Task IDは`V1-DRAW-1000A2`、Riskは`R3`、Issueは`#99`。
- 確認済みBase `64a8a6481bad4dc4d4d62efbf39eddaf35fac60b`以後に、承認済み`V1-DRAW-1000B`がSquash Mergeされていた。Local／Remote `v1/early-release`が一致し、Working Tree clean、未Push／未Merge Commitなしを確認したため、最新の`0c5262d42babc1bf3a63bd991ab07afb014a03c2`をTask Base／PR Baseに採用した。
- GitHub App Wrapperが`performance/`を許可していないため、Task Policyを緩和せず、Branchは`refactor/V1-DRAW-1000A2-set-based-persistence`とした。
- V1-DRAW-1000AはPR `#96`でMerged、Issue `#95`はClosed、Squash Commit `64a8a6481bad4dc4d4d62efbf39eddaf35fac60b`が履歴に存在することを確認した。
- Production Backend／Frontendは`127.0.0.1:8130`／`127.0.0.1:3130`、直前Rollback Runtimeは`8120`／`3120`。Publicは`200`、Adminは正常な`307`、Production Migration Pendingは`0`だった。
- V2 `main`、Production Runtime／Nginx／DB／Redis／Storage、`archive/v1-current`、Annotated TagはRead-only確認だけを行い、変更していない。

### 変更前性能／Bottleneck

- Task専用PostgreSQL 17／Redis 7と代表Fixtureで、100回／1000回を各5回、同一Gachaへ5／10／20 User × 1000回を測定した。
- 100回はp50 `352.282 ms`、p95 `368.740 ms`、Query `288-299`。1000回はp50 `3038.793 ms`、p95 `3143.005 ms`、Query `2610-2660`、Transaction `2965-3133 ms`だった。
- 同一Gachaの最終完了は5 User `17157.004 ms`、10 User `33038.802 ms`、20 User `68965.620 ms`。実測Lock Wait最大はそれぞれ`9040 ms`、`19330 ms`、`41540 ms`だった。
- 1000回RequestのQueryは約2600件で、`DrawResult`、`UserPrize`、Point Lot／Ledgerの個別`INSERT`が大半を占めた。Gacha Row Lock取得後のTransaction保持時間が集中実行を直列に増幅する主要Bottleneckだった。
- 変更前EvidenceはRepository外へ保存し、Directory mode `700`、File mode `600`、Evidence SHA-256は`77f3808496ab886b518a9cfbbd0f38aba823920c4d8a557c53203d778a5b3589`。

### Set-based Persistence／互換境界

- 各DrawのProduction CSPRNGは引き続き`random_int`を使用する。`CryptographicRandomSource`はTest時に決定的な値を注入する境界だけを追加し、抽選回数、順序、Random値、Stage、Prize、Rank、Point Backを固定した。
- Probability Stageは`min_draw_number`順のPointerで進め、同一StageのRangeをCacheする。Prizeが売切れた時だけ全StageのRange Cacheを破棄し、既存のMinimum Guarantee吸収とStage切替条件を維持した。
- `DrawResult`、`UserPrize`、Point Lot、Point LedgerはTransaction内で順番どおりMemory上へ構築し、250件単位でChunked Bulk Insertする。Draw Resultは`draw_request_id + draw_sequence_number`で安全に再取得し、User PrizeとPoint Lot／LedgerのRelationを保持した。
- `DrawResult`／`UserPrize`／Point Lot／Point LedgerにはModel ObserverやModel Eventによる副作用が存在しないことを確認した。既存のAudit／Metric、Response、Idempotency、Lock順は削除・変更していない。
- Bulk開始時刻を`created_at`、`updated_at`、`acquired_at`、Storage期限、Point期限の共通基準として1回だけ確定した。個別履歴1000件、User Prize全件、Point Lot／Ledger全件は省略していない。
- 単一DB Transaction、Gacha／Wallet／Point Lot／PrizeのLock順、全Rollback、Idempotency Replay、同一Key Conflict、既存1／5／10／100／1000回のResponse Contractを維持した。複数Transaction、Queue、Reservation Saga、確率近似は導入していない。

### 変更後性能

- 単独100回はp50 `93.983 ms`、p95 `114.216 ms`、Query `40`。単独1000回はp50 `536.977 ms`、p95 `582.927 ms`、Query `48`、Transaction `480-568 ms`だった。
- 1000回p95は変更前比約`81.5%`短縮し、Query数は約`98.2%`削減した。目標のp95 `2000 ms`以下、35%以上短縮、Query 50%以上削減を満たした。
- 同一Gacha 5 Userはp50 `2191.223 ms`、p95／最終完了 `3632.022 ms`、実測Lock Wait最大`1770 ms`。
- 同一Gacha 10 Userはp50 `3915.324 ms`、p95／最終完了 `7495.911 ms`、実測Lock Wait最大`3880 ms`。
- 同一Gacha 20 Userはp50 `7498.448 ms`、p95 `14010.807 ms`、最終完了`14766.610 ms`、実測Lock Wait最大`7990 ms`。
- 集中実行のRequest当たりQueryは`43-44`、`INSERT=14`、Peak PostgreSQL ConnectionはUser数＋測定Connection、未解消Deadlock／Serialization Failure、500／502／504、Point／在庫／履歴不整合は0件だった。
- 観測Peak PHP Memory増分は最大`6291456 bytes`、Responseは約`6.7 KiB`以下。Timeout、PHP実行時間、Memory Limit、Nginx設定は変更していない。

### Test／Security

- 決定的Random FixtureでDraw順序、Probability Stage、Prize、Rank、Point Back、Point消費／付与、残口数、在庫、Draw History、User Prize、Bulk集計、Idempotencyを検証した。
- 2番目のDraw Result ChunkでFailureを注入し、Draw Request、1000件の履歴、User Prize、Point、在庫、残口数が全Rollbackされることを確認した。
- Full Backend Regressionは`373 Warning／2456 Assertion`。既知`AdminPaymentApiTest`のRefund／Chargeback 2 Failureだけが既存Fingerprintと完全一致し、新規Failureは0件だった。
- Point／Payment／Refund／Chargeback、QA、Probability、Reconciliation、Idempotency、並行実行はFull Regression内で実行した。
- Task専用DBで`migrate:fresh`、最新MigrationのRollback、Reapply、Migration Pending 0を確認した。Baseと変更後のMigrationはともに42件、内容Set SHA-256は`e0ad8f112212a9881521102e29b76fabfcd7c74b0912ec4b14f66af3055b2460`で一致し、Archive CommitのV1 Migration 40件も変更していない。
- Frontend Typecheck／Buildは成功。Lintは既存Fingerprintと同一の`8 Error／1 Warning`で増加なし。
- Composer Manifestは有効。Composer Auditは既存10 Finding、Frontend Auditは既存18 Finding（High 12／Moderate 6）で、Manifest／Lockfileを変更しておらず新規Critical／High Findingは0件。
- PHP 373 FileのSyntax、`git diff --check`、Secret／PII Candidate 0件、Binary／Submoduleなし、Allowed Pathsを確認した。
- Task専用Backendの`/api/health`はApplication／PostgreSQL／Redis／Storageがすべて`ok`。Production Deployment、Production成功Draw、Frontend UI変更、Browser E2Eは未実行。

### GitHub／Cleanup

- Final Headは固定後のMachine-readable Self-review Evidenceを正本とし、Squash CommitはMerge結果と最終完了報告で記録する。
- GitHub Checkが`v1/early-release`に存在しない場合は成功扱いにせず、Local ValidationとFresh Self-reviewをEvidenceとする。
- Merge後にIssue Close、Remote／Local Task Branch、Worktree、Task専用Container／Network／VolumeをCleanupし、Local `v1/early-release`を`origin/v1/early-release`へ同期する。

### 次Task候補

- `V1-DRAW-1000B Bulk Draw 1000 Frontend・結果集計・本番反映`

## V1-DRAW-1000A2D Bulk Draw高速化のV1本番反映

### Task／Preflight

- Task IDは`V1-DRAW-1000A2D`、Riskは`R3`、Production記録用Issueは`#101`。
- Deploy Source Branchは`v1/early-release`、Deploy Source Commitは`a9b910b125ede42f92031e937e8d178f84d842f1`。Local／Remoteが一致し、同Commitが最新履歴であることを確認した。
- V1-DRAW-1000A2はPR `#100`／Issue `#99`で完了していた。現行Production Source `0c5262d42babc1bf3a63bd991ab07afb014a03c2`との差分は、Probability Stage Pointer／Range Cache、Draw Result／User Prize／Point Lot／LedgerのChunked Bulk Insert、Test用Random Source、Performance／Regression Test、本Worklogの7 Pathだけだった。
- Frontend、Migration、Dependency Manifest／Lockfile、Nginx、Environment、V2の差分がDeploy Sourceに含まれないことを確認した。
- 反映前のProductionはBackend `oripa-draw-1000b-backend`／`127.0.0.1:8130`、Frontend `oripa-draw-1000b-frontend`／`127.0.0.1:3130`。Publicは`200`、Adminは正常な`307`、API Healthは`200`、Migration Pendingは`0`だった。

### Backup／Build

- Repository外Evidenceは`/var/backups/oripa-v1/V1-DRAW-1000A2D-20260727T104504Z`へ保存した。Directoryは`root:root`／mode `700`、Fileはmode `600`。
- Full Backup SHA-256は`670ea5ec90aa51b680ec4dd8921578741a605e520e058a032ab12aae14586586`、Schema-only Backup SHA-256は`c32b4efb00a1d026c2ca1cba9d4c3a6acbaa47aa3e4b40020cce014b25dd735b`。
- Full／Schema-only BackupはPostgreSQL 17 Container内の`pg_restore --list`でそれぞれ`551`／`453`行のArchive一覧を取得し、Restore可能な形式であることを確認した。
- 固定CommitからRelease Worktree `/var/www/oripa-v1-releases/V1-DRAW-1000A2D-a9b910b125ed`を作成し、Backend Image `oripa-v1-draw-backend:a9b910b125ed`（Image ID `sha256:5e32540e3e3bd3f066c62a5ac48a84583ebbe5111c1b3778961dbbc2a75d6d32`）をBuildした。
- 新Backendは`oripa-draw-1000a2d-backend`／`127.0.0.1:8140`で起動した。既存Environment Fileを値を表示せず再利用し、現行BackendとのEnvironment Key／Value Digest一致を確認した。
- FrontendはPR `#98`を含む既存Image／Runtime `0c5262d42babc1bf3a63bd991ab07afb014a03c2`／`127.0.0.1:3130`を継続使用し、Build／Container／Upstreamを変更していない。

### Migration／切替前検証

- V1-DRAW-1000A2ではMigration Setに差分がなく、切替前後ともMigration Pendingは`0`。ProductionでMigration、Rollback、Refresh、Reset、Seed、`migrate:fresh`、手動Data修正は実行していない。
- 新BackendのDirect Healthは`200`、未認証Admin APIは`401`、未認証100回Bulk Requestは`401`だった。
- 既存FrontendのPublic Top／Login／Gacha詳細は`200`、100回／1000回選択肢、同一`Idempotency-Key`による再確認導線、Bulk集計表示の実装を確認した。Static Assetは`200`。
- Productionの実User、実Point、実景品へ影響しない専用Fixtureが存在しないため、Production成功Draw、Idempotent Replay、集計結果のData整合確認は実行していない。これらはV1-DRAW-1000A2の非Production Regression／性能検証を正本とする。
- Productionで5／10／20 User集中試験は実行していない。Timeout、PHP Memory Limit、Nginx Timeoutは変更していない。

### Nginx切替／切替後Smoke

- `nginx -t`成功後、Public `/api/`とAdmin `/admin/api/`のBackend Upstreamだけを`127.0.0.1:8130`から`127.0.0.1:8140`へ変更し、NginxだけをReloadした。
- Frontend Upstream `127.0.0.1:3130`、TLS、Domain、Cookie、CORS、CSRF、Upload Size、Timeoutは変更していない。
- Cloudflare経由とOrigin直結の双方でPublic Top／Login／Gacha詳細／API Healthは`200`、Adminは正常な`307`、Static Assetは`200`だった。
- 切替後の未認証Admin APIと1000回Bulk Requestは`401`。Gacha画面に1／5／10／100／1000回の既存選択肢が保持されている。
- 新Backend／NginxのError Scanは0件、500／502／504は0件、Migration Pendingは`0`。確認時のDB Active Connectionは`1`、Lock待機は`0`、新Backend Memoryは約`69.73 MiB`だった。
- 旧Backend `oripa-draw-1000b-backend`／`127.0.0.1:8130`はHealth `200`のままRollback用に維持している。RollbackはBackend Upstreamを`8140→8130`へ戻し、`nginx -t`後にNginxだけをReloadする。Migration操作は不要。

### 保護対象／最終状態

- V1本番DB／Redis／StorageのDataとVolume、Queue／Scheduler、Frontend Runtimeは変更していない。
- Secret、Credential、実PII、DB本文、Environment値はWorklog／Evidenceの表示対象にしていない。
- V2 `main`は`07d9da4c8a482a806c092ec8ef19ac62d901dbec`、`archive/v1-current`とAnnotated TagのCommitは`bfca8efa0b85c00a88fb0fd439a123b722577b68`で不変。
- 最終Production Backendは`a9b910b125ede42f92031e937e8d178f84d842f1`／`127.0.0.1:8140`、Frontendは`0c5262d42babc1bf3a63bd991ab07afb014a03c2`／`127.0.0.1:3130`。
- 最終Publicは`200`、Adminは正常な`307`、API Healthは`200`。RunbookへActive／Rollback Runtimeを反映した。

### 次確認

- 新規機能開発ではなく、ブラウザでの100／1000回ガチャ最終確認を次候補とする。

## SEC-006 V1 Frontend Dependency Security Backport

### Task／Scope

- Task IDは`SEC-006`、Riskは`R3`、Issueは`#157`、PRは`#158`。
- Base／PR Baseは`v1/early-release`、Base SHAは
  `fcaf0b9bb320aa479738e9be6d7b8465114f6226`。
- SEC-005で確定した`frontend/package.json`と`frontend/pnpm-lock.yaml`だけを
  `v1/early-release`へ機械的にBackportした。両FileはSEC-005 Merge済み正本と
  SHA-256が一致する。
- `next 16.2.11`、`sharp 0.35.3`、`js-yaml 4.3.0`を解決した。
  Application Source、Backend、Migration、V2、CI Baseline、Infraは変更していない。

### Compatibility／Security

- Active Production FrontendのSource Commit
  `0c5262d42babc1bf3a63bd991ab07afb014a03c2`と今回BaseのFrontend
  Application Sourceは、Manifest／Lockfileを除いて差分0だった。
- Node `22.22.3`、pnpm `10.12.1`でClean Frozen Install、Typecheck、
  Production Build、起動Health、Top Page、Next Image Optimizerを確認した。
- Next Image Optimizerの実利用経路で`sharp 0.35.3`／libvips `8.18.3`を確認した。
- pnpm AuditはCritical／Highを含め0件。Secret Candidateは0件。
- SEC-005正本と同一の2 Fileを使用するMain側でFresh Auditを収集し、
  Security Unit `6 Test`、Security Gate、Policy Gate、Quality Gateが成功した。
  Composerは期限付き既存Baseline `10件`、Workspace／Legacy pnpmは0件だった。
- LintはActive Production Sourceと同じ`8 Error／1 Warning`で、絶対Pathを
  正規化したFindingのRule、Severity、位置、本文が完全一致し、新規Findingは0件。
- Frontend PackageにはUnit Test Script／Suiteが存在しないため、Unit Testを
  PASSとは記録しない。Typecheck、Build、Health、Image Optimizerを区別して記録する。

### Production／Gate

- 本TaskではArtifact Build、Production Deployment、Container再作成、DB／Redis／
  Storage／Nginx／TLS／Domain変更を実施していない。
- Active Productionは引き続きNext `16.2.9`、sharp `0.34.5`、
  js-yaml `4.2.0`であり、本番反映には別途DEP-001の新Artifact作成前承認が必要。
- Repository外Evidenceは`/var/lib/oripa-v2-evidence/SEC-006/`へ保存した。
- `v1/early-release`にはRequired Check／Workflowが設定されておらず、PR Headの
  Available Checkは0件だった。これをCheck成功とは置き換えず、Local Security／
  Policy／Quality EvidenceとFresh Self-reviewをMerge判断の正本とする。
- Gate G4／G5は`NOT COMPLETE`を維持する。
- DEP-001のArtifact作成／Production Deploymentは本Task内で開始しない。

## INF-001 V1 Frontend Production Dockerfile Foundation

### Task／Scope

- Task IDは`INF-001`、Riskは`R4`、Issueは`#159`、PRは`#160`。
- Base／PR Baseは`v1/early-release`、Base SHAは
  `c4adae79f91a83a2ecb20683844357ba3c21f7c0`。
- Task Policy
  `/etc/ideal-sol/github-app/task-policies/INF-001.json`のSHA-256
  `abafb86e424a9c4f82b88169721c62731c48a23f0a7f0d59eb839d7894cc5641`
  とAllowed Pathsを確認した。
- 変更対象は`infra/docker/frontend/Dockerfile`、本Worklog、
  `worklogs/reports/INF-001-report.md`だけ。Frontend Application Source、
  `package.json`、`pnpm-lock.yaml`、Backend、V2、DB、Migration、Nginxは
  変更していない。

### Dockerfile

- Node `22-alpine`とRepository正本のpnpm `10.12.1`を使用するmulti-stage
  production buildへ更新した。
- Build dependencyとproduction dependencyをそれぞれ
  `pnpm install --frozen-lockfile --ignore-workspace`で解決し、builderで
  `pnpm build`を実行する。
- Runtime Imageへproduction `node_modules`、`.next`、`public`、
  `package.json`、`next.config.ts`だけを配置し、Application Source、
  Lockfile、TypeScript／ESLint等のdevelopment dependencyを含めない。
- Runtimeは非rootの`node` Userで、Nextのproduction CLIを直接起動する。
  RuntimeでCorepack download、Dependency Install、Source編集は行わない。
- SecretをBuild Argument、ENV、Image Layerへ追加していない。既存
  `NEXT_PUBLIC_*`値はBuild時の公開設定境界だけを維持する。

### Build／Smoke／Security

- Classic Builderで非本番検証Image
  `oripa-inf-001-validation:c4adae79f91a-r2`をBuildした。Image IDは
  `sha256:fbc8f394c13ec12d76b601031c45dcbe5cc278df3c12b36aa4a6791ab33fbf75`、
  Sizeは`669,702,219 byte`。
- Image内のnextは`16.2.11`、sharpは`0.35.3`、libvipsは`8.18.3`。
  js-yaml `4.3.0`はbuilderで確認し、build-only development dependencyのため
  Runtime Imageには含めていない。
- Task専用Containerを`127.0.0.1:3301`だけへBindし、Health、Top Page、
  Static PNG、Next Image Optimizer／sharp WebPをすべてHTTP `200`で確認した。
- Typecheckは成功。pnpm Auditは393 dependency、Critical／High／Moderate／
  Lowすべて0件。
- Lintは既存`8 Error／1 Warning`。SEC-006 Evidenceと正規化Finding 9件の
  SHA-256
  `3a40d9957054d2f309d76d566e26101e4745c3709a63f740b6bc5ca456b2441d`
  が完全一致し、新規Findingは0件。
- Frontend Sourceの変更Pathは0件。Manifest SHA-256
  `ed75219ce4e06e5241fd54c2cd07d8267f12ef4495b9ac5bd2d60ec8396d5c49`、
  Lockfile SHA-256
  `c7097ac90e5ea19e03bf2af7f7945e2ecc56d66182af0bd7710621f882f0d8e1`
  はSEC-006確定値から不変。
- 詳細EvidenceはRepository外
  `/var/lib/oripa-v2-evidence/INF-001/`へmode `700`／`600`で保存した。

### 時間を要した作業

- 初回Docker buildは約180秒。Layer Cache確立後のDockerfile修正再Buildは
  約13秒で、成功済みInstall／Build Layerを再利用した。
- 初回Runtime CMDの`pnpm start`は非root UserのCorepack downloadを要求した。
  Runtime Install禁止に合わせ、package scriptの実体であるNext production CLIの
  直接起動へ変更し、Application Source／Manifest／Lockfile変更なしで解消した。
- pnpm strict dependency layoutによりRootからの直接`require('sharp')`が拒否された。
  Dependencyを追加せず、Nextが実際に解決するsharp PackageとImage Optimizer実経路で
  Version／libvips／WebP出力を確認し、全Buildの重複実行を避けた。
- Root空き約9.1GB、`/tmp`空き約2.9GBで安全範囲だったため、Docker Cache、
  稼働Container、Named VolumeのCleanupは行わなかった。

### Production／Gate

- 本TaskのImageとContainerはTask専用の非本番検証用であり、DEP-001のImmutable
  Candidate Artifact作成ではない。
- Production Container、Image Tag、Service、DB、Redis、Storage、Nginx、TLS、
  Domain、Firewall、V2を変更していない。Production Deploymentは未実施。
- Gate G4／G5は`NOT COMPLETE`を維持する。
- Final Head／Squash Commit、GitHub Check、Fresh Self-review、Cleanup結果は
  PR Closeoutと最終完了報告で確定する。
