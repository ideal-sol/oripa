# CRITICAL LOGIC AUDIT

作成日: 2026-06-25

対象:

- 正本: `docs/md/spec_v1.4.md`
- 追加仕様記録: `docs/md/all_check.md`
- 実装確認資料: `docs/review/AS_BUILT_IMPLEMENTATION_MATRIX.md`

監査方針:

- 実装コード、Migration、APIルート、テストファイルを読んで評価した。
- 本監査ではコード、DB、Docker、設定ファイルを変更していない。
- 本監査ではテスト、migrate、Docker build、依存関係更新を実行していない。
- `PASS` はコード上の実装と関連テストファイルを確認できたもの。
- `WARNING` は実装はあるが、本番運用・並行性・公開前条件に注意が残るもの。
- `FAIL` は仕様上必要な実装が見当たらない、または危険な挙動が確認されたもの。
- `NOT_TESTED` はコード上は確認したが、本監査で実行確認していない、または対応テストを確認できないもの。

## Overall Result

| 領域 | 総評 |
|---|---|
| 抽選 | 中核ロジックはLaravel側トランザクション内にあり、`gachas`行ロック、通し番号、CSPRNG、ppm検証、最低保証、在庫吸収、idempotencyを確認できた。日次抽選上限はトランザクション内で検証されるが、既存完了リクエストの集計に依存するため実並行テストは必要。 |
| ポイント | paid/free区分、paid期限なし、free期限あり、無償優先消費、期限近い順、台帳作成、失効処理を確認できた。日次残高スナップショットはService、Command、Scheduler、対象テストを確認済み。 |
| 決済 | mock限定の決済フロー、成功前付与なし、Webhook冪等、返金/CB状態は確認できた。`mock-succeed`はlocal/testing限定だが、`POST /api/payments`でmock決済作成自体は本番でも通る構造のため公開前に要制御。 |
| 認証・外部連携 | メール認証、Googleログイン土台、SMSハッシュ保存、試行回数、LINE署名、管理API認証を確認できた。adminサブドメインはアプリ内のアクセス制御ではなくインフラ/CORS/Cookie設定依存。 |

## 抽選

| 項目 | 評価 | 根拠 | 監査コメント |
|---|---|---|---|
| DBトランザクションの範囲 | PASS | `backend/app/Domain/Gacha/Services/DrawService.php` `DrawService::draw` | ポイント消費、DrawRequest作成、DrawResult作成、在庫更新、sold_count更新、最低保証付与が `DB::transaction` 内で実行される。 |
| `gachas`行の`lockForUpdate` | PASS | `DrawService::draw` | `Gacha::query()->whereKey(...)->lockForUpdate()` で対象ガチャをロックしている。 |
| `draw_sequence_number`の採番 | PASS | `DrawService::draw` | ロック済み `sold_count` を `soldCountBefore` とし、まとめ引き内で `soldCountBefore + drawIndex` を採番している。 |
| 同時抽選時の重複・欠番防止 | PASS | `DrawService::draw`, `2026_06_10_000007_create_draw_tables.php` | `gachas`行ロックと `draw_results` の `unique(['gacha_id', 'draw_sequence_number'])` を確認。実並行テストは本監査では未実行。 |
| まとめ引きの1回ごとのステージ判定 | PASS | `DrawService::draw`, `StageResolver::resolve` | forループ内で各 `drawIndex` ごとに `sequence` を計算し、都度 `StageResolver::resolve` を呼んでいる。 |
| 公開中確率バージョンの参照 | PASS | `DrawService::assertDrawable`, `StageResolver::resolve` | ガチャの `current_probability_version_id` を使用し、`StageResolver` は `whereHas('version', status=published)` を条件にしている。 |
| `random_int`等のCSPRNG | PASS | `DrawService::draw`, `DrawService::randomAssetUrl`, `SmsVerificationService::generateNumericCode` | 抽選乱数と演出素材選択で `random_int` を使用。フロント側抽選ロジックは確認されない。 |
| ppm合計1,000,000 | PASS | `ProbabilityValidator::validateForPublish`, `ProbabilityRangeBuilder::build` | 公開前検証で各ステージ合計 `1_000_000` を要求し、レンジ構築時にも合計不一致なら例外。 |
| 最低保証枠 | PASS | `ProbabilityValidator`, `ProbabilityRangeBuilder`, `DrawService::storePointBackResult`, `DrawService::grantMinimumGuarantee` | 各ステージ最低保証行1件を要求し、point_back時に無償ポイントを付与する。 |
| 在庫切れ確率の最低保証枠への吸収 | PASS | `ProbabilityRangeBuilder::build` | 無効・売切・存在しない景品のppmを最低保証ppmへ加算している。 |
| `daily_draw_limit`のトランザクション内検証 | WARNING | `DrawService::draw`, `DrawService::assertWithinDailyDrawLimit` | `gachas`行ロック後、同一トランザクション内で完了済みDrawRequest合計を確認。ユーザー単位・JST日付境界の設計は妥当だが、実並行テストは本監査では未実行。 |
| idempotency key | PASS | `DrawService::draw`, `2026_06_10_000007_create_draw_tables.php`, `backend/app/Http/Requests/Api/Gacha/DrawRequest.php` | `user_id/gacha_id/idempotency_key` の一意制約、既存completedの再返却、processing時の拒否、API必須バリデーションを確認。 |
| エラー時の全体ロールバック | PASS | `DrawService::draw` | 抽選処理全体が `DB::transaction` 内。例外時はポイント消費・在庫・sold_count・結果作成がロールバックされる構造。 |
| ランク演出素材の選択結果保存 | PASS | `DrawService::selectRankPresentation`, `DrawService::storePrizeResult`, `DrawResult` Migration追加 | 対象ランクの有効な複数素材から `random_int` で選び、`selected_rank_image_url` と `selected_draw_video_url` を `draw_results` に保存する。 |

### 抽選の注意点

- `DrawService::assertPrizeAvailable` はレンジ構築後、当選景品をロックして再確認している。通常は `gachas` 行ロックにより同一ガチャの抽選は直列化されるため安全性は高い。
- 日次抽選上限は `created_at` の日付範囲で完了済みDrawRequestを集計するため、DB/LaravelのJST設定と強く結びつく。
- 実並行テストの実行は今回行っていないため、`CRITICAL_LOGIC_AUDIT`上はコード確認ベースのPASSとする。

## ポイント

| 項目 | 評価 | 根拠 | 監査コメント |
|---|---|---|---|
| paid/freeの区分 | PASS | `2026_06_10_000002_create_point_tables.php`, `Wallet`, `PointLot`, `PointLedger` | wallets、point_lots、point_ledgersでpaid/free区分を保持し、DB CHECKも存在する。 |
| 有償ポイントの`expire_at`がnullであること | PASS | `PointLotService::grantPaid`, `point_lots_expire_rule_check`, `SchemaSpecificationTest` | `grantPaid` は `expireAt: null` 固定。DB CHECKでpaidは `expire_at IS NULL` を要求する。 |
| 無償ポイントの有効期限 | PASS | `PointLotService::grantFree`, `point_lots_expire_rule_check` | `grantFree` は `CarbonInterface $expireAt` 必須。DB CHECKでfreeは `expire_at IS NOT NULL` を要求する。 |
| 無償ポイント優先消費 | PASS | `PointConsumptionService::consume` | freeロットを先に消費し、残りをpaidロットから消費する。 |
| 無償内で期限の近いロット優先 | PASS | `PointConsumptionService::lockedConsumableLots` | freeは `where expire_at > now()`、`orderBy('expire_at')->orderBy('granted_at')->orderBy('id')`。 |
| paidロットの消費順序 | PASS | `PointConsumptionService::lockedConsumableLots` | paidは期限なし方針で `granted_at`, `id` 順のFIFO。 |
| `point_lots`、`point_ledgers`、`wallets`の整合性 | PASS | `PointConsumptionService::consumeFromLot`, `PointLotService::grant`, `PointExpirationService::expire` | 付与・消費・失効でロット、ウォレット、台帳を同時更新する。消費と失効はロックあり。付与側は呼び出し元トランザクション依存または単体処理。 |
| 最低保証ポイントがfreeであること | PASS | `DrawService::grantMinimumGuarantee` | `PointLotService::grantFree` に `PointLotSourceType::MinimumGuarantee` で付与。 |
| 紹介ポイントがfreeであること | PASS | `ReferralRewardService::rewardForReferredUser` | `PointLotService::grantFree` に `PointLotSourceType::Referral` で付与。 |
| LINEポイントがfreeであること | PASS | `LineFriendLinkService::handleCodeMessage` | `PointLotService::grantFree` に `PointLotSourceType::LineFriend` で付与。 |
| 二重付与防止: 最低保証 | PASS | `DrawService::draw`, `draw_results` | 抽選処理トランザクションとidempotencyにより同一DrawRequest再実行時は既存結果を返す。 |
| 二重付与防止: 紹介 | PASS | `ReferralRewardService::rewardForReferredUser` | `status=pending` の紹介行を `lockForUpdate` し、付与後 `rewarded` に変更する。 |
| 二重付与防止: LINE | PASS | `LineFriendLinkService::handleCodeMessage` | `line_user_id` と `user` をロックし、`rewarded_at` 未設定時のみ付与。テストで二重付与なしを確認するケースあり。 |
| 失効処理 | PASS | `PointExpirationService::expire`, `backend/routes/console.php` | freeロットのみ期限切れ対象。`points:expire` commandがあり、hourly schedule設定あり。 |
| 日次残高スナップショット | PASS | `PointBalanceSnapshotService`, `CreatePointBalanceSnapshotCommand`, `PointBalanceSnapshot` Model, `backend/routes/console.php`, `PointBalanceSnapshotServiceTest`, `PointBalanceSnapshotCommandTest` | paid/free未使用残高を集計し、`snapshot_date` unique + `updateOrCreate` で同日再実行時に更新する。未指定実行はAsia/Tokyo前日、手動日付指定は現在ロット残高を指定日として保存する仕様。3月31日・9月30日の基準日判定、Command日付指定/省略、Scheduler登録をテスト済み。 |

### ポイントの注意点

- `PointLotService::grant` 自体は内部で `DB::transaction` を開始していない。決済・抽選・紹介・LINEの呼び出し元がトランザクションを持つ場合は一体化されるが、単体呼び出し時の部分失敗対策は呼び出し側設計に依存する。
- `point_balance_snapshots` 作成処理は実装済み。管理画面/APIでスナップショットを閲覧・CSV出力する機能は別タスクとして残る。

## 決済

| 項目 | 評価 | 根拠 | 監査コメント |
|---|---|---|---|
| 現在mockのみであること | PASS | `StorePaymentRequest::rules`, `PaymentWebhookController::handle`, `PaymentIntentService::create` | providerは `Rule::in(['mock'])`。Webhookもmockのみ受け付ける。 |
| 本番決済として誤認されない制御 | WARNING | `PaymentResource`, `PaymentIntentService::create`, `frontend/src/app/points/purchase/point-purchase-client.tsx` | providerやcheckout metadataはmockだが、API名・購入画面の見え方次第で誤認余地あり。公開前にUI/文言/環境制御の確認が必要。 |
| 決済成功前にポイントを付与しないこと | PASS | `PaymentController::store`, `PaymentPointGrantService::markSucceeded` | store時はpendingのPayment作成のみ。ポイント付与はmarkSucceeded後。 |
| Webhook導入を想定した冪等構造 | PASS | `payments.webhook_event_id unique`, `PaymentWebhookService::handle`, `PaymentPointGrantService::markSucceeded` | `webhook_event_id` 一意制約、既存eventのduplicate返却、決済行ロック、succeeded再処理時の再付与なしを確認。 |
| 返金・チャージバック用データ構造 | PASS | `Payment` Migration, `PaymentStatusService`, `AdminPaymentController`, `AdminPaymentApiTest` | statusにrefunded/chargeback、`refunded_at`、`chargeback_at`、metadata理由、CB時ユーザー停止がある。ポイント取消はmetadata上pending。 |
| mock決済が本番環境で利用できない制御 | WARNING | `PaymentController::mockSucceed`, `StorePaymentRequest::rules` | `mock-succeed` は `local/testing` 以外403。一方、`POST /api/payments` は本番でもprovider mockのpending payment作成が可能。成功付与経路は閉じているが、公開前にmock作成自体の扱いを決める必要がある。 |

### 決済の注意点

- 本番決済未接続は `SPEC_V1_4_DEVIATION_REPORT.md` でも `CONFLICT` とした。
- 返金・チャージバック時のポイント取消は未実装で、`point_reversal` が `pending_manual_or_followup_process` としてmetadataに残る。正本v1.4の「ポイント付与取り消し」要件を完全には満たしていない。
- mock Webhook署名は `MockPaymentWebhookSignatureVerifier` で検証されるが、本番決済事業者導入時は事業者別署名検証へ差し替えが必要。

## 認証・外部連携

| 項目 | 評価 | 根拠 | 監査コメント |
|---|---|---|---|
| メール認証 | PASS | `AuthController::register`, `verifyEmail`, `sendEmailVerificationMail`, `UserEmailVerificationMail` | 24時間の署名付きURL、フロント `/email/verify` 経由、activeかつ未認証のみ、本登録前ログイン拒否を確認。 |
| Googleログイン | PASS | `GoogleAuthController`, `GoogleOAuthService`, `SocialAuthService`, `GoogleAuthApiTest` | Google確認済みメールのみ、既存認証済みメール拒否、未認証メールはブロックしない、初回登録セッションと紹介コード入力土台を確認。 |
| SMSコードのハッシュ保存 | PASS | `SmsVerificationService::send`, `SmsVerificationCode` | 6桁コードを `Hash::make` して `code_hash` に保存。平文保存は確認されない。 |
| SMS試行回数・有効期限 | PASS | `SmsVerificationService::send`, `verify`, `SmsVerificationCode` | `max_attempts`、`attempts`、`expires_at`、期限切れ時expired、試行超過時canceledを確認。 |
| LINE署名検証 | PASS | `LineWebhookController`, `LineMessagingService::verifySignature`, `LineFriendApiTest` | `X-Line-Signature` とchannel secretのHMAC SHA256を `hash_equals` で検証。 |
| 紹介ポイントの二重付与防止 | PASS | `ReferralRewardService::rewardForReferredUser`, `SmsVerificationService::verify` | pending紹介行をロックし、付与後rewardedへ変更。SMS認証完了時に呼ぶ。 |
| LINEポイントの二重付与防止 | PASS | `LineFriendLinkService::handleCodeMessage` | LINEリンクとユーザーをロックし、既に紐づいたLINE ID/ユーザーを拒否。`rewarded_at` が空の場合のみ付与。 |
| 管理API認証と権限 | PASS | `backend/routes/admin.php`, `EnsureAdminUser`, `AdminAuthController`, `AdminAuthApiTest` | `/admin/api/login` 以外は `auth:sanctum` と `EnsureAdminUser` 配下。AdminUserかつactiveのみ許可。 |
| adminサブドメイン制御 | WARNING | `frontend/next.config.ts`, `docs/SHARED_CONTEXT.md`, nginx/Cloudflare運用 | Laravel側Middlewareは管理者認証を行うが、サブドメイン自体の制御はNginx/Cloudflare/Cookie/CORS設定依存。本監査では実環境設定を確認していない。 |

### 認証・外部連携の注意点

- メール認証は未認証メール重複を許可する設計のため、古い確認URLの無効化と未認証アカウントの保持期限を運用で決める必要がある。
- SMS認証は送信プロバイダ抽象化済みだが、現状の実送信事業者は未確定。送信レート制限・費用対策・本人確認上の必須範囲は追加確認が必要。
- Googleログインのフロント接続は別作業範囲。バックエンド土台はあるが、初回登録補完画面とSMS誘導のE2E確認は未実施。

## Critical Findings

| 重大度 | 項目 | 評価 | 内容 | 推奨対応 |
|---|---|---|---|---|
| High | 日次残高スナップショット閲覧/出力 | WARNING | 日次集計を作成するService/Command/Schedule/テストは追加済み。管理画面/APIでの閲覧・CSV出力は未実装。 | 売上管理又はポイント管理の別タスクで、最新値・日次推移・基準日値・CSV出力を実装する。 |
| High | 本番決済 | WARNING | mock成功APIはlocal/testing限定だが、mock決済作成は本番でも通る。実決済未接続のため公開不可。 | 本番決済導入まで購入機能を公開しない、またはproductionでmock payment作成を禁止する。 |
| Medium | 返金・CB時のポイント取消 | WARNING | refunded/chargeback状態とユーザー停止はあるが、対応するポイント取消はmetadata上pending。 | 正本どおりポイント取消・不足時マイナス残高・利用制限を実装する。 |
| Medium | adminサブドメイン制御 | WARNING | アプリ側は管理者認証あり。サブドメイン分離はインフラ設定依存で本監査未確認。 | Cloudflare/Nginx/CORS/Cookie/Sanctum設定を別監査する。 |
| Medium | 日次抽選上限の実並行確認 | NOT_TESTED | コード上はトランザクション内検証だが、実並行テストは今回未実行。 | 同時リクエストで上限超過しないことをFeature/Integrationで検証する。 |

## Test Evidence Reviewed

| テストファイル | 確認対象 |
|---|---|
| `backend/tests/Feature/DrawServiceTest.php` | 抽選、最低保証、idempotency、通し番号 |
| `backend/tests/Feature/DrawApiTest.php` | 抽選API、daily_draw_limit |
| `backend/tests/Feature/ProbabilityRangeBuilderTest.php` | 売切確率の最低保証吸収、ppmレンジ |
| `backend/tests/Feature/StageResolverTest.php` | ステージ解決 |
| `backend/tests/Feature/ProbabilityVersionPublisherTest.php` | 公開確率バージョン |
| `backend/tests/Feature/PointConsumptionServiceTest.php` | 無償優先、期限近い順、FIFO、台帳 |
| `backend/tests/Feature/PointExpirationServiceTest.php` | 無償ポイント失効 |
| `backend/tests/Unit/PointBalanceSnapshotServiceTest.php` | 日次残高スナップショットService |
| `backend/tests/Feature/PointBalanceSnapshotCommandTest.php` | 日次残高スナップショットCommand/Scheduler |
| `backend/tests/Feature/PaymentApiTest.php` | mock決済、成功時付与、local mock confirmation |
| `backend/tests/Feature/PaymentWebhookApiTest.php` | Webhook署名、冪等、成功/失敗 |
| `backend/tests/Feature/AdminPaymentApiTest.php` | 返金/チャージバック状態変更 |
| `backend/tests/Feature/UserAuthApiTest.php` | メール認証、ログイン、未認証メール重複 |
| `backend/tests/Feature/GoogleAuthApiTest.php` | Googleログイン土台 |
| `backend/tests/Feature/SmsVerificationApiTest.php` | SMS認証、紹介付与 |
| `backend/tests/Feature/SmsVerificationStateTest.php` | SMS認証状態 |
| `backend/tests/Feature/LineFriendApiTest.php` | LINE署名、LINE連携、ポイント二重付与防止 |
| `backend/tests/Feature/AdminAuthApiTest.php` | 管理者認証 |
| `backend/tests/Feature/SchemaSpecificationTest.php` | DB制約の一部 |

## Not Executed In This Audit

- `php artisan test`
- `php artisan migrate`
- Docker起動、build、再起動
- DB実データ確認
- 外部Mailgun、LINE、Discord、Googleとの実通信
- ブラウザE2E確認
- 実並行リクエストによる負荷・競合確認

## 2026-06-29 Update

- 日次残高スナップショットのService、Command、Scheduler、対象テストを追加した。
- Target tests passed:
  - `docker compose exec -T backend php artisan test tests/Unit/PointBalanceSnapshotServiceTest.php`
  - `docker compose exec -T backend php artisan test tests/Feature/PointBalanceSnapshotCommandTest.php`
- 管理画面/APIでの閲覧、CSV出力、実並行二重実行の検証は別タスクとして残る。
