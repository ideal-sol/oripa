# Luxe-pack プロジェクト現状共有資料

作成日: 2026-07-21

この文書は、別のChatGPTへ現在の実装状況、確定仕様、未完了事項、今後の仕様変更候補を共有するための資料です。

重要: 「仕様として確定済み」「コード実装済み」「テスト済み」「実環境へ反映済み」「Gitへコミット済み」は、それぞれ別の状態として扱ってください。

## 1. プロジェクト概要

Luxe-packは、ポイントを購入・消費してオリパを抽選し、獲得景品の保管、ポイント交換、配送依頼まで行うWebサービスです。

管理者は、ガチャ、カテゴリ、ランク、景品、確率、演出素材、ユーザー、ポイント、配送、売上、返金・チャージバック、コンテンツ等を管理します。

### 技術構成

- Backend: Laravel API
- Frontend: Next.js
- DB: PostgreSQL
- Cache / Queue: Redis
- Web Server: Nginx
- ローカルメール確認: Mailpit
- 本番メール送信: Mailgun API
- 管理通知: Discord Webhook
- 現在のアップロード保存先: サーバー内ストレージ
- 将来候補: AWS S3
- 現在の決済: 開発・検証用mock決済
- 本番決済プロバイダ: 未接続

## 2. 仕様の優先順位

仕様が競合する場合は、以下の順で判断します。

1. 人間による最新の明示決定
2. `docs/md/spec_v1.5.1.md`
3. `docs/md/spec_v1.6_draft.md`
4. `docs/md/spec_v1.5.md`
5. `docs/decisions/APPROVED_AS_BUILT_SPECIFICATIONS_2026-06-25.md`
6. `docs/md/spec_v1.4.md`
7. `AGENTS.md`
8. `TASK_BOARD.md`
9. `docs/SHARED_CONTEXT.md`
10. `docs/md/all_check.md`

`TASK_BOARD.md` と `docs/SHARED_CONTEXT.md` には、日次残高スナップショットが未実装であるという古い記載が残っています。実際にはService、Command、Scheduler、テスト、管理閲覧APIまで実装済みです。現在状況は `docs/md/spec_v1.6_draft.md` と `worklogs/codex-main.md` を優先してください。

## 3. 変更してはいけない中核ルール

- 抽選ロジックはLaravelだけに置く。
- Next.jsに抽選、確率、ポイント消費ロジックを持たせない。
- 抽選乱数はLaravelでCSPRNGを使用する。
- 確率はppm整数で管理し、`1,000,000 ppm = 100%` とする。
- 公開済み確率バージョンはimmutableとし、直接編集しない。
- 完全ハズレはなく、結果種別は `prize` または `point_back` のみとする。
- 最低保証を維持する。
- `draw_sequence_number` はガチャ行のDBロック下で採番し、重複と欠番を防止する。
- 抽選、ポイント消費、在庫、`sold_count`、`won_count`、抽選結果、台帳、獲得景品はDBトランザクション内で整合させる。
- paidポイントは無期限で `expire_at = null`。
- freeポイントだけが失効対象。
- ポイント消費はfree優先、free内では期限が近い順、同一期限では古い付与順、その後paidをFIFOで消費する。
- ユーザー画面では景品ごとの個別確率を表示しない。ランク合計確率、ステージ、切替条件、最低保証等を表示する。
- 配送状態と追跡番号は景品単位で管理する。
- DB日時と業務日付はAsia/Tokyo基準で扱う。

## 4. Gitと作業ツリーの状況

確認時点の状態:

- 現在ブランチ: `main`
- 現在HEAD: `5914e8b feat: add admin sales management UI`
- `origin/main`: `0af553b Add LINE friend reward settings`
- ローカルの `main` には、`origin/main` より後のコミットが複数存在する。
- さらに、返金・チャージバック、売上CSV、残高閲覧API、QAテスト抽選等の大規模な未コミット差分が存在する。

直近のローカルコミット:

- `5914e8b feat: add admin sales management UI`
- `6f4ea0c feat: add sales management read APIs`
- `9e2fca3 feat: add daily point balance snapshots`
- `f8b779f Add category descriptions and restore specs`
- `0af553b Add LINE friend reward settings`

未コミット差分の規模:

- 追跡済み変更: Backend、Frontend、仕様書、作業ログにまたがる。
- 未追跡ファイル: 返金・チャージバック、QA抽選、売上CSV、残高閲覧APIのService、Model、Controller、Request、Resource、Migration、Test、設計書等。
- `frontend/src/app/admin-dashboard.tsx` に、売上管理拡張、返金・CB UI、QA管理UIの変更が集約されている。

この差分は機能単位でまだコミットされていません。仕様変更や追加実装の前に、現在の差分を失わないよう機能単位のコミット方針を決める必要があります。

## 5. 管理画面の構成

管理画面のroute分割リファクタリング `ADMIN-REF-001` は延期中です。

理由:

- Next.js dev serverでroute分割後のcompile/cache負荷が大きくなった。
- 現行サーバーのメモリ・CPU条件で504が発生した。
- route競合自体は修正したが、表示速度と504は改善しなかった。

現在の運用方針:

- 安定版の `frontend/src/app/admin-dashboard.tsx` を中心に管理画面機能を追加する。
- `frontend/src/app/admin/[[...segments]]/page.tsx` が管理画面URLを受ける。
- route-split refactorを再開しない。
- 全機能追加後、サーバースペックを上げてから再検討する。
- 管理画面の集計APIやQA APIを `refreshAll()` に追加しない。
- 重いAPIは該当画面を開いた時だけ取得する。

## 6. コミット済みの主な直近機能

### ガチャカテゴリ説明

- `gacha_categories.description` をnullable text、最大2,000文字で追加。
- 管理画面の登録・編集で利用可能。
- 管理APIと公開APIに含む。
- ユーザー画面では未表示。
- 管理画面のカテゴリ一覧では説明列を非表示。
- Migration適用済み、対象テストPASS。

### 日次ポイント残高スナップショット保存

- `PointBalanceSnapshotService` 実装済み。
- `points:snapshot-balances` Command実装済み。
- Schedulerは毎日00:10 JSTに前日分を作成。
- 3月31日、9月30日を基準日として記録。
- paid/freeの未使用残高を `point_lots.remaining_amount` から集計。
- 同日再実行は `updateOrCreate` で更新し、重複しない。
- 手動 `--date` は過去時点を再構築せず、実行時点の残高を指定日へ保存する。
- Unit Test: 5 tests / 15 assertions PASS。
- Feature Test: 5 tests / 13 assertions PASS。

### 売上管理Backend Read APIと管理UIの基礎

- 月別売上、日別決済、月別ポイント消費、日別ポイント消費、抽選結果詳細APIを実装。
- 管理画面の「決済」を「売上管理」へ変更。
- `/admin/sales` を主URL、`/admin/payments` を互換URLとして扱う。
- 管理画面は現行安定版構成に追加。

## 7. 未コミットだが実装・テスト済みの主な機能

以下は現在の作業ツリーに存在しますが、現在HEADには含まれていません。

### 7.1 売上管理の追加実装

実装済み:

- 日別返金・チャージバック一覧API。
- 日別サマリー。
- 月別売上と月別ポイント消費のGoogleカレンダー風表示。
- 日付セルから対象日の日別画面へ遷移。
- 月別売上、日別決済、日別返金・CB、月別ポイント消費、日別ポイント消費のCSV API。
- UTF-8 BOM、日本語ヘッダー、対象年月日入りファイル名。
- 管理画面のCSVダウンロードボタン。
- CSV APIはボタン押下時だけ呼び、`refreshAll()` には含めない。
- 静的な性能レビュー文書。

テスト実績:

- `AdminSalesCsvExportTest`: 3 tests / 45 assertions PASS。
- `AdminSalesManagementApiTest`: 8 tests / 77 assertions PASS。
- Frontend `pnpm typecheck`: PASS。

性能上の注意:

- `payments.paid_at/refunded_at/chargeback_at` の集計向けindex候補がある。
- `point_ledgers` の売上管理向け複合index候補がある。
- CSVはメモリ上で生成し、日別CSVは最大10,000行を取得するため、データ増加時に再設計が必要。
- 本番DBへの `EXPLAIN ANALYZE` や負荷試験は未実施。

### 7.2 日次ポイント残高スナップショット閲覧API

実装済みAPI:

- `GET /admin/api/point-balance-snapshots/latest`
- `GET /admin/api/point-balance-snapshots`
- `GET /admin/api/point-balance-snapshots/base-dates`

実装内容:

- 最新スナップショット。
- `date_from` / `date_to` による期間一覧。
- 指定年の3月31日、9月30日の基準日残高。
- 欠損基準日は `exists=false`、`snapshot=null`。
- Feature Test: 6 tests / 45 assertions PASS。

未実装:

- 管理画面の閲覧UI。
- 残高スナップショットCSV。

### 7.3 返金・チャージバック

実装済み:

- 返金・CB用Migration、Model、Enum、Relation。
- 通常返金可否判定。
- ポイント取消Service。
- 通常返金Service。
- チャージバックService。
- 景品hold・返送依頼Service。
- 管理API。
- 管理画面UI。
- 返金・CB履歴の期間検索。
- チャージバック時の返送依頼メール。
- 返送依頼メール失敗記録と管理画面からの再送。

確定ルール:

- 通常返金は対象payment由来ポイントが全額未使用の場合だけ可能。
- 1ptでも使用済みなら通常返金不可。
- チャージバック時は現在保有ポイント全体から取消する。
- 取消順は、購入paid分をpaid lot、購入free bonus分をfree lot、paid不足分を残free lot、残りをshortfallとする。
- free bonus分をpaid lotから取消してはいけない。
- walletとpoint lotをマイナスにしない。
- shortfallではpoint ledgerを作らない。
- 未発送景品は一旦すべてhold。
- 発送済み・配送済み景品は返送依頼対象。
- 抽選履歴、連番、`sold_count`、`won_count` は巻き戻さない。
- メール送信はDB commit後。失敗しても返金・CB処理はrollbackしない。

主要API:

- `GET /admin/api/payments/{payment}/refund-eligibility`
- `POST /admin/api/payments/{payment}/refund`
- `POST /admin/api/payments/{payment}/chargeback`
- `GET /admin/api/payment-reversals`
- `GET /admin/api/payment-reversals/{paymentReversal}`
- `POST /admin/api/payment-reversals/{paymentReversal}/release-holds`
- `POST /admin/api/payment-reversals/{paymentReversal}/send-return-request-mail`
- `POST /admin/api/payment-reversal-prize-actions/{action}/mark-returned`

実環境反映:

- `2026_06_30_000001_create_payment_reversal_tables.php` は実環境でMigration適用済み。

テスト実績:

- RefundEligibilityService: 3 tests / 10 assertions PASS。
- PointReversalService: 補強後 7 tests / 55 assertions PASS。
- PaymentRefundService: 3 tests / 13 assertions PASS。
- ChargebackPrizeActionService: 2 tests / 13 assertions PASS。
- ChargebackReversalService: メール統合後 3 tests / 19 assertions PASS。
- AdminPaymentRefundChargebackApiTest: 5 tests / 39 assertions PASS。
- AdminPaymentReversalApiTest: 期間検索追加後 6 tests / 29 assertions PASS。
- ChargebackReturnRequestMailTest: 1 test / 6 assertions PASS。
- PaymentReturnRequestMailServiceTest: 3 tests / 17 assertions PASS。
- AdminPaymentReturnRequestMailApiTest: 4 tests / 16 assertions PASS。
- Frontend `pnpm typecheck`: PASS。

未実装・未確定:

- 本番決済Webhookとの接続。
- 返金・CB時の決済事業者API呼び出し。
- Discord通知と送信失敗再送。
- 通常返金完了メール。
- 返送期限、送料負担、法務文言の確定。
- 部分返金、複数adjustment、チャージバック取消復元。
- 現在は `payment_reversals.payment_id` がuniqueで、1 paymentにつき最終reversal 1件の初期設計。

### 7.4 QAテストユーザー抽選

目的:

- 通常ユーザーを最大24時間のQAテストモードへ切り替える。
- 通常抽選APIを使ったまま、Ownerが設定した景品を指定順・指定回数で排出する。
- 模擬処理ではなく、ポイント、在庫、獲得景品、配送等は通常データへ実際に反映する。

Backend実装済み:

- QA用Migration、Model、Enum、Relation。
- Owner限定QAモードAPI。
- Owner限定QA排出プラン管理API。
- `QaDrawResolver`。
- 通常 `DrawService` への統合。
- QA抽選識別列と `qa_draw_executions` 実行履歴保存。

実環境反映:

- `2026_07_14_000001_create_qa_test_user_draw_tables.php` は実環境でMigration適用済み。

主要ルール:

- QAモードの `ends_at` は必須、期間上限は24時間。
- OwnerだけがQA設定を閲覧・操作できる。
- admin/operatorは403。
- QAモード無効、開始前、期限切れの場合だけ通常抽選へ戻る。
- QAモード有効中に対象ガチャのactive planがない場合は422。
- 設定不足、不正設定、在庫不足もポイント消費前に422。
- QAモード有効中は通常確率抽選へフォールバックしない。
- 指定景品は `sort_order`、`quantity`、`consumed_count` に従い順番に選択する。
- 景品ロック・在庫再検証後、通常のポイント消費、在庫減少、`sold_count`、`won_count`、`draw_results`、`user_prizes` を使う。
- 固定画像・動画を指定可能。未指定時は通常の演出選択を使う。
- 全item消費後はplanを `completed` にする。
- completed planは再有効化せず、新規planを作る。
- 同一idempotency keyの再実行で二重消費しない。
- 失敗時はQA item消費数を含めてトランザクション全体をrollbackする。

主要API:

- `GET /admin/api/users/{user}/qa-test-mode`
- `PUT /admin/api/users/{user}/qa-test-mode`
- `DELETE /admin/api/users/{user}/qa-test-mode`
- `GET /admin/api/users/{user}/qa-draw-plans`
- `POST /admin/api/users/{user}/qa-draw-plans`
- `GET /admin/api/qa-draw-plans/{plan}`
- `PUT /admin/api/qa-draw-plans/{plan}`
- `DELETE /admin/api/qa-draw-plans/{plan}`
- `POST /admin/api/qa-draw-plans/{plan}/pause`
- `POST /admin/api/qa-draw-plans/{plan}/activate`

管理画面UI実装済み:

- ユーザー詳細のOwner限定QAモード設定。
- QAモード有効化、更新、無効化。
- QA排出プラン一覧と景品設定の折りたたみ詳細。
- QA排出プラン新規作成フォーム。
- 複数景品行、数量、順序変更、固定画像、固定動画の設定。
- 保存前確認、POST保存、成功後フォーム初期化と一覧再取得。
- active planの一時停止。
- paused planの再開。
- active/paused planの無効化。
- completed/disabled planは操作不可。
- QA APIはユーザー詳細をOwnerが開いた時だけ取得し、`refreshAll()` には追加しない。

Backendテスト実績:

- QaTestUserModeServiceTest: 4 tests / 22 assertions PASS。
- AdminQaTestUserModeApiTest: 6 tests / 52 assertions PASS。
- QaDrawPlanServiceTest: 対象テストPASS。
- AdminQaDrawPlanApiTest: 対象テストPASS。
- QaDrawResolverTest: 8 tests / 25 assertions PASS。
- QaTestUserDrawApiTest: 6 tests / 53 assertions PASS。
- 既存DrawApiTest: 5 tests / 24 assertions PASS。
- `backend/tests/Unit/DrawServiceTest.php` はリポジトリに存在しない。
- Frontend `pnpm typecheck`: 最新UI変更後PASS。
- `git diff --check`: 最新UI変更後PASS。

QA機能で未実装または未確認:

- 既存QA排出プランの編集UI。
- QA抽選実行履歴の閲覧APIと管理UI。
- 抽選履歴、売上管理、CSVでのQA識別表示。
- Owner画面での一連のブラウザ手動確認。
- 実際の通常抽選画面から指定景品が順番に出るE2E確認。
- 本格的な同時抽選テスト。
- QA終了時刻をまたぐ境界テストの追加確認。
- QAプラン操作Phase 4B-2はコード実装済みだが、`spec_v1.6_draft.md` と `worklogs/codex-main.md` への記録がまだない。

## 8. その他の実装済み機能

主要な既存機能:

- 通常会員登録、ログイン、メール認証。
- 未認証メールアドレスの重複許可と、認証成立時の占有ルール。
- SMS認証Backend基盤と重複電話番号ルール。
- GoogleログインLaravel API基盤。
- 紹介コードとSMS認証後の紹介ポイント付与。
- LINE友達追加コード、LINE連携、ポイント付与設定。
- ガチャタグ、トップバナー、お知らせ、問い合わせ、固定ページ。
- ランク演出素材マスタ、複数画像・動画のランダム選択、抽選結果への保存。
- 日次抽選上限。
- 景品単位配送。
- 有償/無償ポイントロットと失効処理。
- 利益シミュレーション、商品設計プランナー。
- Mailgunメール送信。
- Discord日次売上通知。
- `admin.luxe-pack.biz` の管理サブドメイン運用。

一部は実装済みでも、E2E、実機、本番外部サービス接続の確認が不足しています。

## 9. 今後の主要タスク候補

優先度はプロジェクト責任者との相談で確定します。

### 優先度が高い候補

1. 現在の大規模未コミット差分を機能単位で整理し、コミット・pushする。
2. QAテスト抽選の管理UIを完成させる。
3. QA抽選履歴API/UIとCSV・抽選履歴上の識別表示を追加する。
4. QAテストユーザーで通常抽選APIを使うE2E確認を行う。
5. 返金・CBの管理画面およびメール再送を実データで確認する。
6. 日次ポイント残高スナップショット閲覧UIを追加する。
7. 本番決済プロバイダ選定後、Webhook、返金、CB、冪等性を接続する。
8. 公開前QA、負荷試験、法務・会計確認を行う。

### 将来対応

- Appleログイン。
- Googleログインのユーザー向けフロント導線完成。
- 実SMS送信事業者との接続。
- PWAとWeb Push通知。
- AWS S3移行。
- 管理画面route分割リファクタリング再実施。
- 売上管理向けindex追加。
- CSV大量出力のQueue/streaming化。
- 部分返金、複数adjustment、チャージバック取消復元。
- 過去日時点残高のpoint ledgerからの厳密再構築。

## 10. 公開前の重要な未解決事項

- 本番決済は未接続で、mock決済だけが開発・検証用として承認済み。
- mock決済を本番のポイント購入として有効化してはいけない。
- 返金・CBの決済事業者側処理は未接続。
- 有償ポイントに関する資金決済法、未使用残高、供託、サービス終了時払戻し等は専門家確認が必要。
- 返送依頼の期限、送料負担、法務文言が未確定。
- 本番データ量での売上集計・CSV性能確認が未実施。
- 抽選とポイントの本格的な同時実行試験が不足。
- 全体Browser/E2E QAが未完了。

## 11. インフラ・作業上の注意

- サーバーは過去にDocker buildやNext.js dev compileでメモリ逼迫・ハング・504を起こしている。
- 全サービスの `docker compose up -d --build` を実行しない。
- Docker操作前に `free -h`、`df -h`、`docker system df`、`docker compose ps` を確認する。
- ログは `docker compose logs --tail=100 <service>` を使用する。
- `npm install`、`pnpm install`、`composer install`、Docker build、Next.js buildは事前説明と承認が必要。
- `docker system prune -a --volumes` は明示許可なしで実行しない。
- `.env`、APIキー、秘密情報をGitへ含めない。
- 現在の未コミット差分を無断でreset、checkout、cleanしない。

## 12. ChatGPTへ相談したい論点の例

この文書を共有した後、以下を相談できます。

- QAテスト抽選の残りUI・履歴・CSVをどの順に完成させるべきか。
- QA機能で通常データを実際に更新する設計の安全策が十分か。
- 返金・チャージバックを本番決済Webhookへ接続する前に不足している設計は何か。
- 本番公開BLOCKERをどの順番で解消すべきか。
- 現在の大規模未コミット差分をどの機能単位でコミットすべきか。
- 売上管理indexをいつ追加すべきか。
- 現行サーバー条件で管理画面リファクタリングを再開する判断基準は何か。
- 法務、会計、資金決済法対応で専門家へ確認すべき項目は何か。

## 13. ChatGPTへ最初に送る依頼文の例

```text
添付したPROJECT_STATUS_FOR_CHATGPT_2026-07-21.mdを読んでください。

このプロジェクトはLaravel API + Next.jsのオリパサイトです。
文書内では、確定仕様、実装済み、テスト済み、Migration適用済み、Gitコミット済みを分けて記載しています。

まずコード実装案は出さず、次の観点で現状をレビューしてください。

1. 本番公開前のBLOCKER
2. QAテスト抽選機能の残作業とリスク
3. 返金・チャージバック機能の残作業とリスク
4. 未コミット差分を安全に整理する順番
5. 今後仕様変更を決める際に先に確定すべき事項

既存の確定仕様を勝手に変更せず、変更案は「提案」として分けてください。
```

## 14. 参照すべき主要ファイル

- `docs/md/spec_v1.5.1.md`
- `docs/md/spec_v1.6_draft.md`
- `docs/decisions/APPROVED_AS_BUILT_SPECIFICATIONS_2026-06-25.md`
- `docs/design/QA_TEST_USER_DRAW_DESIGN.md`
- `docs/review/QA_TEST_USER_DRAW_IMPACT.md`
- `docs/review/QA_TEST_USER_DRAW_TEST_PLAN.md`
- `docs/design/REFUND_CHARGEBACK_DESIGN.md`
- `docs/review/REFUND_CHARGEBACK_TEST_PLAN.md`
- `docs/design/SALES_MANAGEMENT_DESIGN.md`
- `docs/review/SALES_MANAGEMENT_PERFORMANCE_REVIEW.md`
- `worklogs/codex-main.md`
- `TASK_BOARD.md`
- `docs/SHARED_CONTEXT.md`

## 15. この文書の限界

- この文書は2026-07-21時点のリポジトリと作業ログを基にしたスナップショットです。
- 実際の本番データ内容や外部サービスの管理画面設定は含みません。
- 未コミット差分は今後変更される可能性があります。
- Browser/E2E未確認の機能は、Backend TestやtypecheckがPASSしていても本番利用可能とは断定できません。
- `TASK_BOARD.md` と `docs/SHARED_CONTEXT.md` の一部は古いため、最新状況の判断には使用しないでください。
