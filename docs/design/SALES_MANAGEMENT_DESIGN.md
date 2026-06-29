# Sales Management Design

## Purpose

売上管理機能は、管理画面で決済売上とポイント消費を月別・日別に確認するための v1.6 追加予定機能である。

この設計書は実装前調査に基づく設計である。

2026-06-29 update: Backend Read API、集計Service、対象テストは実装済み。管理画面UI、Migration、本番決済接続、返金/チャージバック時のポイント取消は未実装。

## Source Of Truth

優先する仕様は次の通り。

1. 人間による最新の明示決定
2. `docs/md/spec_v1.5.1.md`
3. `docs/md/spec_v1.6_draft.md`

`docs/md/spec_v1.5.1.md` 20.1 では、売上管理は v1.6 予定の次期追加機能として記録されている。

## Current Investigation Summary

### Database

| Table | Current role | Sales management use |
| --- | --- | --- |
| `payments` | 決済履歴。`provider`, `status`, `amount`, `paid_point_amount`, `free_point_amount`, `paid_at`, `refunded_at`, `chargeback_at` を保持 | 決済売上の月別・日別集計、日別決済一覧 |
| `point_purchase_plans` | 購入プラン。`starts_at`, `ends_at` による販売期間あり | 決済一覧で購入プラン名を表示するため、`payments.metadata.point_purchase_plan_id` から参照 |
| `point_ledgers` | ポイント台帳。`point_type`, `ledger_type`, `amount`, `related_type`, `related_id` を保持 | `ledger_type=spend` かつ `related_type=draw_request` をポイント消費として集計 |
| `point_lots` | 付与ロットと残高。資金決済法対応の未使用残高管理 | 売上管理では直接集計対象にしない。残高表示は別機能の `point_balance_snapshots` を使う |
| `draw_requests` | 抽選リクエスト。`draw_count`, `consumed_point_total`, `status` を保持 | ガチャ別消費ポイント、抽選口数、日別ポイント消費一覧の基点 |
| `draw_results` | 抽選結果。景品、ランク、消費ポイント、付与ポイント、演出結果を保持 | 詳細ボタン押下時の当選内容一覧 |

### Backend

| File | Current role | Finding |
| --- | --- | --- |
| `backend/app/Models/Payment.php` | Payment Model | `user()` のみ。購入プランRelationは未定義 |
| `backend/app/Http/Controllers/Api/PaymentController.php` | ユーザー側決済作成・mock成功 | 本番決済未接続。mock成功は local/testing のみ |
| `backend/app/Domain/Payment/Services/PaymentPointGrantService.php` | 決済成功時のポイント付与 | `Payment` 行をlockし、成功済み再送では二重付与しない |
| `backend/app/Domain/Payment/Services/PaymentStatusService.php` | 返金・CB状態更新 | ポイント取消は未実装で metadata に pending と記録 |
| `backend/app/Models/PointPurchasePlan.php` | 購入プランModel | `currentlyAvailable()` あり。販売期間対応済み |
| `backend/app/Domain/Admin/Services/DailySalesReportService.php` | Discord日次売上通知 | 前日決済売上、ガチャ消費、抽選口数、ランク排出を集計済み |
| `backend/app/Console/Commands/SendAdminDailySalesReportCommand.php` | Discord日次売上通知Command | `admin:daily-sales-report` |
| `backend/routes/admin.php` | 管理API route | 既存 `/payments`, `/draw-requests`, `/draw-results` はあるが売上管理専用APIはない |

### Frontend Admin

管理画面は現在、リファクタリング延期後の安定構成である。

| File | Current role |
| --- | --- |
| `frontend/src/app/admin-dashboard.tsx` | 管理画面本体。左メニュー、決済一覧、購入プラン、各管理画面を保持 |
| `frontend/src/app/admin/[[...segments]]/page.tsx` | URL segmentを `AdminDashboard` の初期状態へ変換 |

現在の左メニューには `payments` が「決済」として存在する。売上管理は、管理画面リファクタリングを再開せず、この安定構成へ追加する。

## Functional Requirements

### Menu

左メニューの既存「決済」を「売上管理」へ変更し、お知らせより上へ移動する。

想定順序:

1. 操作ガイド
2. 売上管理
3. お知らせ
4. お問い合わせ
5. ガチャ管理
6. ユーザー管理
7. 配送
8. 購入プラン
9. ポイント
10. 設定

既存の決済一覧機能は売上管理内の日別決済一覧として吸収する。

### Monthly Sales View

月別売上一覧はカレンダー形式で表示する。

表示項目:

- 日付
- 日ごとの総決済売上
- 決済種別ごとの売上
- 成功件数
- 返金件数
- チャージバック件数

集計対象:

- 総売上は `payments.paid_at` が対象期間内で、status が `succeeded` / `refunded` / `chargeback` の決済金額合計。
- `pending` / `failed` / `canceled` は総売上に含めない。
- 返金額は `payments.refunded_at` が対象期間内の決済金額合計。
- チャージバック額は `payments.chargeback_at` が対象期間内の決済金額合計。
- 純売上は `総売上 - 返金額 - チャージバック額`。
- 決済種別は `metadata.payment_method` があれば優先し、なければ `provider` を代替表示する。

現時点の provider は `mock` のみ。本番決済接続後に `credit_card`, `bank_transfer`, `convenience_store`, `paypay` 等を追加する。

### Daily Sales View

日別売上一覧を表示する。

表示項目:

- 決済日時
- 決済種別
- 決済した購入プラン
- 決済金額
- 決済状態
- 決済したユーザー名

購入プラン名は `payments.metadata.point_purchase_plan_id` から `point_purchase_plans.id` を参照する。過去のプラン名変更に備える場合は、将来 `payments.metadata.point_purchase_plan_snapshot` を保存する設計を検討する。

### Monthly Point Consumption View

月別ポイント消費一覧はカレンダー形式で表示する。

表示項目:

- 日付
- 有償ポイント総消費
- 無償ポイント総消費
- ガチャ別の消費ポイント
- 抽選口数

集計対象:

- `point_ledgers.ledger_type = spend`
- `point_ledgers.related_type = draw_request`
- `point_ledgers.amount < 0`
- `ABS(point_ledgers.amount)` を消費量として扱う
- `point_ledgers.point_type` により有償/無償を分ける

ガチャ別集計は `point_ledgers.related_id = draw_requests.id` から `draw_requests.gacha_id` に紐づける。

### Daily Point Consumption View

日別ポイント消費一覧を表示する。

表示項目:

- 日時
- 有償ポイント
- 無償ポイント
- ユーザー名
- ガチャ名
- 詳細

単位は `draw_request` を推奨する。1回のまとめ引きで複数 `point_ledgers` が作られるため、`draw_request_id` ごとに有償/無償消費を集約して1行で表示する。

### Point Consumption Detail

詳細ボタンで、対象ガチャで何を当てたかの一覧を表示する。

参照:

- `draw_results.draw_request_id`
- `draw_results.result_type`
- `draw_results.rank`
- `draw_results.prize`
- `draw_results.consumed_point`
- `draw_results.granted_point`

表示項目案:

- 抽選結果ID
- 通し番号
- 結果種別
- ランク
- 景品名
- 消費ポイント
- 付与ポイント
- 抽選日時

## Date And Time Rules

- Asia/Tokyo 基準で集計する。
- 決済売上は `paid_at` を基準にする。
- ポイント消費は `point_ledgers.created_at` を基準にする。
- 日別範囲は Asia/Tokyo の `00:00:00` から `23:59:59`。
- DB保存時刻は現行方針に従い Asia/Tokyo 運用とする。

## Proposed Backend Design

### Service

実装済み:

- `backend/app/Domain/Admin/Services/SalesManagementReportService.php`

責務:

- 月別決済売上集計
- 日別決済一覧
- 月別ポイント消費集計
- 日別ポイント消費一覧
- 抽選結果詳細
- Asia/Tokyo 日付境界の統一

`DailySalesReportService` はDiscord通知用の文章生成を含むため、画面API用Serviceとは分ける。ただし集計ロジックの考え方は流用する。

### Controller

実装済み:

- `backend/app/Http/Controllers/Admin/Sales/AdminSalesManagementController.php`

エンドポイント案:

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/admin/api/sales/monthly` | 月別決済売上カレンダー |
| GET | `/admin/api/sales/daily-payments` | 日別決済一覧 |
| GET | `/admin/api/sales/monthly-point-consumption` | 月別ポイント消費カレンダー |
| GET | `/admin/api/sales/daily-point-consumption` | 日別ポイント消費一覧 |
| GET | `/admin/api/sales/draw-requests/{drawRequest}` | ポイント消費詳細 |

共通Query:

- `year`
- `month`
- `date`
- `provider`
- `status`
- `gacha_id`
- `user_id`
- `page`
- `per_page`

### Resources

初期実装では専用Resourceは追加せず、Controllerから明示的な配列レスポンスを返す。UI実装時にResource分離が必要になれば追加する。

実装済みテスト:

- `backend/tests/Unit/SalesManagementReportServiceTest.php`
- `backend/tests/Feature/AdminSalesManagementApiTest.php`

## Proposed API Response Shape

### Monthly Sales

```json
{
  "data": {
    "year": 2026,
    "month": 6,
    "timezone": "Asia/Tokyo",
    "days": [
      {
        "date": "2026-06-01",
        "total_amount": 12000,
        "providers": [
          { "provider": "mock", "amount": 12000, "count": 3 }
        ],
        "succeeded_count": 3,
        "refunded_count": 0,
        "chargeback_count": 0
      }
    ]
  }
}
```

### Daily Payment List

```json
{
  "data": [
    {
      "id": 1,
      "paid_at": "2026-06-01T10:00:00+09:00",
      "provider": "mock",
      "purchase_plan": { "id": 1, "name": "ライト" },
      "amount": 1000,
      "status": "succeeded",
      "user": { "id": 10, "name": "Test User", "email": "test@example.com" }
    }
  ],
  "meta": {}
}
```

### Monthly Point Consumption

```json
{
  "data": {
    "year": 2026,
    "month": 6,
    "timezone": "Asia/Tokyo",
    "days": [
      {
        "date": "2026-06-01",
        "paid_point_total": 3000,
        "free_point_total": 500,
        "gachas": [
          { "gacha_id": 4, "gacha_title": "Demo", "paid_point": 2000, "free_point": 500, "draw_count": 5 }
        ]
      }
    ]
  }
}
```

### Daily Point Consumption

```json
{
  "data": [
    {
      "draw_request_id": 100,
      "datetime": "2026-06-01T12:00:00+09:00",
      "paid_point": 2000,
      "free_point": 500,
      "user": { "id": 10, "name": "Test User", "email": "test@example.com" },
      "gacha": { "id": 4, "title": "Demo" },
      "draw_count": 5
    }
  ],
  "meta": {}
}
```

## Proposed Frontend Design

対象は安定版管理画面:

- `frontend/src/app/admin-dashboard.tsx`
- `frontend/src/app/admin/[[...segments]]/page.tsx`

追加する状態:

- `salesView`: `monthly-sales | daily-sales | monthly-points | daily-points | detail`
- `salesMonth`
- `salesDate`
- `salesFilters`
- `salesMonthly`
- `salesDailyPayments`
- `salesMonthlyPointConsumption`
- `salesDailyPointConsumption`
- `selectedSalesDrawRequest`

URL案:

- `/admin/sales`
- `/admin/sales/monthly`
- `/admin/sales/daily?date=YYYY-MM-DD`
- `/admin/sales/points/monthly`
- `/admin/sales/points/daily?date=YYYY-MM-DD`
- `/admin/sales/draw-requests/{id}`

ただし、現行安定構成ではURL segmentを `routeStateFromSegments()` で `AdminDashboard` に渡す方式のため、実装時は既存方式に合わせる。

## Non Goals

今回の売上管理では次を実装しない。

- 本番決済プロバイダ接続
- 返金/チャージバック時のポイント取消
- 管理画面リファクタリング再開
- 売上CSV出力
- 会計仕訳
- 消費税計算
- 決済手数料の実額確定
- point_ledgers から過去残高を再構築する機能

## Risks And Notes

- 現在の決済はmock中心であり、本番決済種別の列挙は将来変更される。
- `payments` には購入プランIDが `metadata` に保存されるため、SQL集計だけでプラン名を高速に出すには制約がある。
- 返金/チャージバックは状態更新のみでポイント取消が未実装のため、売上管理では「状態表示」までに留める。
- ポイント消費は `draw_requests.consumed_point_total` と `point_ledgers` の両方から取れるが、有償/無償内訳は `point_ledgers` が正である。
- 売上管理を実装する際も、抽選ロジック・ポイント消費ロジック・決済ロジックは変更しない。
