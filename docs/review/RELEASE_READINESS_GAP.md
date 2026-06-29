# RELEASE READINESS GAP

作成日: 2026-06-25

対象:

- 現行正本: `docs/md/spec_v1.5.md`
- 承認決定: `docs/decisions/APPROVED_AS_BUILT_SPECIFICATIONS_2026-06-25.md`
- 実装監査: `docs/review/AS_BUILT_IMPLEMENTATION_MATRIX.md`
- 重要ロジック監査: `docs/review/CRITICAL_LOGIC_AUDIT.md`
- テストギャップ: `docs/review/TEST_COVERAGE_GAP.md`

## Position

`docs/md/all_check.md` に記載され、`docs/review/AS_BUILT_IMPLEMENTATION_MATRIX.md` で `VERIFIED`、`IMPLEMENTED_UNTESTED`、`PARTIAL` と確認された現行実装は、プロジェクト責任者により正式承認済みである。

本資料は、仕様承認の可否ではなく、リリース前に残る実装・テスト・運用・法務会計・インフラ確認のギャップを管理する。

## Status Handling

| Status | 承認状況 | リリース上の扱い |
|---|---|---|
| `VERIFIED` | 承認済み | 基本的にリリース候補。ただし本番設定・外部連携は別途確認する |
| `IMPLEMENTED_UNTESTED` | 承認済み | テスト、E2E、実機確認が不足。リリース前に確認を追加する |
| `PARTIAL` | 実装済み部分は承認済み | 未実装部分を残課題として明示し、公開範囲を制限する |
| `PENDING` | 未承認/未実装 | リリース範囲外。必要なら別途実装する |
| `FUTURE` | 将来対応 | リリース範囲外 |
| `NOT_FOUND` | 実装確認不可 | 実装済みとして扱わない |

## P0 Release Blockers

| ID | 領域 | 内容 | 承認状況 | ギャップ | 推奨対応 |
|---|---|---|---|---|---|
| RRG-001 | 本番決済 | 本番決済プロバイダ接続 | 未実装/PENDING | mock決済のみ。実売上処理は不可 | 本番決済事業者、Webhook、署名検証、返金/CB処理を実装するまで本番ポイント購入を公開しない |
| RRG-002 | mock決済 | mock決済の本番誤利用防止 | 開発・検証用として承認済み | `mock-succeed` はlocal/testing限定だが、mock payment作成自体の本番制御確認が必要 | productionでmock作成・成功処理が利用できないことをテストする |
| RRG-003 | 資金決済法 | 有償ポイント無期限・未使用残高管理 | 承認済み | 法務・会計確認、日次残高スナップショット実装確認が必要 | 専門家確認、日次集計Service/Command/Scheduler/API/画面/テスト確認 |
| RRG-004 | 日次残高スナップショット | `point_balance_snapshots` | 正本上必須 | テーブル/Modelはあるが作成処理未確認 | 作成処理とテストを追加又は確認する |
| RRG-005 | 返金/チャージバック | ポイント取消 | 状態管理の土台は承認済み | 付与済みポイント取消、残高不足時処理が未完了 | 自動取消、マイナス残高又は利用制限、未発送景品停止を実装/運用化 |
| RRG-006 | 同時抽選 | 通し番号・在庫・残高の並行安全性 | 実装仕様は承認済み | 実並行テスト不足 | PostgreSQL実DBでConcurrent Testを追加 |
| RRG-007 | 日次抽選上限 | daily_draw_limit | 承認済み | 並行実行・JST境界テスト不足 | 同時リクエスト、23:59/00:00 JST境界テストを追加 |
| RRG-008 | 管理サブドメイン | `admin.luxe-pack.biz` | 承認済み | nginx/Cloudflare/Cookie/CORS/TLS/WAF実設定確認が必要 | 実機で管理ログイン、API、Cookie、CORS、TLSを確認 |
| RRG-009 | 個別景品確率非表示 | ユーザー画面非表示 | 承認済み | 法務表示、規約、注意事項の最終確認が必要 | 代替表示と規約文言を公開前に確認 |
| RRG-010 | メール/SMS/LINE | 外部通知・本人確認 | 現行仕様は承認済み | 本番APIキー、署名、送信制限、Webhook再送確認が必要 | Mailgun、SMS事業者、LINE Webhookの本番相当テスト |

## P1 Release Gaps

| ID | 領域 | 内容 | 承認状況 | ギャップ | 推奨対応 |
|---|---|---|---|---|---|
| RRG-011 | Browser/E2E | ユーザー主要導線 | 仕様承認済み | E2E未整備 | 会員登録、ログイン、ポイント購入mock、抽選、結果表示、景品BOX、配送依頼をPlaywright等で確認 |
| RRG-012 | Browser/E2E | 管理画面主要導線 | 仕様承認済み | E2E未整備 | 管理ログイン、ガチャ/カテゴリ/ランク/景品/確率/配送/設定の画面遷移と再読み込みを確認 |
| RRG-013 | ランク演出 | 過去結果再現性 | 承認済み | 素材変更後の履歴API/E2E不足 | 抽選後に素材変更しても保存済みURLが表示されることを検証 |
| RRG-014 | 紹介ポイント | SMS認証後付与 | 承認済み | 並行SMS verifyテスト不足 | 同一紹介の並行verifyでPointLot/Ledgerが1件のみになることを検証 |
| RRG-015 | LINEポイント | LINE Webhook再送 | 承認済み | イベント再送・並行送信テスト不足 | 同一line_user_id/codeの再送/並行POSTで二重付与なしを検証 |
| RRG-016 | SMS認証 | 試行回数・期限 | 承認済み | max到達後、期限切れ、再送旧コード無効の境界テスト不足 | Carbon固定とFakeSmsSenderで境界テストを追加 |
| RRG-017 | 配送 | 景品単位配送 | 承認済み | 画面E2Eと規約/FAQ反映不足 | ユーザー詳細から配送編集、配送メニュー戻り、追跡番号更新を確認 |
| RRG-018 | 固定ページ | 法務文書 | 承認済み | 最終法務確認と表示確認 | 利用規約、プライバシーポリシー、特商法の表示、改行、編集画面を確認 |
| RRG-019 | アップロード | 画像/動画保存 | 承認済み | 本番容量、バックアップ、S3移行方針 | サーバー保存時のバックアップと将来S3移行手順を文書化 |
| RRG-020 | Discord通知 | 日次売上通知 | 承認済み | mock決済前提の通知内容と本番売上定義 | 本番決済導入時に売上集計定義を再確認 |

## PENDING / FUTURE Not In Current Release

| 項目 | 状態 | 扱い |
|---|---|---|
| 売上管理機能 | FUTURE / v1.6予定 | v1.5では未実装。正本v1.5を基準に別途設計する |
| 本番決済プロバイダ接続 | PENDING | 本番公開前に別途実装が必要 |
| Appleログイン | PENDING | Apple Developer情報準備後に実装 |
| PWA/プッシュ通知 | FUTURE | 運用方針決定後に実装 |
| AWS S3本番移行 | FUTURE | 素材増加後に移行 |
| Cloudflare DNS自動更新コード | NOT_FOUND | リポジトリ内実装としては確認不可 |

## Release Gate Recommendation

1. 本番ポイント購入を有効化する前に、本番決済プロバイダ接続とmock無効化確認を完了する。
2. 抽選、ポイント、Webhook、紹介、LINE、SMSについてP0の並行・再送・境界テストを追加する。
3. 資金決済法・会計・規約・確率表示の公開前確認を完了する。
4. 管理サブドメインとユーザードメインの本番Cookie/CORS/TLSを確認する。
5. 最小限のBrowser/E2Eで、ユーザー側と管理側の主要導線を確認する。

## Notes

- `IMPLEMENTED_UNTESTED` は仕様承認済みであり、仕様変更対象ではない。テストと実機確認を追加する対象である。
- `PARTIAL` は実装済み部分を承認済みとして固定し、未実装部分だけをバックログ化する。
- mock決済は開発・検証用として承認済みだが、本番決済ではない。
