**オリパサイト Codex作業指示書**

Laravelバックエンド版

Version 1.1 / 2026年6月10日

本書は、既存のCodex作業指示書を「バックエンド=Laravel
API」前提に改訂したものです。仕様書v1.4、Laravel版環境構築指示書、および本書をセットでCodexへ渡してください。

# **1. 変更要否と結論**

結論: 変更は必要です。

Laravelへ変更したことで、Codexへの作業指示は「Next.js側に業務APIを持たせない」「LaravelのService/Action/Job/Command/Testで実装する」「抽選・ポイント・在庫・口数・確率ステージ判定をLaravelのDBトランザクションで処理する」という前提に差し替えます。

  --------------------------------------------------------------------------------------------------
  **変更対象**                        **変更内容**
  ----------------------------------- --------------------------------------------------------------
  API実装先                           抽選、ポイント、決済、管理系APIはLaravel APIで実装。Next.js
                                      Route HandlerはBFFやヘルスチェック程度に限定。

  ドメイン設計                        Laravelの app/Domain 配下に Gacha / Probability / Point /
                                      Payment / Shipping / Admin / Audit を分離。

  トランザクション                    DB::transaction() と lockForUpdate()
                                      を前提に、ガチャ行・ウォレット/ロット・景品行をロックする。

  キュー/バッチ                       Redis Queue、Laravel Scheduler、Artisan
                                      CommandでWebhook処理、通知、日次残高集計、整合性検証を実行。

  テスト                              PHPUnitまたはPestでLaravel Feature/Unit
                                      Testを作成し、抽選境界・並行処理・ポイント台帳を検証。

  認証                                Laravel Sanctumを第一候補。Next.jsはLaravel
                                      APIの認証状態を利用する。
  --------------------------------------------------------------------------------------------------

# **2. Codexへ渡す最初の指示**

  ------------------------------------------------------------------------------------------------
  あなたはオリパサイト構築プロジェクトの実装担当です。\
  正式仕様は「オリパサイト構築_仕様書_v1.4.docx」です。\
  バックエンドは Laravel API に固定します。\
  フロントエンドは Next.js / TypeScript / App Router
  とし、抽選・ポイント・決済・管理APIなどの業務ロジックはLaravel側に実装してください。\
  \
  まず以下を確認してください。\
  1. リポジトリ構成: frontend/ と backend/ が存在するか\
  2. backend/ がLaravelプロジェクトか\
  3. docker compose で PostgreSQL / Redis / MinIO / Mailpit が起動できるか\
  4. Laravelの /api/health と Next.js の疎通確認ができるか\
  5. 仕様書v1.4の最重要制約を破る既存実装がないか\
  \
  作業は小さなPR単位で進め、各ステップでテスト結果・変更ファイル・未解決事項を報告してください。
  ------------------------------------------------------------------------------------------------

  ------------------------------------------------------------------------------------------------

# **3. 固定する技術スタック**

  -------------------------------------------------------------------------------------------------------------------------
  **領域**                            **採用方針**
  ----------------------------------- -------------------------------------------------------------------------------------
  Frontend                            Next.js / TypeScript / App Router。Laravel APIを呼び出す。

  Backend                             Laravel API。業務ロジック、認証、抽選、ポイント、決済Webhook、管理APIを担当。

  DB                                  PostgreSQL。抽選通し番号、行ロック、制約、集計に使用。SQLite/MySQLへ置き換えない。

  Cache/Queue                         Redis。Cache、Queue、Rate Limit、必要に応じてSessionに利用。

  Storage                             S3互換ストレージ。ローカルはMinIO。

  Mail                                ローカルはMailpit。本番はSendGrid/Amazon SES等。

  Auth                                Laravel Sanctumを第一候補。SPA cookie認証またはトークン認証を環境に応じて選択。

  Admin                               管理画面UIはLaravel
                                      FilamentまたはNext.js管理画面で可。ただし業務APIとドメインロジックはLaravelに集約。
  -------------------------------------------------------------------------------------------------------------------------

# **4. 作業順序**

  ----------------------------------------------------------------------------------------------------------------------------------------------
  **Phase**               **Codexの作業**                                                             **完了条件**
  ----------------------- --------------------------------------------------------------------------- ------------------------------------------
  0\. 調査                既存リポジトリ、環境構築指示書、仕様書v1.4を確認し、差分計画を出す。        破壊的変更なしで方針を報告。

  1\. Laravel基盤確認     backendのLaravel、PostgreSQL、Redis、Sanctum、Storage、Mailを確認。         php artisan test / /api/health が通る。

  2\. DBマイグレーション  仕様書v1.4の主要テーブル、制約、外部キー、unique/check制約を作成。          migrate:fresh
                                                                                                      が成功。テーブル一覧が仕様と一致。

  3\. ドメイン骨格        Service/Action/DTO/Enum/Policy/FormRequest/Resourceを作成。                 空実装ではなく責務が分かるクラス構成。

  4\. 抽選実装            DrawService、StageResolver、ProbabilityRangeBuilder、SecureRandomを実装。   単発・10連・境界・在庫切れテストが通る。

  5\. ポイント/決済       point_lots、point_ledgers、Payment Webhook、残高集計Commandを実装。         有償/無償・ロット・返金系テストが通る。

  6\. API/画面連携        ユーザーAPI、管理API、Next.js API clientを接続。                            主要エンドポイントのFeature
                                                                                                      Testと型確認が通る。

  7\. バッチ/監視         Scheduler、Queue Worker、失効通知、残高スナップショット、整合性検証を追加。 Command単体テストとREADME更新。

  8\. 完了報告            変更ファイル、テスト結果、未解決事項、法務確認待ちを整理。                  完了報告テンプレートで提出。
  ----------------------------------------------------------------------------------------------------------------------------------------------

# **5. Laravel実装で守る作業ルール**

-   Controllerに業務ロジックを直書きしない。ControllerはFormRequest、Service/Action、Resourceの呼び出しに留める。

-   抽選、ポイント消費、景品在庫、sold_count、確率ステージ判定はLaravelのDB::transaction()内に閉じ込める。

-   行ロックはEloquent/Query Builderの lockForUpdate()
    を使い、ガチャ行、ポイントロット、必要な景品行の整合性を守る。

-   確率はfloat/doubleで保存しない。DB・抽選処理は probability_ppm
    の整数で統一する。

-   乱数は random_int(0, 999999)
    を使用する。mt_rand、rand、JavaScript側乱数は禁止。

-   公開済み確率バージョンはUPDATEしない。変更はdraft作成、検証、publish、新バージョン化で行う。

-   有償ポイントは expire_at = null、無償ポイントのみ expire_at
    を持つ。最低保証・キャンペーン・補填・景品変換は原則freeロットとして扱う。

-   ポイント消費は無償ポイントを優先し、無償内では期限が近い順、有償は古い付与分からFIFOで消費する。法務・会計確認に備え、順序は設定化できる余地を残す。

# **6. Codex用プロンプト集**

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **目的**                            **Codexへ渡す文面**
  ----------------------------------- -----------------------------------------------------------------------------------------------------------------------------------------------------------------------
  リポジトリ調査                      仕様書v1.4とLaravel版環境構築指示書を読み、現リポジトリがLaravel API前提になっているか調査してください。問題点、破壊的変更リスク、実装順序を報告してください。

  DB実装                              仕様書v1.4のDB設計に基づき、Laravel migrationとEloquent Modelを作成してください。確率はgacha_prizesに持たせず、確率バージョン配下のテーブルに一元化してください。

  抽選実装                            DrawServiceをLaravelで実装してください。DB::transaction、lockForUpdate、draw_sequence_number、CSPRNG、最低保証枠、まとめ引き境界、idempotencyを必ず満たしてください。

  ポイント実装                        point_lotsとpoint_ledgersを実装してください。有償ポイントはexpire_at null、無償ポイントのみ期限あり、最低保証はfreeロットとして付与してください。

  テスト実装                          抽選境界、並行抽選、在庫上限、最低保証枠、ポイントロット消費、確率バージョンpublish不可変性をLaravelのテストで検証してください。
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

# **7. 前版からの変更サマリー**

  -----------------------------------------------------------------------------------------------------
  **旧指示書の扱い**                  **Laravel版での変更**
  ----------------------------------- -----------------------------------------------------------------
  バックエンド一般表記                Laravel APIに固定。Rails/NestJS/Next.js API実装の表現を削除。

  Route Handler許容                   Next.js Route Handlerで業務APIを作らない旨を明記。

  DB処理                              Laravel
                                      migration、Eloquent、DB::transaction、lockForUpdate前提へ変更。

  サービス構成                        Laravel app/Domain配下のService/Action/DTO/Enumへ整理。

  キュー/バッチ                       Laravel Queue、Job、Event、Listener、Artisan
                                      Command、Schedulerへ変更。

  テスト                              Laravel Feature/Unit
                                      Testに抽選・ポイント・バージョン・Webhookの検証を追加。

  ポイント方針                        有償ポイント無期限、無償ポイント期限ありを明記。
  -----------------------------------------------------------------------------------------------------

# **8. 禁止事項チェックリスト**

-   Next.js側で抽選API、ポイント消費API、決済Webhook、確率バージョン公開処理を実装しない。

-   gacha_prizesにprobabilityカラムを追加しない。

-   公開済み確率バージョンを上書き更新しない。

-   在庫切れ景品の確率を他景品に再配分しない。最低保証枠へ吸収する。

-   まとめ引き全体に同じステージを一括適用しない。1回ごとにdraw_sequence_numberで判定する。

-   抽選成立後にチャージバックを理由としてdraw_results、sold_count、won_countを巻き戻さない。

-   DB接続をPostgreSQL以外に変えない。

-   法務確認前に「資金決済法対応完了」と画面やREADMEに記載しない。

# **9. 完了報告テンプレート**

  -----------------------------------------------------------------------
  ## 完了報告\
  \
  ##\# 実装した範囲\
  -\
  \
  ##\# 変更ファイル\
  - backend/\...\
  - frontend/\...\
  \
  ##\# 実行した検証\
  - composer test / php artisan test:\
  - pnpm lint / typecheck:\
  - docker compose ps:\
  \
  ##\# 仕様書v1.4との対応\
  - 最低保証枠:\
  - draw_sequence_number:\
  - 確率バージョンimmutable:\
  - ポイントロット:\
  \
  ##\# 未解決事項\
  -\
  \
  ##\# 次に実装すべきこと\
  -
  -----------------------------------------------------------------------

  -----------------------------------------------------------------------

# **付録A. Laravel側主要クラスチェックリスト**

  -----------------------------------------------------------------------
  **領域**                            **必須クラス/ファイル例**
  ----------------------------------- -----------------------------------
  Gacha                               DrawService, DrawRequestController,
                                      GachaResource, GachaPolicy

  Probability                         StageResolver,
                                      ProbabilityRangeBuilder,
                                      ProbabilityVersionPublisher,
                                      ProbabilityValidator,
                                      SnapshotHasher

  Point                               PointLotService,
                                      PointConsumptionService,
                                      PointLedgerService,
                                      BalanceSnapshotCommand,
                                      ExpireFreePointsCommand

  Payment                             PaymentWebhookController,
                                      PaymentPointGrantService,
                                      ChargebackService,
                                      VerifyWebhookSignature Middleware

  Admin                               AdminGachaController,
                                      AdminProbabilityController,
                                      AdminSimulationController,
                                      AuditLogMiddleware

  Tests                               DrawServiceTest,
                                      DrawBoundaryFeatureTest,
                                      PointLotTest,
                                      ProbabilityVersionPublishTest,
                                      PaymentWebhookTest
  -----------------------------------------------------------------------
