# Sales Management Impact Review

## Scope

本レビューは売上管理機能を追加する場合の影響範囲を整理する。現時点では設計のみであり、コード、Migration、DB操作、Docker操作は行っていない。

## Existing Components Reviewed

| Area | File / Table | Current state |
| --- | --- | --- |
| Payment table | `payments` | 決済履歴、状態、金額、付与ポイント、支払日時を保持 |
| Payment Model | `backend/app/Models/Payment.php` | `user()` Relationのみ |
| User payment API | `PaymentController.php` | mock決済作成、mock成功 |
| Payment grant | `PaymentPointGrantService.php` | 成功時ポイント付与、二重付与防止 |
| Payment status | `PaymentStatusService.php` | refund/chargeback状態更新、ポイント取消はpending |
| Purchase plan | `point_purchase_plans`, `PointPurchasePlan.php` | 販売期間対応済み |
| Point ledger | `point_ledgers` | 有償/無償、台帳種別、関連先を保持 |
| Point lot | `point_lots` | 残高・期限管理 |
| Draw request | `draw_requests` | 抽選単位、総消費ポイント |
| Draw result | `draw_results` | 抽選結果詳細 |
| Daily report | `DailySalesReportService.php` | Discord向け前日集計あり |
| Admin routes | `backend/routes/admin.php` | 売上管理専用APIは未定義 |
| Admin UI | `frontend/src/app/admin-dashboard.tsx` | 決済一覧はあるが売上管理画面は未実装 |
| Admin route bridge | `frontend/src/app/admin/[[...segments]]/page.tsx` | 安定版AdminDashboardへ初期状態を渡す |

## Backend Impact

### New Additions Required Later

売上管理実装時は次を追加する見込み。

- `SalesManagementReportService`
- `AdminSalesManagementController`
- 売上管理用Resource
- `backend/routes/admin.php` への `/sales/*` route追加
- Feature/Unit tests

### Existing Logic Not To Modify

次の中核処理は売上管理追加で変更しない。

- 抽選ロジック
- ポイント消費ロジック
- ポイント付与ロジック
- 確率バージョン管理
- mock決済成功処理
- 返金/チャージバック状態更新

### Query Performance Impact

売上管理は集計処理を伴うため、対象月のデータ量が増えると負荷が上がる。

既存Index:

- `payments`: `['user_id', 'created_at']`, unique `['provider', 'provider_payment_id']`
- `point_ledgers`: `['user_id', 'created_at']`, `['related_type', 'related_id']`
- `draw_requests`: `['user_id', 'created_at']`, `['gacha_id', 'created_at']`
- `draw_results`: `['draw_request_id', 'created_at']`, `['user_id', 'created_at']`

追加Index候補:

- `payments(status, paid_at)`
- `payments(provider, paid_at)`
- `point_ledgers(ledger_type, related_type, created_at)`
- `point_ledgers(point_type, ledger_type, created_at)`
- `draw_requests(status, created_at)`

ただし、初期実装で性能問題が顕在化するまではMigrationを必須にしない。集計APIのEXPLAIN確認後、必要なIndexを別Migrationで追加する。

## Frontend Impact

管理画面リファクタリングは延期中のため、売上管理は現行安定構成に追加する。

影響ファイル候補:

- `frontend/src/app/admin-dashboard.tsx`
- `frontend/src/app/admin/[[...segments]]/page.tsx`

想定変更:

- `TabKey` に `sales` を追加
- 左メニューの「決済」を「売上管理」に変更し、お知らせより上へ移動
- 売上管理用stateとAPI呼び出しを追加
- 月別/日別切替UIを追加
- 決済一覧とポイント消費一覧を追加
- 詳細モーダルまたは詳細セクションを追加

注意:

- route分割リファクタリングは再開しない。
- `refreshAll()` に売上管理の重い集計APIを含めると初期表示がさらに重くなるため、売上管理タブを開いた時だけ取得する設計が望ましい。
- 既存の決済一覧API `/admin/api/payments` は残し、売上管理専用APIと役割を分ける。

## Data Accuracy Impact

### Payment Sales

決済売上は `payments.paid_at` と `status=succeeded` を基準とする。

注意:

- `created_at` ではなく成功日時である `paid_at` を基準にする。
- `pending`, `failed`, `canceled` は売上に含めない。
- `refunded`, `chargeback` は別指標として表示する。控除後売上として扱うかは会計方針に合わせて後続判断が必要。

### Point Consumption

ポイント消費は `point_ledgers` を基準とする。

理由:

- `draw_requests.consumed_point_total` では有償/無償の内訳が分からない。
- `point_ledgers` には `point_type` があり、有償/無償別に集計できる。

注意:

- `point_ledgers.amount` は消費時に負数で保存されるため、表示は `ABS(amount)` を用いる。
- `related_type=draw_request` のみをガチャ消費として扱う。
- 管理減算や失効、交換は売上管理のガチャ消費には含めない。

## Legal / Accounting Impact

- 有償ポイント残高管理は資金決済法対応上重要であり、売上管理の「消費」表示と「未使用残高」表示は混同しない。
- 決済金額は円、ポイント消費はptとして明確に分ける。
- mock決済は本番決済ではないため、本番公開前の売上管理では本番決済プロバイダ連携後に再確認が必要。
- 返金/チャージバック時のポイント取消は未実装であり、本番決済公開前BLOCKERとして残る。

## Security Impact

- 売上管理APIは既存管理APIと同じ `auth:sanctum` + `EnsureAdminUser` 配下に置く。
- 一般ユーザーAPIには公開しない。
- ユーザー名・メールアドレス等を含むため、管理者権限のみでアクセスする。
- CSV出力を将来追加する場合、権限・監査ログ・個人情報保護の検討が必要。

## Operational Impact

- 既存のDiscord日次売上通知とは別に、管理画面上で任意日付の確認が可能になる。
- 初期実装では画面確認用の集計APIとし、日次集計テーブルは作らない。
- データ量増加後に集計が重くなる場合は、日次集計テーブルまたはMaterialized View相当の導入を検討する。

## Risks

| Risk | Severity | Mitigation |
| --- | --- | --- |
| 月別集計が重い | Medium | 初期は対象月のみ、必要ならIndex追加 |
| 購入プラン名がmetadata依存 | Medium | 将来Paymentにplan snapshotを保存 |
| 返金/CBの会計上の扱いが未確定 | High | 売上・返金・CBを別表示し、純売上定義は後続確認 |
| 管理画面が再び重くなる | High | 売上APIを `refreshAll()` に含めない |
| mock決済を本番売上と誤認 | High | 画面にprovider/statusを明示、本番決済未接続を仕様に残す |
