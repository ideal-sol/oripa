# Sales Management Test Plan

## Scope

This plan covers the future implementation of the admin sales management feature.

Current task is design only. No tests were added or executed for this document.

## Backend Unit Tests

Target:

- `SalesManagementReportService`

Recommended cases:

| ID | Case | Expected |
| --- | --- | --- |
| SM-U-001 | 月別決済売上を集計する | `status=succeeded` and `paid_at` within month are summed |
| SM-U-002 | provider別売上を集計する | providerごとの金額と件数が返る |
| SM-U-003 | pending/failed/canceledを売上に含めない | totalに含まれない |
| SM-U-004 | refunded/chargebackを別指標として返す | 売上と別カウントで確認できる |
| SM-U-005 | 日別決済一覧を取得する | paid_atが対象日のデータのみ |
| SM-U-006 | 購入プランIDをmetadataから解決する | plan名が表示用データに含まれる |
| SM-U-007 | 月別ポイント消費を集計する | spend/draw_requestのみ集計 |
| SM-U-008 | paid/freeを分けて集計する | point_type別にABS(amount)が集計される |
| SM-U-009 | 管理減算・失効・交換を消費売上に含めない | related_type/ledger_typeで除外 |
| SM-U-010 | draw_request単位の日別消費一覧を返す | 複数ledgerが1行に集約される |
| SM-U-011 | Asia/Tokyoの日付境界を守る | 境界時刻のデータが正しい日付に入る |
| SM-U-012 | 空データ月でも日付枠を返す | カレンダー表示に必要な空日が返る |

## Backend Feature Tests

Target:

- `AdminSalesManagementController`

Recommended cases:

| ID | Endpoint | Case | Expected |
| --- | --- | --- | --- |
| SM-F-001 | `GET /admin/api/sales/monthly` | 管理者がアクセス | 200 |
| SM-F-002 | `GET /admin/api/sales/monthly` | 未ログイン | 401 |
| SM-F-003 | `GET /admin/api/sales/monthly` | 一般ユーザー | 403 or 401 according to current admin middleware |
| SM-F-004 | `GET /admin/api/sales/monthly` | year/month指定 | 対象月の集計 |
| SM-F-005 | `GET /admin/api/sales/daily-payments` | date指定 | 対象日の決済一覧 |
| SM-F-006 | `GET /admin/api/sales/daily-payments` | provider filter | providerで絞り込み |
| SM-F-007 | `GET /admin/api/sales/daily-payments` | status filter | statusで絞り込み |
| SM-F-008 | `GET /admin/api/sales/monthly-point-consumption` | month指定 | 対象月のポイント消費集計 |
| SM-F-009 | `GET /admin/api/sales/daily-point-consumption` | date指定 | draw_request単位で一覧 |
| SM-F-010 | `GET /admin/api/sales/draw-requests/{id}` | detail表示 | draw_resultsを含む |
| SM-F-011 | all | invalid date | 422 |
| SM-F-012 | all | per_page上限超過 | validation or capped result |

## Integration Tests

Recommended cases:

| ID | Case | Expected |
| --- | --- | --- |
| SM-I-001 | PaymentIntentServiceで作成し、PaymentPointGrantServiceで成功化 | 売上管理に成功決済として出る |
| SM-I-002 | mock成功前のpending決済 | 売上には含まれず日別一覧では状態確認可能 |
| SM-I-003 | DrawServiceで抽選しポイント消費 | daily point consumptionに有償/無償別で出る |
| SM-I-004 | まとめ引き | draw_request 1行、draw_results複数件 |
| SM-I-005 | 返金/CB状態更新後 | refund/CB件数または状態として表示される |

## Browser / Manual QA

管理画面リファクタリング延期中のため、手動確認は安定版管理画面で行う。

Checklist:

- 左メニューに「売上管理」が表示される。
- 「売上管理」はお知らせより上に表示される。
- `/admin/sales` を直接開ける。
- 月別/日別を切り替えられる。
- 月別売上一覧がカレンダー形式で表示される。
- 日別売上一覧に決済日時、決済種別、購入プラン、金額、状態、ユーザー名が表示される。
- 月別ポイント消費一覧がカレンダー形式で表示される。
- 日別ポイント消費一覧に日時、有償ポイント、無償ポイント、ユーザー名、ガチャ名、詳細が表示される。
- 詳細ボタンで対象draw_requestの当選内容一覧が表示される。
- 既存の購入プラン一覧・登録・編集が壊れていない。
- 既存のポイント一覧・ポイント調整が壊れていない。
- 既存の配送、ガチャ、ユーザー管理が壊れていない。
- セッション期限切れ時はログイン画面へ遷移する。
- sales APIが他ページ初期表示で呼ばれない。

## Performance Tests

Manual or log-based checks:

- `/admin/guide` で sales API が呼ばれない。
- `/admin/gachas` で sales API が呼ばれない。
- `/admin/sales` の初回表示が10秒未満。
- 月切替時に対象月APIのみ呼ばれる。
- 日付選択時に対象日APIのみ呼ばれる。
- 月別集計でN+1が発生しない。

If slow:

- query planを確認する。
- `payments(status, paid_at)` 等のIndex追加を検討する。
- 日次集計テーブル化を検討する。

## Regression Tests

Existing tests to run selectively after implementation:

- Admin payment API tests
- Point purchase plan API tests
- Payment API tests
- Draw API tests
- Point ledger / consumption tests

Do not run broad destructive commands. Do not run tests in parallel.

## Test Data Requirements

Minimum fixtures:

- admin user
- active normal user
- payment plan
- pending payment
- succeeded mock payment
- refunded payment
- chargeback payment
- gacha
- draw_request completed
- draw_results with prize and point_back
- point_ledgers with paid spend
- point_ledgers with free spend
- unrelated grant/expire/exchange ledgers

## Acceptance Criteria

- 決済売上は `paid_at` 基準で正しく集計される。
- 決済種別ごとの売上が表示できる。
- 日別決済一覧で購入プランとユーザーが確認できる。
- ポイント消費は `point_ledgers` 基準で有償/無償に分かれる。
- 詳細から draw_results が確認できる。
- 未ログイン・非管理者はアクセスできない。
- 既存管理機能が壊れない。
- 管理画面初期表示に売上管理の重いAPIを混ぜない。

## 2026-06-29 Backend Read API Test Results

Implemented:

- `backend/tests/Unit/SalesManagementReportServiceTest.php`
- `backend/tests/Feature/AdminSalesManagementApiTest.php`

Executed target tests:

- `docker compose exec -T backend php artisan test tests/Unit/SalesManagementReportServiceTest.php`
  - PASS: 4 tests, 27 assertions.
- `docker compose exec -T backend php artisan test tests/Feature/AdminSalesManagementApiTest.php`
  - PASS: 6 tests, 45 assertions.

Covered:

- Monthly gross sales, refund amount, chargeback amount, and net sales.
- Payment method fallback from `metadata.payment_method` to `provider`.
- Exclusion of `pending`, `failed`, and `canceled` from gross sales.
- Daily payment list by `paid_at`.
- Purchase plan resolution from `payments.metadata.point_purchase_plan_id`.
- Deleted/missing plan fallback.
- Monthly point consumption from `point_ledgers`.
- Paid/free split by `point_type`.
- Exclusion of non-spend and non-draw ledger rows.
- Daily point consumption grouped by `draw_request`.
- Draw request detail with child `draw_results`.
- Asia/Tokyo start-inclusive/end-exclusive boundaries.
- Empty calendar day frame for monthly responses.
- Unauthenticated and non-admin access rejection.
- Invalid date and `per_page` validation.

Still not covered because UI is not implemented:

- Browser/E2E checks for the future admin sales screen.
- Network check that sales APIs are not called from unrelated admin pages.
- Manual display check for calendar tables and draw result detail UI.
