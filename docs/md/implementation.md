**オリパサイト Codex実装指示書**

Laravelバックエンド版

Version 1.1 / 2026年6月10日

本書は、CodexがLaravel
APIでMVP実装を進めるための詳細指示書です。環境構築は「Laravel版環境構築指示書」に従い、本書では業務実装、マイグレーション、サービス、テスト、APIを定義します。

# **1. 実装アーキテクチャ**

  -----------------------------------------------------------------------------------------------------------
  **層**                              **担当**
  ----------------------------------- -----------------------------------------------------------------------
  Next.js                             画面、APIクライアント、ユーザー操作、表示。業務ロジックは持たない。

  Laravel HTTP                        API Controller、FormRequest、Resource、Middleware、Sanctum認証。

  Laravel Domain                      抽選、確率、ポイント、決済、発送、利益計算のService/Action/DTO/Enum。

  Laravel Queue/Scheduler             Webhook後続処理、メール、失効通知、残高スナップショット、整合性検証。

  PostgreSQL                          トランザクション、行ロック、制約、監査可能な履歴保存。

  Redis                               Queue、Cache、Rate Limit、必要に応じてSession。
  -----------------------------------------------------------------------------------------------------------

Laravelはシステムの正本です。抽選結果、ポイント残高、確率バージョン、決済状態、発送状態の更新はLaravel
APIのみが行います。

# **2. Laravelディレクトリ規約**

  -----------------------------------------------------------------------
  backend/app/\
  ├─ Domain/\
  │ ├─ Gacha/\
  │ │ ├─ Actions/\
  │ │ ├─ DTOs/\
  │ │ ├─ Enums/\
  │ │ └─ Services/\
  │ ├─ Probability/\
  │ ├─ Point/\
  │ ├─ Payment/\
  │ ├─ Shipping/\
  │ ├─ Simulation/\
  │ └─ Audit/\
  ├─ Http/\
  │ ├─ Controllers/Api/\
  │ ├─ Controllers/Admin/\
  │ ├─ Requests/\
  │ └─ Resources/\
  ├─ Models/\
  ├─ Policies/\
  ├─ Jobs/\
  ├─ Console/Commands/\
  └─ Support/
  -----------------------------------------------------------------------

  -----------------------------------------------------------------------

-   app/Models はDBモデルに限定し、抽選処理をModelに肥大化させない。

-   app/Domain 配下のService/Actionにユースケースを置く。

-   外部入力の検証はFormRequest、出力整形はJsonResourceに分離する。

-   enum値はPHP Enumで定義し、DBのstring値と対応させる。

-   管理者操作はAuditLogServiceを通じて必ず記録する。

# **3. マイグレーション実装順序**

  --------------------------------------------------------------------------------------------------------------------------------
  **順序**                **テーブル/対象**            **実装メモ**
  ----------------------- ---------------------------- ---------------------------------------------------------------------------
  1                       users / user_profiles /      認証基盤。Sanctum用personal_access_tokensも含める。
                          admin_users                  

  2                       wallets / point_lots /       有償/無償ロット管理。有償expire_at null、無償expire_atあり。
                          point_ledgers /              
                          point_balance_snapshots      

  3                       payments                     決済状態、返金、チャージバック、webhook_event_idの重複防止。

  4                       gacha_categories / gachas    current_probability_version_id、minimum_guarantee\_\*、sold_countを持つ。

  5                       gacha_ranks / gacha_prizes   gacha_prizesにprobabilityを持たせない。won_countを行ロックで更新。

  6                       gacha_probability_versions / 公開済みimmutable。minimum guarantee
                          stages / probabilities       rowをstageごとに必須。probability_ppmは整数。

  7                       draw_requests / draw_results idempotency_keyユニーク。gacha_id + draw_sequence_numberユニーク。

  8                       user_prizes /                保管期限、発送状態、期限切れ自動変換に対応。
                          shipping_requests /          
                          shipping_items               

  9                       announcements / static_pages お知らせ、固定ページ、管理操作・重要処理ログ。
                          / audit_logs                 
  --------------------------------------------------------------------------------------------------------------------------------

# **4. DB制約・インデックス**

  ------------------------------------------------------------------------------------------------------------
  **対象**                                        **必須制約**
  ----------------------------------------------- ------------------------------------------------------------
  gachas                                          sold_count \>= 0、sold_count \<=
                                                  total_count、current_probability_version_id FK。

  gacha_ranks                                     unique(gacha_id, rank_key)。

  gacha_prizes                                    max_win_count \>= 0、won_count \>= 0、won_count \<=
                                                  max_win_count。probabilityカラム禁止。

  gacha_probability_versions                      unique(gacha_id,
                                                  version_number)。published後はアプリ層で更新禁止。

  gacha_probability_version_stages                min_draw_number \>= 1、max_draw_number
                                                  null許容、範囲の重複/ギャップはServiceで検証。

  gacha_probability_version_prize_probabilities   probability_ppm between 0 and
                                                  1000000。stageごとの合計はServiceで1000000検証。

  draw_requests                                   unique(user_id, gacha_id, idempotency_key)またはglobal
                                                  unique。再送時は既存結果を返す。

  draw_results                                    unique(gacha_id,
                                                  draw_sequence_number)。result_typeはprize/point_backのみ。

  point_lots                                      remaining_amount \>= 0。有償はexpire_at
                                                  null、無償はexpire_at not nullをアプリ層で検証。
  ------------------------------------------------------------------------------------------------------------

# **5. 抽選エンジン実装指示**

DrawService
はLaravel実装の最重要箇所です。ControllerではなくServiceに閉じ込め、必ずFeature
Testを付けます。

  ----------------------------------------------------------------------------------
  // 擬似コード。実装ではDTO/Enum/Exceptionを使って整理する。\
  public function draw(User \$user, Gacha \$gacha, int \$drawCount, string
  \$idempotencyKey): DrawRequest\
  {\
  return DB::transaction(function () use (\$user, \$gacha, \$drawCount,
  \$idempotencyKey) {\
  \$existing = \$this-\>findExistingDrawRequest(\$user, \$gacha, \$idempotencyKey);\
  if (\$existing && \$existing-\>status === \'completed\') {\
  return \$existing-\>load(\'results\');\
  }\
  \
  \$lockedGacha = Gacha::whereKey(\$gacha-\>id)-\>lockForUpdate()-\>firstOrFail();\
  \$this-\>assertActiveAndHasRemainingCount(\$lockedGacha, \$drawCount);\
  \
  \$wallet = Wallet::where(\'user_id\',
  \$user-\>id)-\>lockForUpdate()-\>firstOrFail();\
  \$this-\>pointService-\>assertSpendable(\$user, \$lockedGacha-\>price \*
  \$drawCount);\
  \
  \$drawRequest = \$this-\>createProcessingRequest(\$user, \$lockedGacha,
  \$drawCount, \$idempotencyKey);\
  \$soldCountBefore = \$lockedGacha-\>sold_count;\
  \
  for (\$i = 1; \$i \<= \$drawCount; \$i++) {\
  \$sequence = \$soldCountBefore + \$i;\
  \$stage =
  \$this-\>stageResolver-\>resolve(\$lockedGacha-\>current_probability_version_id,
  \$sequence);\
  \$range = \$this-\>rangeBuilder-\>build(\$stage, \$lockedGacha); //
  在庫切れ分は最低保証へ吸収\
  \$random = random_int(0, 999999);\
  \$result = \$range-\>pick(\$random);\
  \
  if (\$result-\>isPrize()) {\
  \$prize =
  GachaPrize::whereKey(\$result-\>prizeId)-\>lockForUpdate()-\>firstOrFail();\
  \$this-\>assertPrizeStillAvailable(\$prize);\
  \$prize-\>increment(\'won_count\');\
  \$this-\>createUserPrize(\$user, \$prize, \$drawRequest);\
  } else {\
  \$this-\>pointService-\>grantMinimumGuarantee(\$user, \$lockedGacha,
  \$drawRequest);\
  }\
  \
  \$this-\>storeDrawResult(\$drawRequest, \$stage, \$sequence, \$random, \$result);\
  \$lockedGacha-\>increment(\'sold_count\');\
  }\
  \
  \$this-\>pointService-\>consumeForDraw(\$user, \$lockedGacha-\>price \*
  \$drawCount, \$drawRequest);\
  \$drawRequest-\>markCompleted();\
  return \$drawRequest-\>load(\'results\');\
  });\
  }
  ----------------------------------------------------------------------------------

  ----------------------------------------------------------------------------------

-   sold_count_beforeはガチャ行ロック取得後の値とする。

-   draw_sequence_number = sold_count_before +
    draw_index。DB保存値もこの値。

-   まとめ引きで境界をまたぐ場合、1回ごとにStageResolverを呼ぶ。

-   各回の抽選後、won_count更新を次回のProbabilityRangeBuilderへ反映する。

-   途中で例外が出た場合は、抽選結果、ポイント、sold_count、won_count、user_prizesをすべてロールバックする。

# **6. 確率バージョン公開実装**

  ------------------------------------------------------------------------------------------------------------------------------------------
  **クラス**                          **責務**
  ----------------------------------- ------------------------------------------------------------------------------------------------------
  ProbabilityVersionDraftService      管理画面入力値をドラフトとして保持・検証する。

  ProbabilityValidator                ステージ範囲、ギャップ、重複、ppm合計、最低保証行、景品存在を検証する。

  ProbabilityVersionPublisher         検証済みドラフトをpublishedバージョンとして作成し、gachas.current_probability_version_idを更新する。

  SnapshotHasher                      ステージ・確率・最低保証を正規化したJSONからsnapshot_hashを生成する。

  StageResolver                       draw_sequence_numberに対応するpublished stageを返す。該当なしは例外。

  ProbabilityRangeBuilder             在庫切れ景品を除外し、そのppmを最低保証枠に吸収した累積レンジを返す。
  ------------------------------------------------------------------------------------------------------------------------------------------

-   各ステージのprobability_ppm合計は最低保証枠込みでちょうど1,000,000。

-   最低保証枠はprize_id=null、is_minimum_guarantee=trueの1行として保存する。

-   公開済みversion/stage/probabilityはUPDATE/DELETE禁止。変更は新バージョン公開のみ。

-   gacha_prizesの抽選対象切替や景品追加により確率セットが変わる場合も、新バージョン公開を必要とする。

# **7. ポイント・決済実装指示**

今回の運用方針は「有償ポイントは有効期限なし、無償ポイントのみ有効期限あり」です。資金決済法確認に備え、ロット・台帳・残高スナップショットを必ず分けて実装します。

  ---------------------------------------------------------------------------------------------------------------------------------------------------
  **処理**                            **Laravel実装指示**
  ----------------------------------- ---------------------------------------------------------------------------------------------------------------
  有償ポイント付与                    決済成功Webhook確認後、point_lotsにpoint_type=paid、expire_at=nullで作成。point_ledgersにpurchase付与を記録。

  無償ポイント付与                    最低保証、キャンペーン、補填、景品変換はpoint_type=free。expire_at必須。

  ポイント消費                        無償ロットを期限近い順、次に有償ロットを古い順に消費。消費内訳をpoint_ledgersへロット単位で保存。

  失効                                ExpireFreePointsCommandで無償ロットのみ期限失効。事前通知Jobも用意。

  日次集計                            CreatePointBalanceSnapshotCommandで有償/無償未使用残高を集計。3/31・9/30はis_base_date=true。

  返金/チャージバック                 対象付与の取消、残高不足時はマイナス残高または利用制限を記録。抽選履歴・sold_count・won_countは巻き戻さない。
  ---------------------------------------------------------------------------------------------------------------------------------------------------

# **8. API実装マッピング**

  -----------------------------------------------------------------------------------------------
  **API**                                               **Laravel Controller / Service**
  ----------------------------------------------------- -----------------------------------------
  GET /api/gachas                                       GachaController\@index / GachaListService

  GET /api/gachas/{id}                                  GachaController\@show /
                                                        GachaDetailService / GachaResource

  POST /api/gachas/{id}/draw                            DrawController\@store / DrawService

  GET /api/me/points                                    PointController\@index /
                                                        PointBalanceService

  GET /api/me/point-ledgers                             PointLedgerController\@index

  POST /api/payments                                    PaymentController\@store /
                                                        PaymentIntentService

  POST /api/payments/webhook                            PaymentWebhookController\@handle /
                                                        VerifyWebhookSignature

  GET /admin/api/gachas/{id}/probability-matrix         AdminProbabilityController\@matrix

  PUT /admin/api/gachas/{id}/probability-drafts         AdminProbabilityController\@updateDraft

  POST                                                  AdminProbabilityController\@publish
  /admin/api/gachas/{id}/probability-versions/publish   

  POST /admin/api/gachas/{id}/simulate                  AdminSimulationController\@simulate
  -----------------------------------------------------------------------------------------------

# **9. Laravel Job / Command / Scheduler**

  --------------------------------------------------------------------------------------------------------
  **種別**                **名称例**                    **目的**
  ----------------------- ----------------------------- --------------------------------------------------
  Command                 points:snapshot               有償/無償ポイント未使用残高の日次集計。

  Command                 points:expire-free            期限切れ無償ポイントの失効。

  Command                 points:verify-integrity       wallets、point_lots、point_ledgersの整合性検証。

  Command                 prizes:notify-expiration      景品保管期限14/7/1日前の通知。

  Command                 prizes:auto-convert-expired   期限切れ景品の無償ポイント等への自動変換。

  Job                     SendPointExpirationNotice     ポイント失効前通知メール/通知。

  Job                     ProcessPaymentWebhook         署名検証後の決済Webhook後続処理。

  Job                     SendShippingNotification      発送関連通知。
  --------------------------------------------------------------------------------------------------------

app/Console/Kernel.php
またはLaravelの現行構成に合わせてScheduler登録を行い、日次バッチ失敗時はログと通知を残します。

# **10. テスト指示**

  --------------------------------------------------------------------------------------------------------------------------
  **テスト**                          **必須ケース**
  ----------------------------------- --------------------------------------------------------------------------------------
  Unit: StageResolver                 9,999回目はstage_1、10,000回目はstage_2。範囲ギャップ時は例外。

  Unit: ProbabilityRangeBuilder       最低保証込み合計1,000,000ppm。在庫切れ景品ppmを最低保証へ吸収。

  Feature: DrawService                単発抽選、10連、境界またぎ、ポイント不足、総口数不足、idempotency再送。

  Feature: Concurrency                同時抽選でdraw_sequence_numberの重複・欠番がない。sold_countが正しい。

  Feature: PointLot                   有償expire_at null、無償expire_atあり、無償期限近い順→有償FIFOで消費。

  Feature: ProbabilityVersion         公開済みバージョンは編集不可。新バージョン公開でcurrent_probability_version_id更新。

  Feature: PaymentWebhook             重複Webhookは二重付与しない。チャージバック時に発送停止/利用制限。

  Command                             points:snapshot、points:expire-free、points:verify-integrityが期待通り動く。
  --------------------------------------------------------------------------------------------------------------------------

  -----------------------------------------------------------------------
  \# 推奨検証コマンド\
  cd backend\
  php artisan migrate:fresh \--seed\
  php artisan test\
  php artisan points:snapshot\
  php artisan points:verify-integrity\
  \
  cd ../frontend\
  pnpm lint\
  pnpm typecheck
  -----------------------------------------------------------------------

  -----------------------------------------------------------------------

# **11. Codexに実装させる順番**

1.  Laravel migrationとModel、Enum、Factory、Seederを作成する。

2.  確率バージョンpublish系のServiceとテストを作成する。

3.  StageResolver、ProbabilityRangeBuilder、DrawServiceを作成する。

4.  point_lots/point_ledgersの付与・消費・失効Serviceを作成する。

5.  決済Webhookの署名検証と重複防止、付与処理を作成する。

6.  ユーザーAPIと管理APIをController/FormRequest/Resourceで実装する。

7.  バッチCommandとQueue Jobを追加する。

8.  Next.js側のAPI clientと最低限の画面疎通を整える。

9.  テストを追加し、READMEと完了報告を更新する。

# **12. 受け入れ基準**

-   Laravel
    APIだけで抽選、ポイント、確率、決済Webhookの主要処理が完結している。

-   Next.js側に抽選・ポイント消費・決済Webhookの業務ロジックがない。

-   php artisan
    testが成功し、境界・並行・在庫・最低保証・ポイントロットのテストが含まれている。

-   DBにgacha_prizes.probabilityが存在しない。

-   draw_resultsにdraw_sequence_number、probability_version_id、probability_version_stage_id、random_valueが保存される。

-   point_lotsでpaid/freeを区分し、有償はexpire_at
    null、無償は期限ありで運用できる。

-   ポイント残高の日次/基準日スナップショットがCommandで作成できる。

-   公開済み確率バージョンを編集しようとすると拒否される。

-   idempotency key再送で二重抽選・二重消費が発生しない。

# **13. Codexへの最終プロンプト**

  -------------------------------------------------------------------------------------------------------------------------------------------------------------
  以下を実装してください。\
  \
  1. バックエンドはLaravel API固定です。Next.js Route Handlerに業務APIを実装しないでください。\
  2. 仕様書v1.4のDB設計をLaravel migration / Model / Enum / Factory / Seederへ落とし込んでください。\
  3.
  DrawServiceをDB::transactionとlockForUpdateで実装し、draw_sequence_number、確率ステージ、最低保証枠、在庫上限、まとめ引き、idempotencyを満たしてください。\
  4. ポイントはpoint_lots/point_ledgersで管理し、有償ポイントは期限なし、無償ポイントのみ期限ありにしてください。\
  5. 確率バージョンはimmutableなdraft/published方式で実装し、公開済みバージョンの更新は禁止してください。\
  6. LaravelのFeature/Unit Testを追加し、境界値・同時実行・在庫切れ・ポイント消費・Webhook重複を検証してください。\
  7. 作業後、変更ファイル、テスト結果、未解決事項を報告してください。
  -------------------------------------------------------------------------------------------------------------------------------------------------------------

  -------------------------------------------------------------------------------------------------------------------------------------------------------------
