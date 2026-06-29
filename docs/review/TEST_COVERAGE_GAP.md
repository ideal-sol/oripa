# TEST COVERAGE GAP

作成日: 2026-06-25

対象:

- 正本: `docs/md/spec_v1.4.md`
- 追加仕様記録: `docs/md/all_check.md`
- 実装監査: `docs/review/AS_BUILT_IMPLEMENTATION_MATRIX.md`, `docs/review/CRITICAL_LOGIC_AUDIT.md`

監査方針:

- `backend/tests` と `frontend` 配下のテスト設定・テストファイルを確認した。
- 本監査ではテスト実行、migrate、Docker操作、依存関係更新は行っていない。
- 現状はLaravel Feature Test中心。frontend側にPlaywright/Vitest/Jest等のE2E/Browser Test設定は確認できない。

## Priority Definition

- `P0`: 公開前に必須。資金・抽選・セキュリティ・二重付与に直結。
- `P1`: 公開前に強く推奨。運用事故・サポート負荷に直結。
- `P2`: 安定運用・保守性向上のため推奨。

## Summary

| 領域 | 現在のテスト傾向 | 主な不足 |
|---|---|---|
| 抽選 | Feature/Serviceテストあり | 実並行テスト、複数リクエスト競合、演出結果再現性の変更後保持 |
| ポイント | 消費・失効・付与・日次残高スナップショット系テストあり | 三者一致の統合検証、並行付与/消費、スナップショット閲覧/CSV |
| 決済 | mock決済、Webhook冪等テストあり | 本番環境でmock作成禁止、返金/CB時ポイント取消、並行Webhook |
| 認証 | メール、Google、SMS、LINEのFeatureテストあり | OAuth/SMS/LINEのE2E、レート制限、再送イベントの厳密な冪等 |
| 管理/フロント | Laravel APIテスト中心 | Browser/E2Eがほぼ未整備 |

## Coverage Matrix

| ID | 機能 | Unit Test | Feature Test | Integration Test | Concurrent Test | Browser/E2E Test | 現在のテスト有無 | 不足テスト | 推奨テストケース | 優先度 |
|---|---|---|---|---|---|---|---|---|---|---|
| TC-001 | 同時抽選: 通し番号重複・欠番防止 | 部分あり: `DrawServiceTest.php` | あり: `DrawApiTest.php` | 不足 | 不足 | 不足 | idempotency、通し番号の基本確認はあり | 実DBで同時に複数ユーザー/同一ガチャを引くテスト | 10並列で同一ガチャを1回ずつ抽選し、`draw_sequence_number` が1..10で重複・欠番なし、`sold_count=10`、wallet/ledger整合を検証 | P0 |
| TC-002 | 同時抽選: 在庫上限超過防止 | 部分あり | 部分あり | 不足 | 不足 | 不足 | `DrawService`内で景品lockと在庫確認あり | 同一景品残り1個への並行当選テスト | 100%当選・max_win_count=1の景品に2並列抽選し、1件のみprize、もう一方が最低保証またはロールバックされる挙動を仕様化して検証 | P0 |
| TC-003 | まとめ引きの1回ごとのステージ判定 | あり: `DrawServiceTest.php`, `StageResolverTest.php` | あり: `DrawApiTest.php` | 部分あり | 不足 | 不足 | 複数ステージの基本テストあり | 境界をまたぐまとめ引きの明示テスト強化 | sold_count=9、10口目までstage1、11口目からstage2で2連を実行し、結果1がstage1、結果2がstage2になることを検証 | P0 |
| TC-004 | idempotency key | あり: `DrawServiceTest.php` | あり: `DrawApiTest.php` | 部分あり | 不足 | 不足 | 同一key completed時の既存結果返却あり | processing中の同時同一key競合 | 同一ユーザー/ガチャ/idempotency_keyで2並列実行し、1件だけ処理、もう1件は既存結果またはprocessing拒否で二重消費なし | P0 |
| TC-005 | daily_draw_limit | 部分あり | あり: `DrawApiTest.php`, `AdminGachaApiTest.php` | 部分あり | 不足 | 不足 | 上限超過拒否テストあり | JST日付境界、並行上限超過 | limit=1で2並列抽選し1件のみ成功。23:59/00:00 JSTでカウントが日付更新されることをCarbon固定で検証 | P0 |
| TC-006 | CSPRNG利用 | 不足 | 不足 | 不足 | 不足 | 不足 | コード監査で `random_int` を確認 | 静的検査または禁止API検出 | Laravel/Next.js内の抽選経路で `Math.random` や `mt_rand` が使われていないことを静的テストで検証 | P1 |
| TC-007 | ppm合計1,000,000 | あり: `ProbabilityRangeBuilderTest.php`, `ProbabilityVersionPublisherTest.php` | あり: `AdminProbabilityApiTest.php` | あり | 不要 | 不足 | 合計検証、最低保証行検証あり | UI入力%→ppm変換E2E | 管理画面で%入力し、API payloadがppm合計1,000,000になることをブラウザで検証 | P1 |
| TC-008 | 在庫切れ確率の最低保証吸収 | あり: `ProbabilityRangeBuilderTest.php` | 部分あり | 部分あり | 不足 | 不足 | sold out/inactive吸収テストあり | 抽選中に売切になった後のレンジ再構築 | まとめ引き中、1回目で景品在庫0、2回目以降はそのppmが最低保証に吸収されることを検証 | P0 |
| TC-009 | ランク演出素材ランダム選択 | あり: `DrawServiceTest.php` | 部分あり | 部分あり | 不足 | 不足 | 選択URLが候補内であることは確認 | 乱択の固定化/分布ではなく保存・再現性の検証強化 | 複数画像/動画候補から抽選し、`draw_results.selected_*` が保存されることを検証 | P1 |
| TC-010 | ランク演出結果の再現性 | 部分あり: `DrawServiceTest.php` | 不足 | 不足 | 不要 | 不足 | 保存直後のDB値確認はあり | 素材設定変更後も過去結果が変わらないテスト | 抽選後にランク素材を差し替え/削除し、履歴APIや結果APIが保存済み `selected_*` を返すことを検証 | P0 |
| TC-011 | 最低保証ポイント付与 | あり: `DrawServiceTest.php` | あり: `DrawApiTest.php` | 部分あり | 不足 | 不足 | point_backとfree付与確認あり | 複数回最低保証時のロット/台帳数と金額 | 10連全て最低保証で、free lot/ledger/resultの件数と合計が一致することを検証 | P1 |
| TC-012 | paid/free区分と有償期限なし | あり: `PointConsumptionServiceTest.php`, `SchemaSpecificationTest.php` | あり: `PaymentApiTest.php` | 部分あり | 不足 | 不足 | DB CHECKと付与処理確認あり | 管理付与/決済/移行データの包括確認 | 有償付与経路すべてで `expire_at=null`、無償付与経路すべてで `expire_at not null` を検証 | P0 |
| TC-013 | 無償優先・期限近い順消費 | あり: `PointConsumptionServiceTest.php` | 部分あり | 部分あり | 不足 | 不足 | free期限近い順→paid FIFO確認あり | 抽選サービス経由での統合検証 | free複数lot+paid lotで抽選し、draw result、ledger、wallet、lot残高まで一括検証 | P0 |
| TC-014 | point_lots/point_ledgers/wallets三者一致 | 部分あり | 部分あり | 不足 | 不足 | 不足 | 個別テストはあるが全体整合バッチなし | 三者一致監査テスト | 複数付与/消費/失効後、wallet残高=point_lots remaining合計=ledgers累積になることを検証する共通アサーション | P0 |
| TC-015 | 失効処理 | あり: `PointExpirationServiceTest.php` | 部分あり | 部分あり | 不足 | 不足 | free期限切れ失効、paid対象外は確認 | schedule実行確認、失効前通知 | `points:expire` commandをFeatureで実行し、hourly schedule登録と結果ログを検証 | P1 |
| TC-016 | 日次残高スナップショット | あり: `PointBalanceSnapshotServiceTest.php` | あり: `PointBalanceSnapshotCommandTest.php` | 部分あり | 不足 | 不足 | Service/Command/Scheduler、paid/free集計、基準日、冪等更新、未指定時のAsia/Tokyo前日、日付指定、無効日付を確認 | 管理API/CSV表示、並行再実行、三者一致との統合確認 | snapshot閲覧API/CSV追加後、最新値・日次推移・基準日値を検証。必要なら同日Command二重起動時の競合も検証 | P1 |
| TC-017 | 決済成功前にポイント未付与 | 部分あり | あり: `PaymentApiTest.php`, `PaymentWebhookApiTest.php` | 部分あり | 不足 | 不足 | pending作成後、成功時付与確認あり | pending状態で残高不変の明示 | payment作成直後にwallet/lot/ledgerが増えないことを検証 | P0 |
| TC-018 | 二重Webhook | 部分あり | あり: `PaymentWebhookApiTest.php` | 部分あり | 不足 | 不足 | 同一payload再送で二重付与なし確認あり | 並行Webhook再送 | 同一event_idを2並列送信し、Payment 1件、PointLot 1件、Ledger 1件のみを検証 | P0 |
| TC-019 | Webhook署名 | あり | あり: `PaymentWebhookApiTest.php` | 部分あり | 不要 | 不足 | invalid signature拒否あり | 本番プロバイダ導入後の署名方式 | Stripe/GMO/KOMOJU等の署名検証Serviceを追加後、正常/異常/リプレイを検証 | P0 |
| TC-020 | mock決済の本番利用防止 | 部分あり | 部分あり: `PaymentApiTest.php` | 不足 | 不要 | 不足 | `mock-succeed` local/testing限定テストあり | productionでmock payment作成禁止テスト | `app.env=production` 相当で `POST /api/payments provider=mock` が拒否されること。現状は不足 | P0 |
| TC-021 | 返金/チャージバック | 不足 | あり: `AdminPaymentApiTest.php` | 部分あり | 不足 | 不足 | status変更、CB時user suspended確認あり | ポイント取消・不足時処理 | refunded/chargeback時に付与済みポイント取消、残高不足時マイナス/利用制限、未発送景品停止を検証 | P0 |
| TC-022 | メール認証 | 不足 | あり: `UserAuthApiTest.php` | 部分あり | 不足 | 不足 | 登録、重複、リンク無効化あり | フロント `/email/verify` リダイレクトE2E | メール内URLクリックでJSONではなくログイン画面へ遷移し、成功/期限切れ表示を検証 | P1 |
| TC-023 | Googleログイン | 不足 | あり: `GoogleAuthApiTest.php` | 部分あり | 不足 | 不足 | 既存メール判定、初回登録、紹介作成あり | 実OAuth相当E2E、フロント補完画面 | callback→紹介コード入力→SMS認証画面への遷移、既存認証済みメール拒否をブラウザで検証 | P1 |
| TC-024 | SMSコードハッシュ保存 | あり | あり: `SmsVerificationApiTest.php` | 部分あり | 不足 | 不足 | 6桁送信、Hash保存、FakeSmsSenderあり | 平文非保存の明示検証 | DBの `code_hash` が送信コードと一致しない文字列で、`Hash::check` のみ成功することを検証 | P0 |
| TC-025 | SMS試行回数 | 部分あり | あり: `SmsVerificationApiTest.php`, `SmsVerificationStateTest.php` | 部分あり | 不足 | 不足 | invalid code increments attemptsあり | max到達時の以後拒否、再送後旧コード無効 | max_attempts回失敗後canceled、旧コードでは認証不可、再送後新コードのみ認証可を検証 | P0 |
| TC-026 | SMS有効期限 | 部分あり | あり | 部分あり | 不要 | 不足 | expires_atを持つ状態確認あり | 期限切れverifyの明示 | Carbon固定で期限後verifyし、status=expired、認証不可を検証 | P0 |
| TC-027 | 二重紹介ポイント | 部分あり | あり: `SmsVerificationApiTest.php` | 部分あり | 不足 | 不足 | 同一ユーザーで二度verifyしてPointLot 1件確認あり | 並行SMS verifyでの二重付与防止 | 同一紹介のSMS verifyを2並列実行し、UserReferral=rewarded、PointLot/Ledgerが1件のみを検証 | P0 |
| TC-028 | 紹介設定無効/0pt | 不足 | 部分あり | 不足 | 不要 | 不足 | 実装監査ではPARTIAL | 無効時canceled、0pt時canceled | setting inactive/0ptでSMS認証後、ポイント付与なし、referral status=canceledを検証 | P1 |
| TC-029 | LINE署名検証 | あり | あり: `LineFriendApiTest.php` | 部分あり | 不要 | 不足 | invalid signature拒否あり | secret未設定時拒否 | `services.line.channel_secret=null` で403を検証 | P1 |
| TC-030 | LINE Webhook再送 | 部分あり | 部分あり: `LineFriendApiTest.php` | 部分あり | 不足 | 不足 | 同一コード再送で二重付与なし確認あり | 同一イベントID再送の冪等 | LINEイベントIDを保存/判定する仕様がないため、同一Webhook再送時のevent記録重複許容/拒否を仕様化して検証 | P0 |
| TC-031 | LINEポイント二重付与 | 部分あり | あり: `LineFriendApiTest.php` | 部分あり | 不足 | 不足 | 2回送信でPointLot 1件確認あり | 並行messageイベント | 同一line_user_id/codeを2並列送信し、link 1件、PointLot/Ledger 1件、rewarded_at 1回を検証 | P0 |
| TC-032 | 管理API認証 | 不足 | あり: `AdminAuthApiTest.php` | 部分あり | 不要 | 不足 | login/me/logoutやadmin middleware確認あり | 全admin route認証スモーク | 主要admin APIが未認証401/非admin403になることをroute一覧から網羅的に検証 | P1 |
| TC-033 | adminサブドメイン | 不足 | 不足 | 不足 | 不要 | 不足 | コードレベルでは未確認 | Nginx/CORS/Cookie/Sanctumの環境テスト | `admin.luxe-pack.biz` からadmin API、`luxe-pack.biz` からpublic API、Cookie/SameSite/CORSをブラウザで検証 | P0 |
| TC-034 | ガチャタグAPI/管理 | 不足 | あり: `AdminGachaTagApiTest.php`, `GachaApiTest.php` | 部分あり | 不要 | 不足 | 管理APIと公開API確認あり | 管理画面E2E | タグ作成→ガチャ紐づけ→公開APIでタグ表示の流れを検証 | P2 |
| TC-035 | トップバナー | 不足 | あり: `AdminTopBannerApiTest.php`, `TopBannerApiTest.php` | 部分あり | 不要 | 不足 | 管理/公開APIあり | 一括有効/無効のブラウザ確認 | 複数選択で有効化し、公開APIで並び順どおり返ることを検証 | P2 |
| TC-036 | お問い合わせ自動返信 | 不足 | あり: `ContactRequestApiTest.php` | 部分あり | 不要 | 不足 | Discord通知テストはあり。メール自動返信の網羅は要確認 | Mailgun/Mailpit送信経路 | contact送信でユーザー宛自動返信、管理通知、Mail fakeの宛先/本文を検証 | P1 |
| TC-037 | Discord日次売上通知 | 不足 | あり: `AdminDailySalesReportTest.php` | 部分あり | 不要 | 不足 | command送信、Webhook fakeあり | Scheduler登録・JST前日境界 | JST 10:00、前日集計範囲、ガチャ別売上/口数/ランク排出/残口数を検証 | P1 |
| TC-038 | 配送の景品単位管理 | 不足 | あり: `AdminShippingRequestApiTest.php` | 部分あり | 不足 | 不足 | APIテストあり | 画面遷移と個別編集E2E | ユーザー保有景品→配送編集ページ、配送メニュー再選択で一覧へ戻ることをブラウザで検証 | P1 |
| TC-039 | 購入プラン販売期間 | 不足 | あり: `AdminPointPurchasePlanApiTest.php`, `PointPurchasePlanApiTest.php` | 部分あり | 不要 | 不足 | APIテストあり | 境界時刻とtimezone | starts_at/ends_at境界で公開APIに出る/出ないをCarbon固定で検証 | P1 |
| TC-040 | 管理画面巨大コンポーネント回帰 | 不足 | 不足 | 不足 | 不要 | 不足 | frontendにE2Eなし | 主要画面スモーク | login→各左メニュー→一覧→登録→編集→戻る/再読み込みをPlaywrightで検証 | P1 |

## Focused Gaps

### P0: 同時抽選

現在の確認:

- `DrawService::draw` は `DB::transaction` と `gachas.lockForUpdate` を使う。
- `draw_results` は `unique(['gacha_id', 'draw_sequence_number'])` を持つ。
- `DrawServiceTest.php` と `DrawApiTest.php` は通常ケース、idempotency、daily limitを確認している。

不足:

- 実際に同時リクエストを走らせるConcurrent Testがない。
- 同時抽選時の `draw_sequence_number` 欠番なし、`sold_count`、wallet、point_lots、point_ledgersまで一括検証するテストがない。

推奨:

- PostgreSQL実DB前提のIntegration/Concurrent Testを追加する。
- SQLiteではロック挙動が異なるため、PostgreSQLで実行するテストとして分離する。

### P0: 二重Webhook

現在の確認:

- `PaymentWebhookApiTest.php` に同一payload再送で二重付与しないテストがある。
- `payments.webhook_event_id` はunique。

不足:

- 同時に同一Webhookが2本到達した場合のConcurrent Testがない。
- 本番決済プロバイダ導入後の署名・event id仕様は未実装。

推奨:

- 同一event_idを並列POSTし、Payment status、PointLot、PointLedgerが1回分のみになることを検証する。

### P0: 二重紹介ポイント

現在の確認:

- `SmsVerificationApiTest.php` にSMS認証後の紹介ポイント付与と二度目の付与なし確認がある。
- `ReferralRewardService` はpending行を `lockForUpdate` する。

不足:

- 並行SMS認証による二重付与防止テストがない。
- 紹介設定無効/0pt時のキャンセル挙動の専用テストが弱い。

推奨:

- 同一referred userでSMS verifyを2並列実行し、`user_referrals.status=rewarded`、PointLot/Ledger各1件のみを検証する。

### P0: LINE Webhook再送

現在の確認:

- `LineFriendApiTest.php` で署名検証、follow自動応答、コード送信、同一コード再送時の二重付与なしを確認している。

不足:

- LINEイベントID単位の冪等管理はコード上確認できない。
- 同一Webhook payload再送を「イベント履歴は重複してよいが付与は1回」など、仕様として固定するテストが不足。
- 並行Webhook再送のConcurrent Testがない。

推奨:

- 同一line_user_id/codeの並行POSTを検証する。
- LINEイベントIDを保存する設計にする場合は、event id一意制約と再送duplicateテストを追加する。

### P0: SMS試行回数

現在の確認:

- `SmsVerificationApiTest.php` に不正コードでattemptsが増えるテストがある。
- `SmsVerificationStateTest.php` で状態レスポンスを確認している。

不足:

- max_attempts到達後にstatus=canceledとなり、それ以後正しいコードでも認証できないテスト。
- 期限切れコードのverify拒否。
- 再送後に旧コードが無効になるテスト。

推奨:

- Carbon固定とFakeSmsSenderで、期限・再送・試行上限の境界を網羅する。

### P0: 日次上限

現在の確認:

- `DrawApiTest.php` に上限超過拒否テストがある。
- `AdminGachaApiTest.php` に `daily_draw_limit` 登録テストがある。

不足:

- 日付境界がAsia/Tokyoで切り替わるテスト。
- 同時抽選でlimitを超えないテスト。
- 複数回抽選でlimitをまたぐときの部分成功不可、全体拒否の確認。

推奨:

- limit=3で2連後に2連を投げた場合、2回目リクエスト全体が拒否されることを検証する。
- JST 23:59と翌日00:00をCarbon固定で検証する。

### P0: ランク演出結果の再現性

現在の確認:

- `DrawServiceTest.php` に `selected_rank_image_url` と `selected_draw_video_url` が候補内で保存されるテストがある。

不足:

- 抽選後にランク素材マスタやランク紐づけを変更しても、過去結果表示が保存済みURLを使うことのテストがない。
- 結果画面のBrowser/E2Eがない。

推奨:

- 抽選後、rank assetのURLや紐づけを変更し、履歴API/抽選結果APIが `draw_results.selected_*` を返し続けることを検証する。
- ブラウザで動画→結果表示→上部ランク画像と一覧景品サムネイルを確認する。

## Browser/E2E Gap

frontend側で確認した範囲:

- `frontend/package.json` に `dev`, `build`, `start`, `lint`, `typecheck` はある。
- Playwright、Vitest、Jest等のテスト設定は確認できない。

不足:

- 管理画面のログイン、左メニュー遷移、再読み込み、登録/編集フォーム、フラッシュメッセージのE2E。
- ユーザー側の会員登録、メール認証、ログイン、ポイント購入、抽選、演出動画、結果表示、景品BOX、配送依頼のE2E。
- adminサブドメインとユーザードメインのCookie/CORS/Sanctum動作確認。

推奨:

- Playwrightを導入し、まずP0のスモークだけを作る。
- 初期E2Eは「認証」「抽選」「ポイント購入mock」「管理ガチャ設定」「配送編集」に絞る。

## Recommended Test Implementation Order

1. P0: 同時抽選Concurrent Test。
2. P0: daily_draw_limitの並行・JST境界テスト。
3. P0: 二重Webhookの並行テスト。
4. P0: 二重紹介ポイントとLINEポイントの並行テスト。
5. P0: SMS試行回数・期限・再送の境界テスト。
6. P0: ランク演出結果の再現性テスト。
7. P0: production環境でmock決済作成/成功ができないことのテスト。
8. P1: 日次残高スナップショットの管理API/CSV表示テスト。
9. P1: 返金/チャージバック時のポイント取消テスト。
10. P1: Playwrightによる管理画面・ユーザー画面の最小E2E。

## Test Execution Status

- 本監査ではテストを実行していない。
- 既存テストファイルの有無と内容を静的に確認した。
- 過去の実行結果は `worklogs/codex-main.md` に記録があるが、本レポートでは現在実行済みとは扱わない。

## 2026-06-29 Update

- `PointBalanceSnapshotServiceTest.php` and `PointBalanceSnapshotCommandTest.php` were added.
- Target tests passed:
  - `docker compose exec -T backend php artisan test tests/Unit/PointBalanceSnapshotServiceTest.php`
  - `docker compose exec -T backend php artisan test tests/Feature/PointBalanceSnapshotCommandTest.php`

## 2026-06-29 Sales Management Backend Read API Update

- `SalesManagementReportServiceTest.php` and `AdminSalesManagementApiTest.php` were added.
- Target tests passed:
  - `docker compose exec -T backend php artisan test tests/Unit/SalesManagementReportServiceTest.php`
  - `docker compose exec -T backend php artisan test tests/Feature/AdminSalesManagementApiTest.php`
- Covered:
  - Monthly sales gross/refund/chargeback/net aggregation.
  - Daily payment list by `paid_at`.
  - Payment method fallback and purchase plan fallback.
  - Monthly and daily point consumption from `point_ledgers`.
  - Paid/free split and draw_request grouping.
  - Draw request detail with draw_results.
  - Admin authentication, invalid date, and per_page validation.
- Remaining gap:
  - Admin sales UI is not implemented, so Browser/E2E and unrelated-page Network checks remain pending.
  - Production payment provider behavior remains pending until provider integration is implemented.
