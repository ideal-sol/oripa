# SPEC V1.4 DEVIATION REPORT

作成日: 2026-06-25

対象:

- 正本: `docs/md/spec_v1.4.md`
- 現行正本: `docs/md/spec_v1.5.md`
- 追加仕様記録: `docs/md/all_check.md`
- 実装確認資料: `docs/review/AS_BUILT_IMPLEMENTATION_MATRIX.md`
- 承認決定: `docs/decisions/APPROVED_AS_BUILT_SPECIFICATIONS_2026-06-25.md`

監査方針:

- `docs/md/all_check.md` の記載のみで差分を確定せず、コード、Migration、API、画面、テストファイルの存在を確認した。
- 本レポートでは、正本v1.4と現行実装の差分を `ADDITION`、`CLARIFICATION`、`OVERRIDE`、`CONFLICT` に分類する。
- 本作業ではコード、DB、Docker、設定ファイルを変更していない。
- 本作業ではテスト、migrate、Docker build、依存関係更新を実行していない。

## Classification

- `ADDITION`: 正本v1.4に明記がない追加機能または運用追加。
- `CLARIFICATION`: 正本v1.4の抽象記述を、現行実装で具体化したもの。
- `OVERRIDE`: 正本v1.4の記述を、運用判断により上書きしているもの。
- `CONFLICT`: 正本v1.4と両立しない、または本番MVP条件を満たしていないもの。

## Summary

| ID | 分類 | 差分 | 承認状況 | 実装・リリース状況 |
|---|---|---|---|---|
| DEV-001 | OVERRIDE | ガチャ詳細で景品ごとの排出率を表示しない | 承認済み | 表示・法務文言はリリース前確認対象 |
| DEV-002 | OVERRIDE | 配送をまとめ配送から景品単位へ変更 | 承認済み | 運用文言はリリース前確認対象 |
| DEV-003 | OVERRIDE | DB日時をAsia/Tokyoで保存 | 承認済み | 外部連携時刻変換は確認対象 |
| DEV-004 | OVERRIDE | 未認証メールアドレスの重複を許可 | 承認済み | 未認証データ保持期間は運用課題 |
| DEV-005 | ADDITION | 未認証電話番号の重複を許可 | 承認済み | SMS送信制限は運用課題 |
| DEV-006 | CLARIFICATION | 有償ポイント無期限、無償ポイントのみ期限あり | 承認済み | 資金決済法・会計確認は公開前課題 |
| DEV-007 | ADDITION | Google認証済みメールの既存ユーザー判定 | 承認済み | フロント接続/E2Eは不足 |
| DEV-008 | ADDITION | ランク演出画像・動画のランダム選択とdraw_results保存 | 承認済み | 過去結果再現性テストは不足 |
| DEV-009 | ADDITION | 日次抽選上限 | 承認済み | 並行テストは不足 |
| DEV-010 | ADDITION | `admin.luxe-pack.biz` サブドメイン運用 | 承認済み | nginx/Cloudflare/Cookie実設定確認は必要 |
| DEV-011 | CONFLICT | 本番決済未接続でmock決済のみ | mockは開発・検証用として承認済み。本番決済は未承認/PENDING | 本番ポイント購入公開不可 |

## Approval Update 2026-06-25

プロジェクト責任者は、`docs/md/all_check.md` に記載され、`docs/review/AS_BUILT_IMPLEMENTATION_MATRIX.md` で `VERIFIED`、`IMPLEMENTED_UNTESTED`、`PARTIAL` と確認された現行実装を正式仕様として承認した。

このため、DEV-001〜DEV-010およびDEV-012〜DEV-020は、今後「正式採用するか判断が必要」とは扱わない。残る確認事項は、仕様承認ではなく、テスト、E2E、実機、法務、会計、インフラ、リリース準備の不足として扱う。

mock決済は開発・検証用機能として承認済みである。ただし本番決済プロバイダ接続は未実装であり、`PENDING` として扱う。

## Deviations

### DEV-001: ガチャ詳細で景品ごとの排出率を表示しない

- 分類: `OVERRIDE`
- 正本v1.4の記述:
  - `5.4 ガチャ詳細画面` に「景品カード: 画像、景品名、個別確率、最大当選数、残り当選数、商品状態、参考価格を表示」とある。
  - `13.1 必須表示項目` に「ランクごとの合計確率と景品ごとの個別確率」とある。
- 現在実装:
  - `docs/md/all_check.md` では「ガチャ詳細で景品の排出率は表示しない」と記録されている。
  - 関連画面は `frontend/src/app/gachas/[id]/page.tsx`、`frontend/src/app/gachas/[id]/draw-panel.tsx`。
  - API側の確率管理はLaravel側に存在するが、ユーザー画面の表示方針として個別排出率を出さない扱い。
- 差分の理由:
  - ユーザー画面の見やすさ、運用上の表示簡素化、参考サイト寄せのUI調整によるもの。
- DB・API・画面への影響:
  - DB/APIの確率バージョン、ステージ、ppm計算には影響しない。
  - ユーザー画面の表示要件に直接影響する。
  - 管理画面では確率設定・利益計算のために確率情報を保持する必要がある。
- 法務・セキュリティ上の注意:
  - 正本v1.4では確率表示を重要表示項目として扱っている。
  - 個別確率非表示が景品表示法、消費者保護、オリパ運用上の表示義務に抵触しないか確認が必要。
  - 少なくともランク合計確率、ステージ別確率、最低保証内容の表示で足りるか法務確認が必要。
- 承認状況:
  - 承認済み。法務確認済みの表示ルールとして、個別確率非表示の代替表示を正本へ明記する必要がある。
- 残る確認事項:
  - 必要。特に公開前の表示・規約・注意事項との整合確認が必要。

### DEV-002: 配送をまとめ配送から景品単位へ変更

- 分類: `OVERRIDE`
- 正本v1.4の記述:
  - `5.6 マイページ・発送` に「発送依頼: 複数景品をまとめて発送依頼。配送先を選択」とある。
- 現在実装:
  - 配送は景品単位で状態管理する。
  - 関連Migration: `backend/database/migrations/2026_06_19_000001_add_delivery_fields_to_shipping_items.php`、`backend/database/migrations/2026_06_19_000002_split_grouped_shipping_requests.php`。
  - 関連クラス: `backend/app/Models/ShippingItem.php`、`backend/app/Http/Controllers/Admin/Shipping/AdminShippingItemController.php`、`backend/app/Domain/Shipping/Services/ShippingRequestService.php`。
  - 関連API: `PUT /admin/api/shipping-items/{shippingItem}`。
  - 関連テスト: `backend/tests/Feature/AdminShippingRequestApiTest.php`。
- 差分の理由:
  - 管理画面でユーザー単位・まとめ単位だと状態と追跡番号が分かりづらいため。
  - 景品ごとに発送状況、追跡番号、配送状態を管理したい運用要件が追加されたため。
- DB・API・画面への影響:
  - `shipping_items` に配送状態、追跡番号等を持たせる。
  - 既存のまとめ配送データは分割Migrationで景品単位へ補正する。
  - 管理画面の配送一覧・編集は景品単位になる。
- 法務・セキュリティ上の注意:
  - 配送先個人情報の扱いは変わらないが、景品単位で配送履歴が増えるため閲覧権限・ログ管理が重要。
  - 複数景品を同梱しない方針なら送料・発送条件を規約やFAQに明記する必要がある。
- 承認状況:
  - 承認済み。運用の明確性が高く、管理上の事故を減らせる。
- 残る確認事項:
  - 必要。送料負担、同梱可否、ユーザー画面での説明を確定する必要がある。

### DEV-003: DB日時をAsia/Tokyoで保存

- 分類: `OVERRIDE`
- 正本v1.4の記述:
  - 正本v1.4ではDB保存タイムゾーンを明示的にAsia/Tokyoとする記述は確認できない。
  - 抽選日時、決済日時、失効日時、日次集計、基準日集計など時刻を扱う要件は複数存在する。
- 現在実装:
  - `backend/config/app.php`、`backend/config/database.php`、`.env.example`、`backend/.env.example`、`docker-compose.yml` でAsia/Tokyo方針が確認できる。
  - `DB_TIMEZONE=Asia/Tokyo`、`APP_TIMEZONE=Asia/Tokyo` を前提にしている。
- 差分の理由:
  - 運用者・管理画面・日次抽選上限・日次売上通知を日本時間で扱うため。
- DB・API・画面への影響:
  - DBに保存されるtimestamp、Laravelの`now()`、日次集計、日次抽選上限の境界が日本時間になる。
  - APIレスポンスの時刻表示も日本時間前提になりやすい。
- 法務・セキュリティ上の注意:
  - 決済、ポイント失効、日次売上、基準日残高集計は時刻境界が法務・会計に影響する。
  - 外部決済・WebhookがUTCを返す場合、二重変換や日付ズレに注意が必要。
- 承認状況:
  - 承認済み。国内運用なら妥当だが、「保存はJST」「外部連携はUTCを受けてJST正規化」など明文化が必要。
- 残る確認事項:
  - 必要。会計・決済・基準日集計の時刻境界を確認する必要がある。

### DEV-004: 未認証メールアドレスの重複を許可

- 分類: `OVERRIDE`
- 正本v1.4の記述:
  - `5.2 会員登録・認証` にメール登録、メール認証、ログイン、パスワード再設定がある。
  - 未認証メールアドレスの重複許可までは明記されていない。
- 現在実装:
  - 未認証メールアドレスは重複登録可能。
  - active/suspendedの認証済みメールは重複不可。
  - withdrawnの認証済みメールは再利用可能。
  - 同一メールで別ユーザーが認証済みになった場合、未認証ユーザーの確認URLは無効扱い。
  - 関連Migration: `backend/database/migrations/2026_06_24_000004_allow_duplicate_unverified_user_emails.php`。
  - 関連クラス: `backend/app/Http/Requests/Api/RegisterRequest.php`、`backend/app/Http/Controllers/Api/AuthController.php`。
  - 関連テスト: `backend/tests/Feature/UserAuthApiTest.php`。
- 差分の理由:
  - 仮登録状態のメール入力ミス・再登録・SNSログインとの衝突を柔軟に処理するため。
- DB・API・画面への影響:
  - `users.email` の一意制約方針が認証状態ベースになる。
  - 登録、メール認証、ログイン、パスワード再設定APIの判定条件に影響する。
  - 未認証アカウントが残存しやすくなる。
- 法務・セキュリティ上の注意:
  - 未認証アカウントの蓄積、メール確認URLの無効化、なりすまし誤認のUX説明が必要。
  - パスワード再設定は認証済みユーザーだけ対象にする現行方針を維持すべき。
- 承認状況:
  - 承認済み。メール認証フローと未認証アカウントの保管期限・削除方針を正本へ追加する必要がある。
- 残る確認事項:
  - 必要。退会・停止・未認証データ保持期間の運用判断が必要。

### DEV-005: 未認証電話番号の重複を許可

- 分類: `ADDITION`
- 正本v1.4の記述:
  - `5.2 会員登録・認証` の推奨拡張としてSMS認証が挙げられている。
  - 電話番号重複ルールは明記されていない。
- 現在実装:
  - 未認証電話番号の重複は許可。
  - active/suspendedのSMS認証済み電話番号は再利用不可。
  - withdrawnのSMS認証済み電話番号は再利用可能。
  - 電話番号変更時はSMS認証をリセットし、古い番号を再利用可能にする。
  - 関連Migration: `backend/database/migrations/2026_06_24_000002_create_sms_verification_codes_table.php`、`backend/database/migrations/2026_06_24_000003_add_normalized_phone_number_to_user_profiles_table.php`。
  - 関連クラス: `backend/app/Domain/Notification/Services/SmsVerificationService.php`、`backend/app/Http/Controllers/Api/MeController.php`。
  - 関連API: `POST /api/me/sms-verification`、`POST /api/me/sms-verification/verify`、`PUT /api/me/profile`。
  - 関連テスト: `backend/tests/Feature/SmsVerificationApiTest.php`、`backend/tests/Feature/SmsVerificationStateTest.php`。
- 差分の理由:
  - 仮登録・番号入力途中では重複を許し、最初に認証したユーザーを持ち主として扱うため。
- DB・API・画面への影響:
  - `user_profiles.normalized_phone_number` は検索・判定用で、未認証段階ではDB一意制約ではなくサービス層判定が中心。
  - SMS送信APIとプロフィール更新APIの挙動に影響する。
- 法務・セキュリティ上の注意:
  - SMS認証済み番号を本人性の根拠にする場合、停止ユーザー番号の再利用不可は妥当。
  - 未認証番号の多重登録・SMS送信乱用を防ぐレート制限、送信回数制限、監査ログが必要。
- 承認状況:
  - 承認済み。SMS事業者決定後、送信制限・費用対策・本人確認の扱いを明文化する必要がある。
- 残る確認事項:
  - 必要。SMS認証をどの機能の必須条件にするか確定が必要。

### DEV-006: 有償ポイント無期限、無償ポイントのみ期限あり

- 分類: `CLARIFICATION`
- 正本v1.4の記述:
  - `3.3 価格・ポイント` に「有償/無償ごとにロット単位で設定できる。期限値は法務確認のうえ決定」とある。
  - `13.2 資金決済法対応` にロット単位の有効期限、自動失効、失効前通知が記載されている。
- 現在実装:
  - 有償ポイントは期限なし。
  - 無償ポイントのみ期限あり。
  - 関連クラス: `backend/app/Domain/Point/Services/PointLotService.php`、`backend/app/Domain/Point/Services/PointExpirationService.php`、`backend/app/Domain/Point/Services/PointConsumptionService.php`。
  - 関連Migration: `backend/database/migrations/2026_06_10_000002_create_point_tables.php`。
  - 関連画面: `frontend/src/app/points/page.tsx`、`frontend/src/app/admin-dashboard.tsx`。
  - 関連テスト: `backend/tests/Unit/PointExpirationServiceTest.php`、`backend/tests/Unit/PointConsumptionServiceTest.php`、`backend/tests/Feature/MePointApiTest.php`。
- 差分の理由:
  - ユーザー方針として「有償ポイントは期限なし、無償ポイントのみ期限あり」が確定したため。
- DB・API・画面への影響:
  - `point_lots.expires_at` は無償ポイント中心に設定され、有償ポイントはNULL運用になる。
  - ポイント履歴・保有ポイントロット表示で期限切迫無償ポイントを赤字表示する。
- 法務・セキュリティ上の注意:
  - 有償ポイント無期限は資金決済法上の未使用残高管理・供託判定に影響する。
  - 失効しない有償残高の会計・払戻し・サービス終了時対応が重要。
- 承認状況:
  - 承認済み。資金決済法・会計確認のうえ、正本v1.4の期限値未定部分をこの方針で更新する。
- 残る確認事項:
  - 必要。専門家確認が必要。

### DEV-007: Google認証済みメールの既存ユーザー判定

- 分類: `ADDITION`
- 正本v1.4の記述:
  - `5.2 会員登録・認証` の推奨拡張にGoogleログインが含まれるが、具体ルールはない。
- 現在実装:
  - Google初回ログイン時、Googleから取得したメールは即認証済み扱い。
  - 既存の認証済みメールがある場合は拒否する。
  - 既存の未認証メールはブロックしない。
  - Google初回登録は紹介コード入力後にユーザー作成し、次ステップをSMS認証とする。
  - 関連Migration: `backend/database/migrations/2026_06_25_000002_create_social_auth_tables.php`。
  - 関連クラス: `backend/app/Http/Controllers/Api/GoogleAuthController.php`、`backend/app/Domain/Auth/Services/GoogleOAuthService.php`、`backend/app/Domain/Auth/Services/SocialAuthService.php`。
  - 関連API: `GET /api/auth/google/redirect`、`POST /api/auth/google/callback`、`POST /api/auth/google/register`。
  - 関連テスト: `backend/tests/Feature/GoogleAuthApiTest.php`。
- 差分の理由:
  - 通常登録の未認証メール重複ルールとSNSログインを両立するため。
  - Google側の認証済みメールを信頼し、二重のメール認証を省略するため。
- DB・API・画面への影響:
  - `social_accounts`、`social_login_sessions` を追加。
  - フロント側には初回登録補完画面とSMS認証導線が必要。
- 法務・セキュリティ上の注意:
  - Googleの`email_verified`を信頼する前提がある。
  - 既存通常アカウントとの統合ログインは未対応のため、アカウント重複・サポート問い合わせに注意。
- 承認状況:
  - 承認済み。ただしフロント画面、規約、アカウント統合ポリシーを追加する必要がある。
- 残る確認事項:
  - 必要。Google OAuth同意画面、プライバシーポリシー、アカウント統合方針の確認が必要。

### DEV-008: ランク演出画像・動画のランダム選択とdraw_resultsへの保存

- 分類: `ADDITION`
- 正本v1.4の記述:
  - `5.5 ガチャ実行・結果表示` に「抽選結果を演出付きで表示」とある。
  - `4.1` 付近に「ランク画像: ランク見出しや演出に使う画像」とあるが、複数素材・ランダム選択・保存仕様はない。
- 現在実装:
  - ランク演出素材マスタを追加。
  - 1つのランクに複数画像・複数動画を紐づけ可能。
  - 抽選結果作成時、Laravel側で対象ランクの画像・動画からランダムに1つずつ選択。
  - 選択された画像・動画URLを`draw_results`に保存し、後から演出設定が変わっても過去結果を再現可能にする。
  - 関連Migration: `backend/database/migrations/2026_06_17_000001_create_rank_assets_table.php`、`backend/database/migrations/2026_06_22_000003_add_multiple_rank_presentation_assets.php`。
  - 関連クラス: `backend/app/Models/RankAsset.php`、`backend/app/Models/GachaRank.php`、`backend/app/Models/DrawResult.php`、`backend/app/Domain/Draw/Services/DrawService.php`。
  - 関連関数: `DrawService::selectRankPresentation`、`DrawService::createDrawResult`。
  - 関連テスト: `backend/tests/Unit/DrawServiceTest.php`、`backend/tests/Feature/DrawApiTest.php`、`backend/tests/Feature/AdminGachaRankApiTest.php`。
- 差分の理由:
  - ランクが増えても同じ素材を何度もアップロードせず、素材マスタから選択できるようにするため。
  - 抽選結果の再現性を保つため、抽選時点で選ばれた演出URLを保存する必要があるため。
- DB・API・画面への影響:
  - `rank_assets`、`gacha_rank_assets`、`draw_results` の演出関連カラムに影響。
  - 管理画面の設定・ランク登録編集・抽選結果画面に影響。
  - 抽選APIレスポンスに演出URLが含まれる。
- 法務・セキュリティ上の注意:
  - 抽選ロジック自体はLaravel側であり、Next.js側に抽選ランダムは持たせない点は正本と整合。
  - 演出のランダム選択は当落判定ではないが、ユーザー誤認防止のため結果確定後の演出であることを維持すべき。
- 承認状況:
  - 承認済み。抽選結果の監査・再現性が向上する。
- 残る確認事項:
  - 必要。素材削除時の過去結果表示保証、デフォルト画像・動画の扱いを確定する必要がある。

### DEV-009: 日次抽選上限

- 分類: `ADDITION`
- 正本v1.4の記述:
  - ガチャ基本情報、総口数、残り口数、開催期間はあるが、1日あたりのユーザー別抽選上限は明記されていない。
- 現在実装:
  - `gachas.daily_draw_limit` を追加。
  - 空白/nullの場合は制限なし。
  - Laravelの抽選処理内で当日抽選回数を検証する。
  - 関連Migration: `backend/database/migrations/2026_06_23_000001_add_daily_draw_limit_to_gachas.php`。
  - 関連クラス: `backend/app/Models/Gacha.php`、`backend/app/Domain/Draw/Services/DrawService.php`。
  - 関連関数: `DrawService::assertDailyDrawLimit`。
  - 関連API: `POST /api/gachas/{gacha}/draw`、`/admin/api/gachas*`。
  - 関連テスト: `backend/tests/Unit/DrawServiceTest.php`、`backend/tests/Feature/DrawApiTest.php`、`backend/tests/Feature/AdminGachaApiTest.php`。
- 差分の理由:
  - 限定ガチャとして、1日に設定した規定回数しか引けないガチャを運用するため。
- DB・API・画面への影響:
  - ガチャ登録・編集画面に規定回数項目が追加される。
  - 抽選APIは上限超過時にエラーを返す。
  - DB日時Asia/Tokyo方針と日付境界が連動する。
- 法務・セキュリティ上の注意:
  - 利用制限・射幸性抑制の観点ではプラス。
  - 日付境界、複数アカウント利用、SMS認証との組み合わせを検討する必要がある。
- 承認状況:
  - 承認済み。限定ガチャの正式機能として正本へ追加する。
- 残る確認事項:
  - 必要。上限単位を「ユーザーID」「SMS認証済み電話番号」「世帯」などのどれにするか確認が必要。

### DEV-010: `admin.luxe-pack.biz` サブドメイン運用

- 分類: `ADDITION`
- 正本v1.4の記述:
  - 管理側機能の記述はあるが、管理画面のサブドメイン運用は明記されていない。
- 現在実装:
  - 管理画面は `admin.luxe-pack.biz` のサブドメイン運用前提。
  - リポジトリ内では `frontend/next.config.ts` と共有文書上の記録を確認。
  - nginx/Cloudflareの実環境設定は本監査では確認していない。
- 差分の理由:
  - ユーザー側と管理側をドメイン上で分離し、運用とアクセス制御を明確化するため。
- DB・API・画面への影響:
  - DB影響なし。
  - CORS、Cookie、Sanctum、CSRF、Next.js image/cache、Nginx routing、Cloudflare設定に影響する。
  - 管理画面URLがユーザー側と分離される。
- 法務・セキュリティ上の注意:
  - 管理画面のサブドメイン分離はセキュリティ上有利。
  - Cookie domain、SameSite、CORS、管理者セッション期限、IP制限、WAF設定を確認する必要がある。
- 承認状況:
  - 承認済み。インフラ・セキュリティ仕様として正本へ追加する。
- 残る確認事項:
  - 必要。DNS、Cloudflare、TLS、nginx、本番Cookie設定の実機確認が必要。

### DEV-011: 本番決済未接続でmock決済のみ

- 分類: `CONFLICT`
- 正本v1.4の記述:
  - `5.3 ポイント・決済` に「ポイント購入 → 決済 → 決済成功確認 → ポイント付与」とある。
  - 決済方法は「クレジットカードをMVP優先。Apple Pay、Google Pay、コンビニ決済、QR決済は拡張」とある。
  - `14.1 推奨システム構成` に決済としてStripe/GMO/KOMOJU等が記載されている。
  - `15.1 MVP必須範囲` に決済Webhookが含まれる。
- 現在実装:
  - 本番決済プロバイダは未接続。
  - 現行は検証用`mock`決済のみ。
  - 関連クラス: `backend/app/Http/Controllers/Api/PaymentController.php`、`backend/app/Http/Requests/Api/StorePaymentRequest.php`、`backend/app/Domain/Payment/Services/PaymentIntentService.php`、`backend/app/Domain/Payment/Services/PaymentPointGrantService.php`。
  - 関連API: `POST /api/payments`、`POST /api/payments/{payment}/mock-succeed`。
  - 関連テスト: `backend/tests/Feature/PaymentApiTest.php`、`backend/tests/Feature/PaymentWebhookApiTest.php`。
- 差分の理由:
  - 決済審査・決済事業者選定待ち。
  - 現時点ではユーザー動線とポイント付与処理の検証を優先している。
- DB・API・画面への影響:
  - `payments` テーブル、ポイント付与処理、購入画面は存在するが、本番決済の外部連携・Webhook署名検証・返金/チャージバック実処理は未完成。
  - mock成功APIが存在するため、本番環境で露出させない制御が必要。
- 法務・セキュリティ上の注意:
  - 本番決済未接続のまま公開すると実売上を扱えない。
  - mock決済が本番で利用可能だと無償で有償ポイントを取得できる重大リスクがある。
  - 返金、チャージバック、本人認証、3Dセキュア、決済ログ保存、Webhook冪等性の本番確認が必要。
- 承認状況:
  - mock決済は開発・検証用として承認済み。本番決済プロバイダ接続は未実装・PENDINGとして記録する。
- 残る確認事項:
  - 必要。決済事業者、審査状況、公開前のmock無効化、本番Webhook仕様を確定する必要がある。

## Additional Notable Differences

| ID | 分類 | 差分 | 現在実装 | 承認状況 | 残る確認 |
|---|---|---|---|---|---|
| DEV-012 | ADDITION | 紹介コード・紹介ポイント | `user_referrals`, `referral_settings`, SMS認証後付与 | 承認済み | テスト・E2E・運用確認は継続 |
| DEV-013 | ADDITION | LINE友達追加ポイント | `line_friend_settings`, `line_friendships`, LINE webhook | 承認済み | テスト・E2E・運用確認は継続 |
| DEV-014 | ADDITION | トップバナー管理 | `top_banners` と公開API | 承認済み | テスト・E2E・運用確認は継続 |
| DEV-015 | ADDITION | ガチャタグ管理 | `gacha_tags`, `gacha_tag_assignments` と公開API | 承認済み | テスト・E2E・運用確認は継続 |
| DEV-016 | ADDITION | お問い合わせ自動返信 | Laravel Mail経由 | 承認済み | テスト・E2E・運用確認は継続 |
| DEV-017 | ADDITION | Discord日次売上通知 | Scheduler/Command/Discord通知Service | 承認済み | テスト・E2E・運用確認は継続 |
| DEV-018 | CLARIFICATION | 利益シミュレーション・商品設計プランナー | 管理画面とAPIで実装 | 承認済み | テスト・E2E・運用確認は継続 |
| DEV-019 | ADDITION | ランク演出素材マスタ | `rank_assets` と管理画面 | 承認済み | テスト・E2E・運用確認は継続 |
| DEV-020 | ADDITION | 購入プラン販売期間 | `point_purchase_plans.starts_at/ends_at` | 承認済み | テスト・E2E・運用確認は継続 |

## Release Readiness Follow-ups

以下は仕様採用の可否ではなく、承認済み仕様を安全にリリースするための確認・補強事項である。

1. 個別景品確率非表示を前提に、代替表示、規約、注意事項、法務表示の整合を確認する。
2. 景品単位配送を前提に、同梱可否、送料、配送依頼単位を規約・FAQ・画面文言へ反映する。
3. Asia/Tokyo基準を前提に、外部決済・Webhook・会計基準日の時刻変換ルールを確認する。
4. 未認証メール・未認証電話番号の重複許可を前提に、未認証データの削除期限と不正利用対策を決める。
5. 有償ポイント無期限、無償ポイント期限ありを前提に、資金決済法・会計上の公開前確認を行う。
6. Googleログインの現行仕様を前提に、既存通常アカウントとの統合を将来対応にするか別途バックログ化する。
7. ランク演出素材削除時に、過去抽選結果の画像・動画をどこまで保存・再配信保証するか運用を決める。
8. 日次抽選上限の現行ユーザーID単位を前提に、SMS認証電話番号等を使った追加不正対策を将来対応にするか検討する。
9. 管理サブドメインの本番Cookie/CORS/TLS/WAF設定を実機で確認する。
10. mock決済は開発・検証用として維持し、本番決済プロバイダ接続と本番公開時のmock無効化を別途実装する。

## Overall Assessment

- 現行実装は正本v1.4の中核である「Laravel側抽選」「ポイントロット」「確率バージョン」「最低保証」「管理画面」を維持しつつ、運用上必要になった追加機能を多数取り込んでいる。
- 景品個別確率非表示、景品単位配送、DB時刻JST保存、有償ポイント無期限などの差分は、2026-06-25時点でプロジェクト責任者により正式承認済みであり、正本v1.5へ統合済みである。
- 残る課題は仕様承認ではなく、法務・会計確認、テスト/E2E/実機確認、インフラ確認、mock決済の本番無効化、本番決済プロバイダ接続などのリリース準備である。
